<?php
// Automatic organizer payouts through IOTEC disbursement (11/24, migration 044).
//
// Rules (owner): an organizer can withdraw any time, up to exactly their dashboard balance, with no holding period;
// refunds are the organizer's own responsibility. IOTEC's collection fee comes out of obitickets' share, not theirs.
//
// Safety rules in this file:
//  * One payout is sent at most once: the row is CLAIMED with a single UPDATE (PENDING/APPROVED -> PROCESSING) and carries a
//    unique external_id that IOTEC also sees.
//  * If IOTEC clearly refuses (4xx), nothing was sent: the payout is FAILED and the money is back in the balance.
//  * If the outcome is unknown (network error, 5xx, lost answer) the payout STAYS "processing" and an admin is told.
//    It is never resent and never auto-failed on a guess.
//  * The balance check and the insert run under a lock on the organizer's row, so two quick taps cannot overdraw.
//  * Automatic sending needs the Admin switch (auto_payouts). With it on, a withdrawal to any valid MTN/Airtel number
//    goes out at once (owner decision 11/24: no waiting). Only bank requests wait in the admin queue ("Send now").
//  * Protection without delay: the organizer is emailed when money goes to a number other than their saved one,
//    and when their saved number is changed.

// Fees (owner decision 11/24): every payout costs obitickets an ioTec sending fee, so tiny withdrawals are not allowed and
// UGX 10,000 of an organizer's balance stays on their account as a reserve (it covers refunds that arrive after a
// payout). The reserve is still THEIR money: it is released automatically 7 days after their last event ends.
const PAYOUT_MIN_AMOUNT = 5000;           // UGX, smallest withdrawal
const PAYOUT_RESERVE = 10000;             // UGX kept back while the organizer has events running
const PAYOUT_RESERVE_RELEASE_DAYS = 7;

/** Migration 044 has run. */
function payouts_auto_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW COLUMNS FROM payouts LIKE 'external_id'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** ioTec keys are in the server config. */
function payouts_iotec_configured(): bool
{
    return defined('IOTEC_CLIENT_ID') && IOTEC_CLIENT_ID !== '' && defined('IOTEC_CLIENT_SECRET') && IOTEC_CLIENT_SECRET !== ''
        && defined('IOTEC_WALLET_ID') && IOTEC_WALLET_ID !== '';
}

/** The Admin switch: Withdraw sends money immediately. Off by default. */
function payout_auto_enabled(): bool
{
    if (defined('PAYOUT_TEST_FORCE_AUTO')) {   // only ever defined by the command-line test script
        return payouts_auto_ready();
    }
    return payouts_auto_ready() && payouts_iotec_configured() && get_setting('auto_payouts', '0') === '1';
}

function payout_set_auto(int $adminId, bool $on): void
{
    set_setting($adminId, 'auto_payouts', $on ? '1' : '0');
}

/** A Ugandan mobile money number (07XX XXX XXX, +2567..., 2567...). ioTec's own test numbers are accepted only with IOTEC_PAYOUT_TEST_MODE. */
function payout_valid_phone(string $phone): bool
{
    $d = iotec_normalize_phone($phone);
    if (preg_match('/^256(7[0-9]|39|20)\d{7}$/', $d)) {
        return true;
    }
    return defined('IOTEC_PAYOUT_TEST_MODE') && IOTEC_PAYOUT_TEST_MODE && preg_match('/^256011177\d{4}$/', $d) === 1;
}

function payout_digits(?string $phone): string
{
    return $phone ? iotec_normalize_phone($phone) : '';
}

/** The organizer's saved payout number and provider (organizer_profiles), plus when the number last changed. */
function payout_profile(int $organizerId): array
{
    $cols = payouts_auto_ready() ? ', payout_phone_changed_at' : ', NULL AS payout_phone_changed_at';
    $stmt = db()->prepare('SELECT payout_provider, payout_phone' . $cols . ' FROM organizer_profiles WHERE user_id = ?');
    $stmt->execute([$organizerId]);
    return $stmt->fetch() ?: ['payout_provider' => 'MTN_MOMO', 'payout_phone' => '', 'payout_phone_changed_at' => null];
}

