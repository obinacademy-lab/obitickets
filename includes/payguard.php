<?php
declare(strict_types=1);

/**
 * Payment-abuse guard. Starting a mobile money payment sends a prompt to whatever phone number was typed, so without
 * limits anyone with an account can make the platform prompt strangers (a "number sweep"). Before a payment is
 * started, payguard_check() applies these limits using the orders table, so no migration is needed:
 *
 *   1. one phone number may be prompted PAYGUARD_PER_NUMBER_PER_HOUR times an hour, across ALL accounts
 *   2. one account may start PAYGUARD_PER_ACCOUNT_PER_10_MIN payments in 10 minutes
 *   3. PAYGUARD_FAILS_IN_A_ROW failed payments in a row pause that account for 30 minutes
 *   4. one account may use PAYGUARD_DISTINCT_NUMBERS_PER_HOUR different numbers an hour (a sweep changes numbers every time)
 *
 * payguard_alert_if_needed() emails the platform admins when many payments fail in a short time (once an hour).
 * Free orders have no phone and never reach any of this.
 */

const PAYGUARD_PER_NUMBER_PER_HOUR = 3;
const PAYGUARD_PER_ACCOUNT_PER_10_MIN = 3;
const PAYGUARD_FAILS_IN_A_ROW = 3;
const PAYGUARD_DISTINCT_NUMBERS_PER_HOUR = 2;
const PAYGUARD_ALERT_FAILED_COUNT = 6;     // this many failed payments...
const PAYGUARD_ALERT_WINDOW_MIN = 10;      // ...inside this many minutes raises an alert
const PAYGUARD_ALERT_EVERY_MIN = 60;       // and at most one alert email per hour

/** Compares phone numbers however they were typed: 0793 563 000, +256793563000 and 793563000 are the same number. */
function payguard_norm_phone(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '256')) {
        $d = substr($d, 3);
    }
    return ltrim($d, '0');
}

/**
 * Returns null when the payment may start, or ['code' => ..., 'message' => ..., 'wait_minutes' => n] when it must not.
 * Counts every mobile money order, whatever its status, because a failed attempt still sent a prompt.
 */
