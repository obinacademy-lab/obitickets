<?php
declare(strict_types=1);

/**
 * OBI GIFTS, phase 5: automatic gift withdrawals through ioTec disbursement.
 *
 * Owner rules (D2, 11/24): an organizer withdraws their gift earnings any time, sent automatically with no admin click, smallest
 * withdrawal UGX 5,000, one withdrawal open at a time, and a withdrawal is sent once. ioTec's fees are paid by obitickets, never by the organizer.
 * Gifts are completely separate from tickets: this file reads and writes only gift_* tables. It never touches `payouts`,
 * ticket balances, orders or tickets (it only READS the organizer's saved mobile money number from organizer_profiles).
 *
 * Safety rules (copied from the ticket payout engine, includes/payouts.php, and tested the same way):
 *  - The balance check, the reservation and the insert run under a lock, so two quick taps cannot overdraw.
 *  - The money is RESERVED the moment the request is made (available -> reserved), so it cannot be withdrawn twice or lost.
 *  - A withdrawal is CLAIMED with one UPDATE (REQUESTED -> PROCESSING) and carries a unique external id ioTec also sees: only the
 *    call that wins the claim can send, so it goes out at most once.
 *  - ioTec clearly refuses (4xx): nothing was sent, the withdrawal FAILS and the reserved money goes back to the organizer.
 *  - The outcome is unknown (network error, 5xx, lost answer): the withdrawal STAYS "processing", admins are told, and it is NEVER
 *    resent or auto-failed on a guess. Only ioTec's answer (or an admin who checked the ioTec portal) settles it.
 *  - PAID writes its ledger rows and moves reserved -> withdrawn in ONE database transaction, once.
 *  - A development computer never sends or checks real money (see gift_skip_real_check()); pretend mode needs GIFTS_FAKE_PAYMENTS.
 */

const GIFT_WITHDRAW_MIN_UGX = 5000;   // every withdrawal costs obitickets an ioTec fee, so tiny ones are not allowed
const GIFT_WITHDRAW_OPEN_STATES = "'REQUESTED','PENDING_REVIEW','APPROVED','PROCESSING'";

// ---------------------------------------------------------------------------------------------------- the switch

/** The Admin switch for automatic gift withdrawals. Off by default; independent of the gifts switch so earned money can always be taken out. */
function gift_withdraw_enabled(): bool
{
    return gifts_ready() && payouts_iotec_configured() && get_setting('gift_withdrawals_enabled', '0') === '1';
}

function gift_withdraw_set_enabled(int $adminId, bool $on): void
{
    set_setting($adminId, 'gift_withdrawals_enabled', $on ? '1' : '0');
}

// ---------------------------------------------------------------------------------------------------- pretend ioTec payouts (development only)

/**
 * Pretend ioTec sending, for testing on a developer's own computer ONLY. The last digit of the number picks the story:
 * 0 = fails after 4 s, 9 = never confirms, 8 = ioTec refuses at once, 7 = the answer is lost (unclear outcome), anything else = paid after 5 s.
 */
function gift_fake_disburse(float $amount, string $phone, string $externalId, string $note): array
{
    $last = substr(preg_replace('/\D/', '', $phone), -1);
    if ($last === '8') {
        throw new IotecRefusedException('pretend refusal: insufficient funds in the wallet');
    }
    if ($last === '7') {
        throw new IotecException('pretend network error: no answer from ioTec');
    }
    $outcome = $last === '0' ? 'fail' : ($last === '9' ? 'wait' : 'ok');
    return ['transactionId' => 'FAKE-D-' . time() . '-' . $outcome . '-' . bin2hex(random_bytes(3)), 'status' => 'Pending'];
}

