<?php
declare(strict_types=1);

/**
 * Real email delivery via Resend (resend.com) — ported from Obin Academy's
 * own includes/email.php, which uses the same provider. obitickets only
 * needs two real emails right now (password reset, contact-form
 * notification), so this stays much smaller than OA's — add a new
 * send_*_email() wrapper here if a third one is ever needed, following the
 * same pattern.
 */
/** Is the email_log table there? (Sending must keep working before the migration is applied.) */
function email_log_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'email_log'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Records one send attempt. Never throws: a logging problem must not break a sale. */
function email_log_write(string $kind, string $to, string $subject, string $status, ?string $providerId, ?string $error, ?int $orderId): void
{
    if (!email_log_ready()) {
        return;
    }
    try {
        db()->prepare('INSERT INTO email_log (kind, to_email, subject, status, provider_id, error, order_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($kind, 0, 32), mb_substr($to, 0, 191), mb_substr($subject, 0, 255), $status, $providerId ? mb_substr($providerId, 0, 80) : null, $error !== null ? mb_substr($error, 0, 600) : null, $orderId]);
    } catch (Throwable $e) {
        error_log('[email] could not write email_log: ' . $e->getMessage());
    }
}

/**
 * Sends one email through Resend and says honestly whether it was accepted. Every attempt, including the ones that
 * fail or are skipped, is written to email_log so an admin can see what happened. Returns true only when Resend
 * accepted the message.
 */
function resend_send(string $to, string $subject, string $html, string $kind = 'email', ?int $orderId = null): bool
{
    $to = trim($to);
    // Reserved and demo domains can never receive mail (and tests and seed data use them), so do not spend a send on them.
    if (preg_match('/@(example\.(com|org|net)|obitickets\.demo|[^@]+\.(test|invalid|localhost|example))$/i', $to)) {
        error_log("[email] skipping send to reserved/demo address $to");
        email_log_write($kind, $to, $subject, 'SKIPPED', null, 'Reserved or demo address', $orderId);
        return false;
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("[email] not sending: '$to' is not a valid email address. Subject: $subject");
        email_log_write($kind, $to, $subject, 'FAILED', null, 'Not a valid email address', $orderId);
        return false;
    }

    if (!RESEND_API_KEY) {
        error_log("[email] RESEND_API_KEY is not set — skipping send to $to. Subject: $subject");
        email_log_write($kind, $to, $subject, 'FAILED', null, 'RESEND_API_KEY is not set on the server', $orderId);
        return false;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => EMAIL_FROM,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        $why = $body !== false && $body !== '' ? (string) $body : ($curlErr !== '' ? $curlErr : 'No answer from Resend');
        error_log("[email] Resend rejected the email to $to ($status): $why");
        email_log_write($kind, $to, $subject, 'FAILED', null, 'HTTP ' . $status . ': ' . $why, $orderId);
        return false;
    }
    $decoded = is_string($body) ? json_decode($body, true) : null;
    email_log_write($kind, $to, $subject, 'SENT', is_array($decoded) ? ($decoded['id'] ?? null) : null, null, $orderId);
    return true;
}
function send_password_reset_email(string $to, string $name, string $resetUrl): void
{
    resend_send($to, 'Reset your obitickets password', <<<HTML
        <div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
          <h2 style="color: #991B1B;">Reset your password</h2>
          <p>Hi {$name}, we received a request to reset the password for your obitickets account.</p>
          <p>
            <a href="{$resetUrl}" style="display: inline-block; background: #DC2626; color: #fff; padding: 12px 24px; border-radius: 999px; text-decoration: none; font-weight: 600;">
              Reset Password
            </a>
          </p>
          <p style="color: #726C7E; font-size: 14px;">
            This link expires in 1 hour. If you didn't request this, you can safely ignore this email.
          </p>
          <p style="color: #726C7E; font-size: 12px;">
            Or copy and paste this link into your browser:<br>{$resetUrl}
          </p>
        </div>
        HTML);
}

/**
 * Renders one <table>-based ticket card per ticket in the order — inline
 * styles and table layout throughout, since flexbox/grid aren't reliably
 * supported by email clients (Outlook desktop in particular). Each ticket
 * gets its own obitickets-hosted QR image (ticket_qr_image_url() in
 * includes/qr.php); a ticket whose QR couldn't be generated still renders
 * with its code shown as plain text, so one flaky request never breaks the
 * whole email. Shared by the "your tickets are ready" email and the
 * day-before reminder below — neither carries its own copy of this markup.
 */
/**
 * The "Join online" block for an online or hybrid event: platform, a button to the meeting link, and the
 * organizer's joining notes. Only ever built for the buyer's own ticket email, so the link goes to ticket holders.
 * Returns '' for an in-person event.
 */
function render_online_email_block(array $order): string
{
    $mode = (string) ($order['attend_mode'] ?? 'IN_PERSON');
    $url = normalize_meeting_url((string) ($order['online_url'] ?? ''));
    if ($mode === 'IN_PERSON' || $url === null) {
        return '';
    }
    $platform = htmlspecialchars((string) ($order['online_platform'] ?? '')) ?: 'the meeting';
    $href = htmlspecialchars($url);
    $notes = trim((string) ($order['online_notes'] ?? ''));
    $notesHtml = $notes !== ''
        ? '<p style="margin:10px 0 0; font-size:14px; color:#1C1526;"><strong>Notes:</strong> ' . htmlspecialchars($notes) . '</p>'
        : '';
    return '<div style="background:#150D1C; border-radius:14px; padding:18px 20px; margin:18px 0; color:#ffffff;">'
        . '<p style="margin:0; font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#F0A5A5;">Join online</p>'
        . '<p style="margin:6px 0 12px; font-size:16px; font-weight:700; color:#ffffff;">This event is on ' . $platform . '. The room opens ' . ONLINE_JOIN_OPENS_MINUTES . ' minutes before the start (East Africa Time).</p>'
        . '<a href="' . $href . '" style="display:inline-block; background:#2E9E5E; color:#ffffff; text-decoration:none; font-weight:800; padding:12px 22px; border-radius:12px;">Open the meeting link</a>'
        . ($notes !== '' ? '<p style="margin:12px 0 0; font-size:14px; color:#E6E0EE;"><strong>Notes:</strong> ' . htmlspecialchars($notes) . '</p>' : '')
        . '<p style="margin:12px 0 0; font-size:12px; color:#B3ADC0;">This link is for ticket holders only. Please do not forward it.</p>'
        . '</div>';
}

function render_ticket_cards_html(array $order): string
{
    $buyerName = htmlspecialchars($order['buyer_name']);
    $onlineOnly = ($order['attend_mode'] ?? 'IN_PERSON') === 'ONLINE'; // nothing is scanned at a door

    $cards = '';
    foreach ($order['items'] as $item) {
        $tierName = htmlspecialchars($item['tier_name']);
        foreach ($item['tickets'] as $ticket) {
            $code = htmlspecialchars($ticket['ticket_code']);
            $qrUrl = ticket_qr_image_url($ticket['ticket_code']);
            $qrHtml = $qrUrl
                ? '<img src="' . htmlspecialchars($qrUrl) . '" width="180" height="180" alt="QR code for ' . $code . '" style="display:block; margin:0 auto; border-radius:8px;">'
                : '<div style="width:180px; height:180px; margin:0 auto; border:1px dashed #D8D2E4; border-radius:8px; display:table-cell; text-align:center; vertical-align:middle; color:#726C7E; font-size:12px;">QR unavailable &mdash; use the code below</div>';

            if ($onlineOnly) {
                $qrHtml = '';
            }
            $qrCaption = $onlineOnly ? 'Online ticket. Use the join link above to enter the meeting.' : 'Show this QR code at the entrance';

            $cards .= <<<HTML
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; margin:0 auto 24px; border:1px solid #E8E4EF; border-radius:16px; overflow:hidden;">
                <tr>
                  <td style="background:linear-gradient(135deg,#DC2626,#991B1B); background-color:#DC2626; padding:16px 24px;">
                    <span style="font-family:sans-serif; font-size:15px; font-weight:700; color:#ffffff;">obitickets</span>
                    <span style="float:right; font-family:sans-serif; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:rgba(255,255,255,0.85);">E-Ticket</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:24px;">
                    <p style="margin:0; font-family:sans-serif; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#948FA0;">Attendee</p>
                    <p style="margin:2px 0 14px; font-family:sans-serif; font-size:18px; font-weight:800; color:#1C1526;">{$buyerName}</p>
                    <p style="margin:0; font-family:sans-serif; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#948FA0;">Ticket</p>
                    <p style="margin:2px 0 20px; font-family:sans-serif; font-size:15px; font-weight:700; color:#991B1B;">{$tierName}</p>
                    {$qrHtml}
                    <p style="margin:16px 0 0; font-family:monospace; font-size:13px; letter-spacing:0.04em; color:#726C7E; text-align:center;">{$code}</p>
                    <p style="margin:6px 0 0; font-family:sans-serif; font-size:12px; color:#948FA0; text-align:center;">{$qrCaption}</p>
                  </td>
                </tr>
              </table>
              HTML;
        }
    }

    return $cards;
}

function render_ticket_email_html(array $order): string
{
    $onlineBlock = render_online_email_block($order);
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $dateRange = htmlspecialchars(format_event_date_range($order['event_starts_at'], $order['event_ends_at']));
    $venue = htmlspecialchars($order['venue_name'] . ($order['venue_address'] ? ', ' . $order['venue_address'] : ''));
    $ticketUrl = rtrim(APP_URL, '/') . '/order.php?id=' . (int) $order['id'];
    $cards = render_ticket_cards_html($order);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">Your tickets are ready 🎉</h2>
          <p>Hi {$buyerName}, thanks for your order &mdash; your tickets to {$eventTitle} are below.</p>
          {$onlineBlock}
          <p style="background:#FEF2F2; border-radius:12px; padding:14px 16px; color:#1C1526;">
            <strong>{$eventTitle}</strong><br>
            {$dateRange}<br>
            {$venue}
          </p>
          {$cards}
          <p style="font-size:14px; color:#726C7E;">
            You can also view or re-download these anytime from your
            <a href="{$ticketUrl}" style="color:#991B1B;">order page</a>.
          </p>
          <p style="color:#726C7E; font-size:12px;">
            Each QR code is unique to one ticket and can only be scanned in once &mdash; please don't share a screenshot publicly.
          </p>
        </div>
        HTML;
}

/**
 * Emails every ticket in a just-paid order to the buyer, one styled ticket
 * card per ticket. Called from finalize_order_success() right after an
 * order moves PENDING -> PAID; safe to call again for the same order (it
 * just re-sends the same tickets), since finalize_order_success() itself
 * only ever reaches this once per order.
 */
function send_order_tickets_email(int $orderId): bool
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        error_log("[email] send_order_tickets_email: order $orderId not found");
        return false;
    }

    $ticketCount = 0;
    foreach ($order['items'] as $item) {
        $ticketCount += count($item['tickets']);
    }
    if ($ticketCount === 0) {
        error_log("[email] send_order_tickets_email: order $orderId has no tickets yet");
        return false;
    }

    $subject = 'Your ' . ($ticketCount === 1 ? 'ticket' : 'tickets') . ' for ' . $order['event_title'];
    return resend_send($order['buyer_email'], $subject, render_ticket_email_html($order), 'TICKET', $orderId);
}

