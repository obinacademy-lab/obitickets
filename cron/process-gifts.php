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
 * OBI GIFTS: a viewer approves the payment on their phone and closes the app before we heard back. This job asks ioTec about every
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

$cp = coin_purchase_sweep(100);   // OBI COINS purchases whose buyer closed the app before the payment finished
echo date('c') . " OBI COINS purchases checked: {$cp['checked']}, credited: {$cp['credited']}\n";

$polled = gift_withdraw_poll_open(null, 50);
$sent = gift_withdraw_send_waiting(20);
echo date('c') . " gift withdrawals checked: {$polled}, sent: {$sent}\n";

// phase 7: tell Admin this job is alive, and check the books every few hours (emails the admins when something does not add up)
coin_cron_heartbeat();
$books = coin_books_scheduled();
echo date('c') . ' OBI COINS books check: ' . ($books['ran'] ? 'ran, ' . $books['problem_count'] . ' problem(s), ' . $books['high_flags'] . ' high alert(s)' . ($books['alerted'] ? ', admins emailed' : '') : 'not due yet') . "\n";

// the organizers' emails about the gifts they received (queued when each gift was delivered): one email per gift, or one summary when many arrive together
$mail = gift_email_send_queued(200);
echo date('c') . " organizer gift emails: sent {$mail['single']}, summaries {$mail['digest']}, skipped {$mail['skipped']}, failed {$mail['failed']}, waiting {$mail['waiting']}\n";

// OBI MARKET (ADR-003): payments whose buyer closed the page, orders the customer never answered after 7 days, and sellers' withdrawals still processing
$mk = market_sweep(100);
echo date('c') . " OBI MARKET payments checked: {$mk['checked']}, settled: {$mk['settled']}\n";
$mkAuto = market_auto_release_sweep(100);
$mkPolled = market_withdraw_poll_open(null, 50);
$mkSent = market_withdraw_send_waiting(20);
echo date('c') . " OBI MARKET orders auto-confirmed: {$mkAuto}, withdrawals checked: {$mkPolled}, sent: {$mkSent}\n";

// OBI MARKET emails (queued when an order was paid or its status changed): the seller's "new order" email, the customer's confirmation, retries of anything that failed
$mkMail = market_email_send_queued(100);
echo date('c') . " OBI MARKET emails: sent {$mkMail['sent']}, skipped {$mkMail['skipped']}, retrying {$mkMail['retry']}, failed {$mkMail['failed']}, waiting {$mkMail['waiting']}\n";
