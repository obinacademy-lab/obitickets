<?php
declare(strict_types=1);

/**
 * OBI MARKET (ADR-003, phase 4): sellers withdraw their merchandise earnings by mobile money through ioTec.
 *
 * Same rules and safety as the gift withdrawals (includes/gift-payouts.php), applied to the seller's own ledger (market_ledger / market_balances):
 *  - Only AVAILABLE money can be withdrawn (earnings released by customers' "I received my order", the 7-day safety net, or an admin). Pending money cannot.
 *  - Smallest withdrawal UGX 5,000, one open at a time, obitickets pays ioTec's fee. Separate from tickets and gifts: this file writes only market_* tables.
 *  - The money is RESERVED in the same locked transaction that records the request, so it cannot be withdrawn twice.
 *  - A withdrawal is CLAIMED with one UPDATE (REQUESTED -> PROCESSING) and carries a unique external id: only the call that wins the claim can send.
 *  - ioTec clearly refuses: nothing was sent, the withdrawal FAILS and the reserved money returns. Outcome unknown (network, 5xx): it STAYS "processing", admins are
 *    told, and it is never resent or auto-failed on a guess. PAID moves reserved -> withdrawn once.
 *  - A development computer never sends or checks real money (gift_skip_real_check()).
 */

const MARKET_WITHDRAW_MIN_UGX = 5000;
const MARKET_WITHDRAW_OPEN_STATES = "'REQUESTED','PENDING_REVIEW','APPROVED','PROCESSING'";

function market_withdraw_enabled(): bool
{
    return market_ready() && payouts_iotec_configured() && get_setting('market_withdrawals_enabled', '0') === '1';
}

function market_withdraw_set_enabled(int $adminId, bool $on): void
{
    set_setting($adminId, 'market_withdrawals_enabled', $on ? '1' : '0');
    log_admin_action($adminId, 'market.withdraw.switch', 'setting', null, ['on' => $on]);
}

function market_wd_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM market_withdrawals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function market_withdraw_has_open(int $sellerId): bool
{
    $st = db()->prepare('SELECT 1 FROM market_withdrawals WHERE seller_id = ? AND status IN (' . MARKET_WITHDRAW_OPEN_STATES . ') LIMIT 1');
    $st->execute([$sellerId]);
    return (bool) $st->fetchColumn();
}

/** What a seller may take out right now (never below zero: a refund after payout has to be repaid from new sales first). */
function market_withdrawable(int $sellerId): int
{
    return max(0, market_balance($sellerId)['available']);
}

/**
 * Checks, reserves and sends a withdrawal.
 * @param callable|null $sender test hook with the signature of iotec_disburse(); null = the real ioTec (refused on a development computer)
 * @return array{ok:bool, error:?string, withdrawal_id:?int, state:?string, message:?string}
 */