/**
 * Tells the organizer and the platform admins that a ticket was just bought (or a free ticket claimed).
 * Each person gets one email, even if they are both organizer and admin. Never throws.
 *
 * @param list<string>|null $adminEmails Used by tests to avoid touching real admin addresses; null = every active admin.
 * @return int how many emails Resend accepted
 */
function send_sale_notification_emails(int $orderId, ?array $adminEmails = null): int
{
    $sent = 0;
    try {
        $o = get_order_ticket_details($orderId);
        if (!$o) {
            return 0;
        }
        $stmt = db()->prepare('SELECT u.id, u.name, u.email, op.org_name FROM events e JOIN users u ON u.id = e.organizer_id LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE e.id = ?');
        $stmt->execute([(int) $o['event_id']]);
        $org = $stmt->fetch() ?: null;

        if ($adminEmails === null) {
            $adminEmails = db()->query("SELECT email FROM users WHERE role = 'ADMIN' AND (account_status IS NULL OR account_status = 'ACTIVE')")->fetchAll(PDO::FETCH_COLUMN);
        }
        if (defined('SALES_NOTIFY_EMAIL') && SALES_NOTIFY_EMAIL) {
            $adminEmails[] = SALES_NOTIFY_EMAIL;
        }

        $tickets = 0;
        $lines = [];
        foreach ($o['items'] as $item) {
            $n = (int) $item['quantity'];
            $tickets += $n;
            $lines[] = $n . ' &times; ' . htmlspecialchars((string) $item['tier_name']);
        }
        $isFree = (float) $o['total_amount'] <= 0;
        $money = static fn ($v): string => htmlspecialchars($o['currency'] . ' ' . number_format((float) $v, 0));
        $event = htmlspecialchars((string) $o['event_title']);
        $orgName = htmlspecialchars((string) ($org['org_name'] ?? $org['name'] ?? 'the organizer'));
        $headline = $isFree ? 'New free registration' : 'New ticket sale';
        $subject = ($isFree ? 'New free registration: ' : 'New ticket sale: ') . $o['event_title'] . ' (' . $tickets . ' ticket' . ($tickets === 1 ? '' : 's') . ($isFree ? '' : ', ' . $o['currency'] . ' ' . number_format((float) $o['total_amount'], 0)) . ')';
        $buyer = htmlspecialchars((string) $o['buyer_name']) . ' &lt;' . htmlspecialchars((string) $o['buyer_email']) . '&gt;' . (!empty($o['phone']) ? ' &middot; ' . htmlspecialchars((string) $o['phone']) : '');
        $net = (float) $o['subtotal_amount'] - (float) $o['commission_amount'];

        $row = static fn (string $k, string $v): string => '<tr><td style="padding:6px 16px 6px 0;color:#6B6577;white-space:nowrap">' . $k . '</td><td style="padding:6px 0;font-weight:600">' . $v . '</td></tr>';
        $table = static function (array $rows): string { return '<table style="border-collapse:collapse;font-size:15px">' . implode('', $rows) . '</table>'; };
        $wrap = static function (string $title, string $intro, string $tableHtml, string $linkHref, string $linkText): string {
            return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#16111C">'
                . '<h2 style="margin:0 0 6px;font-size:20px">' . $title . '</h2><p style="margin:0 0 18px;color:#4A4455">' . $intro . '</p>' . $tableHtml
                . '<p style="margin:22px 0 0"><a href="' . htmlspecialchars($linkHref) . '" style="display:inline-block;background:#E5383B;color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:8px">' . $linkText . '</a></p>'
                . '<p style="margin:22px 0 0;font-size:12px;color:#8A8494">obitickets &middot; this notice goes to the organizer and the platform admins when a ticket is bought.</p></div>';
        };
        $base = rtrim(APP_URL, '/');

        $common = [
            $row('Event', $event),
            $row('Tickets', implode(', ', $lines)),
            $row('Paid', $isFree ? 'Free' : $money($o['total_amount'])),
            $row('Buyer', $buyer),
            $row('Order', '#' . (int) $orderId . ' &middot; ' . htmlspecialchars(date('D j M Y, g:ia', strtotime((string) ($o['paid_at'] ?? $o['created_at']))))),
        ];

        $sentTo = [];
        if ($org && !empty($org['email'])) {
            $rows = $common;
            if (!$isFree) {
                $rows[] = $row('Your earnings from this order', $money($net));
            }
            $html = $wrap($headline . ' for your event', 'Someone just ' . ($isFree ? 'registered for' : 'bought a ticket to') . ' <b>' . $event . '</b>.', $table($rows), $base . '/org-order-detail.php?id=' . (int) $orderId, 'Open the order');
            if (resend_send((string) $org['email'], $subject, $html, 'SALE_ORGANIZER', $orderId)) {
                $sent++;
            }
            $sentTo[strtolower((string) $org['email'])] = true;
        }
        foreach (array_unique(array_map('strtolower', array_filter(array_map('trim', $adminEmails)))) as $adminEmail) {
            if (isset($sentTo[$adminEmail])) {
                continue; // the organizer who is also an admin already got one
            }
            $rows = $common;
            $rows[] = $row('Organizer', $orgName);
            if (!$isFree) {
                $rows[] = $row('Service fee', $money($o['service_fee_amount']));
                $rows[] = $row('Platform commission', $money($o['commission_amount']));
            }
            $html = $wrap('[Platform] ' . $headline, 'A ticket was just ' . ($isFree ? 'claimed' : 'bought') . ' on obitickets.', $table($rows), $base . '/admin-order-detail.php?id=' . (int) $orderId, 'Open the order');
            if (resend_send($adminEmail, '[obitickets] ' . $subject, $html, 'SALE_ADMIN', $orderId)) {
                $sent++;
            }
        }

        // the same news inside the organizer's dashboard bell
        if ($org) {
            try {
                create_notification((int) $org['id'], 'sale', $headline . ': ' . $o['event_title'], $tickets . ' ticket' . ($tickets === 1 ? '' : 's') . ' &middot; ' . strip_tags($o['buyer_name']) . ($isFree ? '' : ' &middot; ' . $o['currency'] . ' ' . number_format((float) $o['total_amount'], 0)), '/org-order-detail.php?id=' . (int) $orderId);
            } catch (Throwable $e) {
                error_log('[notify] could not add the dashboard notification for order ' . $orderId . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[email] send_sale_notification_emails failed for order ' . $orderId . ': ' . $e->getMessage());
    }
    return $sent;
}

function render_ticket_reminder_email_html(array $order): string
{
    $onlineBlock = render_online_email_block($order);
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $dateRange = htmlspecialchars(format_event_date_range($order['event_starts_at'], $order['event_ends_at']));
    $venue = htmlspecialchars($order['venue_name'] . ($order['venue_address'] ? ', ' . $order['venue_address'] : ''));
    $ticketUrl = rtrim(APP_URL, '/') . '/order.php?id=' . (int) $order['id'];
    $cards = render_ticket_cards_html($order);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">See you soon? 🎟️</h2>
          <p>Hi {$buyerName}, just a reminder that {$eventTitle} is coming up &mdash; your tickets are below.</p>
          {$onlineBlock}
          <p style="background:#FEF2F2; border-radius:12px; padding:14px 16px; color:#1C1526;">
            <strong>{$eventTitle}</strong><br>
            {$dateRange}<br>
            {$venue}
          </p>
          {$cards}
          <p style="font-size:14px; color:#726C7E;">
            You can also view or re-download these anytime from your
            <a href="{$ticketUrl}" style="color:#991B1B;">order page</a>.
          </p>
          <p style="color:#726C7E; font-size:12px;">
            Each QR code is unique to one ticket and can only be scanned in once &mdash; please don't share a screenshot publicly.
          </p>
        </div>
        HTML;
}

/**
 * Emails a "see you soon" reminder for a paid order — same ticket cards as
 * send_order_tickets_email(), different subject/intro. Called from
 * cron/send-event-reminders.php once per paid order on an event starting in
 * roughly 24 hours; never called from the checkout flow itself.
 */
function send_order_reminder_email(int $orderId): void
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        error_log("[email] send_order_reminder_email: order $orderId not found");
        return;
    }

    $ticketCount = 0;
    foreach ($order['items'] as $item) {
        $ticketCount += count($item['tickets']);
    }
    if ($ticketCount === 0) {
        error_log("[email] send_order_reminder_email: order $orderId has no tickets yet");
        return;
    }

    resend_send($order['buyer_email'], 'Reminder: ' . $order['event_title'] . ' is coming up', render_ticket_reminder_email_html($order));
}

/**
 * A "how was it?" nudge sent to every checked-in attendee once an event has
 * finished — see cron/send-review-prompts.php. Not tied to an order (an
 * attendee, not a purchase), so this takes plain values rather than the
 * get_order_ticket_details() shape the emails above use.
 */
function render_review_prompt_email_html(string $name, string $eventTitle, string $eventUrl): string
{
    $safeName = htmlspecialchars($name);
    $safeTitle = htmlspecialchars($eventTitle);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">How was {$safeTitle}? 🎉</h2>
          <p>Hi {$safeName}, thanks for coming out! We'd love to hear what you thought — it only takes a minute.</p>
          <p>
            <a href="{$eventUrl}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">
              Leave a review
            </a>
          </p>
          <p style="color:#726C7E; font-size:12px;">
            Your review helps other people discover great events like this one.
          </p>
        </div>
        HTML;
}

function send_review_prompt_email(string $email, string $name, string $eventTitle, string $eventUrl): void
{
    resend_send($email, 'How was ' . $eventTitle . '?', render_review_prompt_email_html($name, $eventTitle, $eventUrl));
}

/**
 * Sent by notify_waitlist_for_tier() (includes/waitlist.php) when a seat on a
 * sold-out tier opens up. Deliberately says it isn't held — anyone can buy
 * it until it's gone — so nobody thinks they've been promised a ticket.
 */
function render_waitlist_available_email_html(string $name, string $eventTitle, string $tierName, string $eventUrl): string
{
    $safeName = htmlspecialchars($name);
    $safeTitle = htmlspecialchars($eventTitle);
    $safeTier = htmlspecialchars($tierName);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">A ticket just opened up 🎟️</h2>
          <p>Hi {$safeName}, good news &mdash; a <strong>{$safeTier}</strong> ticket for <strong>{$safeTitle}</strong> is available again.</p>
          <p>
            <a href="{$eventUrl}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">
              Get my ticket
            </a>
          </p>
          <p style="color:#726C7E; font-size:13px;">
            Tickets aren't held for you &mdash; it's first come, first served, so grab it soon if you still want to go.
          </p>
          <p style="color:#726C7E; font-size:12px;">You're getting this because you joined the waitlist for this ticket.</p>
        </div>
        HTML;
}

function send_waitlist_available_email(string $email, string $name, string $eventTitle, string $tierName, string $eventUrl): void
{
    resend_send($email, 'A ticket opened up for ' . $eventTitle, render_waitlist_available_email_html($name, $eventTitle, $tierName, $eventUrl));
}

/**
 * The order has no tickets to show (payment never went through), so this is
 * a much simpler email than the two above — just what they were trying to
 * buy, and a link back to the event to start a fresh checkout. Called from
 * cron/expire-stale-orders.php right after fail_order() releases the
 * abandoned reservation, so the "try again" link always points at tickets
 * that are actually available again.
 */
function render_abandoned_checkout_email_html(array $order): string
{
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $eventUrl = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($order['event_slug']);

    $lines = '';
    foreach ($order['items'] as $item) {
        $lines .= '<p style="margin:4px 0; font-family:sans-serif; font-size:14px; color:#1C1526;">' . (int) $item['quantity'] . '&times; ' . htmlspecialchars($item['tier_name']) . '</p>';
    }

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">Still want to go? 🎟️</h2>
          <p>Hi {$buyerName}, your ticket reservation for <strong>{$eventTitle}</strong> didn't go through &mdash; no charge was made, and those tickets have been released back to general sale.</p>
          <div style="background:#FEF2F2; border-radius:12px; padding:14px 16px; margin:16px 0;">
            {$lines}
          </div>
          <p>
            <a href="{$eventUrl}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">
              Try again
            </a>
          </p>
          <p style="color:#726C7E; font-size:12px;">
            If you no longer want to attend, you can safely ignore this email.
          </p>
        </div>
        HTML;
}

function send_abandoned_checkout_email(int $orderId): void
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        error_log("[email] send_abandoned_checkout_email: order $orderId not found");
        return;
    }

    resend_send($order['buyer_email'], 'Still want to go to ' . $order['event_title'] . '?', render_abandoned_checkout_email_html($order));
}

function send_contact_notification_email(string $to, string $name, string $fromEmail, string $topic, string $message): void
{
    // All four values come straight from an unauthenticated public form
    // (contact.php) — escape every one of them before embedding in HTML.
    $safeName = htmlspecialchars($name);
    $safeEmail = htmlspecialchars($fromEmail);
    $safeTopic = htmlspecialchars($topic);
    $safeMessage = nl2br(htmlspecialchars($message));
    resend_send($to, 'New contact message: ' . $topic, <<<HTML
        <div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
          <h2 style="color: #991B1B;">New contact message</h2>
          <p><strong>{$safeTopic}</strong></p>
          <p>From: {$safeName} &lt;{$safeEmail}&gt;</p>
          <div style="background: #FEF2F2; border-radius: 12px; padding: 16px 18px; margin-top: 12px; color: #1C1526;">
            {$safeMessage}
          </div>
        </div>
        HTML);
}
