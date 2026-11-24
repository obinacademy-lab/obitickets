<?php
declare(strict_types=1);

// The deployed web root is the repo root, so this file is reachable by URL: refuse web requests before loading anything.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Run every 5 minutes via a Hostinger cron job:
 *   /usr/bin/php /home/<user>/domains/obitickets.site/public_html/cron/process-gifts.php
 *
 * OB Gifts: a viewer approves the payment on their phone and closes the app before we heard back. This job asks ioTec about every
 * waiting gift and delivers it (exactly once), expires prompts nobody approved, re-checks gifts that expired in the last 24 hours
 * (money can arrive late) and releases earnings whose hold is over (includes/gifts.php).
 *
 * Phase 5, gift withdrawals (includes/gift-payouts.php): it asks ioTec about every withdrawal still "processing" and settles it,
 * and sends a withdrawal that was reserved but never sent (a page that died in between), only while the Admin switch is on. Each
 * withdrawal is claimed with a single UPDATE first, so it can never be sent twice. A development computer never contacts ioTec.
 */
require __DIR__ . '/../includes/bootstrap.php';

$r = gift_sweep(100);
echo date('c') . " gifts checked: {$r['checked']}, delivered: {$r['settled']}, released: {$r['released']}\n";

$polled = gift_withdraw_poll_open(null, 50);
$sent = gift_withdraw_send_waiting(20);
echo date('c') . " gift withdrawals checked: {$polled}, sent: {$sent}\n";