function payguard_check(int $userId, string $phone): ?array
{
    $norm = payguard_norm_phone($phone);
    if ($norm === '') {
        return null;
    }
    $db = db();

    // Everyone's mobile money orders in the last hour (age measured by the database, so time zones cannot skew it).
    $rows = $db->query("
        SELECT user_id, phone, status, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
        FROM orders
        WHERE payment_method = 'MOBILE_MONEY' AND phone IS NOT NULL AND created_at >= (NOW() - INTERVAL 60 MINUTE)
        ORDER BY id DESC LIMIT 3000
    ")->fetchAll();

    // 1. this number, from any account
    $sameNumber = 0;
    $oldest = 0;
    foreach ($rows as $r) {
        if (payguard_norm_phone((string) $r['phone']) === $norm) {
            $sameNumber++;
            $oldest = max($oldest, (int) $r['age']);
        }
    }
    if ($sameNumber >= PAYGUARD_PER_NUMBER_PER_HOUR) {
        return payguard_block('number', 'Too many payment requests have been sent to this number. Please try again in about ' . payguard_wait(3600 - $oldest) . ' minutes.', 3600 - $oldest);
    }

    $mine = array_values(array_filter($rows, static fn ($r) => (int) $r['user_id'] === $userId));

    // 3. several failures in a row (newest first) pause the account
    $run = 0;
    foreach ($mine as $r) {
        if ($r['status'] === 'FAILED') {
            $run++;
        } else {
            break;
        }
    }
    if ($run >= PAYGUARD_FAILS_IN_A_ROW && $mine && (int) $mine[0]['age'] < 1800) {
        return payguard_block('failures', 'Your last ' . $run . ' payments did not go through, so payments are paused for a little while. Please check your number and balance and try again in ' . payguard_wait(1800 - (int) $mine[0]['age']) . ' minutes.', 1800 - (int) $mine[0]['age']);
    }

    // 2. too many starts in 10 minutes
    $recent = array_values(array_filter($mine, static fn ($r) => (int) $r['age'] < 600));
    if (count($recent) >= PAYGUARD_PER_ACCOUNT_PER_10_MIN) {
        $wait = 600 - (int) $recent[count($recent) - 1]['age'];
        return payguard_block('rate', 'You have started several payments in a short time. Please wait ' . payguard_wait($wait) . ' minutes and try again.', $wait);
    }

    // 4. a new number when this account has already used its share of numbers this hour
    $numbers = [];
    foreach ($mine as $r) {
        $numbers[payguard_norm_phone((string) $r['phone'])] = true;
    }
    if (count($numbers) >= PAYGUARD_DISTINCT_NUMBERS_PER_HOUR && !isset($numbers[$norm])) {
        $first = (int) $mine[count($mine) - 1]['age'];
        return payguard_block('numbers', 'Please use the same mobile money number you already tried, or wait ' . payguard_wait(3600 - $first) . ' minutes before using a different one.', 3600 - $first);
    }

    return null;
}

function payguard_wait(int $seconds): int
{
    return max(1, (int) ceil($seconds / 60));
}

function payguard_block(string $code, string $message, int $seconds): array
{
    return ['code' => $code, 'message' => $message, 'wait_minutes' => payguard_wait($seconds)];
}

/**
 * Emails every admin when PAYGUARD_ALERT_FAILED_COUNT or more payments failed (or were blocked) inside the alert
 * window. Sent at most once per PAYGUARD_ALERT_EVERY_MIN, using the email log as the memory. Never throws.
 * $send is injectable for tests: fn(string $to, string $subject, string $html): bool.
 */
function payguard_alert_if_needed(?callable $send = null): bool
{
    try {
        $db = db();
        $failed = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'FAILED' AND payment_method = 'MOBILE_MONEY' AND created_at >= (NOW() - INTERVAL " . PAYGUARD_ALERT_WINDOW_MIN . " MINUTE)")->fetchColumn();
        if ($failed < PAYGUARD_ALERT_FAILED_COUNT) {
            return false;
        }
        if (function_exists('email_log_ready') && email_log_ready()) {
            $recent = (int) $db->query("SELECT COUNT(*) FROM email_log WHERE kind = 'payguard_alert' AND created_at >= (NOW() - INTERVAL " . PAYGUARD_ALERT_EVERY_MIN . " MINUTE)")->fetchColumn();
            if ($recent > 0) {
                return false;
            }
        }

        $top = $db->query("
            SELECT u.id, u.name, u.email, COUNT(*) AS attempts, COUNT(DISTINCT o.phone) AS numbers
            FROM orders o JOIN users u ON u.id = o.user_id
            WHERE o.status = 'FAILED' AND o.payment_method = 'MOBILE_MONEY' AND o.created_at >= (NOW() - INTERVAL " . PAYGUARD_ALERT_WINDOW_MIN . " MINUTE)
            GROUP BY u.id, u.name, u.email ORDER BY attempts DESC LIMIT 5
        ")->fetchAll();

        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $rows = '';
        foreach ($top as $t) {
            $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee"><a href="' . $base . '/admin-customer-detail.php?id=' . (int) $t['id'] . '">' . htmlspecialchars((string) $t['name']) . '</a><br><span style="color:#777;font-size:12px">' . htmlspecialchars((string) $t['email']) . '</span></td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . (int) $t['attempts'] . ' failed</td><td style="padding:6px 10px;border-bottom:1px solid #eee">' . (int) $t['numbers'] . ' numbers</td></tr>';
        }
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px"><h2 style="margin:0 0 8px">Many failed payments</h2>'
            . '<p>' . $failed . ' mobile money payments failed in the last ' . PAYGUARD_ALERT_WINDOW_MIN . ' minutes. That is far more than normal and can mean someone is sending payment prompts to many phone numbers.</p>'
            . ($rows !== '' ? '<table style="border-collapse:collapse;width:100%;font-size:14px">' . $rows . '</table>' : '')
            . '<p>Open the account, and use <b>Suspend customer</b> if it looks automated. The payment limits already block new prompts to the same number, and block an account after repeated failures.</p>'
            . '<p><a href="' . $base . '/admin-orders.php">Open the orders list</a></p></div>';
        $subject = '[Security] ' . $failed . ' failed payments in ' . PAYGUARD_ALERT_WINDOW_MIN . ' minutes';

        $sent = false;
        $admins = function_exists('get_admin_users') ? get_admin_users() : [];
        foreach ($admins as $a) {
            $to = (string) ($a['email'] ?? '');
            if ($to === '') {
                continue;
            }
            $ok = $send ? (bool) $send($to, $subject, $html) : resend_send($to, $subject, $html, 'payguard_alert');
            $sent = $sent || $ok;
        }
        if ($send && function_exists('email_log_write') && $admins) {
            email_log_write('payguard_alert', (string) ($admins[0]['email'] ?? ''), $subject, $sent ? 'SENT' : 'FAILED', null, null, null);
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('[payguard] alert failed: ' . $e->getMessage());
        return false;
    }
}