/**
 * UGX of this organizer's balance that must stay on the account: PAYOUT_RESERVE while they have an event that is
 * on, was cancelled recently or ended less than PAYOUT_RESERVE_RELEASE_DAYS days ago, otherwise 0 (released).
 */
function payout_reserve(int $organizerId): int
{
    if (defined('PAYOUT_TEST_NO_RESERVE')) {   // only ever defined by the command-line test script
        return 0;
    }
    $q = db()->prepare("SELECT 1 FROM events WHERE organizer_id = ? AND status IN ('PUBLISHED','CANCELLED')
                        AND ends_at >= NOW() - INTERVAL " . (int) PAYOUT_RESERVE_RELEASE_DAYS . ' DAY LIMIT 1');
    $q->execute([$organizerId]);
    return $q->fetchColumn() ? PAYOUT_RESERVE : 0;
}

/** What the organizer may withdraw right now: the available balance minus the reserve (never below zero). */
function payout_withdrawable(int $organizerId, float $available): float
{
    return max(0.0, floor($available - payout_reserve($organizerId)));
}

/** Why a request cannot be sent (a bad number or amount), or null when it is fine. Bank requests still wait for an admin. */
function payout_input_error(string $method, string $destination, float $amount): ?string
{
    if ($amount < PAYOUT_MIN_AMOUNT) {
        return 'The smallest withdrawal is UGX ' . number_format(PAYOUT_MIN_AMOUNT) . '.';
    }
    if (!in_array($method, ['MTN_MOMO', 'AIRTEL_MONEY'], true)) {
        return null;   // bank: queued for an admin, nothing more to validate here
    }
    if (!payout_valid_phone($destination)) {
        return 'Enter a valid MTN or Airtel number, like 0772 123 456.';
    }
    return null;
}

/** Tells the organizer by email that money went to a number other than the one saved in Settings. */
function payout_email_other_number(int $organizerId, float $amount, string $destination): void
{
    try {
        $u = db()->prepare('SELECT email, name FROM users WHERE id = ?');
        $u->execute([$organizerId]);
        if (($row = $u->fetch()) && function_exists('resend_send')) {
            $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
            resend_send((string) $row['email'], 'A payout was sent to a different number',
                '<div style="font-family:sans-serif;max-width:520px"><h2 style="color:#991B1B">Payout sent</h2><p>Hi ' . $h((string) $row['name']) . ', UGX ' . number_format($amount) . ' from your obitickets balance was just sent to <b>' . $h($destination) . '</b>, which is not the payout number saved in your settings.</p>'
                . '<p>If this was you, nothing else to do. <b>If it was not you</b>, change your password now and contact us right away.</p></div>',
                'payout_other_number');
        }
    } catch (Throwable $e) {
        error_log('[payout] other-number email failed: ' . $e->getMessage());
    }
}

/**
 * An organizer asks to withdraw. The balance is checked and the payout row created under a lock; if automatic sending
 * is on and the request qualifies it is sent straight away, otherwise it waits in the admin queue.
 * @return array{ok:bool, error:?string, payout_id:?int, state:?string, message:?string}  state: 'sent' | 'queued'
 */
function payout_request(int $organizerId, float $amount, string $method, string $destination, ?callable $sender = null): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e, 'payout_id' => null, 'state' => null, 'message' => null];
    $destination = trim($destination);
    if ($amount <= 0 || $amount != round($amount)) {
        return $fail('Enter a whole number of shillings greater than zero.');
    }
    if ($destination === '') {
        return $fail('Enter a phone number or account to receive the payout.');
    }
    $profile = payout_profile($organizerId);
    $auto = payout_auto_enabled();
    $reason = null;
    if ($amount < PAYOUT_MIN_AMOUNT) {
        // every payout costs an ioTec fee: this holds whether or not automatic sending is on
        return $fail('The smallest withdrawal is UGX ' . number_format(PAYOUT_MIN_AMOUNT) . '.');
    }
    if ($auto) {
        if ($bad = payout_input_error($method, $destination, $amount)) {
            return $fail($bad);
        }
        if (!in_array($method, ['MTN_MOMO', 'AIRTEL_MONEY'], true)) {
            $reason = 'Bank transfers are paid by hand.';
        }
    }
    $savedOk = in_array($profile['payout_provider'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY'], true) && payout_valid_phone((string) ($profile['payout_phone'] ?? ''));
    $otherNumber = $auto && !$reason && $savedOk && payout_digits($destination) !== payout_digits((string) $profile['payout_phone']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$organizerId]);   // one withdrawal at a time per organizer
        $open = $pdo->prepare("SELECT COUNT(*) FROM payouts WHERE organizer_id = ? AND status IN ('PENDING','APPROVED','PROCESSING')");
        $open->execute([$organizerId]);
        if ((int) $open->fetchColumn() > 0) {
            $pdo->rollBack();
            return $fail('You already have a withdrawal being processed. You can request another once it is done.');
        }
        $available = array_sum(array_column(get_organizer_balance($organizerId), 'available'));
        if ($amount > $available) {
            $pdo->rollBack();
            return $fail("That's more than your available balance (UGX " . number_format(max(0, $available)) . ').');
        }
        $reserve = payout_reserve($organizerId);
        if ($reserve > 0 && $amount > $available - $reserve) {
            $pdo->rollBack();
            $can = max(0, floor($available - $reserve));
            return $fail('UGX ' . number_format($reserve) . ' of your balance stays on your account as a reserve while you have events running, and for ' . PAYOUT_RESERVE_RELEASE_DAYS . ' days after your last event ends. '
                . ($can >= PAYOUT_MIN_AMOUNT ? 'You can withdraw up to UGX ' . number_format($can) . ' now.' : 'You can withdraw once your balance reaches UGX ' . number_format($reserve + PAYOUT_MIN_AMOUNT) . '.'));
        }
        $pdo->prepare('INSERT INTO payouts (organizer_id, amount, currency, method, destination, notes) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$organizerId, $amount, 'UGX', $method, $destination, $auto && $reason ? 'Queued: ' . $reason : null]);
        $payoutId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[payout] request failed: ' . $e->getMessage());
        return $fail('Something went wrong. Please try again.');
    }
    try {
        log_admin_action($organizerId, 'payout.request', 'payout', $payoutId, ['amount' => $amount, 'auto' => $auto && !$reason]);
    } catch (Throwable $e) {
        // the audit helper must never block a withdrawal
    }

    if ($auto && !$reason) {
        if (!$savedOk) {
            // first time: remember this number so the next withdrawal is one tap
            try {
                db()->prepare('UPDATE organizer_profiles SET payout_provider = ?, payout_phone = ?, payout_phone_changed_at = NOW() WHERE user_id = ?')
                    ->execute([$method, $destination, $organizerId]);
            } catch (Throwable $e) {
                error_log('[payout] could not save payout number: ' . $e->getMessage());
            }
        }
        $r = payout_send($payoutId, null, $sender, true);
        if ($r['ok']) {
            if ($otherNumber) {
                payout_email_other_number($organizerId, $amount, $destination);
            }
            return ['ok' => true, 'error' => null, 'payout_id' => $payoutId, 'state' => 'sent',
                'message' => 'Sent! UGX ' . number_format($amount) . ' is on its way to ' . $destination . '. It usually arrives within a minute.'];
        }
        $st = db()->prepare('SELECT status FROM payouts WHERE id = ?');
        $st->execute([$payoutId]);
        if ($st->fetchColumn() === 'FAILED') {
            // the money never left: it is back in the balance, so say so plainly instead of promising a payment
            return $fail('We could not send the money right now. Nothing was taken from your balance. Please try again in 30 minutes.');
        }
        return ['ok' => true, 'error' => null, 'payout_id' => $payoutId, 'state' => 'queued',
            'message' => 'Your request is in and is being processed.'];
    }
    return ['ok' => true, 'error' => null, 'payout_id' => $payoutId, 'state' => 'queued',
        'message' => $reason ? $reason . ' It will be paid shortly.' : "Withdrawal requested. We'll process it shortly."];
}

