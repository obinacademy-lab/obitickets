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
 * Run on a schedule (hourly is fine — same cadence as
 * cron/send-event-reminders.php) via a Hostinger cron job:
 *   php /home/<user>/domains/obitickets.site/cron/expire-stale-orders.php
 *
 * Closes a known gap flagged in create_pending_order() (includes/payments.php):
 * a buyer who closes the tab mid-checkout leaves their order PENDING with its
 * ticket stock reserved, since nothing server-side listens for the payment —
 * only the buyer's own browser polls iotec for a final result.
 *
 * That same fact is why this must NEVER fail an order on age alone: someone
 * who approved the mobile money prompt and *then* closed the tab has been
 * charged, and their order looks identical to an abandoned one. So every
 * stale order is first reconciled against iotec's real status, exactly as a
 * browser poll would:
 *   - iotec says paid   -> finalized here (tickets minted and emailed, as if
 *                          they'd stayed on the page); no recovery email.
 *   - iotec says failed -> released (resolve_order_with_iotec already ran
 *                          fail_order) and the buyer gets the "still want to
 *                          go?" nudge — iotec itself resolves an unanswered
 *                          prompt to Failed ("Request timed out"), so this is
 *                          the normal abandoned-checkout path.
 *   - iotec has no transaction id for it (the collection request never got
 *     off the ground, so no money can have moved) -> safe to release.
 *   - iotec still says pending, or couldn't be reached -> left alone and
 *     retried next run. Holding a few seats a little longer beats failing a
 *     payment that actually went through.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$stmt = db()->prepare("
    SELECT * FROM orders
    WHERE status = 'PENDING' AND created_at <= (NOW() - INTERVAL 10 MINUTE)
");
$stmt->execute();
$staleOrders = $stmt->fetchAll();

if (!$staleOrders) {
    echo "No stale pending orders right now.\n";
    exit;
}

foreach ($staleOrders as $order) {
    $orderId = (int) $order['id'];
    $resolved = resolve_order_with_iotec($order);

    if ($resolved['status'] === 'PAID') {
        echo "Order #{$orderId}: iotec confirmed payment — finalized and tickets sent.\n";
    } elseif ($resolved['status'] === 'FAILED') {
        send_abandoned_checkout_email($orderId);
        echo "Order #{$orderId}: iotec reports it failed — released and emailed a recovery nudge.\n";
    } elseif (!$order['payment_reference']) {
        fail_order($orderId, 'Payment request expired — please try again.');
        send_abandoned_checkout_email($orderId);
        echo "Order #{$orderId}: never reached iotec — released and emailed a recovery nudge.\n";
    } else {
        echo "Order #{$orderId}: still pending at iotec — left alone, will retry.\n";
    }
}
