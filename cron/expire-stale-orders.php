<?php
declare(strict_types=1);

/**
 * Run on a schedule (hourly is fine — same cadence as
 * cron/send-event-reminders.php) via a Hostinger cron job:
 *   php /home/<user>/domains/obitickets.site/cron/expire-stale-orders.php
 *
 * Closes a known gap flagged in create_pending_order() (includes/payments.php):
 * an attendee who abandons the mobile money prompt (closes the tab, never
 * approves or declines) leaves their order PENDING and its ticket stock
 * reserved forever, since nothing but the buyer's own browser ever polls for
 * a final result. Anything still PENDING 30+ minutes after it was created is
 * treated as abandoned — release the reservation via the same fail_order()
 * path a real iotec decline already uses, then email the buyer a one-time
 * "still want to go?" nudge with a link back to the event.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$stmt = db()->prepare("
    SELECT id FROM orders
    WHERE status = 'PENDING' AND created_at <= (NOW() - INTERVAL 30 MINUTE)
");
$stmt->execute();
$staleOrderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$staleOrderIds) {
    echo "No stale pending orders right now.\n";
    exit;
}

foreach ($staleOrderIds as $orderId) {
    fail_order((int) $orderId, 'Payment request expired — please try again.');
    send_abandoned_checkout_email((int) $orderId);
    echo "Order #{$orderId}: released the reservation and emailed a recovery nudge.\n";
}
