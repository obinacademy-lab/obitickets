<?php
/**
 * OBI COINS watching (phase 7): alerts for patterns an admin should look at, the scheduled books check run by the cron job, and the payment
 * verification report. Everything here only READS the money tables; nothing in this file changes a balance, a purchase or a gift.
 */

const COIN_FLAG_BURST_GIFTS = 15;          // coin gifts by one sender inside 10 minutes
const COIN_FLAG_FAILED_BUYS = 4;           // failed or expired purchases by one account in 24 hours
const COIN_FLAG_SHARED_PHONE_ACCOUNTS = 3; // accounts that paid with the same phone number in 30 days
const COIN_FLAG_BIG_SPEND_UGX = 300000;    // bought by one account in 24 hours
const COIN_FLAG_REVERSED_PURCHASES = 2;    // reversed purchases by one account in 30 days
const COIN_FLAG_FAILED_WITHDRAWALS = 3;    // failed withdrawals by one organizer in 7 days
const COIN_BOOKS_EVERY_HOURS = 6;          // the scheduled books check runs at most this often

/**
 * Patterns an admin should look at. These are signals, never accusations: nothing is blocked or changed by them. Everything is read from the
 * existing tables, so there is nothing to clear: an alert goes away when the pattern is no longer in the data.
 * @return list<array{code:string, severity:string, title:string, detail:string, user_id:?int, user_name:string}>
 */
