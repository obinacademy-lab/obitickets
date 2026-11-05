<?php
declare(strict_types=1);

/**
 * Automatic messages to buyers: a reminder 24 hours and 2 hours before an event, and one nudge after a failed
 * payment. Email is the only channel today; every send goes through notify_claim() / notify_finish() with a
 * channel name, so SMS or WhatsApp can be added later without touching the schedules or the rules.
 *
 * "Send once" is enforced by the database (notification_log has a UNIQUE key on order + kind + channel), not by
 * the cron job's memory, so two overlapping cron runs can never both send the same message.
 */

const NOTIFY_MAX_ATTEMPTS = 3;          // a send that failed (provider down) is retried on later runs, this many times in all
const REMINDER_24H_WINDOW = [22, 24];   // due once the event is between 22 and 24 hours away (a late cron run still catches it)
const REMINDER_2H_WINDOW_MIN = [45, 120]; // ... and between 45 and 120 minutes away
const REMINDER_SKIP_RECENT_BUYERS = ['reminder_24h' => 3, 'reminder_2h' => 1]; // hours: no reminder minutes after they bought
const RECOVERY_DELAY_MINUTES = 15;      // nudge a failed payment this long after it was started
const RECOVERY_MAX_AGE_HOURS = 6;       // ... and never one older than this (no surprise emails about last week)
const RECOVERY_LINK_HOURS = 48;         // how long the "pay now" link works

function notify_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'notification_log'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Reserves the right to send this message. True means "you send it now"; false means it was already sent (or
 * is being sent, or has used up its attempts). A previous FAILED attempt can be claimed again until NOTIFY_MAX_ATTEMPTS.
 */
