<?php
declare(strict_types=1);

/**
 * Follow-ups to buyers whose payment failed. An admin, an organizer, or a team member who can see orders opens the
 * failed order, edits a friendly message and either emails it (through the normal email service, replies go to the
 * sender) or opens a WhatsApp chat with the buyer (a wa.me link: the message goes from the sender's own WhatsApp,
 * so delivery cannot be confirmed). Each send is recorded in order_followups, which also enforces the limits: one
 * send per channel per day and three in all, and nothing once the buyer has since bought tickets or the event is over.
 */

const FOLLOWUP_MAX_PER_CHANNEL = 3;
const FOLLOWUP_COOLDOWN_HOURS = 24;
const FOLLOWUP_MAX_MESSAGE = 1000;

/** Is the order_followups table there? (The order pages must keep working before the migration is applied.) */
function followup_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'order_followups'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Everything a follow-up needs about one order: the buyer, the event, the organizer, the tickets. Null if not found. */
function followup_load(int $orderId): ?array
{
    $stmt = db()->prepare('
        SELECT o.id, o.user_id, o.event_id, o.status, o.phone AS pay_phone, o.created_at,
               u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone,
               e.title AS event_title, e.slug AS event_slug, e.starts_at, e.ends_at, e.organizer_id,
               op.org_name
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        LEFT JOIN organizer_profiles op ON op.user_id = e.organizer_id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }
    $stmt = db()->prepare('SELECT oi.quantity, tt.name AS tier_name FROM order_items oi JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE oi.order_id = ? ORDER BY oi.id');
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll();
    return $order;
}

/**
 * A Ugandan mobile number as WhatsApp wants it (2567XXXXXXXX), from the number the buyer paid with, else the one on
 * their profile. Null when neither looks like a real mobile number.
 */
function followup_phone(array $order): ?string
{
    foreach ([(string) ($order['pay_phone'] ?? ''), (string) ($order['buyer_phone'] ?? '')] as $raw) {
        if (preg_replace('/\D/', '', $raw) === '') {
            continue;
        }
        $digits = iotec_normalize_phone($raw);
        if (preg_match('/^2567\d{8}$/', $digits)) {
            return $digits;
        }
    }
    return null;
}

/** "+256 772 123 456" for display. */
function followup_phone_display(string $digits): string
{
    return '+' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3) . ' ' . substr($digits, 9);
}