/**
 * Sends one payout through ioTec. Safe to call twice: only the call that wins the PENDING/APPROVED -> PROCESSING claim sends.
 * @param callable|null $sender test hook with the same signature as iotec_disburse()
 * @return array{ok:bool, message:string}
 */
function payout_send(int $payoutId, ?int $adminId = null, ?callable $sender = null, bool $auto = false): array
{
    if (!payouts_auto_ready()) {
        return ['ok' => false, 'message' => 'Automatic payouts are not set up yet (run database update 044).'];
    }
    $stmt = db()->prepare('SELECT * FROM payouts WHERE id = ?');
    $stmt->execute([$payoutId]);
    $p = $stmt->fetch();
    if (!$p) {
        return ['ok' => false, 'message' => 'Payout not found.'];
    }
    if (!in_array($p['status'], ['PENDING', 'APPROVED'], true) || $p['iotec_id'] !== null) {
        return ['ok' => false, 'message' => 'This payout was already sent or closed.'];
    }
    if (!in_array($p['method'], ['MTN_MOMO', 'AIRTEL_MONEY'], true)) {
        return ['ok' => false, 'message' => 'Only mobile money payouts can be sent automatically. Pay bank transfers by hand.'];
    }
    if (!payout_valid_phone((string) $p['destination'])) {
        return ['ok' => false, 'message' => 'The payout number is not a valid mobile money number.'];
    }
    if ($sender === null && !payouts_iotec_configured()) {
        return ['ok' => false, 'message' => 'ioTec is not configured on this server.'];
    }

    $externalId = 'OTP-' . (int) $p['id'];
    $claim = db()->prepare("UPDATE payouts SET status = 'PROCESSING', external_id = ?, sent_at = NOW(), is_auto = ?, processed_by = ?, failure_reason = NULL
                            WHERE id = ? AND status IN ('PENDING','APPROVED') AND iotec_id IS NULL");
    $claim->execute([$externalId, $auto ? 1 : 0, $adminId, $payoutId]);
    if ($claim->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'This payout was already picked up.'];
    }

    $sender = $sender ?? 'iotec_disburse';
    try {
        $res = $sender((float) $p['amount'], (string) $p['destination'], $externalId, 'obitickets payout #' . (int) $p['id']);
    } catch (IotecRefusedException $e) {
        // ioTec said no before moving anything: the payout fails and the money stays in the organizer's balance
        $why = 'ioTec refused the payment: ' . mb_substr($e->getMessage(), 0, 300);
        payout_mark_failed($payoutId, $why, false);
        payout_wallet_alert($payoutId, $why, (float) $p['amount']);   // one email per incident, not one per organizer
        return ['ok' => false, 'message' => 'ioTec refused the payment. Nothing was sent.'];
    } catch (Throwable $e) {
        // unknown outcome: the money may have gone. Keep it processing, tell an admin, never resend.
        db()->prepare('UPDATE payouts SET failure_reason = ? WHERE id = ?')->execute(['Waiting for ioTec to confirm: ' . mb_substr($e->getMessage(), 0, 300), $payoutId]);
        error_log('[payout] unclear outcome for payout ' . $payoutId . ': ' . $e->getMessage());
        payout_alert_admins('Payout #' . $payoutId . ' needs a check', 'We could not confirm whether ioTec sent payout #' . $payoutId . ' (UGX ' . number_format((float) $p['amount']) . '). It stays "processing" and will NOT be sent again. Check it in the ioTec portal, then mark it paid or failed in Admin > Payouts.');
        return ['ok' => true, 'message' => 'Sent to ioTec, waiting for confirmation.'];
    }

    db()->prepare('UPDATE payouts SET iotec_id = ? WHERE id = ?')->execute([(string) $res['transactionId'], $payoutId]);
    $now = strtolower((string) ($res['status'] ?? ''));
    if (in_array($now, ['success', 'successful', 'completed'], true)) {
        payout_mark_paid($payoutId);
    } elseif (in_array($now, ['failed', 'rejected', 'cancelled', 'declined'], true)) {
        payout_mark_failed($payoutId, 'ioTec reported the payment failed.', true);
    }
    return ['ok' => true, 'message' => 'Sent to ioTec.'];
}

