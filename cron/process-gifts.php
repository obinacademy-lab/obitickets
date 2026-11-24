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
 * (money can arrive late) and releases earnings whose hold is over. It never sends money out. See includes/gifts.php.
 */
require __DIR__ . '/../includes/bootstrap.php';

$r = gift_sweep(100);
echo date('c') . " gifts checked: {$r['checked']}, delivered: {$r['settled']}, released: {$r['released']}\n";
