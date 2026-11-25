<?php
/**
 * OBI MARKET (ADR-003), part 2: what happens to an order after it is paid, the seller's money, refunds, and the books check.
 *
 * THE FLOW. NEW -> PROCESSING -> SHIPPED (delivery) or READY_FOR_COLLECTION -> COMPLETED.
 *  - Only the CUSTOMER completes an order ("I received it"). That is what releases the seller's earnings (owner decision 11/25).
 *    Safety net: if the seller marked it shipped/ready and nothing happens for MARKET_AUTO_RELEASE_DAYS days and the customer has not reported a problem,
 *    the job completes it, so a seller is never stuck. A reported problem freezes that until an admin decides.
 *  - The seller can cancel before shipping (a full refund is then owed to the customer). Refunds are approved by an admin and paid back to the customer
 *    by hand (ioTec portal); the system records every reversal in the ledger. Nothing is ever edited or deleted: corrections are new ledger rows.
 *
 * THE MONEY. market_ledger rows move a seller's money between buckets: pending (sold, not confirmed), available (can be withdrawn), reserved (a withdrawal
 * is on its way), withdrawn (paid out). market_balances must always equal the sum of the rows: market_reconcile() proves it.
 */

// ---------------------------------------------------------------------------------------------------- the seller's ledger

/**
 * The ONLY place a seller's balance changes (inside the caller's transaction). Locks the balance row, writes the ledger row (a once_key makes a
 * movement happen once), updates the balances. Returns the ledger row id.
 */
