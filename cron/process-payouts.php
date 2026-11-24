<?php
declare(strict_types=1);

// The deployed web root is the repo root, so this file is reachable by URL: refuse web requests before loading anything.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Run every 5 minutes via a Hostinger cron job:
 *   php /home/<user>/domains/obitickets.site/cron/process-payouts.php
 *
 * 1. Sends any mobile money request still waiting (when Automatic payouts is ON in Admin), so nobody presses Send now.
 * 2. Asks ioTec how each payout that is still "processing" went and marks it paid or failed (includes/payouts.php).
 * The payout pages also check on load, so this job just covers organizers who are not looking at the page.
 */
require __DIR__ . '/../includes/bootstrap.php';

$sent = payout_send_waiting(20);
$checked = payout_poll_open(null, 100);
echo date('c') . " waiting payouts sent: {$sent}, payouts checked: {$checked}\n";
