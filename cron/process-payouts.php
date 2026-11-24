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
 * Asks ioTec how each payout that is still "processing" went and marks it paid or failed (includes/payouts.php).
 * It never sends money: sending only happens when an organizer taps Withdraw or an admin presses Send now.
 * The payout pages also check on load, so this job just covers organizers who are not looking at the page.
 */
require __DIR__ . '/../includes/bootstrap.php';

$checked = payout_poll_open(null, 100);
echo date('c') . " payouts checked: {$checked}\n";