/** @return array{0: bool, 1: string} [may follow up, why not]. */
function followup_eligibility(array $order): array
{
    if ($order['status'] !== 'FAILED') {
        return [false, 'Follow-ups are only for orders whose payment failed.'];
    }
    $end = $order['ends_at'] ?: $order['starts_at'];
    if ($end && strtotime((string) $end) < time()) {
        return [false, 'This event has already taken place, so there is nothing left to buy.'];
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND event_id = ? AND status = 'PAID' AND created_at >= ?");
    $stmt->execute([$order['user_id'], $order['event_id'], $order['created_at']]);
    if ((int) $stmt->fetchColumn() > 0) {
        return [false, 'Good news: this buyer has since bought tickets for this event, so no follow-up is needed.'];
    }
    return [true, ''];
}

/** Past follow-ups on this order, newest first, with who sent them. */
function followup_history(int $orderId): array
{
    $stmt = db()->prepare('
        SELECT f.*, u.name AS sender_name FROM order_followups f
        LEFT JOIN users u ON u.id = f.sent_by
        WHERE f.order_id = ? ORDER BY f.id DESC LIMIT 20
    ');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

/** @return array{0: bool, 1: string} [may send on this channel now, why not]. Only sends that went out count. */
function followup_channel_check(int $orderId, string $channel): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS n, MAX(created_at) AS last FROM order_followups WHERE order_id = ? AND channel = ? AND status IN ('SENT','OPENED')");
    $stmt->execute([$orderId, $channel]);
    $row = $stmt->fetch();
    $label = $channel === 'EMAIL' ? 'emailed' : 'messaged on WhatsApp';
    if ((int) $row['n'] >= FOLLOWUP_MAX_PER_CHANNEL) {
        return [false, 'This buyer has already been ' . $label . ' ' . FOLLOWUP_MAX_PER_CHANNEL . ' times about this order. That is the limit.'];
    }
    if ($row['last'] && strtotime((string) $row['last']) > time() - FOLLOWUP_COOLDOWN_HOURS * 3600) {
        $next = date('d M, H:i', strtotime((string) $row['last']) + FOLLOWUP_COOLDOWN_HOURS * 3600);
        return [false, 'This buyer was ' . $label . ' in the last day. You can do it again after ' . $next . '.'];
    }
    return [true, ''];
}

function followup_event_url(array $order): string
{
    return rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode((string) $order['event_slug']);
}

/** The sender, as the follow-up needs to know them: name, email, and what they are (admin, organizer or team member). */
function followup_sender(int $userId, string $role): array
{
    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch() ?: ['id' => $userId, 'name' => '', 'email' => ''];
    return ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email'], 'role' => $role];
}

/** The message the sender starts from. Friendly, plain, and it never mentions why the payment failed. */
function followup_default_message(array $order, array $sender): string
{
    $first = trim(explode(' ', trim((string) $order['buyer_name']))[0]) ?: 'there';
    $from = $sender['role'] === 'ADMIN' ? 'Obitickets' : (trim((string) ($order['org_name'] ?? '')) ?: 'the event team');
    $name = trim($sender['name']);
    $who = ($name !== '' && strcasecmp($name, $from) !== 0) ? $name . ' from ' . $from : $from;
    $who = rtrim($who, '. ');
    return "Hi {$first}, this is {$who}. We noticed your ticket purchase for {$order['event_title']} didn't go through, so no ticket was issued.\n\n"
        . 'If you would still like to come, you can try again here: ' . followup_event_url($order) . "\n\n"
        . "If any money left your account, just reply and we will sort it out.";
}

/** @return string|null An error message, or null when the text is fine to send. */
function followup_validate_message(string $message): ?string
{
    $message = trim($message);
    if (mb_strlen($message) < 10) {
        return 'Write a message first (at least a sentence).';
    }
    if (mb_strlen($message) > FOLLOWUP_MAX_MESSAGE) {
        return 'That message is too long. Keep it under ' . FOLLOWUP_MAX_MESSAGE . ' characters.';
    }
    $own = rtrim(APP_URL, '/');
    if (preg_match_all('~https?://[^\s<>"\']+~i', $message, $m)) {
        foreach ($m[0] as $url) {
            if (stripos($url, $own) !== 0) {
                return 'Only links to ' . preg_replace('~^https?://~', '', $own) . ' can be included in this message.';
            }
        }
    }
    return null;
}

function followup_record(int $orderId, array $sender, string $channel, string $status, ?string $note = null): void
{
    db()->prepare('INSERT INTO order_followups (order_id, sent_by, sender_role, channel, status, note) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $sender['id'] ?: null, $sender['role'], $channel, $status, $note !== null ? mb_substr($note, 0, 300) : null]);
}

function render_followup_email_html(array $order, array $sender, string $message): string
{
    $name = htmlspecialchars((string) $order['buyer_name']);
    $event = htmlspecialchars((string) $order['event_title']);
    $url = htmlspecialchars(followup_event_url($order));
    $body = nl2br(htmlspecialchars(trim($message)), false);
    $from = $sender['role'] === 'ADMIN' ? 'Obitickets' : (trim((string) ($order['org_name'] ?? '')) ?: 'the event team');
    $fromH = htmlspecialchars($from);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto; color:#1C1526;">
          <p style="font-size:15px; line-height:1.65;">{$body}</p>
          <p style="margin:24px 0;">
            <a href="{$url}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">Try again: {$event}</a>
          </p>
          <p style="color:#726C7E; font-size:12px; line-height:1.6;">
            You are receiving this because you tried to buy a ticket for {$event} on Obitickets and the payment did not go through. Sent by {$fromH}. You can reply to this email.
          </p>
        </div>
        HTML;
}

/**
 * Does one follow-up action, after the page has checked who the sender is. Returns:
 *  ['ok' => true, 'flash' => text]            an email went out
 *  ['ok' => true, 'redirect' => wa.me link]   the WhatsApp chat should open
 *  ['ok' => false, 'error' => text]           nothing was sent
 * $emailSender is only for tests: a callable with the same signature as resend_send.
 */
function followup_handle(int $orderId, array $sender, string $action, string $message, ?callable $emailSender = null): array
{
    if (!followup_ready()) {
        return ['ok' => false, 'error' => 'Follow-ups are not set up on this server yet.'];
    }
    $order = followup_load($orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    [$may, $why] = followup_eligibility($order);
    if (!$may) {
        return ['ok' => false, 'error' => $why];
    }
    if ($err = followup_validate_message($message)) {
        return ['ok' => false, 'error' => $err];
    }
    $message = trim($message);

    if ($action === 'followup_email') {
        [$can, $whyNot] = followup_channel_check($orderId, 'EMAIL');
        if (!$can) {
            return ['ok' => false, 'error' => $whyNot];
        }
        $subject = 'About your ticket for ' . $order['event_title'];
        $replyTo = filter_var($sender['email'], FILTER_VALIDATE_EMAIL) ? $sender['email'] : null;
        $send = $emailSender ?? 'resend_send';
        $sent = (bool) $send((string) $order['buyer_email'], $subject, render_followup_email_html($order, $sender, $message), 'followup', $orderId, $replyTo);
        followup_record($orderId, $sender, 'EMAIL', $sent ? 'SENT' : 'FAILED', $sent ? null : 'Not accepted by the email service (see Email log).');
        return $sent
            ? ['ok' => true, 'flash' => 'Email sent to ' . $order['buyer_email'] . '.']
            : ['ok' => false, 'error' => 'The email was not accepted. An admin can see exactly why in the Email log.'];
    }

    if ($action === 'followup_whatsapp') {
        $phone = followup_phone($order);
        if ($phone === null) {
            return ['ok' => false, 'error' => 'This buyer has no mobile number we can use for WhatsApp.'];
        }
        [$can, $whyNot] = followup_channel_check($orderId, 'WHATSAPP');
        if (!$can) {
            return ['ok' => false, 'error' => $whyNot];
        }
        followup_record($orderId, $sender, 'WHATSAPP', 'OPENED', 'Chat opened from the sender\'s WhatsApp.');
        return ['ok' => true, 'redirect' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($message)];
    }

    return ['ok' => false, 'error' => 'Unknown action.'];
}

/**
 * Handles a POST from the order page. Returns null when the request is not a follow-up, otherwise the followup_handle
 * result with 'done' set to the URL to redirect to (WhatsApp, or back to the order after an email).
 */
function followup_post(int $orderId, array $sender, string $backUrl): ?array
{
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['followup_email', 'followup_whatsapp'], true)) {
        return null;
    }
    $result = followup_handle($orderId, $sender, $action, (string) ($_POST['message'] ?? ''));
    if ($result['ok']) {
        $result['done'] = $result['redirect'] ?? ($backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'followup=sent');
    }
    return $result;
}

/** The "Follow up with this buyer" card. $error is a message from a send that just failed; $draft keeps what they typed. */
function render_followup_panel(int $orderId, array $sender, ?string $error = null, ?string $draft = null, bool $isAdmin = false): string
{
    if (!followup_ready()) {
        return $isAdmin ? '<div class="admin-card"><h3 style="margin-bottom:6px">Follow up with this buyer</h3><p class="muted" style="font-size:0.84rem">Not set up yet: run migration/025_order_followups.sql on this server first.</p></div>' : '';
    }
    $order = followup_load($orderId);
    if (!$order || $order['status'] !== 'FAILED') {
        return '';
    }
    [$may, $why] = followup_eligibility($order);
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
    $out = '<div class="admin-card" id="followup"><h3 style="margin-bottom:4px">Follow up with this buyer</h3>';
    if (!$may) {
        return $out . '<p class="muted" style="font-size:0.84rem; margin-top:8px">' . $h($why) . '</p></div>';
    }
    $phone = followup_phone($order);
    [$canEmail, $emailWhy] = followup_channel_check($orderId, 'EMAIL');
    [$canWa, $waWhy] = followup_channel_check($orderId, 'WHATSAPP');
    $text = $draft ?? followup_default_message($order, $sender);

    $out .= '<p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:14px">The payment for this order did not go through. A friendly message can win the sale back. You can edit it before sending.</p>';
    $out .= '<div class="admin-detail-row"><span class="k">Email</span><span class="v">' . $h((string) $order['buyer_email']) . '</span></div>';
    $out .= '<div class="admin-detail-row"><span class="k">WhatsApp</span><span class="v">' . ($phone ? $h(followup_phone_display($phone)) : '<span class="muted">No usable mobile number</span>') . '</span></div>';
    if ($error) {
        $out .= '<p style="color:var(--danger); font-size:0.84rem; margin:12px 0 0">' . $h($error) . '</p>';
    }
    $out .= '<form method="post" action="#followup" style="margin-top:14px">' . csrf_field();
    $out .= '<div class="admin-form-row"><label for="fu-msg">Message</label><textarea id="fu-msg" name="message" rows="8" maxlength="' . FOLLOWUP_MAX_MESSAGE . '" required style="width:100%; resize:vertical">' . $h($text) . '</textarea></div>';
    $out .= '<div style="display:flex; gap:10px; flex-wrap:wrap">';
    $out .= '<button class="btn btn-purple" type="submit" name="action" value="followup_email"' . ($canEmail ? '' : ' disabled') . '>Send email</button>';
    if ($phone) {
        $out .= '<button class="btn btn-line" type="submit" name="action" value="followup_whatsapp" formtarget="_blank"' . ($canWa ? '' : ' disabled') . '><svg width="15" height="15" aria-hidden="true" style="margin-right:6px"><use href="#ic-whatsapp"/></svg>Open WhatsApp</button>';
    }
    $out .= '</div></form>';
    foreach ([[$canEmail, $emailWhy], [$canWa && $phone, $waWhy]] as [$can, $reason]) {
        if (!$can && $reason !== '') {
            $out .= '<p class="muted" style="font-size:0.78rem; margin-top:10px">' . $h($reason) . '</p>';
        }
    }
    if ($phone) {
        $out .= '<p class="muted" style="font-size:0.74rem; margin-top:10px">WhatsApp opens on your own phone or WhatsApp Web with the message ready to send. We can only record that it was opened, not that it was delivered.</p>';
    }
    $history = followup_history($orderId);
    if ($history) {
        $out .= '<div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--line)"><h4 style="font-size:0.82rem; margin-bottom:8px">History</h4>';
        foreach ($history as $f) {
            $what = $f['channel'] === 'EMAIL' ? ($f['status'] === 'SENT' ? 'Emailed' : 'Email not sent') : 'WhatsApp opened';
            $who = trim((string) $f['sender_name']) !== '' ? $f['sender_name'] : ucfirst(strtolower($f['sender_role']));
            $out .= '<div class="admin-detail-row"><span class="k">' . $h($what) . '</span><span class="v">' . $h($who) . ' &middot; ' . $h(date('d M, H:i', strtotime((string) $f['created_at']))) . '</span></div>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}