function payout_mark_paid(int $payoutId): bool
{
    $u = db()->prepare("UPDATE payouts SET status = 'PAID', processed_at = NOW(), failure_reason = NULL WHERE id = ? AND status = 'PROCESSING'");
    $u->execute([$payoutId]);
    if ($u->rowCount() !== 1) {
        return false;
    }
    $p = db()->prepare('SELECT organizer_id, amount, currency, destination FROM payouts WHERE id = ?');
    $p->execute([$payoutId]);
    if ($row = $p->fetch()) {
        try {
            create_notification((int) $row['organizer_id'], 'payout', 'Payout completed',
                'UGX ' . number_format((float) $row['amount']) . ' was sent to ' . $row['destination'] . '.', '/org-payouts.php');
        } catch (Throwable $e) {
            error_log('[payout] paid notification failed: ' . $e->getMessage());
        }
    }
    return true;
}

function payout_mark_failed(int $payoutId, string $reason, bool $alertAdmin): bool
{
    $u = db()->prepare("UPDATE payouts SET status = 'FAILED', processed_at = NOW(), failure_reason = ? WHERE id = ? AND status IN ('PROCESSING','PENDING','APPROVED')");
    $u->execute([mb_substr($reason, 0, 500), $payoutId]);
    if ($u->rowCount() !== 1) {
        return false;
    }
    $p = db()->prepare('SELECT organizer_id, amount FROM payouts WHERE id = ?');
    $p->execute([$payoutId]);
    if ($row = $p->fetch()) {
        try {
            create_notification((int) $row['organizer_id'], 'payout', 'Payout could not be sent',
                'Your withdrawal of UGX ' . number_format((float) $row['amount']) . ' did not go through. The money is back in your balance, so you can try again in 30 minutes.', '/org-payouts.php');
        } catch (Throwable $e) {
            error_log('[payout] failed notification failed: ' . $e->getMessage());
        }
        if ($alertAdmin) {
            payout_alert_admins('Payout #' . $payoutId . ' failed', 'Payout #' . $payoutId . ' (UGX ' . number_format((float) $row['amount']) . ') failed: ' . $reason);
        }
    }
    return true;
}

