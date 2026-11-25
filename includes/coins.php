<?php
declare(strict_types=1);

/**
 * OBI COINS (ADR-002): the virtual currency users buy with real money and spend to send OBI GIFTS.
 *
 * What this file guarantees:
 *  - A wallet balance changes ONLY together with an append-only coin_ledger row, in one database transaction, and can never go below zero.
 *  - Every operation happens once: coin_ledger has a unique key per (type, related thing), and a purchase is settled under a row lock,
 *    so a repeated poll, a replayed callback or a double tap cannot credit or spend twice.
 *  - Coins are credited only after the SERVER has asked ioTec and seen the payment succeed, for the right amount. Never on the browser's word.
 *  - A gift sent with coins is valued in UGX at the accounting rate in force at that moment; the rate, the coins, the UGX value and the
 *    40/60 split are all stored on the gift and never recomputed (changing the rate or the split later cannot touch history).
 *  - Nothing can be bought or sent until an admin set the accounting rate, priced at least one package and switched coins on.
 *  - Coins cannot be withdrawn or sent from one user to another. Organizer earnings stay in UGX (includes/gifts.php, gift-payouts.php).
 *  - Gifts and coins never touch orders, tickets, QR codes or ticket payouts.
 *  - A development computer never starts or checks a real payment (same rules as gifts: gift_skip_real_check, GIFTS_FAKE_PAYMENTS).
 */

const COIN_PENDING_EXPIRY_MINUTES = 10;
const COIN_OPEN_PURCHASE_LIMIT = 3;        // payment prompts a user may have waiting at once
const COIN_PHONE_HOURLY_LIMIT = 6;         // prompts to one phone number per hour
const COIN_MIN_PURCHASE_UGX = 500;
const COIN_MAX_PURCHASE_UGX = 500000;      // one purchase
const COIN_DAILY_PURCHASE_CAP_UGX = 1000000;   // per user per 24 hours

// ---------------------------------------------------------------------------------------------------- readiness and the switches

/** Migration 046 has run. */
function coins_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = gifts_ready()
                && (bool) db()->query("SHOW TABLES LIKE 'coin_wallets'")->fetch()
                && (bool) db()->query("SHOW COLUMNS FROM gift_transactions LIKE 'payment_source'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Coins are really on: the switch, the keys, an accounting rate and at least one package with a price. */
function coins_enabled(): bool
{
    return coins_ready() && payouts_iotec_configured() && get_setting('coins_enabled', '0') === '1' && coin_rate_current() !== null && coin_packages_active() !== [];
}

function coin_set_enabled(int $adminId, bool $on): void
{
    set_setting($adminId, 'coins_enabled', $on ? '1' : '0');
}

/** What is still missing before the switch can work (for the admin screen). @return list<string> */
function coins_missing_setup(): array
{
    $m = [];
    if (!coins_ready()) { return ['The database update (migration 046) has not been run.']; }
    if (!payouts_iotec_configured()) { $m[] = 'the ioTec keys are missing from the server config'; }
    if (coin_rate_current() === null) { $m[] = 'the accounting rate (UGX per OBI COIN) is not set'; }
    if (coin_packages_active() === []) { $m[] = 'no recharge package is active with a UGX price'; }
    return $m;
}

// ---------------------------------------------------------------------------------------------------- the accounting rate

/** UGX value of coins: whole shillings, halves round up. $rateE4 is "UGX per coin x 10,000" (10000 = UGX 1.0000 per coin). */
function coin_unit_ugx(int $coins, int $rateE4): int
{
    return intdiv($coins * $rateE4 + 5000, 10000);
}

/** The rate in force now: ['version', 'rate_e4', 'ugx_per_coin'(display)] or null when none has been set. */
function coin_rate_current(): ?array
{
    if (!coins_ready_tables()) {
        return null;
    }
    $row = db()->query('SELECT version, rate_e4 FROM coin_accounting_rates WHERE effective_from <= NOW() ORDER BY effective_from DESC, version DESC LIMIT 1')->fetch();
    return $row ? ['version' => (int) $row['version'], 'rate_e4' => (int) $row['rate_e4'], 'ugx_per_coin' => (int) $row['rate_e4'] / 10000] : null;
}

/** Lighter than coins_ready(): just the rate table (used while the rest is being checked). */
function coins_ready_tables(): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool) db()->query("SHOW TABLES LIKE 'coin_accounting_rates'")->fetch(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Parses "1", "0.5", "1.2500" (at most 4 decimals) into rate_e4, or null when it is not a valid positive rate. */
function coin_parse_rate(string $s): ?int
{
    $s = trim(str_replace(',', '.', $s));
    if (!preg_match('/^(\d{1,6})(?:\.(\d{1,4}))?$/', $s, $m)) {
        return null;
    }
    $e4 = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '0', 4, '0');
    return $e4 >= 1 && $e4 <= 1000000 * 10000 ? $e4 : null;   // UGX 0.0001 up to UGX 1,000,000 per coin
}

/** @return list<array> newest first */
function coin_rate_history(): array
{
    return coins_ready_tables() ? db()->query('SELECT r.*, u.name AS admin_name FROM coin_accounting_rates r LEFT JOIN users u ON u.id = r.changed_by ORDER BY r.version DESC')->fetchAll() : [];
}

/**
 * Sets a new accounting rate version. It applies to NEW gifts from its start time; old gifts keep their rate. Needs a reason, never in the past.
 * @return array{ok:bool, version?:int, error?:string}
 */
