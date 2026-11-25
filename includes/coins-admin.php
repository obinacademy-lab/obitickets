<?php
/**
 * OBI COINS: what the Admin screens read (phase 6). Everything here is read-only; every change goes through the engine functions in
 * coins.php (rate, packages, adjustments, freezing, reversals, recipient status) and gift-payouts.php (withdrawal approval), which audit it.
 */

/** Days offered on the overview. 0 = all time. */
const COIN_ADMIN_RANGES = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year', 0 => 'All time'];

/**
 * The OBI COINS numbers for the Admin overview. Money in UGX is whole shillings; coins are whole numbers.
 * The "now" numbers (coins held in wallets, what organizers are owed) are as of this moment; the rest cover the chosen days.
 * @return array<string,mixed>
 */
function coin_admin_overview(int $days = 30): array
{
    $pdo = db();
    $days = array_key_exists($days, COIN_ADMIN_RANGES) ? $days : 30;
    $since = $days > 0 ? date('Y-m-d H:i:s', time() - $days * 86400) : '1970-01-01 00:00:00';
    $one = static function (string $sql, array $args = []) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: [];
    };

    $bought = $one("SELECT COUNT(*) n, COALESCE(SUM(price_ugx), 0) ugx, COALESCE(SUM(coins), 0) coins, COALESCE(SUM(bonus_coins), 0) bonus, COALESCE(SUM(gateway_fee_ugx), 0) fees
                    FROM coin_purchases WHERE payment_status IN ('SUCCESSFUL','REVERSED') AND paid_at >= ?", [$since]);
    $reversed = $one("SELECT COUNT(*) n, COALESCE(SUM(price_ugx), 0) ugx, COALESCE(SUM(coins + bonus_coins), 0) coins FROM coin_purchases WHERE payment_status = 'REVERSED' AND reversed_at >= ?", [$since]);
    $unpaid = $one("SELECT SUM(payment_status IN ('PENDING','PROCESSING')) waiting, SUM(payment_status IN ('FAILED','CANCELLED','EXPIRED')) failed FROM coin_purchases WHERE created_at >= ?", [$since]);

    $led = [];
    $st = $pdo->prepare('SELECT entry_type, COALESCE(SUM(coins), 0) c FROM coin_ledger WHERE created_at >= ? GROUP BY entry_type');
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) { $led[$r['entry_type']] = (int) $r['c']; }
    $adj = $one("SELECT COALESCE(SUM(CASE WHEN coins > 0 THEN coins ELSE 0 END), 0) added, COALESCE(SUM(CASE WHEN coins < 0 THEN -coins ELSE 0 END), 0) removed FROM coin_ledger WHERE entry_type = 'ADJUSTMENT' AND created_at >= ?", [$since]);

    $gifts = ['SUCCESSFUL' => ['n' => 0, 'coins' => 0, 'gross' => 0, 'platform' => 0, 'organizer' => 0], 'REFUNDED' => ['n' => 0, 'coins' => 0, 'gross' => 0, 'platform' => 0, 'organizer' => 0]];
    $st = $pdo->prepare("SELECT payment_status s, COUNT(*) n, COALESCE(SUM(coins_spent), 0) coins, COALESCE(SUM(gross_ugx), 0) gross, COALESCE(SUM(platform_ugx), 0) platform, COALESCE(SUM(organizer_ugx), 0) organizer
                         FROM gift_transactions WHERE payment_source = 'COINS' AND payment_status IN ('SUCCESSFUL','REFUNDED') AND paid_at >= ? GROUP BY payment_status");
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) { $gifts[$r['s']] = ['n' => (int) $r['n'], 'coins' => (int) $r['coins'], 'gross' => (int) $r['gross'], 'platform' => (int) $r['platform'], 'organizer' => (int) $r['organizer']]; }

    $held = $one("SELECT COALESCE(SUM(balance), 0) coins, SUM(balance > 0) wallets, SUM(status = 'FROZEN') frozen FROM coin_wallets");
    $owed = $one('SELECT COALESCE(SUM(available_ugx), 0) available, COALESCE(SUM(reserved_ugx), 0) reserved, COALESCE(SUM(pending_ugx), 0) pending FROM gift_balances');
    $paidOut = $one("SELECT COALESCE(SUM(amount_ugx), 0) ugx, COUNT(*) n FROM gift_withdrawals WHERE status = 'PAID' AND completed_at >= ?", [$since]);
    $review = (int) $pdo->query("SELECT COUNT(*) FROM gift_withdrawals WHERE status = 'PENDING_REVIEW'")->fetchColumn();
    $rate = coin_rate_current();
    $heldCoins = (int) ($held['coins'] ?? 0);

    $ok = $gifts['SUCCESSFUL'];
    return [
        'days' => $days, 'since' => $since,
        'bought' => ['n' => (int) $bought['n'], 'ugx' => (int) $bought['ugx'], 'coins' => (int) $bought['coins'], 'bonus' => (int) $bought['bonus'], 'fees' => (int) $bought['fees']],
        'reversed' => ['n' => (int) $reversed['n'], 'ugx' => (int) $reversed['ugx'], 'coins' => (int) $reversed['coins']],
        'waiting' => (int) ($unpaid['waiting'] ?? 0), 'failed' => (int) ($unpaid['failed'] ?? 0),
        'coins_spent' => -($led['GIFT_SPEND'] ?? 0), 'coins_refunded' => $led['GIFT_REFUND'] ?? 0,
        'adjust_added' => (int) $adj['added'], 'adjust_removed' => (int) $adj['removed'],
        'gifts' => $ok, 'gifts_reversed' => $gifts['REFUNDED'],
        'held_coins' => $heldCoins, 'held_wallets' => (int) ($held['wallets'] ?? 0), 'frozen_wallets' => (int) ($held['frozen'] ?? 0),
        'held_ugx' => $rate ? coin_unit_ugx($heldCoins, (int) $rate['rate_e4']) : null,
        'owed' => ['available' => (int) $owed['available'], 'reserved' => (int) $owed['reserved'], 'pending' => (int) $owed['pending']],
        'paid_out' => ['ugx' => (int) $paidOut['ugx'], 'n' => (int) $paidOut['n']],
        'review_waiting' => $review,
        'platform_after_fees' => $ok['platform'] - (int) $bought['fees'],
        'rate' => $rate,
    ];
}

