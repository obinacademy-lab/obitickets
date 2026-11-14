<?php
declare(strict_types=1);

// The deployed web root is the repo root, so this file is reachable by URL —
// refuse web requests before loading anything. Checks REQUEST_METHOD (set only
// by a web server) rather than PHP_SAPI alone, so the real cron still runs
// even if the host's `php` binary isn't literally the "cli" SAPI.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Run every 15 minutes via a Hostinger cron job:
 *   php /home/<user>/domains/obitickets.site/cron/send-event-reminders.php
 *
 * Emails every paid order a reminder about 24 hours and again about 2 hours before the event starts. Each message
 * is sent once PER ORDER (notification_log has a unique key), so a late run still catches everyone, an overlapping
 * run cannot double-send, and someone who buys after the first run still gets the 2-hour reminder. The windows are
 * in includes/notify.php (REMINDER_24H_WINDOW, REMINDER_2H_WINDOW_MIN). Online events say when the room opens.
 *
 * Until migration/026_notification_log.sql has been run, this falls back to the old behaviour: one 24-hour email per
 * event, guarded by events.reminder_sent_at.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (!notify_ready()) {
    $stmt = db()->prepare("
        SELECT id, title
        FROM events
        WHERE status = 'PUBLISHED'
          AND reminder_sent_at IS NULL
          AND starts_at BETWEEN (NOW() + INTERVAL 23 HOUR) AND (NOW() + INTERVAL 25 HOUR)
    ");
    $stmt->execute();
    $dueEvents = $stmt->fetchAll();
    if (!$dueEvents) {
        echo "No events due for a reminder right now (migration 026 not applied: using the once-per-event fallback).\n";
        exit;
    }
    foreach ($dueEvents as $event) {
        $orderStmt = db()->prepare("SELECT id FROM orders WHERE event_id = ? AND status = 'PAID'");
        $orderStmt->execute([$event['id']]);
        $orderIds = $orderStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orderIds as $orderId) {
            send_order_reminder_email((int) $orderId);
        }
        db()->prepare('UPDATE events SET reminder_sent_at = NOW() WHERE id = ?')->execute([$event['id']]);
        echo "Event #{$event['id']} \"{$event['title']}\": reminded " . count($orderIds) . " order(s).\n";
    }
    exit;
}

foreach (['reminder_24h', 'reminder_2h'] as $kind) {
    $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    foreach (reminder_due_order_ids($kind) as $orderId) {
        $counts[reminder_send($orderId, $kind)]++;
    }
    echo notify_kind_label($kind) . ": sent {$counts['sent']}, skipped {$counts['skipped']}, failed {$counts['failed']}.\n";
}

// Socials: email organizers whose plan ends in 3 days, within a day, or has just ended (once per plan period).
// Kept in this cron so no new cron job is needed. Does nothing until migration 029 has been run.
if (function_exists('socials_send_plan_reminders')) {
    try {
        $soCounts = socials_send_plan_reminders();
        echo "Socials plan reminders: sent {$soCounts['sent']}, skipped {$soCounts['skipped']}, failed {$soCounts['failed']}.\n";
    } catch (Throwable $e) {
        error_log('[socials] plan reminders: ' . $e->getMessage());
        echo "Socials plan reminders failed: " . $e->getMessage() . "\n";
    }
}