function coin_rate_set(int $adminId, string $ugxPerCoin, string $reason, ?string $effectiveFrom = null): array
{
    if (!coins_ready_tables()) {
        return ['ok' => false, 'error' => 'The database update (migration 046) has not been run.'];
    }
    $e4 = coin_parse_rate($ugxPerCoin);
    if ($e4 === null) {
        return ['ok' => false, 'error' => 'Enter the rate as UGX per OBI COIN, a number above zero with at most 4 decimals (for example 1 or 0.75).'];
    }
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Give a reason for the change.'];
    }
    $at = date('Y-m-d H:i:s');
    if ($effectiveFrom !== null && $effectiveFrom !== '') {
        $ts = strtotime($effectiveFrom);
        if ($ts === false || $ts < time() - 60) {
            return ['ok' => false, 'error' => 'The start time cannot be in the past.'];
        }
        $at = date('Y-m-d H:i:s', $ts);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $v = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) + 1 FROM coin_accounting_rates FOR UPDATE')->fetchColumn();
        $pdo->prepare('INSERT INTO coin_accounting_rates (version, rate_e4, effective_from, changed_by, reason) VALUES (?, ?, ?, ?, ?)')->execute([$v, $e4, $at, $adminId, mb_substr($reason, 0, 250)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_admin_action($adminId, 'coins.rate', 'coin_rate', $v, ['ugx_per_coin' => $e4 / 10000, 'effective_from' => $at, 'reason' => $reason]);
    return ['ok' => true, 'version' => $v];
}

// ---------------------------------------------------------------------------------------------------- packages

/** Packages a user can buy right now: active AND priced. */
function coin_packages_active(): array
{
    if (!coins_ready()) {
        return [];
    }
    return db()->query('SELECT * FROM coin_packages WHERE is_active = 1 AND price_ugx IS NOT NULL AND price_ugx > 0 ORDER BY sort_order, id')->fetchAll();
}

function coin_package_find(int $id, bool $activeOnly = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM coin_packages WHERE id = ?' . ($activeOnly ? ' AND is_active = 1 AND price_ugx IS NOT NULL AND price_ugx > 0' : ''));
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Admin: add or edit a package. A package cannot be activated without a UGX price.
 * @return array{ok:bool, id?:int, error?:string}
 */
function coin_admin_save_package(int $adminId, ?int $id, array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) {
        return ['ok' => false, 'error' => 'Give the package a name (up to 60 letters).'];
    }
    $coins = filter_var($in['coins'] ?? '', FILTER_VALIDATE_INT);
    $bonus = ($in['bonus_coins'] ?? '') === '' ? 0 : filter_var($in['bonus_coins'], FILTER_VALIDATE_INT);
    if ($coins === false || $coins < 1 || $coins > 100000000) {
        return ['ok' => false, 'error' => 'The number of OBI COINS must be a whole number above zero.'];
    }
    if ($bonus === false || $bonus < 0 || $bonus > 100000000) {
        return ['ok' => false, 'error' => 'Bonus OBI COINS must be a whole number, or empty for none.'];
    }
    $priceRaw = trim((string) ($in['price_ugx'] ?? ''));
    $price = null;
    if ($priceRaw !== '') {
        $price = filter_var($priceRaw, FILTER_VALIDATE_INT);
        if ($price === false || $price < 1 || $price > COIN_MAX_PURCHASE_UGX) {
            return ['ok' => false, 'error' => 'The UGX price must be a whole number from 1 to ' . number_format(COIN_MAX_PURCHASE_UGX) . '.'];
        }
    }
    $active = !empty($in['is_active']) ? 1 : 0;
    if ($active && $price === null) {
        return ['ok' => false, 'error' => 'Set the UGX price before the package can be switched on.'];
    }
    $featured = !empty($in['is_featured']) ? 1 : 0;
    $sort = (int) ($in['sort_order'] ?? 0);
    $desc = mb_substr(trim((string) ($in['description'] ?? '')), 0, 250);
    $pdo = db();
    if ($id) {
        $before = coin_package_find($id);
        if (!$before) {
            return ['ok' => false, 'error' => 'That package does not exist.'];
        }
        $pdo->prepare('UPDATE coin_packages SET name = ?, description = ?, coins = ?, bonus_coins = ?, price_ugx = ?, is_active = ?, is_featured = ?, sort_order = ? WHERE id = ?')
            ->execute([$name, $desc, $coins, $bonus, $price, $active, $featured, $sort, $id]);
        log_admin_action($adminId, 'coins.package.edit', 'coin_package', $id, ['from' => ['coins' => (int) $before['coins'], 'bonus' => (int) $before['bonus_coins'], 'price_ugx' => $before['price_ugx'], 'active' => (int) $before['is_active']],
            'to' => ['coins' => $coins, 'bonus' => $bonus, 'price_ugx' => $price, 'active' => $active]]);
        return ['ok' => true, 'id' => $id];
    }
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'package';
    $base = $slug; $i = 2;
    while ($pdo->query('SELECT 1 FROM coin_packages WHERE slug = ' . $pdo->quote($slug))->fetchColumn()) { $slug = $base . '-' . $i++; }
    $pdo->prepare('INSERT INTO coin_packages (slug, name, description, coins, bonus_coins, price_ugx, is_active, is_featured, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $name, $desc, $coins, $bonus, $price, $active, $featured, $sort]);
    $newId = (int) $pdo->lastInsertId();
    log_admin_action($adminId, 'coins.package.add', 'coin_package', $newId, ['coins' => $coins, 'bonus' => $bonus, 'price_ugx' => $price, 'active' => $active]);
    return ['ok' => true, 'id' => $newId];
}

// ---------------------------------------------------------------------------------------------------- the wallet and its ledger

function coin_wallet_ensure(PDO $pdo, int $userId): void
{
    $pdo->prepare('INSERT INTO coin_wallets (user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_id = user_id')->execute([$userId]);
}

/** A user's wallet numbers (zeros when they have none yet). */
function coin_wallet(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM coin_wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    $r = $stmt->fetch();
    return ['balance' => (int) ($r['balance'] ?? 0), 'purchased' => (int) ($r['total_purchased'] ?? 0), 'bonus' => (int) ($r['total_bonus'] ?? 0),
            'spent' => (int) ($r['total_spent'] ?? 0), 'status' => (string) ($r['status'] ?? 'ACTIVE')];
}

/**
 * The ONLY place a wallet balance changes. Must run inside the caller's database transaction. It locks the wallet row, refuses to go below
 * zero, updates the balance and the running totals, and writes the ledger row (unique per type + related thing, so it can only happen once).
 *
 * @param string $type PURCHASE | BONUS | GIFT_SPEND | GIFT_REFUND | PURCHASE_REVERSAL | BONUS_REVERSAL | ADJUSTMENT
 * @param int    $coins signed: credit +, debit -
 * @return int the ledger row id
 */
function coin_wallet_apply(PDO $pdo, int $userId, string $type, int $coins, string $reference, ?string $relatedType, ?int $relatedId, ?string $note = null, ?int $adminId = null): int
{
    if ($coins === 0 || !$pdo->inTransaction()) {
        throw new LogicException('A wallet change needs a non-zero amount and an open transaction.');
    }
    coin_wallet_ensure($pdo, $userId);
    $w = $pdo->prepare('SELECT balance FROM coin_wallets WHERE user_id = ? FOR UPDATE');
    $w->execute([$userId]);
    $balance = (int) $w->fetchColumn();
    $after = $balance + $coins;
    if ($after < 0) {
        throw new CoinInsufficientException($balance, -$coins);
    }
    $bump = match ($type) {
        'PURCHASE' => 'total_purchased = total_purchased + ' . $coins,
        'BONUS' => 'total_bonus = total_bonus + ' . $coins,
        'GIFT_SPEND', 'GIFT_REFUND' => 'total_spent = total_spent - ' . $coins,   // a spend is negative: spent goes up; a refund is positive: spent goes down
        'PURCHASE_REVERSAL' => 'total_purchased = total_purchased + ' . $coins,   // negative
        'BONUS_REVERSAL' => 'total_bonus = total_bonus + ' . $coins,              // negative
        default => 'updated_at = updated_at',
    };
    $pdo->prepare("UPDATE coin_wallets SET balance = ?, $bump WHERE user_id = ?")->execute([$after, $userId]);
    $pdo->prepare('INSERT INTO coin_ledger (user_id, entry_type, coins, balance_after, reference, related_type, related_id, note, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $type, $coins, $after, $reference, $relatedType, $relatedId, $note !== null ? mb_substr($note, 0, 250) : null, $adminId]);
    return (int) $pdo->lastInsertId();
}

class CoinInsufficientException extends RuntimeException
{
    public int $balance;
    public int $needed;
    public function __construct(int $balance, int $needed)
    {
        parent::__construct('Not enough OBI COINS.');
        $this->balance = $balance;
        $this->needed = $needed;
    }
}

function coin_new_reference(string $prefix): string
{
    return $prefix . '-' . strtoupper(base_convert((string) time(), 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/** A user's wallet history, newest first (cursor = last id seen). @return array{rows:list<array>, next:?int} */
function coin_wallet_history(int $userId, int $limit = 25, int $before = 0): array
{
    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare('SELECT id, entry_type, coins, balance_after, reference, related_type, related_id, note, created_at FROM coin_ledger WHERE user_id = ?' . ($before > 0 ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
    $stmt->execute($before > 0 ? [$userId, $before] : [$userId]);
    $rows = $stmt->fetchAll();
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $next = (int) $rows[count($rows) - 1]['id'];
    }
    $labels = ['PURCHASE' => 'OBI COINS bought', 'BONUS' => 'Bonus OBI COINS', 'GIFT_SPEND' => 'Gift sent', 'GIFT_REFUND' => 'Gift refunded', 'PURCHASE_REVERSAL' => 'Purchase reversed',
               'BONUS_REVERSAL' => 'Bonus reversed', 'ADJUSTMENT' => 'Adjustment by obitickets'];
    foreach ($rows as &$r) {
        $r['label'] = $labels[$r['entry_type']] ?? $r['entry_type'];
        $r['coins'] = (int) $r['coins'];
        $r['balance_after'] = (int) $r['balance_after'];
    }
    unset($r);
    return ['rows' => $rows, 'next' => $next];
}

/**
 * Admin: add or remove coins with a reason (kept in the audit log, shown in the user's history, the user is told). Cannot take a wallet below zero.
 * @return array{ok:bool, balance?:int, error?:string}
 */
function coin_adjust(int $adminId, int $userId, int $coins, string $reason): array
{
    $reason = trim($reason);
    if ($coins === 0 || abs($coins) > 100000000) {
        return ['ok' => false, 'error' => 'Enter a number of OBI COINS to add (positive) or remove (negative).'];
    }
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Give a reason for the adjustment.'];
    }
    $u = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role <> 'ADMIN'");
    $u->execute([$userId]);
    if (!$u->fetchColumn()) {
        return ['ok' => false, 'error' => 'That user does not exist.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lid = coin_wallet_apply($pdo, $userId, 'ADJUSTMENT', $coins, coin_new_reference('CAD'), null, null, $reason, $adminId);
        $after = (int) $pdo->query('SELECT balance FROM coin_wallets WHERE user_id = ' . $userId)->fetchColumn();
        $pdo->commit();
    } catch (CoinInsufficientException $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'That would take the wallet below zero (it holds ' . number_format($e->balance) . ' OBI COINS).'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_admin_action($adminId, 'coins.adjust', 'coin_wallet', $userId, ['coins' => $coins, 'reason' => $reason, 'ledger' => $lid]);
    try {
        create_notification($userId, 'coins', 'Your OBI COINS were adjusted', ($coins > 0 ? number_format($coins) . ' OBI COINS were added' : number_format(-$coins) . ' OBI COINS were removed') . ' (' . $reason . ').', '/wallet.php');
    } catch (Throwable $e) {
        error_log('[coins] adjustment notification failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'balance' => $after];
}

// ---------------------------------------------------------------------------------------------------- buying coins

/**
 * A user starts buying a package: checked, recorded, and an ioTec prompt is sent to their phone. The coins arrive only when the server sees ioTec
 * report the payment (coin_purchase_resolve), never on the browser's word.
 *
 * @param callable|null $collect test hook with the signature of iotec_initiate_collection(); null = real ioTec (refused on a development computer)
 * @return array{ok:bool, error?:string, id?:int, reference?:string, price?:int, coins?:int, bonus?:int, reused?:bool}
 */
function coin_purchase_start(array $user, int $packageId, string $phone, ?string $idempotencyKey = null, ?string $method = null, ?callable $collect = null): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e];
    if (!coins_ready() || !coins_enabled()) {
        return $fail('OBI COINS is not available yet.');
    }
    if ($collect === null && gift_is_development()) {
        return $fail('Real payments are switched off on this development computer. Turn on GIFTS_FAKE_PAYMENTS=1 in .env to try OBI COINS with pretend payments.');
    }
    $userId = (int) $user['id'];
    if (!payout_valid_phone($phone)) {
        return $fail('Enter a valid MTN or Airtel number, like 0772 123 456.');
    }
    $method = gift_detect_method($phone) ?? (in_array($method, ['MTN_MOMO', 'AIRTEL_MONEY'], true) ? $method : null);
    if ($method === null) {
        return $fail('Use an MTN or Airtel mobile money number.');
    }
    $pkg = coin_package_find($packageId, true);
    if (!$pkg) {
        return $fail('That package is not available.');
    }
    $price = (int) $pkg['price_ugx'];
    if ($price < COIN_MIN_PURCHASE_UGX || $price > COIN_MAX_PURCHASE_UGX) {
        return $fail('That package cannot be bought right now.');
    }
    $idem = $idempotencyKey !== null && $idempotencyKey !== '' ? mb_substr($idempotencyKey, 0, 64) : null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);   // one purchase start at a time per user: limits cannot be raced
        if ($idem !== null) {
            $dup = $pdo->prepare('SELECT id, reference, price_ugx, coins, bonus_coins FROM coin_purchases WHERE user_id = ? AND idempotency_key = ?');
            $dup->execute([$userId, $idem]);
            if ($row = $dup->fetch()) {
                $pdo->commit();
                return ['ok' => true, 'id' => (int) $row['id'], 'reference' => $row['reference'], 'price' => (int) $row['price_ugx'], 'coins' => (int) $row['coins'], 'bonus' => (int) $row['bonus_coins'], 'reused' => true];
            }
        }
        $open = $pdo->prepare("SELECT COUNT(*) FROM coin_purchases WHERE user_id = ? AND payment_status IN ('PENDING','PROCESSING') AND created_at > NOW() - INTERVAL " . (int) COIN_PENDING_EXPIRY_MINUTES . ' MINUTE');
        $open->execute([$userId]);
        if ((int) $open->fetchColumn() >= COIN_OPEN_PURCHASE_LIMIT) {
            $pdo->rollBack();
            return $fail('You have payments waiting for approval on your phone. Approve or wait a few minutes.');
        }
        $day = $pdo->prepare("SELECT COALESCE(SUM(price_ugx), 0) FROM coin_purchases WHERE user_id = ? AND created_at >= NOW() - INTERVAL 24 HOUR AND payment_status IN ('PENDING','PROCESSING','SUCCESSFUL')");
        $day->execute([$userId]);
        if ((int) $day->fetchColumn() + $price > COIN_DAILY_PURCHASE_CAP_UGX) {
            $pdo->rollBack();
            return $fail('You have reached the daily limit for buying OBI COINS (UGX ' . number_format(COIN_DAILY_PURCHASE_CAP_UGX) . '). Try again tomorrow.');
        }
        $phoneN = iotec_normalize_phone($phone);
        $byPhone = $pdo->prepare('SELECT COUNT(*) FROM coin_purchases WHERE phone = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $byPhone->execute([$phoneN]);
        if ((int) $byPhone->fetchColumn() >= COIN_PHONE_HOURLY_LIMIT) {
            $pdo->rollBack();
            return $fail('Too many payments were started for this number in the last hour. Please try again later.');
        }
        $reference = coin_new_reference('CPU');
        $pdo->prepare('INSERT INTO coin_purchases (reference, idempotency_key, user_id, package_id, package_name, coins, bonus_coins, price_ugx, payment_method, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $idem, $userId, (int) $pkg['id'], $pkg['name'], (int) $pkg['coins'], (int) $pkg['bonus_coins'], $price, $method, $phoneN]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[coins] could not create purchase: ' . $e->getMessage());
        return $fail('Something went wrong. Please try again.');
    }
    try {
        $res = ($collect ?? 'iotec_initiate_collection')((float) $price, $phone, 'COIN-' . $id, 'obitickets OBI COINS ' . $reference);
        $pdo->prepare("UPDATE coin_purchases SET provider_reference = ?, payment_status = 'PROCESSING' WHERE id = ? AND payment_status = 'PENDING'")->execute([(string) $res['transactionId'], $id]);
    } catch (Throwable $e) {
        error_log('[coins] initiate collection failed for purchase ' . $id . ': ' . $e->getMessage());
        coin_purchase_fail($id, "We couldn't start the mobile money payment. Please try again.");
        return $fail("We couldn't start the mobile money payment. Please try again.");
    }
    gift_log(null, 'coin_purchase_started', 'purchase ' . $id . ' UGX ' . $price . ' via ' . $method);
    return ['ok' => true, 'id' => $id, 'reference' => $reference, 'price' => $price, 'coins' => (int) $pkg['coins'], 'bonus' => (int) $pkg['bonus_coins']];
}

function coin_purchase_fail(int $id, string $message, string $status = 'FAILED'): bool
{
    if (!in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
        $status = 'FAILED';
    }
    $u = db()->prepare("UPDATE coin_purchases SET payment_status = ?, status_message = ? WHERE id = ? AND payment_status IN ('PENDING','PROCESSING')");
    $u->execute([$status, mb_substr($message, 0, 250), $id]);
    if ($u->rowCount() !== 1) {
        return false;
    }
    try {
        $r = db()->prepare('SELECT user_id, price_ugx FROM coin_purchases WHERE id = ?');
        $r->execute([$id]);
        if ($row = $r->fetch()) {
            create_notification((int) $row['user_id'], 'coins', 'OBI COINS payment not completed', 'Your payment of UGX ' . number_format((int) $row['price_ugx']) . ' was not completed, so no OBI COINS were added and you were not charged.', '/wallet.php');
        }
    } catch (Throwable $e) {
        error_log('[coins] failed notification failed: ' . $e->getMessage());
    }
    return true;
}

/**
 * Credits the coins for a paid purchase, exactly once: the row is locked, must still be waiting (or expired a moment ago with the money arriving
 * late), and the wallet credit + ledger rows + status change happen in ONE transaction.
 */
function coin_purchase_settle(int $id, ?string $message = null, ?int $gatewayFee = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM coin_purchases WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p || !in_array($p['payment_status'], ['PENDING', 'PROCESSING', 'EXPIRED'], true)) {
            $pdo->rollBack();
            return false;
        }
        $uid = (int) $p['user_id'];
        $fee = $gatewayFee !== null && $gatewayFee > 0 ? $gatewayFee : null;
        $pdo->prepare("UPDATE coin_purchases SET payment_status = 'SUCCESSFUL', status_message = ?, gateway_fee_ugx = ?, paid_at = NOW() WHERE id = ?")
            ->execute([$message !== null ? mb_substr($message, 0, 250) : null, $fee, $id]);
        coin_wallet_apply($pdo, $uid, 'PURCHASE', (int) $p['coins'], 'CPC-' . $id, 'purchase', $id, 'Bought ' . $p['package_name'] . ' (' . $p['reference'] . ')');
        if ((int) $p['bonus_coins'] > 0) {
            coin_wallet_apply($pdo, $uid, 'BONUS', (int) $p['bonus_coins'], 'CPB-' . $id, 'purchase', $id, 'Bonus with ' . $p['package_name']);
        }
        $balance = (int) $pdo->query('SELECT balance FROM coin_wallets WHERE user_id = ' . $uid)->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    gift_log(null, 'coin_purchase_settled', 'purchase ' . $id . ' credited ' . ((int) $p['coins'] + (int) $p['bonus_coins']) . ' coins');
    try {
        create_notification($uid, 'coins', 'OBI COINS added', number_format((int) $p['coins'] + (int) $p['bonus_coins']) . ' OBI COINS were added to your wallet. Your balance is ' . number_format($balance) . '.', '/wallet.php');
    } catch (Throwable $e) {
        error_log('[coins] success notification failed: ' . $e->getMessage());
    }
    return true;
}

/**
 * Asks ioTec about one waiting purchase and credits, fails or expires it. Safe to call from a page poll AND the cron sweep.
 * @param callable|null $statusCheck test hook with the signature of iotec_check_collection_status()
 * @return array{status:string, message:?string, credited:bool}   status: PENDING | PAID | FAILED
 */
function coin_purchase_resolve(array $p, ?callable $statusCheck = null): array
{
    $out = static fn (string $s, ?string $m = null, bool $c = false): array => ['status' => $s, 'message' => $m, 'credited' => $c];
    $state = (string) $p['payment_status'];
    if (in_array($state, ['SUCCESSFUL', 'REVERSED'], true)) {
        return $out('PAID', null, true);
    }
    if (in_array($state, ['FAILED', 'CANCELLED'], true)) {
        return $out('FAILED', $p['status_message']);
    }
    $stale = strtotime((string) $p['created_at']) < time() - COIN_PENDING_EXPIRY_MINUTES * 60;
    if (empty($p['provider_reference'])) {
        if ($stale && $state !== 'EXPIRED') {
            coin_purchase_fail((int) $p['id'], 'The payment request expired. Please try again.', 'EXPIRED');
            return $out('FAILED', 'The payment request expired. Please try again.');
        }
        return $state === 'EXPIRED' ? $out('FAILED', $p['status_message']) : $out('PENDING');
    }
    if ($skip = gift_skip_real_check($statusCheck, (string) $p['provider_reference'], gift_is_development(), gift_fake_allowed())) {
        if ($skip === 'fake_reference') {
            gift_log(null, 'fake_reference_ignored', 'purchase ' . $p['id'] . ': a pretend payment reference was seen where pretend payments are not allowed');
        }
        return $out($state === 'EXPIRED' ? 'FAILED' : 'PENDING', $state === 'EXPIRED' ? $p['status_message'] : null);
    }
    try {
        $r = ($statusCheck ?? 'iotec_check_collection_status')((string) $p['provider_reference']);
    } catch (Throwable $e) {
        error_log('[coins] status check failed for purchase ' . $p['id'] . ': ' . $e->getMessage());
        return $state === 'EXPIRED' ? $out('FAILED', $p['status_message']) : $out('PENDING');
    }
    $status = (string) ($r['status'] ?? '');
    if ($status === 'Success') {
        // the amount ioTec says it collected must be the price of the package; otherwise a person looks at it first
        if (isset($r['amount']) && $r['amount'] !== null && (int) round((float) $r['amount']) !== (int) $p['price_ugx']) {
            gift_log(null, 'coin_amount_mismatch', 'purchase ' . $p['id'] . ': ioTec collected ' . $r['amount'] . ', the package costs ' . $p['price_ugx']);
            return $out('PENDING', 'This payment is being checked.');
        }
        $fee = isset($r['charge']) && $r['charge'] !== null ? (int) round((float) $r['charge']) : null;
        coin_purchase_settle((int) $p['id'], $r['statusMessage'] ?? null, $fee);
        return $out('PAID', $r['statusMessage'] ?? null, true);
    }
    if ($state === 'EXPIRED') {
        return $out('FAILED', $p['status_message']);
    }
    if ($status === 'Failed') {
        $msg = (string) ($r['statusMessage'] ?? 'The payment was not completed.');
        coin_purchase_fail((int) $p['id'], $msg);
        return $out('FAILED', $msg);
    }
    if ($stale) {
        coin_purchase_fail((int) $p['id'], 'The payment request expired. Please try again.', 'EXPIRED');
        return $out('FAILED', 'The payment request expired. Please try again.');
    }
    return $out('PENDING', $r['statusMessage'] ?? null);
}

/** The buyer's page asks how their purchase is doing (only their own). */
function coin_purchase_poll(int $id, int $userId, ?callable $statusCheck = null): array
{
    $stmt = db()->prepare('SELECT * FROM coin_purchases WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $p = $stmt->fetch();
    if (!$p) {
        throw new RuntimeException('Purchase not found.');
    }
    $res = coin_purchase_resolve($p, $statusCheck);
    $res['balance'] = coin_wallet($userId)['balance'];
    return $res;
}

/**
 * Cron: settles purchases whose buyer closed the app before the payment finished, expires prompts nobody approved, and re-checks purchases that
 * expired in the last 24 hours (money can arrive late).
 * @return array{checked:int, credited:int}
 */
function coin_purchase_sweep(int $limit = 50, ?callable $statusCheck = null): array
{
    if (!coins_ready()) {
        return ['checked' => 0, 'credited' => 0];
    }
    $rows = db()->query("SELECT * FROM coin_purchases
                         WHERE payment_status IN ('PENDING','PROCESSING') OR (payment_status = 'EXPIRED' AND provider_reference IS NOT NULL AND created_at > NOW() - INTERVAL 24 HOUR)
                         ORDER BY id LIMIT " . (int) $limit)->fetchAll();
    $credited = 0;
    foreach ($rows as $p) {
        $before = $p['payment_status'];
        $res = coin_purchase_resolve($p, $statusCheck);
        if ($res['status'] === 'PAID' && $before !== 'SUCCESSFUL') {
            $credited++;
        }
    }
    return ['checked' => count($rows), 'credited' => $credited];
}

/**
 * Admin: the payment provider reversed a purchase. The coins come back out of the wallet, but only if they are still there: coins already spent
 * on gifts are never taken silently into a negative balance, the case is flagged for a person to decide (and the wallet can be frozen).
 * @return array{ok:bool, error?:string, flagged?:bool}
 */
function coin_purchase_reverse(int $id, int $adminId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Give a reason for the reversal.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM coin_purchases WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p || $p['payment_status'] !== 'SUCCESSFUL') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Only a paid purchase can be reversed, and only once.'];
        }
        $uid = (int) $p['user_id'];
        coin_wallet_ensure($pdo, $uid);
        $bal = $pdo->prepare('SELECT balance FROM coin_wallets WHERE user_id = ? FOR UPDATE');
        $bal->execute([$uid]);
        $have = (int) $bal->fetchColumn();
        $total = (int) $p['coins'] + (int) $p['bonus_coins'];
        if ($have < $total) {
            $pdo->rollBack();
            gift_log(null, 'coin_purchase_reverse_blocked', 'purchase ' . $id . ': ' . ($total - $have) . ' of its coins were already spent; wallet holds ' . $have);
            log_admin_action($adminId, 'coins.purchase.reverse_blocked', 'coin_purchase', $id, ['reason' => $reason, 'coins' => $total, 'wallet_holds' => $have]);
            return ['ok' => false, 'flagged' => true, 'error' => 'Some of these OBI COINS were already spent on gifts (the wallet holds ' . number_format($have) . ' of the ' . number_format($total) . '). Nothing was changed. Decide what to do (for example freeze the wallet or adjust it by hand).'];
        }
        coin_wallet_apply($pdo, $uid, 'PURCHASE_REVERSAL', -(int) $p['coins'], 'CPR-' . $id, 'purchase', $id, 'Payment reversed: ' . mb_substr($reason, 0, 120), $adminId);
        if ((int) $p['bonus_coins'] > 0) {
            coin_wallet_apply($pdo, $uid, 'BONUS_REVERSAL', -(int) $p['bonus_coins'], 'CBR-' . $id, 'purchase', $id, 'Payment reversed: ' . mb_substr($reason, 0, 120), $adminId);
        }
        $pdo->prepare("UPDATE coin_purchases SET payment_status = 'REVERSED', reversed_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_admin_action($adminId, 'coins.purchase.reverse', 'coin_purchase', $id, ['reason' => $reason, 'coins' => $total, 'price_ugx' => (int) $p['price_ugx']]);
    try {
        create_notification($uid, 'coins', 'An OBI COINS purchase was reversed', number_format($total) . ' OBI COINS were taken off your wallet because the payment of UGX ' . number_format((int) $p['price_ugx']) . ' was reversed.', '/wallet.php');
    } catch (Throwable $e) {
        error_log('[coins] reversal notification failed: ' . $e->getMessage());
    }
    return ['ok' => true];
}

/** Admin: freeze or unfreeze a wallet (a frozen wallet cannot spend; it can still be credited, e.g. by a refund). */
function coin_wallet_set_status(int $adminId, int $userId, string $status, string $reason): array
{
    if (!in_array($status, ['ACTIVE', 'FROZEN'], true) || trim($reason) === '') {
        return ['ok' => false, 'error' => 'Choose a status and give a reason.'];
    }
    $pdo = db();
    coin_wallet_ensure($pdo, $userId);
    $pdo->prepare('UPDATE coin_wallets SET status = ? WHERE user_id = ?')->execute([$status, $userId]);
    log_admin_action($adminId, 'coins.wallet.' . strtolower($status), 'coin_wallet', $userId, ['reason' => trim($reason)]);
    return ['ok' => true];
}

// ---------------------------------------------------------------------------------------------------- sending a gift with coins

/**
 * Sends a gift from the sender's wallet. Everything happens in ONE database transaction: the wallet is locked, the balance checked, the coins
 * debited, the gift recorded with its rate and its 40/60 split, the ledger rows written and the organizer's UGX gift balance credited. If anything
 * fails nothing is left half done. There is no payment prompt, so nothing waits and nothing can be left pending.
 *
 * @return array{ok:bool, error?:string, insufficient?:bool, balance?:int, needed?:int, id?:int, reference?:string, gross?:int, coins?:int, balance_after?:int, reused?:bool, public_message?:?string}
 */
function coin_gift_send(array $sender, int $organizerId, int $giftId, int $quantity, bool $anonymous, ?int $postId = null, ?string $idempotencyKey = null): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e];
    if (!coins_ready() || !coins_enabled() || !gifts_ready()) {
        return $fail('OBI GIFTS paid with OBI COINS is not available yet.');
    }
    $senderId = (int) $sender['id'];
    if ($quantity < 1 || $quantity > GIFT_MAX_QUANTITY) {
        return $fail('Choose between 1 and ' . GIFT_MAX_QUANTITY . ' gifts.');
    }
    $gift = gift_find($giftId, true);
    if (!$gift || (int) ($gift['price_coins'] ?? 0) < 1) {
        return $fail('That gift is not available.');
    }
    if ($err = gift_recipient_error($organizerId, $senderId, $postId)) {
        return $fail($err);
    }
    $rate = coin_rate_current();
    if ($rate === null) {
        return $fail('OBI GIFTS paid with OBI COINS is not available yet.');
    }
    $unitCoins = (int) $gift['price_coins'];
    $coins = $unitCoins * $quantity;
    $unitUgx = coin_unit_ugx($unitCoins, $rate['rate_e4']);
    $gross = $unitUgx * $quantity;
    if ($unitUgx < 1) {
        return $fail('That gift cannot be sent right now.');
    }
    if ($gross > GIFT_MAX_PAYMENT_UGX) {
        return $fail('A single gift can be worth at most UGX ' . number_format(GIFT_MAX_PAYMENT_UGX) . '. Choose fewer.');
    }
    $idem = $idempotencyKey !== null && $idempotencyKey !== '' ? mb_substr($idempotencyKey, 0, 64) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$senderId]);   // one gift at a time per sender: limits cannot be raced
        if ($idem !== null) {
            $dup = $pdo->prepare('SELECT id, reference, gross_ugx, coins_spent FROM gift_transactions WHERE sender_id = ? AND idempotency_key = ?');
            $dup->execute([$senderId, $idem]);
            if ($row = $dup->fetch()) {
                $pdo->commit();
                return ['ok' => true, 'id' => (int) $row['id'], 'reference' => $row['reference'], 'gross' => (int) $row['gross_ugx'], 'coins' => (int) $row['coins_spent'], 'reused' => true, 'balance_after' => coin_wallet($senderId)['balance']];
            }
        }
        $day = $pdo->prepare("SELECT COALESCE(SUM(gross_ugx), 0) FROM gift_transactions WHERE sender_id = ? AND created_at >= NOW() - INTERVAL 24 HOUR AND payment_status IN ('PENDING','PROCESSING','SUCCESSFUL')");
        $day->execute([$senderId]);
        if ((int) $day->fetchColumn() + $gross > GIFT_DAILY_CAP_UGX) {
            $pdo->rollBack();
            return $fail('You have reached the daily gift limit. Try again tomorrow.');
        }
        coin_wallet_ensure($pdo, $senderId);
        $w = $pdo->prepare('SELECT balance, status FROM coin_wallets WHERE user_id = ? FOR UPDATE');
        $w->execute([$senderId]);
        $wallet = $w->fetch();
        if ($wallet['status'] === 'FROZEN') {
            $pdo->rollBack();
            return $fail('Your OBI COINS wallet is on hold. Please contact support.');
        }
        if ((int) $wallet['balance'] < $coins) {
            $pdo->rollBack();
            return ['ok' => false, 'insufficient' => true, 'balance' => (int) $wallet['balance'], 'needed' => $coins, 'error' => 'You do not have enough OBI COINS for this gift.'];
        }
        $cfg = gift_commission_current();
        $split = gift_split($gross, $cfg['platform_bps'], $cfg['organizer_bps']);
        $reference = gift_new_reference();
        $hold = (int) GIFT_HOLD_HOURS;
        $pdo->prepare("INSERT INTO gift_transactions
              (reference, idempotency_key, sender_id, recipient_organizer_id, gift_id, post_id, quantity, unit_price_ugx, gross_ugx, platform_ugx, organizer_ugx,
               config_version, payment_method, phone, payment_status, delivery_status, status_message, is_anonymous, paid_at, delivered_at, available_at, released_at,
               payment_source, coins_spent, unit_coins, accounting_rate_e4)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'COINS', '', 'SUCCESSFUL', 'DELIVERED', 'Paid with OBI COINS', ?, NOW(), NOW(), NOW() + INTERVAL $hold HOUR, " . ($hold > 0 ? 'NULL' : 'NOW()') . ",
                      'COINS', ?, ?, ?)")
            ->execute([$reference, $idem, $senderId, $organizerId, $giftId, $postId, $quantity, $unitUgx, $gross, $split['platform'], $split['organizer'], $cfg['version'],
                $anonymous ? 1 : 0, $coins, $unitCoins, $rate['rate_e4']]);
        $txId = (int) $pdo->lastInsertId();
        gift_post_delivery($pdo, $txId, $organizerId, $gross, $split['platform'], $split['organizer'], null, 'COIN_LIABILITY');
        coin_wallet_apply($pdo, $senderId, 'GIFT_SPEND', -$coins, 'CSP-' . $txId, 'gift', $txId, 'Sent ' . $gift['name'] . ($quantity > 1 ? ' x ' . $quantity : '') . ' (' . $reference . ')');
        $after = (int) $pdo->query('SELECT balance FROM coin_wallets WHERE user_id = ' . $senderId)->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[coins] could not send gift: ' . $e->getMessage());
        return $fail('Something went wrong. Nothing was taken from your wallet. Please try again.');
    }
    gift_log($txId, 'settled_coins', $coins . ' coins at rate ' . ($rate['rate_e4'] / 10000) . ' = UGX ' . $gross . ' = platform ' . $split['platform'] . ' + organizer ' . $split['organizer']);
    gift_notify_delivered($txId);
    return ['ok' => true, 'id' => $txId, 'reference' => $reference, 'gross' => $gross, 'coins' => $coins, 'balance_after' => $after, 'public_message' => gift_public_message($txId)];
}

// ---------------------------------------------------------------------------------------------------- who can receive gifts

/** SUSPENDED | APPROVED | null (null = the default rule: an organizer with a live video). */
function gift_recipient_status(int $userId): ?string
{
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW TABLES LIKE 'gift_recipient_status'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    if (!$has) {
        return null;
    }
    $stmt = db()->prepare('SELECT status FROM gift_recipient_status WHERE user_id = ?');
    $stmt->execute([$userId]);
    $s = $stmt->fetchColumn();
    return $s !== false ? (string) $s : null;
}

/**
 * Admin: suspend an account (no new gifts, no new withdrawals; history and balance stay), approve another kind of recipient, or go back to the default.
 * @param string $status SUSPENDED | APPROVED | DEFAULT
 */
function gift_recipient_set(int $adminId, int $userId, string $status, string $reason): array
{
    $reason = trim($reason);
    if (!in_array($status, ['SUSPENDED', 'APPROVED', 'DEFAULT'], true) || $reason === '') {
        return ['ok' => false, 'error' => 'Choose a status and give a reason.'];
    }
    $u = db()->prepare("SELECT role FROM users WHERE id = ?");
    $u->execute([$userId]);
    $role = $u->fetchColumn();
    if ($role === false || $role === 'ADMIN') {
        return ['ok' => false, 'error' => 'That account cannot be changed.'];
    }
    $before = gift_recipient_status($userId);
    if ($status === 'DEFAULT') {
        db()->prepare('DELETE FROM gift_recipient_status WHERE user_id = ?')->execute([$userId]);
    } else {
        db()->prepare('INSERT INTO gift_recipient_status (user_id, status, reason, changed_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason), changed_by = VALUES(changed_by)')
            ->execute([$userId, $status, mb_substr($reason, 0, 250), $adminId]);
    }
    log_admin_action($adminId, 'gift.recipient', 'user', $userId, ['from' => $before, 'to' => $status === 'DEFAULT' ? null : $status, 'reason' => $reason]);
    return ['ok' => true];
}

// ---------------------------------------------------------------------------------------------------- the books check for coins

/**
 * Proves the coin books are consistent. Returns a list of problems (empty = all good):
 *  - every wallet: balance = sum of its ledger, never negative, and the running totals match the ledger
 *  - every paid purchase has its credit rows; no unpaid one has any; a reversed one has its reversal rows
 *  - every gift sent with coins has exactly one spend row of the right size, a returned one has its refund row, and its UGX value matches the rate it stored
 * @return list<string>
 */
function coin_reconcile(): array
{
    if (!coins_ready()) {
        return [];
    }
    $problems = [];
    $pdo = db();
    foreach ($pdo->query("SELECT w.*, COALESCE(SUM(l.coins), 0) AS led,
            COALESCE(SUM(CASE WHEN l.entry_type IN ('PURCHASE','PURCHASE_REVERSAL') THEN l.coins END), 0) AS purchased,
            COALESCE(SUM(CASE WHEN l.entry_type IN ('BONUS','BONUS_REVERSAL') THEN l.coins END), 0) AS bonus,
            COALESCE(-SUM(CASE WHEN l.entry_type IN ('GIFT_SPEND','GIFT_REFUND') THEN l.coins END), 0) AS spent
        FROM coin_wallets w LEFT JOIN coin_ledger l ON l.user_id = w.user_id GROUP BY w.user_id")->fetchAll() as $w) {
        $u = 'wallet of user ' . $w['user_id'];
        if ((int) $w['balance'] !== (int) $w['led']) { $problems[] = $u . ': balance ' . $w['balance'] . ' but its ledger adds up to ' . $w['led']; }
        if ((int) $w['balance'] < 0) { $problems[] = $u . ': balance is negative'; }
        if ((int) $w['total_purchased'] !== (int) $w['purchased'] || (int) $w['total_bonus'] !== (int) $w['bonus'] || (int) $w['total_spent'] !== (int) $w['spent']) {
            $problems[] = $u . ': purchased / bonus / spent totals do not match its ledger';
        }
    }
    foreach ($pdo->query("SELECT p.id, p.reference, p.payment_status, p.coins, p.bonus_coins,
            (SELECT COALESCE(SUM(coins), 0) FROM coin_ledger l WHERE l.related_type = 'purchase' AND l.related_id = p.id AND l.entry_type = 'PURCHASE') AS got,
            (SELECT COALESCE(SUM(coins), 0) FROM coin_ledger l WHERE l.related_type = 'purchase' AND l.related_id = p.id AND l.entry_type = 'BONUS') AS gotb,
            (SELECT COALESCE(SUM(coins), 0) FROM coin_ledger l WHERE l.related_type = 'purchase' AND l.related_id = p.id AND l.entry_type = 'PURCHASE_REVERSAL') AS rev,
            (SELECT COALESCE(SUM(coins), 0) FROM coin_ledger l WHERE l.related_type = 'purchase' AND l.related_id = p.id AND l.entry_type = 'BONUS_REVERSAL') AS revb
        FROM coin_purchases p")->fetchAll() as $p) {
        $ref = 'purchase ' . $p['reference'];
        $paid = in_array($p['payment_status'], ['SUCCESSFUL', 'REVERSED'], true);
        if ($paid && ((int) $p['got'] !== (int) $p['coins'] || (int) $p['gotb'] !== (int) $p['bonus_coins'])) { $problems[] = $ref . ': paid but its coins were not credited correctly'; }
        if (!$paid && ((int) $p['got'] !== 0 || (int) $p['gotb'] !== 0)) { $problems[] = $ref . ': ' . $p['payment_status'] . ' but coins were credited'; }
        if ($p['payment_status'] === 'REVERSED' && ((int) $p['rev'] !== -(int) $p['coins'] || (int) $p['revb'] !== -(int) $p['bonus_coins'])) { $problems[] = $ref . ': reversed but the coins were not taken back correctly'; }
        if ($p['payment_status'] !== 'REVERSED' && ((int) $p['rev'] !== 0 || (int) $p['revb'] !== 0)) { $problems[] = $ref . ': not reversed but has reversal rows'; }
    }
    foreach ($pdo->query("SELECT t.id, t.reference, t.payment_status, t.coins_spent, t.unit_coins, t.quantity, t.unit_price_ugx, t.accounting_rate_e4,
            (SELECT COALESCE(SUM(coins), 0) FROM coin_ledger l WHERE l.related_type = 'gift' AND l.related_id = t.id AND l.entry_type = 'GIFT_SPEND') AS spend,
            (SELECT COALESCE(SUM(coins), 0) FROM coin_ledger l WHERE l.related_type = 'gift' AND l.related_id = t.id AND l.entry_type = 'GIFT_REFUND') AS refund
        FROM gift_transactions t WHERE t.payment_source = 'COINS'")->fetchAll() as $t) {
        $ref = 'gift ' . $t['reference'];
        if ((int) $t['coins_spent'] !== (int) $t['unit_coins'] * (int) $t['quantity']) { $problems[] = $ref . ': coins spent is not price x quantity'; }
        if ((int) $t['spend'] !== -(int) $t['coins_spent']) { $problems[] = $ref . ': the wallet spend row does not match the coins spent'; }
        if ($t['payment_status'] === 'REFUNDED' && (int) $t['refund'] !== (int) $t['coins_spent']) { $problems[] = $ref . ': reversed but its coins were not returned'; }
        if ($t['payment_status'] !== 'REFUNDED' && (int) $t['refund'] !== 0) { $problems[] = $ref . ': not reversed but its coins were returned'; }
        if ($t['accounting_rate_e4'] !== null && (int) $t['unit_price_ugx'] !== coin_unit_ugx((int) $t['unit_coins'], (int) $t['accounting_rate_e4'])) { $problems[] = $ref . ': its UGX value does not match the rate it was sent with'; }
    }
    return $problems;
}

// ---------------------------------------------------------------------------------------------------- small helpers for the screens

/** The user's most recent purchase that is still waiting for their approval (so the page can pick up where they left off), or null. */
function coin_purchase_open(int $userId): ?array
{
    if (!coins_ready()) {
        return null;
    }
    $stmt = db()->prepare("SELECT id, reference, package_name, coins, bonus_coins, price_ugx, payment_method, phone, created_at FROM coin_purchases
                           WHERE user_id = ? AND payment_status IN ('PENDING','PROCESSING') AND created_at > NOW() - INTERVAL " . (int) COIN_PENDING_EXPIRY_MINUTES . ' MINUTE ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** The number to suggest at checkout: the account's own phone, else the one used for their last purchase or gift, written like 0772 123 456. */
function coin_phone_hint(array $user): string
{
    $phone = trim((string) ($user['phone'] ?? ''));
    if ($phone === '' && coins_ready()) {
        $stmt = db()->prepare('SELECT phone FROM coin_purchases WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $phone = (string) $stmt->fetchColumn();
    }
    if ($phone === '') {
        return gift_phone_hint($user);
    }
    $d = iotec_normalize_phone($phone);
    return preg_match('/^256(\d{9})$/', $d, $m) ? '0' . $m[1] : $phone;
}

/** 256772123456 -> 0772123456 (how people write their number). */
function coin_phone_display(?string $normalized): string
{
    $d = iotec_normalize_phone((string) $normalized);
    return preg_match('/^256(\d{9})$/', $d, $m) ? '0' . $m[1] : (string) $normalized;
}

/** Whether to show the wallet in menus: coins are on, or this person already has a wallet history they should still be able to see. */
function coins_menu_visible(int $userId): bool
{
    if (!coins_ready()) {
        return false;
    }
    if (coins_enabled()) {
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM coin_ledger WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/** What a package looks like to a buyer (never any internal ids beyond the package id). */
function coin_package_public(array $p): array
{
    return ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'description' => (string) ($p['description'] ?? ''), 'coins' => (int) $p['coins'], 'bonus' => (int) $p['bonus_coins'],
            'total' => (int) $p['coins'] + (int) $p['bonus_coins'], 'price' => (int) $p['price_ugx'], 'featured' => (int) $p['is_featured'] === 1];
}

/**
 * The gifts a person has SENT, newest first (cursor = last id seen): paid gifts and reversed ones. A gift paid with OBI COINS shows its coins,
 * a gift paid directly (before coins) shows its UGX. Only the person's own gifts, whether or not they sent them anonymously.
 * @return array{rows:list<array>, next:?int}
 */
function gift_sent_history(int $userId, int $limit = 15, int $before = 0): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare("SELECT t.id, t.paid_at, t.quantity, t.gross_ugx, t.coins_spent, t.payment_source, t.payment_status, t.is_anonymous, g.name AS gift_name, g.emoji,
                                  COALESCE(NULLIF(op.org_name, ''), u.name) AS organizer
                           FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id
                           LEFT JOIN users u ON u.id = t.recipient_organizer_id LEFT JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id
                           WHERE t.sender_id = ? AND t.payment_status IN ('SUCCESSFUL','REFUNDED')" . ($before > 0 ? ' AND t.id < ?' : '') . ' ORDER BY t.id DESC LIMIT ' . ($limit + 1));
    $stmt->execute($before > 0 ? [$userId, $before] : [$userId]);
    $rows = $stmt->fetchAll();
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $next = (int) $rows[count($rows) - 1]['id'];
    }
    $out = [];
    foreach ($rows as $r) {
        $isCoins = $r['payment_source'] === 'COINS';
        $out[] = ['id' => (int) $r['id'], 'when' => (string) $r['paid_at'], 'gift' => (string) $r['gift_name'], 'emoji' => (string) $r['emoji'], 'quantity' => (int) $r['quantity'],
                  'to' => (string) $r['organizer'], 'coins' => $isCoins ? (int) $r['coins_spent'] : null, 'ugx' => $isCoins ? null : (int) $r['gross_ugx'],
                  'reversed' => $r['payment_status'] === 'REFUNDED', 'anonymous' => (int) $r['is_anonymous'] === 1];
    }
    return ['rows' => $out, 'next' => $next];
}