/**
 * Wallets: with no search, the biggest balances; with a search (name, email, phone or id), the matching accounts (with or without a wallet yet).
 * @return list<array{id:int, name:string, email:string, phone:?string, role:string, balance:int, status:string, purchased:int, spent:int}>
 */
function coin_admin_wallets(string $q = '', int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $q = trim($q);
    $pdo = db();
    if ($q === '') {
        $rows = $pdo->query("SELECT u.id, u.name, u.email, u.phone, u.role, w.balance, w.status, w.total_purchased, w.total_spent FROM coin_wallets w JOIN users u ON u.id = w.user_id
                             WHERE w.balance > 0 OR w.total_purchased > 0 OR w.total_spent > 0 ORDER BY w.balance DESC, u.id LIMIT $limit")->fetchAll();
    } else {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st = $pdo->prepare("SELECT u.id, u.name, u.email, u.phone, u.role, w.balance, w.status, w.total_purchased, w.total_spent FROM users u LEFT JOIN coin_wallets w ON w.user_id = u.id
                             WHERE u.role <> 'ADMIN' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.id = ?) ORDER BY (w.balance IS NULL), w.balance DESC, u.id LIMIT $limit");
        $st->execute([$like, $like, $like, ctype_digit($q) ? (int) $q : 0]);
        $rows = $st->fetchAll();
    }
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
        'role' => (string) $r['role'], 'balance' => (int) ($r['balance'] ?? 0), 'status' => (string) ($r['status'] ?? 'ACTIVE'), 'purchased' => (int) ($r['total_purchased'] ?? 0), 'spent' => (int) ($r['total_spent'] ?? 0)], $rows);
}

/**
 * One account in full: who, the wallet, the latest ledger rows, purchases and the gifts they sent.
 * @return array<string,mixed>|null
 */
function coin_admin_wallet_detail(int $userId): ?array
{
    $pdo = db();
    $u = $pdo->prepare("SELECT id, name, email, phone, role, account_status, created_at FROM users WHERE id = ? AND role <> 'ADMIN'");
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) {
        return null;
    }
    $p = $pdo->prepare('SELECT id, reference, package_name, coins, bonus_coins, price_ugx, payment_method, payment_status, status_message, created_at, paid_at FROM coin_purchases WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $p->execute([$userId]);
    $g = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(coins_spent), 0) coins FROM gift_transactions WHERE sender_id = ? AND payment_source = 'COINS' AND payment_status = 'SUCCESSFUL'");
    $g->execute([$userId]);
    return ['user' => $user, 'wallet' => coin_wallet($userId), 'ledger' => coin_wallet_history($userId, 25)['rows'], 'purchases' => $p->fetchAll(),
            'gifts' => $g->fetch() ?: ['n' => 0, 'coins' => 0], 'recipient' => gift_recipient_status($userId)];
}