/** True when ioTec's refusal reads like an empty or low wallet. */
function payout_reason_looks_like_low_wallet(?string $reason): bool
{
    return $reason !== null && preg_match('/insufficient|not enough|balance|funds?\b|wallet|float|top.?up/i', $reason) === 1;
}

/**
 * Admin warning: has ioTec been refusing payouts since the last one it accepted? That usually means the ioTec wallet is
 * empty. It clears by itself as soon as a payout goes through again (after a top-up). Organizers never see any of this.
 * @return array{warn:bool, low:bool, count:int, reason:?string, at:?string}
 */
function payout_wallet_status(): array
{
    $none = ['warn' => false, 'low' => false, 'count' => 0, 'reason' => null, 'at' => null];
    if (!payouts_auto_ready()) {
        return $none;
    }
    $lastOk = db()->query("SELECT MAX(sent_at) FROM payouts WHERE iotec_id IS NOT NULL AND status IN ('PAID','PROCESSING')")->fetchColumn() ?: '1970-01-01 00:00:00';
    $stmt = db()->prepare("SELECT failure_reason, processed_at FROM payouts WHERE status = 'FAILED' AND failure_reason LIKE 'ioTec refused the payment%'
                           AND processed_at > ? AND processed_at >= NOW() - INTERVAL 48 HOUR ORDER BY processed_at DESC, id DESC");
    $stmt->execute([$lastOk]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return $none;
    }
    $reason = preg_replace('/^ioTec refused the payment:\s*/i', '', (string) $rows[0]['failure_reason']);
    return ['warn' => true, 'low' => payout_reason_looks_like_low_wallet($reason), 'count' => count($rows), 'reason' => $reason, 'at' => (string) $rows[0]['processed_at']];
}

/**
 * Emails the admins when ioTec refuses a payout, at most once every 3 hours however many organizers are turned away.
 * @return bool true when an email was sent (false when it was held back to avoid a flood)
 */
function payout_wallet_alert(int $payoutId, string $reason, float $amount): bool
{
    try {
        $last = db()->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'payout_wallet_alert_at'")->fetchColumn();
        if ($last && strtotime((string) $last) > time() - 3 * 3600) {
            return false;
        }
        db()->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('payout_wallet_alert_at', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([date('Y-m-d H:i:s')]);
        $low = payout_reason_looks_like_low_wallet($reason);
        payout_alert_admins($low ? 'Your ioTec wallet may be low: payouts are being refused' : 'ioTec refused a payout',
            'Payout #' . $payoutId . ' (UGX ' . number_format($amount) . ') was refused. ' . $reason . ' '
            . ($low ? 'Please top up your ioTec wallet. ' : 'Please check it in the ioTec portal. ')
            . 'Organizers were told to try again in 30 minutes and their money is safe. You will not get another email about this for 3 hours; the warning in Admin > Payouts clears itself once a payout goes through.');
        return true;
    } catch (Throwable $e) {
        error_log('[payout] wallet alert failed: ' . $e->getMessage());
        return false;
    }
}

/** Asks ioTec how a processing payout went and updates it. Returns the payout's status afterwards. */
function payout_poll(int $payoutId, ?callable $checker = null, ?callable $finder = null): ?string
{
    $stmt = db()->prepare('SELECT * FROM payouts WHERE id = ?');
    $stmt->execute([$payoutId]);
    $p = $stmt->fetch();
    if (!$p || $p['status'] !== 'PROCESSING') {
        return $p['status'] ?? null;
    }
    try {
        if (empty($p['iotec_id'])) {
            // the first answer was lost: look the payout up by OUR reference, but only after a few minutes
            if (!$p['external_id'] || strtotime((string) $p['sent_at']) > time() - 300) {
                return 'PROCESSING';
            }
            $found = ($finder ?? 'iotec_find_disbursement_by_external_id')((string) $p['external_id']);
            if ($found === null) {
                if (strpos((string) $p['failure_reason'], 'STUCK') === false) {
                    db()->prepare('UPDATE payouts SET failure_reason = ? WHERE id = ?')->execute(['STUCK: ioTec has no record of this payout. Check it in the ioTec portal, then mark it failed or paid.', $payoutId]);
                    payout_alert_admins('Payout #' . $payoutId . ' is stuck', 'ioTec has no record of payout #' . $payoutId . ' (UGX ' . number_format((float) $p['amount']) . '). Check the ioTec portal, then mark it paid or failed in Admin > Payouts.');
                }
                return 'PROCESSING';
            }
            db()->prepare('UPDATE payouts SET iotec_id = ? WHERE id = ?')->execute([$found['transactionId'], $payoutId]);
            $p['iotec_id'] = $found['transactionId'];
        }
        $st = ($checker ?? 'iotec_check_disbursement_status')((string) $p['iotec_id']);
    } catch (Throwable $e) {
        error_log('[payout] status check failed for payout ' . $payoutId . ': ' . $e->getMessage());
        return 'PROCESSING';
    }
    $s = strtolower((string) $st['status']);
    if (in_array($s, ['success', 'successful', 'completed'], true)) {
        payout_mark_paid($payoutId);
    } elseif (in_array($s, ['failed', 'rejected', 'cancelled', 'declined'], true)) {
        payout_mark_failed($payoutId, 'ioTec: ' . ($st['statusMessage'] ?: 'the payment failed'), true);
    }
    $r = db()->prepare('SELECT status FROM payouts WHERE id = ?');
    $r->execute([$payoutId]);
    return (string) $r->fetchColumn();
}

/** Checks every payout that is still processing (the cron job, and the payout pages on load). */
function payout_poll_open(?int $organizerId = null, int $limit = 25): int
{
    if (!payouts_auto_ready()) {
        return 0;
    }
    $sql = "SELECT id FROM payouts WHERE status = 'PROCESSING' AND external_id IS NOT NULL" . ($organizerId ? ' AND organizer_id = ' . (int) $organizerId : '') . ' ORDER BY id LIMIT ' . (int) $limit;
    $n = 0;
    foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $id) {
        payout_poll((int) $id);
        $n++;
    }
    return $n;
}

/**
 * Sends mobile money requests that are still waiting (made before automatic payouts were on, or interrupted), so nobody
 * has to press Send now. Only when the Admin switch is on. Bank requests are skipped: they are paid by hand.
 * Each row is still claimed with the single-sender UPDATE in payout_send(), so it can never go out twice.
 * @return int how many were sent
 */
function payout_send_waiting(int $limit = 20, ?callable $sender = null): int
{
    if (!payout_auto_enabled()) {
        return 0;
    }
    $ids = db()->query("SELECT id FROM payouts WHERE status = 'PENDING' AND iotec_id IS NULL AND method IN ('MTN_MOMO','AIRTEL_MONEY')
                        AND requested_at < NOW() - INTERVAL 1 MINUTE ORDER BY id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    foreach ($ids as $id) {
        $r = payout_send((int) $id, null, $sender, true);
        if ($r['ok']) {
            $sent++;
        }
    }
    return $sent;
}

/** Emails the active admins (never throws). */
function payout_alert_admins(string $subject, string $text): void
{
    try {
        if (!function_exists('resend_send')) {
            return;
        }
        $emails = db()->query("SELECT email FROM users WHERE role = 'ADMIN' AND (account_status IS NULL OR account_status = 'ACTIVE')")->fetchAll(PDO::FETCH_COLUMN);
        $html = '<div style="font-family:sans-serif;max-width:520px"><h2 style="color:#991B1B">' . htmlspecialchars($subject) . '</h2><p>' . htmlspecialchars($text) . '</p>'
            . '<p><a href="' . htmlspecialchars(rtrim(APP_URL, '/') . '/admin-payouts.php') . '">Open payouts</a></p></div>';
        foreach ($emails as $e) {
            resend_send((string) $e, '[obitickets] ' . $subject, $html, 'payout_alert');
        }
    } catch (Throwable $e) {
        error_log('[payout] admin alert failed: ' . $e->getMessage());
    }
}

/** Counts for the admin switch card. */
function payout_auto_stats(): array
{
    if (!payouts_auto_ready()) {
        return ['processing' => 0, 'stuck' => 0, 'auto_paid' => 0];
    }
    $q = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();
    return [
        'processing' => $q("SELECT COUNT(*) FROM payouts WHERE status = 'PROCESSING'"),
        'stuck' => $q("SELECT COUNT(*) FROM payouts WHERE status = 'PROCESSING' AND (failure_reason LIKE 'STUCK%' OR failure_reason LIKE 'Waiting for%')"),
        'auto_paid' => $q("SELECT COUNT(*) FROM payouts WHERE status = 'PAID' AND is_auto = 1"),
    ];
}