function notify_claim(int $orderId, string $kind, string $channel = 'EMAIL'): bool
{
    $stmt = db()->prepare("INSERT IGNORE INTO notification_log (order_id, kind, channel, status) VALUES (?, ?, ?, 'SENDING')");
    $stmt->execute([$orderId, $kind, $channel]);
    if ($stmt->rowCount() === 1) {
        return true;
    }
    $retry = db()->prepare("UPDATE notification_log SET status = 'SENDING', attempts = attempts + 1
                            WHERE order_id = ? AND kind = ? AND channel = ? AND status = 'FAILED' AND attempts < ?");
    $retry->execute([$orderId, $kind, $channel, NOTIFY_MAX_ATTEMPTS]);
    return $retry->rowCount() === 1;
}

function notify_finish(int $orderId, string $kind, string $channel, string $status, ?string $note = null): void
{
    db()->prepare('UPDATE notification_log SET status = ?, note = ? WHERE order_id = ? AND kind = ? AND channel = ?')
        ->execute([$status, $note !== null ? mb_substr($note, 0, 300) : null, $orderId, $kind, $channel]);
}

/** Marks a message as deliberately not sent (so the cron stops looking at it), with the reason. */
function notify_skip(int $orderId, string $kind, string $reason, string $channel = 'EMAIL'): void
{
    db()->prepare("INSERT IGNORE INTO notification_log (order_id, kind, channel, status, note) VALUES (?, ?, ?, 'SKIPPED', ?)")
        ->execute([$orderId, $kind, $channel, mb_substr($reason, 0, 300)]);
}

/** @return list<array<string,mixed>> newest first */
function notify_history(int $orderId): array
{
    if (!notify_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM notification_log WHERE order_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function notify_kind_label(string $kind): string
{
    return match ($kind) {
        'reminder_24h' => '24-hour reminder',
        'reminder_2h' => '2-hour reminder',
        'recovery' => 'Payment follow-up (automatic)',
        default => $kind,
    };
}

/** A small read-only block for the admin and organizer order pages: what automatic messages went to this buyer. */
function render_notify_history(int $orderId): string
{
    $rows = notify_history($orderId);
    if (!$rows) {
        return '';
    }
    $html = '<section class="card" style="margin-top:16px; padding:18px 20px"><h3 style="margin:0 0 10px; font-size:1rem">Automatic messages</h3><ul style="list-style:none; margin:0; padding:0; display:grid; gap:8px">';
    foreach ($rows as $r) {
        $tone = match ($r['status']) { 'SENT' => '#1B7A3D', 'FAILED' => '#B91C1C', default => '#6B6577' };
        $html .= '<li style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:.9rem">'
            . '<span>' . htmlspecialchars(notify_kind_label((string) $r['kind'])) . ' &middot; ' . htmlspecialchars(ucfirst(strtolower((string) $r['channel']))) . '</span>'
            . '<span style="color:' . $tone . '">' . htmlspecialchars(ucfirst(strtolower((string) $r['status']))) . ' &middot; '
            . htmlspecialchars(date('j M, g:i A', strtotime((string) $r['updated_at']))) . '</span></li>';
        if (!empty($r['note']) && $r['status'] !== 'SENT') {
            $html .= '<li style="font-size:.8rem; color:#6B6577; margin-top:-4px">' . htmlspecialchars((string) $r['note']) . '</li>';
        }
    }
    return $html . '</ul></section>';
}

/* ---------------------------------------------------------------------------------------------------------
   Reminders
   --------------------------------------------------------------------------------------------------------- */

/**
 * Paid orders that are due a reminder of this kind right now and have not had it. An order with no valid ticket
 * left (all cancelled or already checked in) and a buyer who bought only a moment ago are left out.
 * @return list<int>
 */
function reminder_due_order_ids(string $kind): array
{
    if ($kind === 'reminder_24h') {
        [$fromMin, $toMin] = [REMINDER_24H_WINDOW[0] * 60, REMINDER_24H_WINDOW[1] * 60];
    } else {
        [$fromMin, $toMin] = REMINDER_2H_WINDOW_MIN;
    }
    $recentHours = REMINDER_SKIP_RECENT_BUYERS[$kind] ?? 1;
    $stmt = db()->prepare("
        SELECT o.id
        FROM orders o
        JOIN events e ON e.id = o.event_id
        WHERE o.status = 'PAID'
          AND e.status = 'PUBLISHED'
          AND e.starts_at > (NOW() + INTERVAL ? MINUTE)
          AND e.starts_at <= (NOW() + INTERVAL ? MINUTE)
          AND (o.paid_at IS NULL OR o.paid_at <= (NOW() - INTERVAL ? HOUR))
          AND EXISTS (SELECT 1 FROM order_items oi JOIN tickets t ON t.order_item_id = oi.id WHERE oi.order_id = o.id AND t.status = 'VALID')
          AND NOT EXISTS (SELECT 1 FROM notification_log n WHERE n.order_id = o.id AND n.kind = ? AND n.channel = 'EMAIL'
                          AND (n.status IN ('SENT','SENDING','SKIPPED') OR n.attempts >= ?))
        ORDER BY e.starts_at, o.id
    ");
    $stmt->execute([$fromMin, $toMin, $recentHours, $kind, NOTIFY_MAX_ATTEMPTS]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Sends one reminder, once. @return string sent | skipped | failed */
function reminder_send(int $orderId, string $kind, ?callable $sender = null): string
{
    if (!notify_claim($orderId, $kind, 'EMAIL')) {
        return 'skipped';
    }
    try {
        $ok = $sender ? (bool) $sender($orderId, $kind) : send_order_reminder_email($orderId, $kind);
    } catch (Throwable $e) {
        error_log("[notify] $kind for order $orderId: " . $e->getMessage());
        notify_finish($orderId, $kind, 'EMAIL', 'FAILED', $e->getMessage());
        return 'failed';
    }
    notify_finish($orderId, $kind, 'EMAIL', $ok ? 'SENT' : 'FAILED', $ok ? null : 'The email provider did not accept it (see the email log)');
    return $ok ? 'sent' : 'failed';
}

/* ---------------------------------------------------------------------------------------------------------
   Failed-payment recovery
   --------------------------------------------------------------------------------------------------------- */

function recovery_sign(int $orderId, int $expires): string
{
    return substr(hash_hmac('sha256', "retry|$orderId|$expires", APP_SECRET), 0, 40);
}

/** The "pay now" link in the recovery email: signed, expires, and carries nothing but the order id. */
function recovery_retry_url(int $orderId, ?int $now = null): string
{
    $expires = ($now ?? time()) + RECOVERY_LINK_HOURS * 3600;
    return rtrim(APP_URL, '/') . '/retry-payment.php?o=' . $orderId . '&x=' . $expires . '&s=' . recovery_sign($orderId, $expires);
}

/** @return bool whether a retry link's signature and expiry are good */
function recovery_link_valid(int $orderId, int $expires, string $sig, ?int $now = null): bool
{
    return $orderId > 0 && $expires >= ($now ?? time()) && hash_equals(recovery_sign($orderId, $expires), $sig);
}

/** The cart a failed order held, in the shape checkout keeps in the session. @return array{event_slug:string,qty:array<int,int>} */
function recovery_cart_for_order(int $orderId): ?array
{
    $stmt = db()->prepare('SELECT e.slug FROM orders o JOIN events e ON e.id = o.event_id WHERE o.id = ?');
    $stmt->execute([$orderId]);
    $slug = $stmt->fetchColumn();
    if ($slug === false) {
        return null;
    }
    $items = db()->prepare('SELECT ticket_type_id, quantity FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $qty = [];
    foreach ($items->fetchAll() as $it) {
        $qty[(int) $it['ticket_type_id']] = (int) $it['quantity'];
    }
    return $qty ? ['event_slug' => (string) $slug, 'qty' => $qty] : null;
}

/**
 * Failed orders that are ready for their one nudge: started 15 minutes to 6 hours ago, no nudge sent yet for this
 * order. The finer rules (already paid, sold out, someone already followed up ...) are in recovery_eligibility().
 * @return list<int>
 */
function recovery_candidate_order_ids(): array
{
    $stmt = db()->prepare("
        SELECT o.id FROM orders o
        WHERE o.status = 'FAILED'
          AND o.created_at <= (NOW() - INTERVAL ? MINUTE)
          AND o.created_at >= (NOW() - INTERVAL ? HOUR)
          AND NOT EXISTS (SELECT 1 FROM notification_log n WHERE n.order_id = o.id AND n.kind = 'recovery' AND n.channel = 'EMAIL')
        ORDER BY o.id
    ");
    $stmt->execute([RECOVERY_DELAY_MINUTES, RECOVERY_MAX_AGE_HOURS]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @return array{ok:bool,reason:string} why a failed order should (not) get the nudge */
function recovery_eligibility(array $order): array
{
    if (($order['status'] ?? '') !== 'FAILED') {
        return ['ok' => false, 'reason' => 'The order is not failed'];
    }
    if (($order['account_status'] ?? 'ACTIVE') !== 'ACTIVE' || trim((string) ($order['buyer_email'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'The buyer cannot be emailed'];
    }
    if (($order['event_status'] ?? '') !== 'PUBLISHED' || strtotime((string) $order['event_ends_at']) < time()) {
        return ['ok' => false, 'reason' => 'The event is over or no longer on sale'];
    }
    $paid = db()->prepare("SELECT 1 FROM orders WHERE user_id = ? AND event_id = ? AND status = 'PAID' LIMIT 1");
    $paid->execute([$order['user_id'], $order['event_id']]);
    if ($paid->fetchColumn()) {
        return ['ok' => false, 'reason' => 'They already hold a paid ticket for this event'];
    }
    $nudged = db()->prepare("SELECT 1 FROM notification_log n JOIN orders o2 ON o2.id = n.order_id
                             WHERE n.kind = 'recovery' AND n.channel = 'EMAIL' AND n.status IN ('SENT','SENDING')
                               AND o2.user_id = ? AND o2.event_id = ? AND n.created_at >= (NOW() - INTERVAL 24 HOUR) LIMIT 1");
    $nudged->execute([$order['user_id'], $order['event_id']]);
    if ($nudged->fetchColumn()) {
        return ['ok' => false, 'reason' => 'They were already nudged about this event today'];
    }
    if (function_exists('followup_ready') && followup_ready()) {
        $manual = db()->prepare('SELECT 1 FROM order_followups WHERE order_id = ? AND created_at >= (NOW() - INTERVAL 24 HOUR) LIMIT 1');
        $manual->execute([$order['id']]);
        if ($manual->fetchColumn()) {
            return ['ok' => false, 'reason' => 'An admin or organizer already followed up'];
        }
    }
    $cart = recovery_cart_for_order((int) $order['id']);
    if (!$cart) {
        return ['ok' => false, 'reason' => 'The order has no tickets recorded'];
    }
    $resolved = resolve_checkout_cart($cart, (int) $order['user_id']);
    if (isset($resolved['error'])) {
        return ['ok' => false, 'reason' => 'Those tickets can no longer be bought (' . $resolved['error'] . ')'];
    }
    return ['ok' => true, 'reason' => ''];
}

/** One order's nudge. @return string sent | skipped:<reason> | failed */
function recovery_nudge(int $orderId, ?callable $sender = null): string
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        return 'skipped:order not found';
    }
    $extra = db()->prepare('SELECT e.status AS event_status, u.account_status FROM orders o JOIN events e ON e.id = o.event_id JOIN users u ON u.id = o.user_id WHERE o.id = ?');
    $extra->execute([$orderId]);
    $order += (array) $extra->fetch();

    $check = recovery_eligibility($order);
    if (!$check['ok']) {
        notify_skip($orderId, 'recovery', $check['reason']);
        return 'skipped:' . $check['reason'];
    }
    if (!notify_claim($orderId, 'recovery', 'EMAIL')) {
        return 'skipped:already handled';
    }
    try {
        $ok = $sender ? (bool) $sender($orderId, 'recovery') : send_recovery_email($orderId);
    } catch (Throwable $e) {
        error_log("[notify] recovery for order $orderId: " . $e->getMessage());
        notify_finish($orderId, 'recovery', 'EMAIL', 'FAILED', $e->getMessage());
        return 'failed';
    }
    notify_finish($orderId, 'recovery', 'EMAIL', $ok ? 'SENT' : 'FAILED', $ok ? null : 'The email provider did not accept it (see the email log)');
    return $ok ? 'sent' : 'failed';
}

/**
 * The whole recovery sweep: nudge every failed order that is due. A failed send is retried on later runs (up to
 * NOTIFY_MAX_ATTEMPTS) while the order is still inside the age window.
 * @return array<string,int> counts by result
 */
function recovery_sweep(?callable $sender = null): array
{
    $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    $retry = db()->prepare("SELECT order_id FROM notification_log WHERE kind = 'recovery' AND channel = 'EMAIL' AND status = 'FAILED' AND attempts < ?
                            AND created_at >= (NOW() - INTERVAL ? HOUR)");
    $retry->execute([NOTIFY_MAX_ATTEMPTS, RECOVERY_MAX_AGE_HOURS]);
    $ids = array_unique(array_merge(array_map('intval', $retry->fetchAll(PDO::FETCH_COLUMN)), recovery_candidate_order_ids()));
    foreach ($ids as $orderId) {
        $r = recovery_nudge($orderId, $sender);
        $counts[str_starts_with($r, 'skipped') ? 'skipped' : $r]++;
    }
    return $counts;
}

/* ---------------------------------------------------------------------------------------------------------
   Tickets that never reached the buyer
   --------------------------------------------------------------------------------------------------------- */

/**
 * Paid orders for events that have not finished yet whose ticket email was never accepted by the email provider
 * (no SENT row in email_log, which also covers orders from before the log existed). Oldest first.
 * @return list<array<string,mixed>>
 */
function tickets_undelivered_orders(): array
{
    if (!email_log_ready()) {
        return [];
    }
    return db()->query("
        SELECT o.id, o.paid_at, u.name AS buyer_name, u.email AS buyer_email, e.title AS event_title, e.starts_at, e.attend_mode,
               (SELECT l.status FROM email_log l WHERE l.order_id = o.id AND l.kind = 'TICKET' ORDER BY l.id DESC LIMIT 1) AS last_status,
               (SELECT l.error FROM email_log l WHERE l.order_id = o.id AND l.kind = 'TICKET' ORDER BY l.id DESC LIMIT 1) AS last_error
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        WHERE o.status = 'PAID'
          AND e.ends_at >= NOW()
          AND EXISTS (SELECT 1 FROM order_items oi JOIN tickets t ON t.order_item_id = oi.id WHERE oi.order_id = o.id)
          AND NOT EXISTS (SELECT 1 FROM email_log l WHERE l.order_id = o.id AND l.kind = 'TICKET' AND l.status = 'SENT')
        ORDER BY o.paid_at, o.id
    ")->fetchAll();
}

/** Paid orders for events that already took place and never had a ticket email accepted (not worth emailing now). */
function tickets_undelivered_past_count(): int
{
    if (!email_log_ready()) {
        return 0;
    }
    return (int) db()->query("
        SELECT COUNT(*) FROM orders o JOIN events e ON e.id = o.event_id
        WHERE o.status = 'PAID' AND e.ends_at < NOW()
          AND NOT EXISTS (SELECT 1 FROM email_log l WHERE l.order_id = o.id AND l.kind = 'TICKET' AND l.status = 'SENT')
    ")->fetchColumn();
}

/**
 * Sends the ticket email again to every order in tickets_undelivered_orders(), at most $limit per call, a little
 * apart (the provider limits how fast it takes mail), and stops after three refusals in a row (a set-up problem
 * such as an unverified sender domain, where carrying on would only fill the log with failures).
 * @param callable|null $sender fn(int $orderId): bool, for tests
 * @return array{sent:int,failed:int,stopped:bool,remaining:int}
 */
function tickets_resend_undelivered(int $limit = 50, ?callable $sender = null): array
{
    $orders = tickets_undelivered_orders();
    $sent = $failed = $streak = 0;
    $stopped = false;
    foreach (array_slice($orders, 0, $limit) as $o) {
        $ok = $sender ? (bool) $sender((int) $o['id']) : send_order_tickets_email((int) $o['id']);
        if ($ok) {
            $sent++;
            $streak = 0;
        } else {
            $failed++;
            if (++$streak >= 3) {
                $stopped = true;
                break;
            }
        }
        if (!$sender) {
            usleep(600000);
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'stopped' => $stopped, 'remaining' => max(0, count($orders) - $sent)];
}