/**
 * Coin purchases, newest first. $status: all | paid | waiting | failed | reversed.
 * @return array{rows:list<array<string,mixed>>, next:?int}
 */
function coin_admin_purchases(string $status = 'all', string $q = '', int $before = 0, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $where = ['1=1'];
    $args = [];
    $map = ['paid' => "p.payment_status = 'SUCCESSFUL'", 'waiting' => "p.payment_status IN ('PENDING','PROCESSING')", 'failed' => "p.payment_status IN ('FAILED','CANCELLED','EXPIRED')", 'reversed' => "p.payment_status = 'REVERSED'"];
    if (isset($map[$status])) { $where[] = $map[$status]; }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(p.reference LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR p.phone LIKE ?' . (ctype_digit($q) ? ' OR p.user_id = ?' : '') . ')';
        array_push($args, $like, $like, $like, $like);
        if (ctype_digit($q)) { $args[] = (int) $q; }
    }
    if ($before > 0) { $where[] = 'p.id < ?'; $args[] = $before; }
    $st = db()->prepare('SELECT p.id, p.reference, p.user_id, u.name AS user_name, p.package_name, p.coins, p.bonus_coins, p.price_ugx, p.payment_method, p.phone, p.payment_status, p.status_message, p.gateway_fee_ugx, p.created_at, p.paid_at
                         FROM coin_purchases p JOIN users u ON u.id = p.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT ' . ($limit + 1));
    $st->execute($args);
    $rows = $st->fetchAll();
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $next = (int) $rows[count($rows) - 1]['id'];
    }
    return ['rows' => $rows, 'next' => $next];
}

/**
 * Who can receive gifts: the accounts an admin has set (approved or suspended), plus a search over accounts with their gift earnings.
 * @return array{set:list<array<string,mixed>>, found:list<array<string,mixed>>}
 */
function coin_admin_recipients(string $q = ''): array
{
    $pdo = db();
    $set = $pdo->query("SELECT s.user_id AS id, u.name, u.email, u.role, s.status, s.reason, s.updated_at, a.name AS admin_name, COALESCE(b.total_earned_ugx, 0) AS earned
                        FROM gift_recipient_status s JOIN users u ON u.id = s.user_id LEFT JOIN users a ON a.id = s.changed_by LEFT JOIN gift_balances b ON b.organizer_id = s.user_id
                        ORDER BY s.status = 'SUSPENDED' DESC, s.updated_at DESC LIMIT 100")->fetchAll();
    $found = [];
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st = $pdo->prepare("SELECT u.id, u.name, u.email, u.role, COALESCE(NULLIF(op.org_name, ''), '') AS org_name, s.status, COALESCE(b.total_earned_ugx, 0) AS earned
                             FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id LEFT JOIN gift_recipient_status s ON s.user_id = u.id LEFT JOIN gift_balances b ON b.organizer_id = u.id
                             WHERE u.role <> 'ADMIN' AND (u.name LIKE ? OR u.email LIKE ? OR op.org_name LIKE ? OR u.id = ?) ORDER BY u.role = 'ORGANIZER' DESC, u.id LIMIT 30");
        $st->execute([$like, $like, $like, ctype_digit($q) ? (int) $q : 0]);
        $found = $st->fetchAll();
    }
    return ['set' => $set, 'found' => $found];
}

/** The latest changes made to OBI COINS settings, packages, the rate, wallets, recipients and withdrawal approval. */
function coin_admin_audit(int $limit = 40): array
{
    $st = db()->query("SELECT l.created_at, l.action, l.entity_type, l.entity_id, l.details, u.name AS admin_name FROM audit_logs l LEFT JOIN users u ON u.id = l.admin_id
                       WHERE l.action LIKE 'coins.%' OR l.action IN ('gift.recipient','gift.withdraw.approval_mode','gift.withdraw.approve','gift.withdraw.decline','gift.catalog.update','gift.catalog.create')
                       ORDER BY l.id DESC LIMIT " . max(1, min(200, $limit)));
    return $st->fetchAll();
}