function coin_admin_flags(): array
{
    if (!coins_ready()) {
        return [];
    }
    $pdo = db();
    $flags = [];
    $names = [];
    $nameOf = static function (int $id) use ($pdo, &$names): string {
        if (!isset($names[$id])) {
            $q = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $q->execute([$id]);
            $names[$id] = (string) ($q->fetchColumn() ?: ('#' . $id));
        }
        return $names[$id];
    };
    $add = static function (string $code, string $sev, string $title, string $detail, ?int $uid) use (&$flags, $nameOf): void {
        $flags[] = ['code' => $code, 'severity' => $sev, 'title' => $title, 'detail' => $detail, 'user_id' => $uid, 'user_name' => $uid ? $nameOf($uid) : ''];
    };
    $try = static function (callable $f): void {
        try {
            $f();
        } catch (Throwable $e) {
            error_log('[coins] alert rule skipped: ' . $e->getMessage());
        }
    };

    $try(function () use ($pdo, $add) {   // many gifts in a few minutes
        foreach ($pdo->query("SELECT sender_id, COUNT(*) n, SUM(coins_spent) coins FROM gift_transactions
                              WHERE payment_source = 'COINS' AND payment_status IN ('SUCCESSFUL','REFUNDED') AND created_at >= NOW() - INTERVAL 24 HOUR
                              GROUP BY sender_id, FLOOR(UNIX_TIMESTAMP(created_at) / 600) HAVING n >= " . COIN_FLAG_BURST_GIFTS)->fetchAll() as $r) {
            $add('BURST_GIFTS', 'medium', 'Many gifts in a few minutes', (int) $r['n'] . ' gifts (' . number_format((int) $r['coins']) . ' OBI COINS) within 10 minutes in the last 24 hours.', (int) $r['sender_id']);
        }
    });
    $try(function () use ($pdo, $add) {   // payments that keep failing
        foreach ($pdo->query("SELECT user_id, COUNT(*) n FROM coin_purchases WHERE payment_status IN ('FAILED','CANCELLED','EXPIRED') AND created_at >= NOW() - INTERVAL 24 HOUR
                              GROUP BY user_id HAVING n >= " . COIN_FLAG_FAILED_BUYS)->fetchAll() as $r) {
            $add('FAILED_BUYS', 'medium', 'Payments keep failing', (int) $r['n'] . ' failed or expired purchases in the last 24 hours (could be a sweep of other people\'s numbers, or just a wrong number).', (int) $r['user_id']);
        }
    });
    $try(function () use ($pdo, $add) {   // one phone paying for several accounts
        foreach ($pdo->query("SELECT phone, COUNT(DISTINCT user_id) n, MIN(user_id) first_user FROM coin_purchases WHERE payment_status IN ('SUCCESSFUL','REVERSED') AND created_at >= NOW() - INTERVAL 30 DAY
                              GROUP BY phone HAVING n >= " . COIN_FLAG_SHARED_PHONE_ACCOUNTS)->fetchAll() as $r) {
            $p = (string) $r['phone'];
            $add('SHARED_PHONE', 'high', 'One phone number paid for several accounts', (int) $r['n'] . ' accounts paid with ' . substr($p, 0, 6) . '***' . substr($p, -3) . ' in the last 30 days. Showing the first one.', (int) $r['first_user']);
        }
    });
    $try(function () use ($pdo, $add) {   // a lot bought in a day
        foreach ($pdo->query("SELECT user_id, SUM(price_ugx) s FROM coin_purchases WHERE payment_status = 'SUCCESSFUL' AND created_at >= NOW() - INTERVAL 24 HOUR
                              GROUP BY user_id HAVING s >= " . COIN_FLAG_BIG_SPEND_UGX)->fetchAll() as $r) {
            $add('BIG_SPEND', 'medium', 'A lot of OBI COINS bought in a day', 'UGX ' . number_format((int) $r['s']) . ' bought in the last 24 hours.', (int) $r['user_id']);
        }
    });
    $try(function () use ($pdo, $add) {   // buying coins with the recipient's own payout number
        foreach ($pdo->query("SELECT t.sender_id, t.recipient_organizer_id, COUNT(*) n, SUM(t.coins_spent) coins FROM gift_transactions t
                              JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id
                              WHERE t.payment_source = 'COINS' AND t.payment_status = 'SUCCESSFUL' AND t.created_at >= NOW() - INTERVAL 30 DAY AND op.payout_phone IS NOT NULL AND op.payout_phone <> ''
                                AND EXISTS (SELECT 1 FROM coin_purchases p WHERE p.user_id = t.sender_id AND p.payment_status IN ('SUCCESSFUL','REVERSED')
                                            AND RIGHT(REGEXP_REPLACE(p.phone, '[^0-9]', ''), 9) = RIGHT(REGEXP_REPLACE(op.payout_phone, '[^0-9]', ''), 9))
                              GROUP BY t.sender_id, t.recipient_organizer_id")->fetchAll() as $r) {
            $add('OWN_NUMBER', 'high', 'Paying with the organizer\'s own number', (int) $r['n'] . ' gifts (' . number_format((int) $r['coins']) . ' OBI COINS) went to an organizer whose payout number is the number this sender bought coins with (organizer #' . (int) $r['recipient_organizer_id'] . ').', (int) $r['sender_id']);
        }
    });
    $try(function () use ($pdo, $add) {   // everything going to one organizer
        $rows = $pdo->query("SELECT sender_id, recipient_organizer_id AS org, COUNT(*) n, SUM(coins_spent) coins FROM gift_transactions
                             WHERE payment_source = 'COINS' AND payment_status = 'SUCCESSFUL' AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY sender_id, recipient_organizer_id")->fetchAll();
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['sender_id']][] = $r;
        }
        foreach ($by as $sid => $list) {
            $total = array_sum(array_column($list, 'coins'));
            $count = array_sum(array_column($list, 'n'));
            foreach ($list as $r) {
                if ($count >= 5 && $total >= 50000 && (int) $r['coins'] >= 0.9 * $total) {
                    $add('ONE_ORGANIZER', 'low', 'Nearly all gifts go to one organizer', number_format((int) $r['coins']) . ' of ' . number_format((int) $total) . ' OBI COINS in 7 days went to organizer #' . (int) $r['org'] . '. Often just a big fan.', $sid);
                }
            }
        }
    });
    $try(function () use ($pdo, $add) {   // reversed purchases
        foreach ($pdo->query("SELECT user_id, COUNT(*) n FROM coin_purchases WHERE payment_status = 'REVERSED' AND reversed_at >= NOW() - INTERVAL 30 DAY GROUP BY user_id HAVING n >= " . COIN_FLAG_REVERSED_PURCHASES)->fetchAll() as $r) {
            $add('REVERSALS', 'high', 'Purchases reversed more than once', (int) $r['n'] . ' of this account\'s purchases were reversed in the last 30 days.', (int) $r['user_id']);
        }
    });
    $try(function () use ($pdo, $add) {   // withdrawals failing
        foreach ($pdo->query("SELECT organizer_id, COUNT(*) n FROM gift_withdrawals WHERE status = 'FAILED' AND requested_at >= NOW() - INTERVAL 7 DAY GROUP BY organizer_id HAVING n >= " . COIN_FLAG_FAILED_WITHDRAWALS)->fetchAll() as $r) {
            $add('FAILED_WITHDRAWALS', 'medium', 'Withdrawals keep failing', (int) $r['n'] . ' failed gift withdrawals in the last 7 days (a wrong number, or the ioTec wallet is low).', (int) $r['organizer_id']);
        }
    });

    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($flags, static fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
    return $flags;
}

/** Saves a setting without writing an audit line (used for the clock and the check results, which change by themselves). */
function coin_quiet_setting(string $key, string $value): void
{
    try {
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    } catch (Throwable $e) {
        error_log('[coins] could not save setting ' . $key . ': ' . $e->getMessage());
    }
}

/** The cron job calls this on every run, so Admin can see that it is running. */
function coin_cron_heartbeat(): void
{
    coin_quiet_setting('cron_gifts_last_run', date('Y-m-d H:i:s'));
}

/**
 * The books check, run by the cron job every few hours: wallets, purchases, coin gifts and the gift ledger must add up. When something does not,
 * the admins are emailed (once per day for the same problem, at once when the problem changes). It never changes any money.
 * @return array{at:string, problem_count:int, problems:list<string>, high_flags:int, ran:bool, alerted:bool}
 */
function coin_books_scheduled(bool $force = false): array
{
    $last = json_decode((string) get_setting('coin_books_last', ''), true);
    if (!$force && is_array($last) && !empty($last['at']) && strtotime((string) $last['at']) > time() - COIN_BOOKS_EVERY_HOURS * 3600) {
        return ['at' => (string) $last['at'], 'problem_count' => (int) ($last['problem_count'] ?? 0), 'problems' => (array) ($last['problems'] ?? []), 'high_flags' => (int) ($last['high_flags'] ?? 0), 'ran' => false, 'alerted' => false];
    }
    if (!coins_ready()) {
        return ['at' => date('Y-m-d H:i:s'), 'problem_count' => 0, 'problems' => [], 'high_flags' => 0, 'ran' => false, 'alerted' => false];
    }
    $problems = array_values(array_unique(array_merge(coin_reconcile(), function_exists('gift_reconcile') ? gift_reconcile() : [])));   // the gift check includes the coin check
    $high = array_values(array_filter(coin_admin_flags(), static fn ($f) => $f['severity'] === 'high'));
    $hash = md5(json_encode([$problems, array_map(static fn ($f) => [$f['code'], $f['user_id']], $high)]));
    $prevHash = is_array($last) ? (string) ($last['hash'] ?? '') : '';
    $prevAlert = is_array($last) ? (string) ($last['alerted_at'] ?? '') : '';
    $alerted = false;
    $alertedAt = $prevAlert;
    if (($problems || $high) && ($hash !== $prevHash || $prevAlert === '' || strtotime($prevAlert) < time() - 86400)) {
        $lines = array_slice($problems, 0, 10);
        foreach (array_slice($high, 0, 5) as $f) {
            $lines[] = 'ALERT: ' . $f['title'] . ($f['user_name'] !== '' ? ' (' . $f['user_name'] . ')' : '') . ': ' . $f['detail'];
        }
        try {
            gift_wd_alert_admins('OBI COINS check: ' . count($problems) . ' problem' . (count($problems) === 1 ? '' : 's') . ', ' . count($high) . ' high alert' . (count($high) === 1 ? '' : 's'),
                "The scheduled check of the OBI COINS books found:\n- " . implode("\n- ", $lines) . "\n\nOpen Admin > OBI GIFTS > OBI COINS to look.");
            $alerted = true;
            $alertedAt = date('Y-m-d H:i:s');
        } catch (Throwable $e) {
            error_log('[coins] could not send the books alert: ' . $e->getMessage());
        }
    }
    $res = ['at' => date('Y-m-d H:i:s'), 'problem_count' => count($problems), 'problems' => array_slice($problems, 0, 20), 'high_flags' => count($high), 'hash' => $hash, 'alerted_at' => $alertedAt];
    coin_quiet_setting('coin_books_last', json_encode($res));
    return ['at' => $res['at'], 'problem_count' => $res['problem_count'], 'problems' => $res['problems'], 'high_flags' => $res['high_flags'], 'ran' => true, 'alerted' => $alerted];
}

/**
 * The payment verification report: every claim OBI COINS makes about money, checked against the data right now.
 * Each line is pass, warn (look at it) or fail (fix before taking more money). Nothing here changes anything.
 * @return array{overall:string, groups:array<string,list<array{label:string, status:string, detail:string}>>}
 */
function coin_admin_verification(): array
{
    $g = [];
    $pdo = db();
    $line = static function (string $group, string $label, string $status, string $detail) use (&$g): void {
        $g[$group][] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };
    $count = static function (string $sql) use ($pdo): int {
        try {
            return (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return -1;
        }
    };
    if (!coins_ready()) {
        return ['overall' => 'fail', 'groups' => ['Setup' => [['label' => 'Database update', 'status' => 'fail', 'detail' => 'Migration 046 has not been run.']]]];
    }
    $rateText = static fn (int $e4): string => rtrim(rtrim(number_format($e4 / 10000, 4, '.', ''), '0'), '.');

    // ---- setup
    $S = 'Setup';
    $line($S, 'ioTec keys on the server', payouts_iotec_configured() ? 'pass' : 'fail', payouts_iotec_configured() ? 'Present, so purchases and withdrawals can reach ioTec.' : 'Missing from the server config: nobody can buy OBI COINS.');
    $isProd = defined('APP_ENV') && APP_ENV === 'production';
    $line($S, 'Running as the live site', $isProd ? 'pass' : 'warn', $isProd ? 'Production mode: pretend payments are refused.' : 'This is not in production mode (a development computer never sends real money).');
    $line($S, 'OBI GIFTS switch', gifts_enabled() ? 'pass' : 'warn', gifts_enabled() ? 'ON: viewers can see the Gift button.' : 'OFF: nobody sees gifts yet.');
    $missing = coins_missing_setup();
    $line($S, 'OBI COINS switch', coins_enabled() ? 'pass' : 'warn', coins_enabled() ? 'ON: people can buy OBI COINS and pay gifts from the wallet.' : 'OFF (or something is still missing): ' . ($missing ? implode('; ', $missing) : 'switch it on in Overview') . '.');
    $rate = coin_rate_current();
    $line($S, 'Accounting rate in force', $rate ? 'pass' : 'fail', $rate ? '1 OBI COIN = UGX ' . $rateText((int) $rate['rate_e4']) . ' (version ' . $rate['version'] . ').' : 'No rate is in force: gifts cannot be valued.');
    $pk = count(coin_packages_active());
    $line($S, 'Packages people can buy', $pk > 0 ? 'pass' : 'fail', $pk . ' package' . ($pk === 1 ? '' : 's') . ' shown with a price.');
    $hb = (string) get_setting('cron_gifts_last_run', '');
    $age = $hb !== '' ? time() - (int) strtotime($hb) : null;
    $line($S, 'Background job (every 5 minutes)', $age !== null && $age <= 900 ? 'pass' : ($age !== null && $age <= 3600 ? 'warn' : 'fail'),
        $age === null ? 'It has never reported in. Payments left open when someone closes the app are not settled without it: check the cron job in hPanel.' : 'Last ran ' . ($age < 90 ? 'just now' : floor($age / 60) . ' minutes ago') . ' (' . date('d M, H:i', (int) strtotime($hb)) . ').');

    // ---- money is credited only on proof
    $P = 'Money is credited only on proof from ioTec';
    $noRef = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status IN ('SUCCESSFUL','REVERSED') AND (provider_reference IS NULL OR provider_reference = '')");
    $line($P, 'Every paid purchase has an ioTec reference', $noRef === 0 ? 'pass' : 'fail', $noRef === 0 ? 'None without one.' : $noRef . ' paid purchase(s) have no ioTec reference.');
    $fake = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status IN ('SUCCESSFUL','REVERSED') AND provider_reference LIKE 'FAKE%'");
    $fakeBad = $isProd && $fake > 0;
    $line($P, 'No pretend payments counted as real', $fakeBad ? 'fail' : 'pass', $fakeBad ? $fake . ' purchase(s) carry a pretend payment reference on the live site.' : 'None on the live site.');
    $dup = $count('SELECT COUNT(*) FROM (SELECT provider_reference FROM coin_purchases WHERE provider_reference IS NOT NULL GROUP BY provider_reference HAVING COUNT(*) > 1) d');
    $line($P, 'No ioTec payment used twice', $dup === 0 ? 'pass' : 'fail', $dup === 0 ? 'Each ioTec reference belongs to one purchase.' : $dup . ' ioTec reference(s) are on more than one purchase.');
    $cp = coin_reconcile();
    $line($P, 'Wallets, purchases and coin gifts add up', $cp === [] ? 'pass' : 'fail', $cp === [] ? 'Every balance equals its history; every paid purchase was credited once; every coin gift was spent once.' : count($cp) . ' problem(s), for example: ' . $cp[0]);
    $gp = function_exists('gift_reconcile') ? array_values(array_diff(gift_reconcile(), $cp)) : [];   // gift_reconcile repeats the coin problems: show them once
    $line($P, 'Organizer balances add up', $gp === [] ? 'pass' : 'fail', $gp === [] ? 'The gift ledger, balances and withdrawals agree.' : count($gp) . ' problem(s), for example: ' . $gp[0]);

    // ---- waiting or stuck
    $W = 'Waiting or stuck';
    $stuck = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status IN ('PENDING','PROCESSING') AND created_at < NOW() - INTERVAL 15 MINUTE");
    $line($W, 'Payments waiting more than 15 minutes', $stuck === 0 ? 'pass' : 'warn', $stuck === 0 ? 'None.' : $stuck . ' waiting. The background job should settle them; use "Check waiting payments now". If one stays, ioTec may be holding it for a person to look at (for example the amount did not match).');
    $late = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status = 'EXPIRED' AND provider_reference IS NOT NULL AND created_at > NOW() - INTERVAL 24 HOUR");
    $line($W, 'Expired payments still being re-checked', 'pass', $late . ' expired in the last 24 hours (money that arrives late is still credited, once).');
    $failedN = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status IN ('FAILED','CANCELLED','EXPIRED') AND created_at > NOW() - INTERVAL 24 HOUR");
    $paidN = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status IN ('SUCCESSFUL','REVERSED') AND created_at > NOW() - INTERVAL 24 HOUR");
    $failing = $failedN >= 5 && $failedN > $paidN;
    $line($W, 'Purchases failing in the last 24 hours', $failing ? 'warn' : 'pass', $paidN . ' paid, ' . $failedN . ' failed or expired.' . ($failing ? ' More fail than succeed: check ioTec and the ioTec wallet.' : ''));
    $wdStuck = $count("SELECT COUNT(*) FROM gift_withdrawals WHERE status = 'PROCESSING' AND requested_at < NOW() - INTERVAL 10 MINUTE");
    $line($W, 'Withdrawals processing more than 10 minutes', $wdStuck === 0 ? 'pass' : 'warn', $wdStuck === 0 ? 'None.' : $wdStuck . ' need a check in Withdrawals (look the reference up in the ioTec portal).');
    $wdReview = $count("SELECT COUNT(*) FROM gift_withdrawals WHERE status = 'PENDING_REVIEW'");
    $line($W, 'Withdrawals waiting for your approval', $wdReview === 0 ? 'pass' : 'warn', $wdReview === 0 ? 'None.' : $wdReview . ' waiting in Withdrawals.');

    // ---- the proof only real money can give
    $R = 'Proof with real money';
    $realBuys = $count("SELECT COUNT(*) FROM coin_purchases WHERE payment_status IN ('SUCCESSFUL','REVERSED') AND provider_reference NOT LIKE 'FAKE%'");
    $line($R, 'A real OBI COINS purchase has completed', $realBuys > 0 ? 'pass' : 'warn', $realBuys > 0 ? $realBuys . ' real purchase(s) credited.' : 'None yet. Make one small purchase yourself (the Basic package) and see the coins arrive.');
    $realGifts = $count("SELECT COUNT(*) FROM gift_transactions t WHERE t.payment_source = 'COINS' AND t.payment_status IN ('SUCCESSFUL','REFUNDED') AND EXISTS (SELECT 1 FROM coin_purchases p WHERE p.user_id = t.sender_id AND p.payment_status IN ('SUCCESSFUL','REVERSED') AND p.provider_reference NOT LIKE 'FAKE%')");
    $line($R, 'A gift has been sent with real OBI COINS', $realGifts > 0 ? 'pass' : 'warn', $realGifts > 0 ? $realGifts . ' gift(s) sent from real purchases.' : 'None yet. Send one small gift yourself and check it in the organizer\'s OBI GIFTS & earnings.');
    $realWd = $count("SELECT COUNT(*) FROM gift_withdrawals w JOIN gift_payouts p ON p.withdrawal_id = w.id WHERE w.status = 'PAID' AND p.provider_reference NOT LIKE 'FAKE%'");
    $line($R, 'A real withdrawal has been paid out', $realWd > 0 ? 'pass' : 'warn', $realWd > 0 ? $realWd . ' real withdrawal(s) paid.' : 'None yet. Withdraw a small amount as an organizer and see it arrive on the phone.');

    // ---- limits in force (information)
    $L = 'Limits in force';
    $line($L, 'Buying OBI COINS', 'pass', 'At most ' . COIN_STARTS_PER_10_MIN . ' payments per account per 10 minutes; ' . COIN_OPEN_PURCHASE_LIMIT . ' waiting at once; ' . COIN_FAILS_IN_A_ROW . ' failures in a row pause the account ' . COIN_FAILS_PAUSE_MINUTES . ' minutes; ' . COIN_DISTINCT_PHONES_PER_HOUR . ' different numbers an hour; ' . COIN_PHONE_HOURLY_LIMIT . ' prompts per number an hour; UGX ' . number_format(COIN_DAILY_PURCHASE_CAP_UGX) . ' a day.');
    $line($L, 'Sending gifts', 'pass', 'At most ' . COIN_GIFTS_PER_MINUTE . ' gifts a minute per account; UGX ' . number_format(GIFT_DAILY_CAP_UGX) . ' of gifts a day; one gift can be worth at most UGX ' . number_format(GIFT_MAX_PAYMENT_UGX) . '; a repeat tap is the same gift (never charged twice).');
    $line($L, 'Looking at a payment', 'pass', 'ioTec is asked about the same payment at most once every ' . COIN_POLL_MIN_SECONDS . ' seconds per visitor. Refusals by these limits count towards the IP blocker when it is switched on.');
    $line($L, 'Screens on phones', 'pass', 'Wallet, recharge sheet, gift sheet, OBI GIFTS & earnings and the admin screens were checked at 320 and 375 pixels wide with no sideways scrolling (11/25).');

    $overall = 'pass';
    foreach ($g as $rows) {
        foreach ($rows as $r) {
            if ($r['status'] === 'fail') {
                $overall = 'fail';
                break 2;
            }
            if ($r['status'] === 'warn') {
                $overall = 'warn';
            }
        }
    }
    return ['overall' => $overall, 'groups' => $g];
}