function market_withdraw_request(int $sellerId, int $actorId, int $amount, string $method, string $destination, ?callable $sender = null): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e, 'withdrawal_id' => null, 'state' => null, 'message' => null];
    if (!market_ready()) {
        return $fail('Withdrawals are not available yet.');
    }
    if ($sender === null && gift_is_development()) {
        return $fail('This is a development computer: real withdrawals are never sent from here.');
    }
    if (!market_withdraw_enabled()) {
        return $fail('Withdrawing OBI MARKET money is not open yet.');
    }
    $store = market_store($sellerId);
    if (!$store || $store['status'] === 'SUSPENDED') {
        return $fail('Your withdrawals are on hold while your store is under review. Please contact support.');
    }
    $destination = trim($destination);
    if ($amount <= 0) {
        return $fail('Enter a whole number of shillings greater than zero.');
    }
    if ($amount < MARKET_WITHDRAW_MIN_UGX) {
        return $fail('The smallest withdrawal is UGX ' . number_format(MARKET_WITHDRAW_MIN_UGX) . '.');
    }
    if (!in_array($method, ['MTN_MOMO', 'AIRTEL_MONEY'], true)) {
        return $fail('Choose MTN Mobile Money or Airtel Money.');
    }
    if (!payout_valid_phone($destination)) {
        return $fail('Enter a valid MTN or Airtel number, like 0772 123 456.');
    }
    $profile = payout_profile($sellerId);
    $savedOk = in_array($profile['payout_provider'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY'], true) && payout_valid_phone((string) ($profile['payout_phone'] ?? ''));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$sellerId]);   // one withdrawal at a time per seller
        $pdo->prepare('INSERT IGNORE INTO market_balances (seller_id) VALUES (?)')->execute([$sellerId]);
        $b = $pdo->prepare('SELECT available_ugx FROM market_balances WHERE seller_id = ? FOR UPDATE');
        $b->execute([$sellerId]);
        $available = (int) $b->fetchColumn();
        $open = $pdo->prepare('SELECT COUNT(*) FROM market_withdrawals WHERE seller_id = ? AND status IN (' . MARKET_WITHDRAW_OPEN_STATES . ')');
        $open->execute([$sellerId]);
        if ((int) $open->fetchColumn() > 0) {
            $pdo->rollBack();
            return $fail('You already have a withdrawal being processed. You can request another once it is done.');
        }
        if ($available < 0) {
            $pdo->rollBack();
            return $fail('A refund is being paid back from your earnings (UGX ' . number_format(-$available) . '). You can withdraw once new sales cover it.');
        }
        if ($amount > $available) {
            $pdo->rollBack();
            return $fail("That's more than the money you can withdraw now (UGX " . number_format($available) . ').');
        }
        $reference = 'MWD-' . strtoupper(bin2hex(random_bytes(5)));
        $pdo->prepare("INSERT INTO market_withdrawals (reference, seller_id, amount_ugx, method, destination, status) VALUES (?, ?, ?, ?, ?, 'REQUESTED')")
            ->execute([$reference, $sellerId, $amount, $method, $destination]);
        $wid = (int) $pdo->lastInsertId();
        market_ledger_add($pdo, $sellerId, 'WITHDRAW_RESERVE', null, $wid, 0, -$amount, $amount, 0, 0, 'wd-reserve-' . $wid, 'Withdrawal requested', $actorId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[market] withdrawal request failed: ' . $e->getMessage());
        return $fail('Something went wrong. Please try again.');
    }
    try {
        log_admin_action($actorId, 'market.withdraw.request', 'market_withdrawal', $wid, ['amount' => $amount, 'seller' => $sellerId]);
    } catch (Throwable $e) {
        // the audit helper must never block a withdrawal
    }
    if (!$savedOk) {
        try {
            $pdo->prepare('UPDATE organizer_profiles SET payout_provider = ?, payout_phone = ?, payout_phone_changed_at = NOW() WHERE user_id = ?')->execute([$method, $destination, $sellerId]);
        } catch (Throwable $e) {
            error_log('[market] could not save payout number: ' . $e->getMessage());
        }
    }
    $r = market_withdraw_send($wid, $sender);
    if ($r['ok']) {
        return ['ok' => true, 'error' => null, 'withdrawal_id' => $wid, 'state' => $r['state'] ?? 'processing', 'message' => ($r['state'] ?? '') === 'sent'
            ? 'Sent! UGX ' . number_format($amount) . ' is on its way to ' . $destination . '. It usually arrives within a minute.'
            : 'Your withdrawal is in and is being processed.'];
    }
    $row = market_wd_find($wid);
    if ($row && $row['status'] === 'FAILED') {
        return $fail('We could not send the money right now. Nothing was taken from your balance. Please try again in 30 minutes.');
    }
    return ['ok' => true, 'error' => null, 'withdrawal_id' => $wid, 'state' => 'processing', 'message' => 'Your withdrawal is in and is being processed.'];
}

/**
 * Sends one withdrawal through ioTec. Safe to call twice: only the call that wins the REQUESTED -> PROCESSING claim sends.
 * @return array{ok:bool, message:string, state?:string}
 */
function market_withdraw_send(int $withdrawalId, ?callable $sender = null): array
{
    $w = market_wd_find($withdrawalId);
    if (!$w) {
        return ['ok' => false, 'message' => 'Withdrawal not found.'];
    }
    if ($w['status'] !== 'REQUESTED') {
        return ['ok' => false, 'message' => 'This withdrawal was already sent or closed.'];
    }
    if ($sender === null && gift_is_development()) {
        return ['ok' => false, 'message' => 'This is a development computer: real withdrawals are never sent from here.'];
    }
    if (!payout_valid_phone((string) $w['destination'])) {
        return ['ok' => false, 'message' => 'The payout number is not a valid mobile money number.'];
    }
    if ($sender === null && !payouts_iotec_configured()) {
        return ['ok' => false, 'message' => 'ioTec is not configured on this server.'];
    }
    $pdo = db();
    $externalId = 'OMW-' . $withdrawalId;   // ticket payouts use OTP-<id>, gifts OGW-<id>: the three never collide in the shared ioTec wallet
    $claim = $pdo->prepare("UPDATE market_withdrawals SET status = 'PROCESSING', sent_at = NOW() WHERE id = ? AND status = 'REQUESTED'");
    $claim->execute([$withdrawalId]);
    if ($claim->rowCount() !== 1) {
        return ['ok' => false, 'message' => 'This withdrawal was already picked up.'];
    }
    $sender = $sender ?? 'iotec_disburse';
    try {
        $res = $sender((float) $w['amount_ugx'], (string) $w['destination'], $externalId, 'obitickets OBI MARKET earnings #' . $withdrawalId);
    } catch (IotecRefusedException $e) {
        $why = 'ioTec refused the payment: ' . mb_substr($e->getMessage(), 0, 200);
        market_withdraw_fail($withdrawalId, $why, true);
        return ['ok' => false, 'message' => 'ioTec refused the payment. Nothing was sent.'];
    } catch (Throwable $e) {
        // unknown outcome: the money may have gone. Keep it processing, tell an admin, never resend.
        $note = 'Waiting for ioTec to confirm: ' . mb_substr($e->getMessage(), 0, 200);
        $pdo->prepare('UPDATE market_withdrawals SET reason = ? WHERE id = ?')->execute([mb_substr($note, 0, 250), $withdrawalId]);
        error_log('[market] unclear outcome for withdrawal ' . $withdrawalId . ': ' . $e->getMessage());
        gift_wd_alert_admins('OBI MARKET withdrawal #' . $withdrawalId . ' needs a check', 'We could not confirm whether ioTec sent OBI MARKET withdrawal #' . $withdrawalId . ' (UGX ' . number_format((int) $w['amount_ugx']) . '). It stays "processing" and will NOT be sent again. Check the ioTec portal.');
        return ['ok' => true, 'message' => 'Sent to ioTec, waiting for confirmation.', 'state' => 'processing'];
    }
    $pdo->prepare('UPDATE market_withdrawals SET provider_reference = ? WHERE id = ?')->execute([(string) $res['transactionId'], $withdrawalId]);
    $now = strtolower((string) ($res['status'] ?? ''));
    if (in_array($now, ['success', 'successful', 'completed'], true)) {
        market_withdraw_mark_paid($withdrawalId);
        return ['ok' => true, 'message' => 'Sent to ioTec.', 'state' => 'sent'];
    }
    if (in_array($now, ['failed', 'rejected', 'cancelled', 'declined'], true)) {
        market_withdraw_fail($withdrawalId, 'ioTec reported the payment failed.', true);
        return ['ok' => false, 'message' => 'ioTec reported the payment failed.'];
    }
    return ['ok' => true, 'message' => 'Sent to ioTec.', 'state' => 'sent'];
}

/** The money really left: reserved -> withdrawn, once. */
function market_withdraw_mark_paid(int $withdrawalId, ?int $byAdminId = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM market_withdrawals WHERE id = ? FOR UPDATE');
        $st->execute([$withdrawalId]);
        $w = $st->fetch();
        if (!$w || $w['status'] !== 'PROCESSING') {
            $pdo->rollBack();
            return false;
        }
        $amt = (int) $w['amount_ugx'];
        $seller = (int) $w['seller_id'];
        $pdo->prepare("UPDATE market_withdrawals SET status = 'PAID', completed_at = NOW(), reason = NULL, reviewed_by = COALESCE(?, reviewed_by) WHERE id = ?")->execute([$byAdminId, $withdrawalId]);
        market_ledger_add($pdo, $seller, 'WITHDRAW_PAID', null, $withdrawalId, 0, 0, -$amt, $amt, 0, 'wd-paid-' . $withdrawalId, 'Sent through ioTec to ' . $w['destination'], $byAdminId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    try {
        create_notification($seller, 'market_order', 'Withdrawal completed', 'UGX ' . number_format($amt) . ' of your OBI MARKET earnings was sent to ' . $w['destination'] . '.', '/org-market-earnings.php');
    } catch (Throwable $e) {
        error_log('[market] withdrawal paid notification failed: ' . $e->getMessage());
    }
    if ($byAdminId !== null) {
        log_admin_action($byAdminId, 'market.withdraw.mark_paid', 'market_withdrawal', $withdrawalId, ['amount' => $amt]);
    }
    return true;
}

/** Nothing was sent: the reserved money goes back to the seller. */
function market_withdraw_fail(int $withdrawalId, string $reason, bool $alertAdmin, ?int $byAdminId = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM market_withdrawals WHERE id = ? FOR UPDATE');
        $st->execute([$withdrawalId]);
        $w = $st->fetch();
        if (!$w || !in_array($w['status'], ['REQUESTED', 'PROCESSING'], true)) {
            $pdo->rollBack();
            return false;
        }
        $amt = (int) $w['amount_ugx'];
        $seller = (int) $w['seller_id'];
        $pdo->prepare("UPDATE market_withdrawals SET status = 'FAILED', completed_at = NOW(), reason = ?, reviewed_by = COALESCE(?, reviewed_by) WHERE id = ?")->execute([mb_substr($reason, 0, 250), $byAdminId, $withdrawalId]);
        market_ledger_add($pdo, $seller, 'WITHDRAW_RETURN', null, $withdrawalId, 0, $amt, -$amt, 0, 0, 'wd-return-' . $withdrawalId, mb_substr($reason, 0, 200), $byAdminId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    try {
        create_notification($seller, 'market_order', 'Withdrawal could not be sent', 'Your withdrawal of UGX ' . number_format($amt) . ' did not go through. The money is back in your OBI MARKET balance, so you can try again in 30 minutes.', '/org-market-earnings.php');
    } catch (Throwable $e) {
        error_log('[market] withdrawal failed notification failed: ' . $e->getMessage());
    }
    if ($alertAdmin) {
        gift_wd_alert_admins('OBI MARKET withdrawal #' . $withdrawalId . ' failed', 'OBI MARKET withdrawal #' . $withdrawalId . ' (UGX ' . number_format($amt) . ') failed: ' . $reason);
    }
    if ($byAdminId !== null) {
        log_admin_action($byAdminId, 'market.withdraw.mark_failed', 'market_withdrawal', $withdrawalId, ['amount' => $amt, 'reason' => $reason]);
    }
    return true;
}

/** Asks ioTec how a processing withdrawal is doing and settles it. Returns the withdrawal's status now. */
function market_withdraw_poll(int $withdrawalId, ?callable $checker = null, ?callable $finder = null): ?string
{
    $w = market_wd_find($withdrawalId);
    if (!$w || $w['status'] !== 'PROCESSING') {
        return $w['status'] ?? null;
    }
    $ref = (string) ($w['provider_reference'] ?? 'OMW-' . $withdrawalId);
    if (gift_skip_real_check($checker ?? $finder, $ref, gift_is_development(), gift_fake_allowed()) !== null) {
        return 'PROCESSING';
    }
    try {
        if (empty($w['provider_reference'])) {
            // the first answer was lost: look it up by OUR reference, but only after a few minutes
            if (strtotime((string) ($w['sent_at'] ?? $w['requested_at'])) > time() - 300) {
                return 'PROCESSING';
            }
            if ($finder === null && gift_is_development()) {
                return 'PROCESSING';
            }
            $found = ($finder ?? 'iotec_find_disbursement_by_external_id')('OMW-' . $withdrawalId);
            if ($found === null) {
                if (strpos((string) $w['reason'], 'STUCK') === false) {
                    $note = 'STUCK: ioTec has no record of this withdrawal. Check it in the ioTec portal, then mark it failed or paid.';
                    db()->prepare('UPDATE market_withdrawals SET reason = ? WHERE id = ?')->execute([mb_substr($note, 0, 250), $withdrawalId]);
                    gift_wd_alert_admins('OBI MARKET withdrawal #' . $withdrawalId . ' is stuck', 'ioTec has no record of OBI MARKET withdrawal #' . $withdrawalId . ' (UGX ' . number_format((int) $w['amount_ugx']) . '). Check the ioTec portal, then mark it paid or failed.');
                }
                return 'PROCESSING';
            }
            db()->prepare('UPDATE market_withdrawals SET provider_reference = ? WHERE id = ?')->execute([$found['transactionId'], $withdrawalId]);
            $w['provider_reference'] = $found['transactionId'];
        }
        $st = ($checker ?? 'iotec_check_disbursement_status')((string) $w['provider_reference']);
    } catch (Throwable $e) {
        error_log('[market] withdrawal status check failed for ' . $withdrawalId . ': ' . $e->getMessage());
        return 'PROCESSING';
    }
    $s = strtolower((string) $st['status']);
    if (in_array($s, ['success', 'successful', 'completed'], true)) {
        market_withdraw_mark_paid($withdrawalId);
    } elseif (in_array($s, ['failed', 'rejected', 'cancelled', 'declined'], true)) {
        market_withdraw_fail($withdrawalId, 'ioTec: ' . ($st['statusMessage'] ?: 'the payment failed'), true);
    }
    return (string) (market_wd_find($withdrawalId)['status'] ?? '');
}

/** Checks every withdrawal still processing (the scheduled job, and the earnings page on load). */
function market_withdraw_poll_open(?int $sellerId = null, int $limit = 25, ?callable $checker = null): int
{
    if (!market_ready()) {
        return 0;
    }
    $sql = "SELECT id FROM market_withdrawals WHERE status = 'PROCESSING'" . ($sellerId ? ' AND seller_id = ' . (int) $sellerId : '') . ' ORDER BY id LIMIT ' . (int) $limit;
    $n = 0;
    foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $id) {
        market_withdraw_poll((int) $id, $checker ?? gift_disburse_status_fn());
        $n++;
    }
    return $n;
}

/** Sends withdrawals that were requested but interrupted before sending (each is still claimed by the single-sender UPDATE). */
function market_withdraw_send_waiting(int $limit = 20, ?callable $sender = null): int
{
    if (!market_withdraw_enabled()) {
        return 0;
    }
    $ids = db()->query("SELECT id FROM market_withdrawals WHERE status = 'REQUESTED' AND requested_at < NOW() - INTERVAL 1 MINUTE ORDER BY id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    foreach ($ids as $id) {
        if (market_withdraw_send((int) $id, $sender ?? gift_disburse_fn())['ok']) {
            $sent++;
        }
    }
    return $sent;
}

/** @return list<array<string,mixed>> a seller's withdrawals, newest first, with the words to show */
function market_withdraw_history(int $sellerId, int $limit = 25): array
{
    $st = db()->prepare('SELECT * FROM market_withdrawals WHERE seller_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $st->execute([$sellerId]);
    $labels = ['REQUESTED' => ['Sending', 'wait'], 'PROCESSING' => ['Processing', 'wait'], 'PENDING_REVIEW' => ['Waiting for approval', 'wait'], 'APPROVED' => ['Approved', 'wait'], 'PAID' => ['Paid', 'ok'], 'FAILED' => ['Failed, money returned', 'bad'], 'REJECTED' => ['Declined, money returned', 'bad'], 'CANCELLED' => ['Cancelled', 'bad']];
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'reference' => (string) $r['reference'], 'amount' => (int) $r['amount_ugx'], 'method' => (string) $r['method'], 'destination' => (string) $r['destination'],
        'status' => (string) $r['status'], 'label' => $labels[$r['status']][0] ?? (string) $r['status'], 'tone' => $labels[$r['status']][1] ?? 'wait', 'when' => (string) $r['requested_at'], 'done' => $r['completed_at'], 'note' => $r['reason']], $st->fetchAll());
}