function gift_fake_disburse_status(string $transactionId): array
{
    $p = explode('-', $transactionId);   // FAKE, D, time, outcome, hex
    $age = time() - (int) ($p[2] ?? time());
    $outcome = $p[3] ?? 'wait';
    if ($outcome === 'ok' && $age >= 5) {
        return ['status' => 'Success', 'statusMessage' => 'Paid (pretend payout)'];
    }
    if ($outcome === 'fail' && $age >= 4) {
        return ['status' => 'Failed', 'statusMessage' => 'The payout failed (pretend payout).'];
    }
    return ['status' => 'Pending', 'statusMessage' => null];
}

/** null = the real ioTec; only a developer's computer in pretend mode gets the pretend sender. */
function gift_disburse_fn(): ?callable
{
    return gift_fake_allowed() ? 'gift_fake_disburse' : null;
}

function gift_disburse_status_fn(): ?callable
{
    return gift_fake_allowed() ? 'gift_fake_disburse_status' : null;
}

// ---------------------------------------------------------------------------------------------------- small helpers

function gift_wd_ledger(PDO $pdo, int $withdrawalId, string $account, string $entry, int $amount, ?string $note = null): void
{
    $pdo->prepare('INSERT INTO gift_ledger (gift_tx_id, withdrawal_id, account, entry, amount_ugx, entry_type, note) VALUES (NULL, ?, ?, ?, ?, ?, ?)')
        ->execute([$withdrawalId, $account, $entry, $amount, 'WITHDRAWAL', $note]);
}