function market_ledger_add(PDO $pdo, int $sellerId, string $type, ?int $orderId, ?int $withdrawalId, int $pending, int $available, int $reserved, int $withdrawn, int $platform, ?string $onceKey, ?string $note, ?int $actorId): int
{
    $pdo->prepare('INSERT IGNORE INTO market_balances (seller_id) VALUES (?)')->execute([$sellerId]);
    $pdo->prepare('SELECT seller_id FROM market_balances WHERE seller_id = ? FOR UPDATE')->execute([$sellerId]);
    $pdo->prepare('INSERT INTO market_ledger (seller_id, entry_type, order_id, withdrawal_id, pending_delta, available_delta, reserved_delta, withdrawn_delta, platform_delta, once_key, note, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$sellerId, $type, $orderId, $withdrawalId, $pending, $available, $reserved, $withdrawn, $platform, $onceKey, $note !== null ? mb_substr($note, 0, 250) : null, $actorId]);
    $id = (int) $pdo->lastInsertId();
    $earned = in_array($type, ['SALE', 'REFUND'], true) ? $pending + $available : 0;
    $pdo->prepare('UPDATE market_balances SET pending_ugx = pending_ugx + ?, available_ugx = available_ugx + ?, reserved_ugx = reserved_ugx + ?, withdrawn_ugx = withdrawn_ugx + ?, total_earned_ugx = total_earned_ugx + ? WHERE seller_id = ?')
        ->execute([$pending, $available, $reserved, $withdrawn, $earned, $sellerId]);
    return $id;
}

/** @return array{pending:int, available:int, reserved:int, withdrawn:int, total_earned:int} */
function market_balance(int $sellerId): array
{
    $st = db()->prepare('SELECT * FROM market_balances WHERE seller_id = ?');
    $st->execute([$sellerId]);
    $r = $st->fetch();
    return ['pending' => (int) ($r['pending_ugx'] ?? 0), 'available' => (int) ($r['available_ugx'] ?? 0), 'reserved' => (int) ($r['reserved_ugx'] ?? 0), 'withdrawn' => (int) ($r['withdrawn_ugx'] ?? 0), 'total_earned' => (int) ($r['total_earned_ugx'] ?? 0)];
}

function market_order_find(int $orderId): ?array
{
    $st = db()->prepare('SELECT * FROM market_orders WHERE id = ?');
    $st->execute([$orderId]);
    return $st->fetch() ?: null;
}

/** A locked copy of the order inside the caller's transaction. */
function market_order_lock(PDO $pdo, int $orderId): ?array
{
    $st = $pdo->prepare('SELECT * FROM market_orders WHERE id = ? FOR UPDATE');
    $st->execute([$orderId]);
    return $st->fetch() ?: null;
}

// ---------------------------------------------------------------------------------------------------- the seller moves an order along

/**
 * The seller updates an order they own. Allowed: NEW -> PROCESSING; NEW or PROCESSING -> SHIPPED (delivery orders) or READY_FOR_COLLECTION (collection
 * orders); NEW or PROCESSING -> CANCELLED (a full refund is then owed to the customer, the stock goes back). The seller can never mark an order paid
 * or completed. @return array{ok:bool, error?:string}
 */
function market_order_set_status(int $sellerId, int $orderId, string $to, ?string $note = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $o = market_order_lock($pdo, $orderId);
        if (!$o || (int) $o['seller_id'] !== $sellerId) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That order does not exist.'];
        }
        if ($o['payment_status'] !== 'PAID' || in_array($o['fulfilment'], ['PENDING_PAYMENT', 'CANCELLED', 'REFUNDED', 'COMPLETED'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This order cannot be changed any more.'];
        }
        $from = (string) $o['fulfilment'];
        $ok = match ($to) {
            'PROCESSING' => $from === 'NEW',
            'SHIPPED' => in_array($from, ['NEW', 'PROCESSING'], true) && $o['delivery_method'] === 'DELIVERY',
            'READY_FOR_COLLECTION' => in_array($from, ['NEW', 'PROCESSING'], true) && $o['delivery_method'] === 'COLLECTION',
            'CANCELLED' => in_array($from, ['NEW', 'PROCESSING'], true),
            default => false,
        };
        if (!$ok) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That change is not possible for this order right now.'];
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
        if ($to === 'CANCELLED') {
            if ($note === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Say why you are cancelling: the customer will read it.'];
            }
            market_refund_apply($pdo, $o, (int) $o['merchandise_ugx'] - (int) $o['refunded_merch_ugx'], true, 'Cancelled by the seller: ' . $note, true, $sellerId, 'SELLER', 'CANCELLED');
        } else {
            $release = in_array($to, ['SHIPPED', 'READY_FOR_COLLECTION'], true);
            $pdo->prepare('UPDATE market_orders SET fulfilment = ?, shipped_at = IF(?, NOW(), shipped_at), release_after = IF(?, ?, release_after), seller_note = COALESCE(?, seller_note) WHERE id = ?')
                ->execute([$to, $release ? 1 : 0, $release ? 1 : 0, date('Y-m-d H:i:s', time() + MARKET_AUTO_RELEASE_DAYS * 86400), $note, $orderId]);
            market_history_add($pdo, $orderId, $from, $to, $sellerId, 'SELLER', $note);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    market_order_notify_customer($orderId, $to);
    return ['ok' => true];
}

/** Tells the customer in their bell about a step that matters. Never throws. */
function market_order_notify_customer(int $orderId, string $to): void
{
    $o = market_order_find($orderId);
    if (!$o) {
        return;
    }
    $text = match ($to) {
        'PROCESSING' => ['Your order is being prepared', 'The seller is getting your order ' . $o['order_number'] . ' ready.'],
        'SHIPPED' => ['Your order is on its way', 'Your order ' . $o['order_number'] . ' was sent. When it arrives, open it and tap "I received my order".'],
        'READY_FOR_COLLECTION' => ['Your order is ready to collect', 'Your order ' . $o['order_number'] . ' is ready. After you collect it, open it and tap "I received my order".'],
        'CANCELLED' => ['Your order was cancelled', 'The seller cancelled order ' . $o['order_number'] . '. A full refund will be sent to your phone.'],
        default => null,
    };
    if ($text) {
        try {
            create_notification((int) $o['customer_id'], 'market_order', $text[0], $text[1], '/market-order.php?id=' . $orderId);
        } catch (Throwable $e) {
            error_log('[market] customer notification failed: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------------------------------- completing an order releases the earnings

/** What is still pending for this order after any refunds: the amount a release moves to available. */
function market_order_pending_amount(PDO $pdo, int $orderId): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(pending_delta), 0) FROM market_ledger WHERE order_id = ? AND entry_type IN ('SALE','REFUND','RELEASE')");
    $st->execute([$orderId]);
    return (int) $st->fetchColumn();
}

/**
 * Marks a locked order COMPLETED and moves its pending earnings to available, once. $by: CUSTOMER | AUTO | ADMIN.
 * @return int the amount released
 */
function market_order_complete(PDO $pdo, array $o, string $by, ?int $actorId, ?string $note = null): int
{
    $released = 0;
    if ($o['earnings_state'] === 'PENDING') {
        $released = market_order_pending_amount($pdo, (int) $o['id']);
        if ($released > 0) {
            market_ledger_add($pdo, (int) $o['seller_id'], 'RELEASE', (int) $o['id'], null, -$released, $released, 0, 0, 0, 'release-' . (int) $o['id'], 'Order ' . $o['order_number'] . ' confirmed received (' . strtolower($by) . ')', $actorId);
        }
    }
    $pdo->prepare("UPDATE market_orders SET fulfilment = 'COMPLETED', received_at = NOW(), received_by = ?, earnings_state = IF(earnings_state = 'PENDING', 'AVAILABLE', earnings_state) WHERE id = ?")->execute([$by, (int) $o['id']]);
    market_history_add($pdo, (int) $o['id'], (string) $o['fulfilment'], 'COMPLETED', $actorId, $by === 'CUSTOMER' ? 'CUSTOMER' : ($by === 'ADMIN' ? 'ADMIN' : 'SYSTEM'), $note ?? ($by === 'CUSTOMER' ? 'The customer confirmed they received it' : ($by === 'AUTO' ? 'Confirmed automatically after ' . MARKET_AUTO_RELEASE_DAYS . ' days' : 'Confirmed by an admin')));
    return $released;
}

/**
 * The customer approves that the order was delivered or collected. This releases the seller's earnings.
 * @return array{ok:bool, error?:string, released?:int}
 */
function market_order_confirm_received(int $customerId, int $orderId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $o = market_order_lock($pdo, $orderId);
        if (!$o || (int) $o['customer_id'] !== $customerId) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That order does not exist.'];
        }
        if ($o['fulfilment'] === 'COMPLETED') {
            $pdo->rollBack();
            return ['ok' => true, 'released' => 0];   // already confirmed: pressing twice changes nothing
        }
        if (!in_array($o['fulfilment'], ['SHIPPED', 'READY_FOR_COLLECTION'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'You can confirm once the seller has sent your order or marked it ready to collect.'];
        }
        $released = market_order_complete($pdo, $o, 'CUSTOMER', $customerId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    try {
        create_notification((int) $o['seller_id'], 'market_order', 'Order ' . $o['order_number'] . ' received', 'The customer confirmed they received it. UGX ' . number_format($released) . ' is now available to withdraw.', '/org-market-order.php?id=' . $orderId);
    } catch (Throwable $e) {
        error_log('[market] seller notification failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'released' => $released];
}

/** The customer says something is wrong with a sent order: the automatic release is frozen until an admin decides. @return array{ok:bool, error?:string} */
function market_order_report_problem(int $customerId, int $orderId, string $reason): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 250) {
        return ['ok' => false, 'error' => 'Tell us what is wrong (5 to 250 characters).'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $o = market_order_lock($pdo, $orderId);
    if (!$o || (int) $o['customer_id'] !== $customerId) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'That order does not exist.'];
    }
    if (!in_array($o['fulfilment'], ['SHIPPED', 'READY_FOR_COLLECTION'], true) || $o['dispute_at'] !== null) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $o['dispute_at'] !== null ? 'You already reported a problem with this order. We are looking at it.' : 'You can report a problem once the order has been sent or is ready to collect.'];
    }
    $pdo->prepare('UPDATE market_orders SET dispute_at = NOW(), dispute_reason = ? WHERE id = ?')->execute([$reason, $orderId]);
    market_history_add($pdo, $orderId, (string) $o['fulfilment'], (string) $o['fulfilment'], $customerId, 'CUSTOMER', 'Problem reported: ' . $reason);
    $pdo->commit();
    try {
        create_notification((int) $o['seller_id'], 'market_order', 'Problem reported on ' . $o['order_number'], $reason, '/org-market-order.php?id=' . $orderId);
        if (function_exists('gift_wd_alert_admins')) {
            gift_wd_alert_admins('OBI MARKET: a customer reported a problem with ' . $o['order_number'], $reason . ' (order #' . $orderId . ')');
        }
    } catch (Throwable $e) {
        error_log('[market] problem notification failed: ' . $e->getMessage());
    }
    return ['ok' => true];
}

/** Admin settles a reported problem: 'release' pays the seller (as if the customer confirmed), 'refund' refunds in full. @return array{ok:bool, error?:string} */
function market_order_admin_resolve(int $adminId, int $orderId, string $action, string $note, bool $restock = false): array
{
    $note = trim($note);
    if (!in_array($action, ['release', 'refund'], true) || $note === '') {
        return ['ok' => false, 'error' => 'Choose what to do and give a reason.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $o = market_order_lock($pdo, $orderId);
        if (!$o || !in_array($o['fulfilment'], ['SHIPPED', 'READY_FOR_COLLECTION', 'PROCESSING', 'NEW', 'REFUND_REQUESTED'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This order cannot be settled that way.'];
        }
        if ($action === 'release') {
            market_order_complete($pdo, $o, 'ADMIN', $adminId, 'An admin confirmed it: ' . $note);
        } else {
            market_refund_apply($pdo, $o, (int) $o['merchandise_ugx'] - (int) $o['refunded_merch_ugx'], true, $note, $restock, $adminId, 'ADMIN', 'REFUNDED');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_admin_action($adminId, 'market.order.' . $action, 'market_order', $orderId, ['note' => $note]);
    return ['ok' => true];
}

/**
 * The background job: an order the seller sent, that the customer never confirmed and never complained about, is confirmed after
 * MARKET_AUTO_RELEASE_DAYS days so the seller is paid. @return int how many were completed
 */
function market_auto_release_sweep(int $limit = 100): int
{
    if (!market_ready()) {
        return 0;
    }
    $st = db()->prepare("SELECT id FROM market_orders WHERE earnings_state = 'PENDING' AND fulfilment IN ('SHIPPED','READY_FOR_COLLECTION') AND release_after IS NOT NULL AND release_after <= ? AND dispute_at IS NULL ORDER BY id LIMIT " . (int) $limit);
    $st->execute([date('Y-m-d H:i:s')]);
    $done = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $o = market_order_lock($pdo, (int) $id);
            if ($o && $o['earnings_state'] === 'PENDING' && in_array($o['fulfilment'], ['SHIPPED', 'READY_FOR_COLLECTION'], true) && $o['dispute_at'] === null) {
                $released = market_order_complete($pdo, $o, 'AUTO', null);
                $pdo->commit();
                $done++;
                try {
                    create_notification((int) $o['seller_id'], 'market_order', 'Order ' . $o['order_number'] . ' confirmed', 'It was confirmed automatically. UGX ' . number_format($released) . ' is now available to withdraw.', '/org-market-order.php?id=' . $id);
                } catch (Throwable $e) {
                    error_log('[market] auto release notification failed: ' . $e->getMessage());
                }
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[market] auto release failed for order ' . $id . ': ' . $e->getMessage());
        }
    }
    return $done;
}

// ---------------------------------------------------------------------------------------------------- refunds

/**
 * Refunds part or all of a LOCKED, paid order, inside the caller's transaction. The commission is reversed in proportion, the seller's money is taken
 * off (from pending if the customer has not confirmed yet, from available otherwise: it can go negative and is paid back from future sales), and the
 * stock goes back only when asked and only for a full refund. The customer is paid back by hand; refund_paid_ugx tracks what was actually sent.
 * @return array{refund:int, commission:int, seller:int}
 */
function market_refund_apply(PDO $pdo, array $o, int $merchAmount, bool $includeDelivery, string $reason, bool $restock, ?int $actorId, string $role, string $finalFulfilment = 'REFUNDED'): array
{
    if (!in_array($o['payment_status'], ['PAID', 'PARTIALLY_REFUNDED'], true)) {
        throw new MarketStockException('Only a paid order can be refunded.');
    }
    $merch = (int) $o['merchandise_ugx'];
    $merchLeft = $merch - (int) $o['refunded_merch_ugx'];
    $deliveryLeft = (int) $o['delivery_ugx'] - max(0, (int) $o['refunded_ugx'] - (int) $o['refunded_merch_ugx']);
    $merchAmount = max(0, min($merchAmount, $merchLeft));
    $delivery = $includeDelivery ? max(0, $deliveryLeft) : 0;
    if ($merchAmount + $delivery <= 0) {
        throw new MarketStockException('There is nothing left to refund on this order.');
    }
    $commissionTotal = (int) $o['commission_ugx'];
    $before = $merch > 0 ? intdiv((int) $o['refunded_merch_ugx'] * $commissionTotal * 2 + $merch, 2 * $merch) : 0;
    $after = $merch > 0 ? intdiv(((int) $o['refunded_merch_ugx'] + $merchAmount) * $commissionTotal * 2 + $merch, 2 * $merch) : 0;
    $commissionBack = $after - $before;
    $sellerBack = $merchAmount - $commissionBack + $delivery;
    $refund = $merchAmount + $delivery;
    $newRefunded = (int) $o['refunded_ugx'] + $refund;
    $full = $newRefunded >= (int) $o['total_ugx'];
    if ($o['earnings_state'] === 'PENDING' || $o['earnings_state'] === 'AVAILABLE') {
        $pending = $o['earnings_state'] === 'PENDING';
        market_ledger_add($pdo, (int) $o['seller_id'], 'REFUND', (int) $o['id'], null, $pending ? -$sellerBack : 0, $pending ? 0 : -$sellerBack, 0, 0, -$commissionBack, 'refund-' . (int) $o['id'] . '-' . $newRefunded,
            'Refund on ' . $o['order_number'] . ': ' . mb_substr($reason, 0, 120), $actorId);
    }
    if ($restock && $full && $o['earnings_state'] !== 'NONE') {
        $it = $pdo->prepare('SELECT variant_id, quantity FROM market_order_items WHERE order_id = ? ORDER BY variant_id');
        $it->execute([(int) $o['id']]);
        foreach ($it->fetchAll() as $i) {
            market_stock_adjust($pdo, (int) $i['variant_id'], (int) $i['quantity'], 0, 'refund_restock', null, (int) $o['id'], $actorId);
        }
    }
    $state = $full && $o['earnings_state'] === 'PENDING' ? 'REVERSED' : $o['earnings_state'];
    $pdo->prepare('UPDATE market_orders SET refunded_ugx = ?, refunded_merch_ugx = refunded_merch_ugx + ?, payment_status = ?, fulfilment = ?, earnings_state = ? WHERE id = ?')
        ->execute([$newRefunded, $merchAmount, $full ? 'REFUNDED' : 'PARTIALLY_REFUNDED', $full ? $finalFulfilment : $o['fulfilment'], $state, (int) $o['id']]);
    market_history_add($pdo, (int) $o['id'], (string) $o['fulfilment'], $full ? $finalFulfilment : (string) $o['fulfilment'], $actorId, $role, ($full ? 'Refunded in full' : 'Partly refunded') . ' (UGX ' . number_format($refund) . '): ' . mb_substr($reason, 0, 150));
    try {
        create_notification((int) $o['customer_id'], 'market_order', $full ? 'Your order was refunded' : 'Part of your order was refunded', 'UGX ' . number_format($refund) . ' for order ' . $o['order_number'] . ' will be sent back to your phone.', '/market-order.php?id=' . (int) $o['id']);
        create_notification((int) $o['seller_id'], 'market_order', 'Refund on ' . $o['order_number'], 'UGX ' . number_format($sellerBack) . ' was taken off your earnings. ' . $reason, '/org-market-order.php?id=' . (int) $o['id']);
    } catch (Throwable $e) {
        error_log('[market] refund notification failed: ' . $e->getMessage());
    }
    return ['refund' => $refund, 'commission' => $commissionBack, 'seller' => $sellerBack];
}

/** Admin refunds part or all of a paid order. @return array{ok:bool, error?:string, refund?:int} */
function market_order_refund(int $adminId, int $orderId, int $merchAmount, bool $includeDelivery, string $reason, bool $restock = false): array
{
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Give a reason for the refund.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $o = market_order_lock($pdo, $orderId);
        if (!$o) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That order does not exist.'];
        }
        $r = market_refund_apply($pdo, $o, $merchAmount, $includeDelivery, trim($reason), $restock, $adminId, 'ADMIN');
        $pdo->commit();
    } catch (MarketStockException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_admin_action($adminId, 'market.order.refund', 'market_order', $orderId, ['refund' => $r['refund'], 'reason' => trim($reason)]);
    return ['ok' => true, 'refund' => $r['refund']];
}

/** Admin records that refund money was really sent to the customer by hand. @return array{ok:bool, error?:string} */
function market_refund_mark_paid(int $adminId, int $orderId, int $amount): array
{
    $o = market_order_find($orderId);
    if (!$o || $amount <= 0 || $amount > (int) $o['refunded_ugx'] - (int) $o['refund_paid_ugx']) {
        return ['ok' => false, 'error' => 'That is more than is still owed to this customer.'];
    }
    db()->prepare('UPDATE market_orders SET refund_paid_ugx = refund_paid_ugx + ? WHERE id = ?')->execute([$amount, $orderId]);
    log_admin_action($adminId, 'market.refund.paid', 'market_order', $orderId, ['amount' => $amount]);
    return ['ok' => true];
}

/** Refunds that are approved but not yet sent back to the customer, and orders paid after the stock was gone (money to return). @return list<array<string,mixed>> */
function market_refunds_owed(): array
{
    return db()->query("SELECT o.id, o.order_number, o.customer_id, u.name AS customer, c.phone, o.total_ugx, o.refunded_ugx, o.refund_paid_ugx,
                               IF(o.fulfilment = 'REFUND_REQUESTED' AND o.refunded_ugx = 0, o.total_ugx, o.refunded_ugx - o.refund_paid_ugx) AS owed_ugx, o.fulfilment
                        FROM market_orders o JOIN users u ON u.id = o.customer_id JOIN market_checkouts c ON c.id = o.checkout_id
                        WHERE (o.refunded_ugx > o.refund_paid_ugx) OR (o.fulfilment = 'REFUND_REQUESTED' AND o.payment_status = 'PAID' AND o.refunded_ugx = 0) ORDER BY o.id")->fetchAll();
}

// ---------------------------------------------------------------------------------------------------- the books check

/**
 * Proves the OBI MARKET books are consistent. Returns a list of problems (empty = all good):
 *  - every seller's balances equal the sum of their ledger rows, and nothing but a refund-after-withdrawal makes available negative
 *  - every paid order has exactly one SALE row of the right size, commission + seller share = merchandise, items add up, the checkout total equals its orders
 *  - every variant's reserved count equals what open (unpaid) checkouts really hold; stock is never negative
 * @return list<string>
 */
function market_reconcile(): array
{
    if (!market_ready()) {
        return [];
    }
    $problems = [];
    $pdo = db();
    foreach ($pdo->query('SELECT b.*, COALESCE(SUM(l.pending_delta), 0) p, COALESCE(SUM(l.available_delta), 0) a, COALESCE(SUM(l.reserved_delta), 0) r, COALESCE(SUM(l.withdrawn_delta), 0) w,
                                 COALESCE(SUM(CASE WHEN l.entry_type IN (\'SALE\',\'REFUND\') THEN l.pending_delta + l.available_delta END), 0) earned
                          FROM market_balances b LEFT JOIN market_ledger l ON l.seller_id = b.seller_id GROUP BY b.seller_id')->fetchAll() as $b) {
        $s = 'seller ' . $b['seller_id'];
        if ((int) $b['pending_ugx'] !== (int) $b['p']) { $problems[] = $s . ': pending balance ' . $b['pending_ugx'] . ' but the ledger adds up to ' . $b['p']; }
        if ((int) $b['available_ugx'] !== (int) $b['a']) { $problems[] = $s . ': available balance ' . $b['available_ugx'] . ' but the ledger adds up to ' . $b['a']; }
        if ((int) $b['reserved_ugx'] !== (int) $b['r']) { $problems[] = $s . ': reserved balance does not match the ledger'; }
        if ((int) $b['withdrawn_ugx'] !== (int) $b['w']) { $problems[] = $s . ': withdrawn total does not match the ledger'; }
        if ((int) $b['total_earned_ugx'] !== (int) $b['earned']) { $problems[] = $s . ': total earned does not match the ledger'; }
        if ((int) $b['pending_ugx'] < 0) { $problems[] = $s . ': pending balance is negative'; }
    }
    foreach ($pdo->query("SELECT o.*, (SELECT COUNT(*) FROM market_ledger l WHERE l.order_id = o.id AND l.entry_type = 'SALE') AS sale_rows,
                                 (SELECT COALESCE(SUM(l.pending_delta + l.available_delta), 0) FROM market_ledger l WHERE l.order_id = o.id AND l.entry_type = 'SALE') AS sale_amount,
                                 (SELECT COALESCE(SUM(l.platform_delta), 0) FROM market_ledger l WHERE l.order_id = o.id AND l.entry_type = 'SALE') AS sale_platform,
                                 (SELECT COALESCE(SUM(i.line_ugx), 0) FROM market_order_items i WHERE i.order_id = o.id) AS items_total
                          FROM market_orders o")->fetchAll() as $o) {
        $n = 'order ' . $o['order_number'];
        if ((int) $o['commission_ugx'] + (int) $o['seller_ugx'] !== (int) $o['merchandise_ugx']) { $problems[] = $n . ': commission + seller share is not the merchandise value'; }
        if ((int) $o['items_total'] !== (int) $o['merchandise_ugx']) { $problems[] = $n . ': its items add up to ' . $o['items_total'] . ' but the merchandise value is ' . $o['merchandise_ugx']; }
        $earns = in_array($o['earnings_state'], ['PENDING', 'AVAILABLE', 'REVERSED'], true);
        if ($earns && ((int) $o['sale_rows'] !== 1 || (int) $o['sale_amount'] !== (int) $o['total_ugx'] - (int) $o['commission_ugx'] || (int) $o['sale_platform'] !== (int) $o['commission_ugx'])) {
            $problems[] = $n . ': its sale was not recorded correctly in the ledger';
        }
        if (!$earns && (int) $o['sale_rows'] !== 0) { $problems[] = $n . ': has a ledger sale but no earnings'; }
        if ($o['payment_status'] === 'PAID' && $o['fulfilment'] === 'PENDING_PAYMENT') { $problems[] = $n . ': paid but still waiting for payment'; }
        if ((int) $o['refunded_ugx'] > (int) $o['total_ugx']) { $problems[] = $n . ': refunded more than it cost'; }
    }
    foreach ($pdo->query('SELECT c.id, c.reference, c.total_ugx, COALESCE(SUM(o.total_ugx), 0) AS orders_total FROM market_checkouts c LEFT JOIN market_orders o ON o.checkout_id = c.id GROUP BY c.id') as $c) {
        if ((int) $c['total_ugx'] !== (int) $c['orders_total']) { $problems[] = 'checkout ' . $c['reference'] . ': the total does not equal its orders'; }
    }
    foreach ($pdo->query("SELECT v.id, v.reserved, v.stock, COALESCE((SELECT SUM(i.quantity) FROM market_order_items i JOIN market_orders o ON o.id = i.order_id JOIN market_checkouts c ON c.id = o.checkout_id
                                    WHERE i.variant_id = v.id AND o.fulfilment = 'PENDING_PAYMENT' AND c.payment_status IN ('PENDING','PROCESSING')), 0) AS held
                          FROM market_variants v")->fetchAll() as $v) {
        if ((int) $v['reserved'] !== (int) $v['held']) { $problems[] = 'variant ' . $v['id'] . ': ' . $v['reserved'] . ' reserved but open checkouts hold ' . $v['held']; }
        if ((int) $v['stock'] < 0 || (int) $v['reserved'] < 0) { $problems[] = 'variant ' . $v['id'] . ': negative stock'; }
    }
    return $problems;
}
