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
 * Run on a schedule (hourly is plenty) via a Hostinger cron job:
 *   php /home/<user>/domains/obitickets.site/cron/send-event-reminders.php
 *
 * Emails every paid attendee of an event starting in roughly 24 hours, once
 * per event (events.reminder_sent_at guards against a second cron run
 * re-sending the same reminder). The 23-25 hour window is deliberately wider
 * than the hourly cadence so a late or missed run still catches every event
 * exactly once.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

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
    echo "No events due for a reminder right now.\n";
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