function gift_wd_find(int $withdrawalId): ?array
{
    $stmt = db()->prepare('SELECT w.*, p.external_id, p.provider_reference, p.sent_at, p.failure_reason AS payout_failure
                           FROM gift_withdrawals w LEFT JOIN gift_payouts p ON p.withdrawal_id = w.id WHERE w.id = ?');
    $stmt->execute([$withdrawalId]);
    return $stmt->fetch() ?: null;
}

/** Does this organizer have a withdrawal that is not finished yet? */
function gift_withdraw_has_open(int $organizerId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM gift_withdrawals WHERE organizer_id = ? AND status IN (' . GIFT_WITHDRAW_OPEN_STATES . ') LIMIT 1');
    $stmt->execute([$organizerId]);
    return (bool) $stmt->fetchColumn();
}

/** What an organizer may take out right now (never below zero: a gift debt after a reversal has to be repaid first). */
function gift_withdrawable(int $organizerId): int
{
    return max(0, gift_balance($organizerId)['available']);
}

/**
 * Emails every admin (reuses the ticket payout helper so alerts reach the same people). Every alert is also kept in
 * $GLOBALS['gift_wd_alerts'] for the tests, and a development computer only logs: it never sends real email.
 */
function gift_wd_alert_admins(string $subject, string $text): void
{
    $GLOBALS['gift_wd_alerts'][] = [$subject, $text];
    if (gift_is_development() || !function_exists('payout_alert_admins')) {
        error_log('[gift] admin alert: ' . $subject . ': ' . $text);
        return;
    }
    payout_alert_admins($subject, $text);
}

/** Tells the organizer by email that money went to a number other than their saved one (never really sent from a development computer). */
function gift_wd_email_other_number(int $organizerId, int $amount, string $destination): void
{
    $GLOBALS['gift_wd_mails'][] = [$organizerId, $amount, $destination];
    if (gift_is_development()) {
        return;
    }
    payout_email_other_number($organizerId, (float) $amount, $destination);
}

/**
 * Emails the admins when ioTec refuses a gift withdrawal, at most once every 3 hours (shared with ticket payouts: it is the same ioTec wallet).
 * @return bool true when an email was sent
 */
function gift_wd_wallet_alert(int $withdrawalId, string $reason, int $amount): bool
{
    try {
        $last = db()->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'payout_wallet_alert_at'")->fetchColumn();
        if ($last && strtotime((string) $last) > time() - 3 * 3600) {
            return false;
        }
        db()->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('payout_wallet_alert_at', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([date('Y-m-d H:i:s')]);
        $low = function_exists('payout_reason_looks_like_low_wallet') && payout_reason_looks_like_low_wallet($reason);
        gift_wd_alert_admins($low ? 'Your ioTec wallet may be low: gift withdrawals are being refused' : 'ioTec refused a gift withdrawal',
            'Gift withdrawal #' . $withdrawalId . ' (UGX ' . number_format($amount) . ') was refused. ' . $reason . ' '
            . ($low ? 'Please top up your ioTec wallet. ' : 'Please check it in the ioTec portal. ')
            . 'The organizer was told to try again in 30 minutes and their money is safe. You will not get another email about this for 3 hours.');
        return true;
    } catch (Throwable $e) {
        error_log('[gift] wallet alert failed: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------------------------------- request

/**
 * An organizer asks to withdraw gift money. Checked and reserved under a lock, then sent straight away.
 *
 * @param callable|null $sender test hook with the signature of iotec_disburse(); null = the real ioTec (refused on a development computer)
 * @return array{ok:bool, error:?string, withdrawal_id:?int, state:?string, message:?string}  state: 'sent' | 'processing'
 */
function gift_withdraw_request(int $organizerId, int $actorId, int $amount, string $method, string $destination, ?callable $sender = null): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e, 'withdrawal_id' => null, 'state' => null, 'message' => null];
    if (!gifts_ready()) {
        return $fail('Gift withdrawals are not available yet.');
    }
    if ($sender === null && gift_is_development()) {
        return $fail('This is a development computer: real withdrawals are never sent from here.');
    }
    if (!gift_withdraw_enabled()) {
        return $fail('Withdrawing gift money is not open yet.');
    }
    if (gift_recipient_status($organizerId) === 'SUSPENDED') {
        return $fail('Your gift withdrawals are on hold while your account is under review. Please contact support.');
    }
    $destination = trim($destination);
    if ($amount <= 0) {
        return $fail('Enter a whole number of shillings greater than zero.');
    }
    if ($amount < GIFT_WITHDRAW_MIN_UGX) {
        return $fail('The smallest withdrawal is UGX ' . number_format(GIFT_WITHDRAW_MIN_UGX) . '.');
    }
    if (!in_array($method, ['MTN_MOMO', 'AIRTEL_MONEY'], true)) {
        return $fail('Choose MTN Mobile Money or Airtel Money.');
    }
    if (!payout_valid_phone($destination)) {
        return $fail('Enter a valid MTN or Airtel number, like 0772 123 456.');
    }
    $profile = payout_profile($organizerId);
    $savedOk = in_array($profile['payout_provider'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY'], true) && payout_valid_phone((string) ($profile['payout_phone'] ?? ''));
    $otherNumber = $savedOk && payout_digits($destination) !== payout_digits((string) $profile['payout_phone']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$organizerId]);   // one withdrawal at a time per organizer
        gift_balance_row($pdo, $organizerId);
        $b = $pdo->prepare('SELECT available_ugx FROM gift_balances WHERE organizer_id = ? FOR UPDATE');
        $b->execute([$organizerId]);
        $available = (int) $b->fetchColumn();
        $open = $pdo->prepare('SELECT COUNT(*) FROM gift_withdrawals WHERE organizer_id = ? AND status IN (' . GIFT_WITHDRAW_OPEN_STATES . ')');
        $open->execute([$organizerId]);
        if ((int) $open->fetchColumn() > 0) {
            $pdo->rollBack();
            return $fail('You already have a gift withdrawal being processed. You can request another once it is done.');
        }
        if ($available < 0) {
            $pdo->rollBack();
            return $fail('A reversed gift is being paid back from your gift earnings (UGX ' . number_format(-$available) . '). You can withdraw once new gifts cover it.');
        }
        if ($amount > $available) {
            $pdo->rollBack();
            return $fail("That's more than your gift balance (UGX " . number_format($available) . ').');
        }
        $reference = 'GWD-' . strtoupper(bin2hex(random_bytes(5)));
        $pdo->prepare('INSERT INTO gift_withdrawals (reference, organizer_id, amount_ugx, method, destination, status, is_auto) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$reference, $organizerId, $amount, $method, $destination, 'REQUESTED']);
        $wid = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE gift_balances SET available_ugx = available_ugx - ?, reserved_ugx = reserved_ugx + ? WHERE organizer_id = ?')
            ->execute([$amount, $amount, $organizerId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[gift] withdrawal request failed: ' . $e->getMessage());
        return $fail('Something went wrong. Please try again.');
    }
    try {
        log_admin_action($actorId, 'gift.withdraw.request', 'gift_withdrawal', $wid, ['amount' => $amount, 'organizer' => $organizerId]);
    } catch (Throwable $e) {
        // the audit helper must never block a withdrawal
    }
    if (!$savedOk) {
        // first time: remember this number so the next withdrawal is one tap (the same saved number the ticket payouts use)
        try {
            $pdo->prepare('UPDATE organizer_profiles SET payout_provider = ?, payout_phone = ?, payout_phone_changed_at = NOW() WHERE user_id = ?')->execute([$method, $destination, $organizerId]);
        } catch (Throwable $e) {
            error_log('[gift] could not save payout number: ' . $e->getMessage());
        }
    }

    $r = gift_withdraw_send($wid, $sender);
    if ($r['ok']) {
        if ($otherNumber) {
            gift_wd_email_other_number($organizerId, $amount, $destination);
        }
        return ['ok' => true, 'error' => null, 'withdrawal_id' => $wid, 'state' => $r['state'] ?? 'processing', 'message' => ($r['state'] ?? '') === 'sent'
            ? 'Sent! UGX ' . number_format($amount) . ' is on its way to ' . $destination . '. It usually arrives within a minute.'
            : 'Your withdrawal is in and is being processed.'];
    }
    $row = gift_wd_find($wid);
    if ($row && $row['status'] === 'FAILED') {
        // the money never left: it is back in the balance, so say so plainly instead of promising a payment
        return $fail('We could not send the money right now. Nothing was taken from your balance. Please try again in 30 minutes.');
    }
    return ['ok' => true, 'error' => null, 'withdrawal_id' => $wid, 'state' => 'processing', 'message' => 'Your withdrawal is in and is being processed.'];
}

// ---------------------------------------------------------------------------------------------------- send

/**
 * Sends one withdrawal through ioTec. Safe to call twice: only the call that wins the REQUESTED -> PROCESSING claim sends.
 * @param callable|null $sender test hook with the signature of iotec_disburse()
 * @return array{ok:bool, message:string, state?:string}   state: 'sent' (ioTec took it) | 'processing' (outcome not known yet)
 */
function gift_withdraw_send(int $withdrawalId, ?callable $sender = null): array
{
    $w = gift_wd_find($withdrawalId);
    if (!$w) {
        return ['ok' => false, 'message' => 'Withdrawal not found.'];
    }
    if ($w['status'] !== 'REQUESTED') {
        return ['ok' => false, 'message' => 'This withdrawal was already sent or closed.'];
    }
    if ($sender === null && gift_is_development()) {
        return ['ok' => false, 'message' => 'This is a development computer: real withdrawals are never sent from here.'];
    }
    if (!in_array($w['method'], ['MTN_MOMO', 'AIRTEL_MONEY'], true) || !payout_valid_phone((string) $w['destination'])) {
        return ['ok' => false, 'message' => 'The payout number is not a valid mobile money number.'];
    }
    if ($sender === null && !payouts_iotec_configured()) {
        return ['ok' => false, 'message' => 'ioTec is not configured on this server.'];
    }
    $pdo = db();
    $externalId = 'OGW-' . $withdrawalId;   // OUR reference; ticket payouts use OTP-<id>, so the two never collide in the shared ioTec wallet

    $claim = $pdo->prepare("UPDATE gift_withdrawals SET status = 'PROCESSING' WHERE id = ? AND status = 'REQUESTED'");
    $claim->execute([$withdrawalId]);
    if ($claim->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'This withdrawal was already picked up.'];
    }
    try {
        $pdo->prepare('INSERT INTO gift_payouts (withdrawal_id, external_id, amount_ugx, status, sent_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$withdrawalId, $externalId, (int) $w['amount_ugx'], 'PROCESSING']);
    } catch (Throwable $e) {
        // could not even record the attempt: nothing was sent, but never guess; an admin decides
        error_log('[gift] could not record payout attempt for withdrawal ' . $withdrawalId . ': ' . $e->getMessage());
        gift_wd_alert_admins('Gift withdrawal #' . $withdrawalId . ' needs a check', 'Gift withdrawal #' . $withdrawalId . ' (UGX ' . number_format((int) $w['amount_ugx']) . ') could not be recorded before sending. NOTHING was sent. Check it in Admin > OBI GIFTS > Withdrawals.');
        return ['ok' => false, 'message' => 'Could not start the withdrawal. An admin has been told.'];
    }

    $sender = $sender ?? 'iotec_disburse';
    try {
        $res = $sender((float) $w['amount_ugx'], (string) $w['destination'], $externalId, 'obitickets gift earnings #' . $withdrawalId);
    } catch (IotecRefusedException $e) {
        // ioTec said no before moving anything: the withdrawal fails and the money goes back to the organizer
        $why = 'ioTec refused the payment: ' . mb_substr($e->getMessage(), 0, 300);
        gift_withdraw_fail($withdrawalId, $why, false);
        gift_wd_wallet_alert($withdrawalId, $why, (int) $w['amount_ugx']);   // one email per incident, not one per organizer
        return ['ok' => false, 'message' => 'ioTec refused the payment. Nothing was sent.'];
    } catch (Throwable $e) {
        // unknown outcome: the money may have gone. Keep it processing, tell an admin, never resend.
        $note = 'Waiting for ioTec to confirm: ' . mb_substr($e->getMessage(), 0, 200);
        $pdo->prepare('UPDATE gift_payouts SET failure_reason = ? WHERE withdrawal_id = ?')->execute([$note, $withdrawalId]);
        $pdo->prepare('UPDATE gift_withdrawals SET reason = ? WHERE id = ?')->execute([mb_substr($note, 0, 250), $withdrawalId]);
        error_log('[gift] unclear outcome for withdrawal ' . $withdrawalId . ': ' . $e->getMessage());
        gift_wd_alert_admins('Gift withdrawal #' . $withdrawalId . ' needs a check', 'We could not confirm whether ioTec sent gift withdrawal #' . $withdrawalId . ' (UGX ' . number_format((int) $w['amount_ugx']) . '). It stays "processing" and will NOT be sent again. Check it in the ioTec portal, then mark it paid or failed in Admin > OBI GIFTS > Withdrawals.');
        return ['ok' => true, 'message' => 'Sent to ioTec, waiting for confirmation.', 'state' => 'processing'];
    }

    $pdo->prepare('UPDATE gift_payouts SET provider_reference = ? WHERE withdrawal_id = ?')->execute([(string) $res['transactionId'], $withdrawalId]);
    $now = strtolower((string) ($res['status'] ?? ''));
    if (in_array($now, ['success', 'successful', 'completed'], true)) {
        gift_withdraw_mark_paid($withdrawalId);
        return ['ok' => true, 'message' => 'Sent to ioTec.', 'state' => 'sent'];
    }
    if (in_array($now, ['failed', 'rejected', 'cancelled', 'declined'], true)) {
        gift_withdraw_fail($withdrawalId, 'ioTec reported the payment failed.', true);
        return ['ok' => false, 'message' => 'ioTec reported the payment failed.'];
    }
    return ['ok' => true, 'message' => 'Sent to ioTec.', 'state' => 'sent'];
}

// ---------------------------------------------------------------------------------------------------- settle

/** The money really left: reserved -> withdrawn and the ledger rows, in ONE transaction, exactly once. */
function gift_withdraw_mark_paid(int $withdrawalId, ?int $byAdminId = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gift_withdrawals WHERE id = ? FOR UPDATE');
        $stmt->execute([$withdrawalId]);
        $w = $stmt->fetch();
        if (!$w || $w['status'] !== 'PROCESSING') {
            $pdo->rollBack();
            return false;
        }
        $org = (int) $w['organizer_id'];
        $amt = (int) $w['amount_ugx'];
        $pdo->prepare("UPDATE gift_withdrawals SET status = 'PAID', completed_at = NOW(), reason = NULL, reviewed_by = COALESCE(?, reviewed_by) WHERE id = ?")->execute([$byAdminId, $withdrawalId]);
        $pdo->prepare("UPDATE gift_payouts SET status = 'PAID', confirmed_at = NOW(), failure_reason = NULL WHERE withdrawal_id = ?")->execute([$withdrawalId]);
        gift_balance_row($pdo, $org);
        $pdo->prepare('UPDATE gift_balances SET reserved_ugx = reserved_ugx - ?, total_withdrawn_ugx = total_withdrawn_ugx + ? WHERE organizer_id = ?')->execute([$amt, $amt, $org]);
        gift_wd_ledger($pdo, $withdrawalId, 'ORGANIZER:' . $org, 'DEBIT', $amt, 'Gift earnings withdrawn');
        gift_wd_ledger($pdo, $withdrawalId, 'PAYOUT', 'CREDIT', $amt, 'Sent through ioTec to ' . $w['destination']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    try {
        create_notification($org, 'gift', 'Withdrawal completed', 'UGX ' . number_format($amt) . ' of your gift earnings was sent to ' . $w['destination'] . '.', '/org-gifts.php?tab=withdrawals');
    } catch (Throwable $e) {
        error_log('[gift] withdrawal paid notification failed: ' . $e->getMessage());
    }
    if ($byAdminId !== null) {
        log_admin_action($byAdminId, 'gift.withdraw.mark_paid', 'gift_withdrawal', $withdrawalId, ['amount' => $amt]);
    }
    return true;
}

/** Nothing was sent (ioTec refused, or reported a failure, or an admin checked): the reserved money goes back to the organizer. */
function gift_withdraw_fail(int $withdrawalId, string $reason, bool $alertAdmin, ?int $byAdminId = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gift_withdrawals WHERE id = ? FOR UPDATE');
        $stmt->execute([$withdrawalId]);
        $w = $stmt->fetch();
        if (!$w || !in_array($w['status'], ['REQUESTED', 'PROCESSING'], true)) {
            $pdo->rollBack();
            return false;
        }
        $org = (int) $w['organizer_id'];
        $amt = (int) $w['amount_ugx'];
        $pdo->prepare("UPDATE gift_withdrawals SET status = 'FAILED', completed_at = NOW(), reason = ?, reviewed_by = COALESCE(?, reviewed_by) WHERE id = ?")->execute([mb_substr($reason, 0, 250), $byAdminId, $withdrawalId]);
        $pdo->prepare("UPDATE gift_payouts SET status = 'FAILED', failure_reason = ? WHERE withdrawal_id = ?")->execute([mb_substr($reason, 0, 500), $withdrawalId]);
        gift_balance_row($pdo, $org);
        $pdo->prepare('UPDATE gift_balances SET reserved_ugx = reserved_ugx - ?, available_ugx = available_ugx + ? WHERE organizer_id = ?')->execute([$amt, $amt, $org]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    try {
        create_notification($org, 'gift', 'Withdrawal could not be sent',
            'Your gift withdrawal of UGX ' . number_format($amt) . ' did not go through. The money is back in your gift balance, so you can try again in 30 minutes.', '/org-gifts.php?tab=withdrawals');
    } catch (Throwable $e) {
        error_log('[gift] withdrawal failed notification failed: ' . $e->getMessage());
    }
    if ($alertAdmin) {
        gift_wd_alert_admins('Gift withdrawal #' . $withdrawalId . ' failed', 'Gift withdrawal #' . $withdrawalId . ' (UGX ' . number_format($amt) . ') failed: ' . $reason);
    }
    if ($byAdminId !== null) {
        log_admin_action($byAdminId, 'gift.withdraw.mark_failed', 'gift_withdrawal', $withdrawalId, ['amount' => $amt, 'reason' => $reason]);
    }
    return true;
}

// ---------------------------------------------------------------------------------------------------- ask ioTec how it went

/**
 * Asks ioTec how a processing withdrawal went and settles it. Returns the withdrawal's status afterwards.
 * @param callable|null $checker test hook with the signature of iotec_check_disbursement_status()
 * @param callable|null $finder  test hook with the signature of iotec_find_disbursement_by_external_id()
 */
function gift_withdraw_poll(int $withdrawalId, ?callable $checker = null, ?callable $finder = null): ?string
{
    $w = gift_wd_find($withdrawalId);
    if (!$w || $w['status'] !== 'PROCESSING') {
        return $w['status'] ?? null;
    }
    // a development computer never asks the real ioTec, and a pretend reference is never trusted outside pretend mode
    $ref = (string) ($w['provider_reference'] ?? $w['external_id'] ?? '');
    if (gift_skip_real_check($checker ?? $finder, $ref, gift_is_development(), gift_fake_allowed()) !== null) {
        return 'PROCESSING';
    }
    try {
        if (empty($w['provider_reference'])) {
            // the first answer was lost: look it up by OUR reference, but only after a few minutes
            if (empty($w['external_id']) || strtotime((string) $w['sent_at']) > time() - 300) {
                return 'PROCESSING';
            }
            if ($finder === null && gift_is_development()) {
                return 'PROCESSING';   // a development computer never asks the real ioTec, not even to look something up
            }
            $found = ($finder ?? 'iotec_find_disbursement_by_external_id')((string) $w['external_id']);
            if ($found === null) {
                if (strpos((string) $w['payout_failure'], 'STUCK') === false) {
                    $note = 'STUCK: ioTec has no record of this withdrawal. Check it in the ioTec portal, then mark it failed or paid.';
                    db()->prepare('UPDATE gift_payouts SET failure_reason = ? WHERE withdrawal_id = ?')->execute([$note, $withdrawalId]);
                    db()->prepare('UPDATE gift_withdrawals SET reason = ? WHERE id = ?')->execute([mb_substr($note, 0, 250), $withdrawalId]);
                    gift_wd_alert_admins('Gift withdrawal #' . $withdrawalId . ' is stuck', 'ioTec has no record of gift withdrawal #' . $withdrawalId . ' (UGX ' . number_format((int) $w['amount_ugx']) . '). Check the ioTec portal, then mark it paid or failed in Admin > OBI GIFTS > Withdrawals.');
                }
                return 'PROCESSING';
            }
            db()->prepare('UPDATE gift_payouts SET provider_reference = ? WHERE withdrawal_id = ?')->execute([$found['transactionId'], $withdrawalId]);
            $w['provider_reference'] = $found['transactionId'];
        }
        $st = ($checker ?? 'iotec_check_disbursement_status')((string) $w['provider_reference']);
    } catch (Throwable $e) {
        error_log('[gift] withdrawal status check failed for ' . $withdrawalId . ': ' . $e->getMessage());
        return 'PROCESSING';
    }
    $s = strtolower((string) $st['status']);
    if (in_array($s, ['success', 'successful', 'completed'], true)) {
        gift_withdraw_mark_paid($withdrawalId);
    } elseif (in_array($s, ['failed', 'rejected', 'cancelled', 'declined'], true)) {
        gift_withdraw_fail($withdrawalId, 'ioTec: ' . ($st['statusMessage'] ?: 'the payment failed'), true);
    }
    $r = db()->prepare('SELECT status FROM gift_withdrawals WHERE id = ?');
    $r->execute([$withdrawalId]);
    return (string) $r->fetchColumn();
}

/** Checks every withdrawal that is still processing (the cron job, and the withdrawals page on load). */
function gift_withdraw_poll_open(?int $organizerId = null, int $limit = 25, ?callable $checker = null): int
{
    if (!gifts_ready()) {
        return 0;
    }
    $sql = "SELECT id FROM gift_withdrawals WHERE status = 'PROCESSING'" . ($organizerId ? ' AND organizer_id = ' . (int) $organizerId : '') . ' ORDER BY id LIMIT ' . (int) $limit;
    $n = 0;
    foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $id) {
        gift_withdraw_poll((int) $id, $checker ?? gift_disburse_status_fn());
        $n++;
    }
    return $n;
}

/**
 * Sends withdrawals that were requested but interrupted before they were sent (a crash between reserving and sending), so nobody has
 * to press anything. Only when the switch is on. Each one is still claimed with the single-sender UPDATE, so it can never go out twice.
 * @return int how many were sent
 */
function gift_withdraw_send_waiting(int $limit = 20, ?callable $sender = null): int
{
    if (!gift_withdraw_enabled()) {
        return 0;
    }
    $ids = db()->query("SELECT id FROM gift_withdrawals WHERE status = 'REQUESTED' AND method IN ('MTN_MOMO','AIRTEL_MONEY')
                        AND requested_at < NOW() - INTERVAL 1 MINUTE ORDER BY id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    foreach ($ids as $id) {
        if (gift_withdraw_send((int) $id, $sender ?? gift_disburse_fn())['ok']) {
            $sent++;
        }
    }
    return $sent;
}

// ---------------------------------------------------------------------------------------------------- what the screens show

/**
 * An organizer's withdrawals, newest first. The number is shown masked in lists elsewhere; the organizer sees their own number in full.
 * @return list<array{id:int, reference:string, amount:int, method:string, destination:string, status:string, label:string, tone:string, when:string, done:?string, note:?string}>
 */
function gift_withdraw_history(int $organizerId, int $limit = 25): array
{
    $stmt = db()->prepare('SELECT id, reference, amount_ugx, method, destination, status, reason, requested_at, completed_at FROM gift_withdrawals WHERE organizer_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute([$organizerId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $s = (string) $r['status'];
        [$label, $tone] = match ($s) {
            'PAID' => ['Paid', 'success'],
            'FAILED', 'REJECTED', 'CANCELLED' => ['Not sent, money returned', 'danger'],
            default => ['Processing', 'warn'],
        };
        $out[] = ['id' => (int) $r['id'], 'reference' => (string) $r['reference'], 'amount' => (int) $r['amount_ugx'], 'method' => (string) $r['method'],
                  'destination' => (string) $r['destination'], 'status' => $s, 'label' => $label, 'tone' => $tone,
                  'when' => (string) $r['requested_at'], 'done' => $r['completed_at'] !== null ? (string) $r['completed_at'] : null,
                  // when a person at obitickets settled it by hand, the organizer sees that decision (never ioTec's technical messages)
                  'note' => str_starts_with((string) $r['reason'], 'Checked by an admin') ? (string) $r['reason'] : null];
    }
    return $out;
}

/** Admin list: recent withdrawals of everyone, with how long each open one has been waiting. */
function gift_withdraw_admin_list(int $limit = 60): array
{
    $stmt = db()->query('SELECT w.id, w.reference, w.organizer_id, w.amount_ugx, w.method, w.destination, w.status, w.reason, w.requested_at, w.completed_at,
                                TIMESTAMPDIFF(MINUTE, w.requested_at, NOW()) AS age_min, COALESCE(NULLIF(op.org_name, \'\'), u.name) AS organizer, p.provider_reference
                         FROM gift_withdrawals w JOIN users u ON u.id = w.organizer_id LEFT JOIN organizer_profiles op ON op.user_id = w.organizer_id
                         LEFT JOIN gift_payouts p ON p.withdrawal_id = w.id ORDER BY w.id DESC LIMIT ' . max(1, min(300, $limit)));
    return $stmt->fetchAll();
}
