<?php
declare(strict_types=1);

/**
 * OBI MARKET (ADR-003, phase 7): what the admin screens show and do. Read-mostly queries across ALL sellers (the admin sees everything), and the admin-only actions
 * (store suspend/restore, feature a product), each written to the audit log with the admin's name and the reason. The money actions themselves (refund, settle a problem, mark a
 * refund sent, mark a withdrawal paid or failed, change the commission) live in market-orders.php / market-payouts.php / market.php and are only CALLED from the admin page.
 */

const MARKET_PAID_SQL = "o.payment_status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED')";

/** What the admin switch needs and what would make a launch go badly. @return list<array{label:string, status:string, detail:string}> status: pass | warn | fail */
function market_admin_setup_check(): array
{
    $isProd = defined('APP_ENV') && APP_ENV === 'production';
    $hb = (string) get_setting('cron_gifts_last_run', '');
    $age = $hb !== '' ? time() - (int) strtotime($hb) : null;
    $cfg = market_commission_current();
    return [
        ['label' => 'Database update (migration 047)', 'status' => market_ready() ? 'pass' : 'fail', 'detail' => market_ready() ? 'The OBI MARKET tables are there.' : 'Run migration/047_obi_market.sql first.'],
        ['label' => 'ioTec keys on the server', 'status' => payouts_iotec_configured() ? 'pass' : 'fail', 'detail' => payouts_iotec_configured() ? 'Present: customers can pay and sellers can withdraw.' : 'Missing from the server config: the shop cannot take payments, so it stays closed.'],
        ['label' => 'Email service (Resend)', 'status' => RESEND_API_KEY ? 'pass' : 'fail', 'detail' => RESEND_API_KEY ? 'The key is set: order emails can be sent.' : 'The Resend key is missing: sellers would NOT get their new-order emails. Do not open the shop.'],
        ['label' => 'Running as the live site', 'status' => $isProd ? 'pass' : 'warn', 'detail' => $isProd ? 'Production mode: pretend payments are refused.' : 'Not in production mode (a development computer never sends real money).'],
        ['label' => 'Background job (every 5 minutes)', 'status' => $age !== null && $age <= 900 ? 'pass' : ($age !== null && $age <= 3600 ? 'warn' : 'fail'),
            'detail' => $age === null ? 'It has never reported in. Payments left open, order emails and the 7-day auto-confirmation need it: check the cron job in hPanel.' : 'Last ran ' . ($age < 90 ? 'just now' : floor($age / 60) . ' minutes ago') . '.'],
        ['label' => 'Commission in force', 'status' => $cfg['bps'] >= 0 ? 'pass' : 'fail', 'detail' => rtrim(rtrim(number_format($cfg['bps'] / 100, 2), '0'), '.') . '% of the merchandise price (version ' . $cfg['version'] . ').'],
        ['label' => 'Categories', 'status' => count(market_categories()) > 0 ? 'pass' : 'fail', 'detail' => count(market_categories()) . ' shown to sellers and customers.'],
    ];
}

/** The numbers on the overview. @return array<string,int> */
function market_admin_numbers(): array
{
    $pdo = db();
    $one = static function (string $sql) use ($pdo): int {
        try {
            return (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };
    $sale = "o.payment_status IN ('PAID','PARTIALLY_REFUNDED') AND o.fulfilment NOT IN ('CANCELLED','REFUNDED','REFUND_REQUESTED','PENDING_PAYMENT')";
    return [
        'stores' => $one("SELECT COUNT(*) FROM market_stores WHERE status = 'ACTIVE'"),
        'products' => $one("SELECT COUNT(*) FROM market_products WHERE status = 'ACTIVE'"),
        'orders_all' => $one("SELECT COUNT(*) FROM market_orders o WHERE $sale"),
        'orders_30' => $one("SELECT COUNT(*) FROM market_orders o WHERE $sale AND o.paid_at >= NOW() - INTERVAL 30 DAY"),
        'sales_all' => $one("SELECT COALESCE(SUM(o.merchandise_ugx - o.refunded_merch_ugx), 0) FROM market_orders o WHERE $sale"),
        'sales_30' => $one("SELECT COALESCE(SUM(o.merchandise_ugx - o.refunded_merch_ugx), 0) FROM market_orders o WHERE $sale AND o.paid_at >= NOW() - INTERVAL 30 DAY"),
        'commission_all' => $one('SELECT COALESCE(SUM(platform_delta), 0) FROM market_ledger'),
        'commission_30' => $one('SELECT COALESCE(SUM(platform_delta), 0) FROM market_ledger WHERE created_at >= NOW() - INTERVAL 30 DAY'),
        'sellers_pending' => $one('SELECT COALESCE(SUM(pending_ugx), 0) FROM market_balances'),
        'sellers_available' => $one('SELECT COALESCE(SUM(available_ugx), 0) FROM market_balances'),
        'sellers_reserved' => $one('SELECT COALESCE(SUM(reserved_ugx), 0) FROM market_balances'),
        'sellers_withdrawn' => $one('SELECT COALESCE(SUM(withdrawn_ugx), 0) FROM market_balances'),
        'disputes' => $one("SELECT COUNT(*) FROM market_orders WHERE dispute_at IS NOT NULL AND received_by IS NULL AND fulfilment IN ('SHIPPED','READY_FOR_COLLECTION')"),
        'refunds_owed' => count(market_refunds_owed()),
        'refunds_owed_ugx' => array_sum(array_map(static fn ($r) => (int) $r['owed_ugx'], market_refunds_owed())),
        'wd_processing' => $one("SELECT COUNT(*) FROM market_withdrawals WHERE status IN ('REQUESTED','PROCESSING','PENDING_REVIEW','APPROVED')"),
        'wd_stuck' => $one("SELECT COUNT(*) FROM market_withdrawals WHERE status = 'PROCESSING' AND requested_at < NOW() - INTERVAL 10 MINUTE"),
        'emails_failed' => $one("SELECT COUNT(*) FROM market_emails WHERE status = 'FAILED'"),
        'emails_waiting' => $one("SELECT COUNT(*) FROM market_emails WHERE status IN ('QUEUED','SENDING')"),
        'payments_stuck' => $one("SELECT COUNT(*) FROM market_checkouts WHERE payment_status IN ('PENDING','PROCESSING') AND created_at < NOW() - INTERVAL 15 MINUTE"),
        'new_unseen' => $one("SELECT COUNT(*) FROM market_orders WHERE fulfilment = 'NEW' AND paid_at < NOW() - INTERVAL 2 DAY"),
    ];
}

/** The little number on the admin menu: things waiting for a person (open problems, refunds to send, withdrawals to look at, failed emails). */
function market_admin_badge(): int
{
    if (!market_ready()) {
        return 0;
    }
    $n = market_admin_numbers();
    return $n['disputes'] + $n['refunds_owed'] + $n['wd_stuck'] + $n['emails_failed'];
}

/** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} every product of every store, for the admin */
function market_admin_products(array $f = [], int $page = 1, int $per = 25): array
{
    $where = ['1=1'];
    $args = [];
    $st = (string) ($f['status'] ?? '');
    if (in_array($st, ['ACTIVE', 'DRAFT', 'INACTIVE', 'ARCHIVED', 'REMOVED'], true)) {
        $where[] = 'p.status = ?';
        $args[] = $st;
    }
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(p.name LIKE ? OR s.name LIKE ? OR p.sku LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (!empty($f['seller'])) {
        $where[] = 'p.seller_id = ?';
        $args[] = (int) $f['seller'];
    }
    $from = 'FROM market_products p JOIN market_stores s ON s.seller_id = p.seller_id JOIN market_categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where);
    $cnt = db()->prepare("SELECT COUNT(*) $from");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $q2 = db()->prepare("SELECT p.id, p.name, p.status, p.is_featured, p.primary_image, p.sold_count, p.updated_at, s.name AS store, s.seller_id, s.slug AS store_slug, c.name AS category,
                                COALESCE((SELECT MIN(v.price_ugx) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1), p.base_price_ugx) AS price,
                                (SELECT COALESCE(SUM(v.stock - v.reserved), 0) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS available
                         $from ORDER BY p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $q2->execute($args);
    return ['rows' => $q2->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function market_admin_feature(int $adminId, int $productId, bool $on): bool
{
    $u = db()->prepare("UPDATE market_products SET is_featured = ? WHERE id = ? AND status = 'ACTIVE'");
    $u->execute([$on ? 1 : 0, $productId]);
    if ($u->rowCount() === 1) {
        log_admin_action($adminId, 'market.product.' . ($on ? 'feature' : 'unfeature'), 'market_product', $productId);
        return true;
    }
    return false;
}

/** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} stores with what they sell and what they are owed */
function market_admin_sellers(string $q = '', int $page = 1, int $per = 25): array
{
    $where = '1=1';
    $args = [];
    if (trim($q) !== '') {
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        $where = '(s.name LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR s.slug LIKE ?)';
        $args = [$like, $like, $like, $like];
    }
    $from = "FROM market_stores s JOIN users u ON u.id = s.seller_id LEFT JOIN market_balances b ON b.seller_id = s.seller_id WHERE $where";
    $cnt = db()->prepare("SELECT COUNT(*) $from");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = db()->prepare("SELECT s.seller_id, s.name, s.slug, s.status, s.is_visible, s.delivery_mode, s.delivery_fee_ugx, s.created_at, u.name AS owner, u.email,
                                COALESCE(b.pending_ugx, 0) AS pending, COALESCE(b.available_ugx, 0) AS available, COALESCE(b.withdrawn_ugx, 0) AS withdrawn, COALESCE(b.total_earned_ugx, 0) AS earned,
                                (SELECT COUNT(*) FROM market_products p WHERE p.seller_id = s.seller_id AND p.status = 'ACTIVE') AS products,
                                (SELECT COUNT(*) FROM market_orders o WHERE o.seller_id = s.seller_id AND " . MARKET_PAID_SQL . ") AS orders,
                                (SELECT COUNT(*) FROM market_orders o WHERE o.seller_id = s.seller_id AND o.dispute_at IS NOT NULL AND o.received_by IS NULL AND o.fulfilment IN ('SHIPPED','READY_FOR_COLLECTION')) AS disputes
                         $from ORDER BY s.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Suspend a store (nothing of it is shown or sold, withdrawals are on hold) or restore it. A reason is required and kept. */
function market_admin_store_status(int $adminId, int $sellerId, string $to, string $reason): array
{
    $reason = trim($reason);
    if (!in_array($to, ['ACTIVE', 'SUSPENDED'], true)) {
        return ['ok' => false, 'error' => 'Unknown status.'];
    }
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Give a reason first. It is kept in the audit log.'];
    }
    $u = db()->prepare('UPDATE market_stores SET status = ? WHERE seller_id = ?');
    $u->execute([$to, $sellerId]);
    if (!market_store($sellerId)) {
        return ['ok' => false, 'error' => 'That store does not exist.'];
    }
    log_admin_action($adminId, 'market.store.' . strtolower($to), 'market_store', $sellerId, ['reason' => $reason]);
    try {
        create_notification($sellerId, 'market_order', $to === 'SUSPENDED' ? 'Your OBI MARKET store was suspended' : 'Your OBI MARKET store is back', $to === 'SUSPENDED' ? 'Customers cannot see or buy your products for now. Please contact support. Reason: ' . $reason : 'Your store and your live products are visible again.', '/org-market-store.php');
    } catch (Throwable $e) {
        error_log('[market] store status notification failed: ' . $e->getMessage());
    }
    return ['ok' => true];
}

/** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} every order of every store. $f: group (all | new | open | sent | done | problem | cancelled | unpaid), q, from, to, seller */
function market_admin_orders(array $f = [], int $page = 1, int $per = 25): array
{
    $where = ['1=1'];
    $args = [];
    $groups = ['new' => "o.fulfilment = 'NEW'", 'open' => "o.fulfilment IN ('NEW','PROCESSING')", 'sent' => "o.fulfilment IN ('SHIPPED','READY_FOR_COLLECTION')", 'done' => "o.fulfilment = 'COMPLETED'",
        'problem' => "(o.dispute_at IS NOT NULL AND o.received_by IS NULL AND o.fulfilment IN ('SHIPPED','READY_FOR_COLLECTION')) OR o.fulfilment = 'REFUND_REQUESTED'", 'cancelled' => "o.fulfilment IN ('CANCELLED','REFUNDED')",
        'unpaid' => "o.payment_status IN ('PENDING','FAILED','EXPIRED')"];
    $g = (string) ($f['group'] ?? 'all');
    if (isset($groups[$g])) {
        $where[] = '(' . $groups[$g] . ')';
    } else {
        $where[] = "o.payment_status NOT IN ('PENDING','FAILED','EXPIRED')";
    }
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(o.order_number LIKE ? OR c.customer_name LIKE ? OR c.customer_phone LIKE ? OR c.customer_email LIKE ? OR s.name LIKE ? OR c.reference LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like, $like);
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
            $where[] = "DATE(o.created_at) $op ?";
            $args[] = (string) $f[$k];
        }
    }
    if (!empty($f['seller'])) {
        $where[] = 'o.seller_id = ?';
        $args[] = (int) $f['seller'];
    }
    $from = 'FROM market_orders o JOIN market_checkouts c ON c.id = o.checkout_id JOIN market_stores s ON s.seller_id = o.seller_id WHERE ' . implode(' AND ', $where);
    $cnt = db()->prepare("SELECT COUNT(*) $from");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = db()->prepare("SELECT o.id, o.order_number, o.total_ugx, o.merchandise_ugx, o.commission_ugx, o.fulfilment, o.payment_status, o.earnings_state, o.dispute_at, o.received_by, o.created_at, o.paid_at, o.delivery_method,
                                c.customer_name, s.name AS store, s.seller_id
                         $from ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** One order in full for an admin: everything the seller and customer see, the payment, the ledger rows and the emails. @return array<string,mixed>|null */
function market_admin_order(string $orderNumber): ?array
{
    $st = db()->prepare('SELECT o.*, c.customer_name, c.customer_email, c.customer_phone, c.delivery_name, c.delivery_phone, c.delivery_address, c.delivery_district, c.delivery_note AS customer_note,
                                c.payment_method, c.phone AS pay_phone, c.reference AS checkout_reference, c.provider_reference, c.gateway_fee_ugx, c.paid_late, c.payment_status AS checkout_status,
                                s.name AS store_name, s.slug AS store_slug, u.email AS seller_email, u.name AS seller_name
                         FROM market_orders o JOIN market_checkouts c ON c.id = o.checkout_id JOIN market_stores s ON s.seller_id = o.seller_id JOIN users u ON u.id = o.seller_id WHERE o.order_number = ?');
    $st->execute([$orderNumber]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    $id = (int) $o['id'];
    $q = static function (string $sql) use ($id): array {
        $s = db()->prepare($sql);
        $s->execute([$id]);
        return $s->fetchAll();
    };
    $o['items'] = $q('SELECT product_id, product_name, variant_label, sku, image_path, unit_price_ugx, quantity, line_ugx FROM market_order_items WHERE order_id = ? ORDER BY id');
    $o['history'] = $q('SELECT h.to_status, h.actor_role, h.note, h.created_at, u.name AS actor FROM market_order_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.order_id = ? ORDER BY h.id');
    $o['ledger'] = $q('SELECT entry_type, pending_delta, available_delta, platform_delta, note, created_at FROM market_ledger WHERE order_id = ? ORDER BY id');
    $o['emails'] = $q('SELECT kind, to_email, status, attempts, last_error, sent_at, created_at FROM market_emails WHERE order_id = ? ORDER BY id');
    return $o;
}

/** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} the order email queue and log. $status: ALL | QUEUED | SENT | FAILED | SKIPPED */
function market_admin_emails(string $status = 'ALL', int $page = 1, int $per = 30): array
{
    $where = in_array($status, ['QUEUED', 'SENDING', 'SENT', 'FAILED', 'SKIPPED'], true) ? 'e.status = ' . db()->quote($status) : '1=1';
    $total = (int) db()->query("SELECT COUNT(*) FROM market_emails e WHERE $where")->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $rows = db()->query("SELECT e.id, e.kind, e.to_email, e.status, e.attempts, e.last_error, e.created_at, e.sent_at, o.order_number
                         FROM market_emails e JOIN market_orders o ON o.id = e.order_id WHERE $where ORDER BY e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Puts a failed (or skipped) email back in the queue to be tried again by the next run. */
function market_admin_requeue_email(int $adminId, int $emailId): bool
{
    $u = db()->prepare("UPDATE market_emails SET status = 'QUEUED', attempts = 0, last_error = 'Sent back to the queue by an admin', claimed_at = NULL WHERE id = ? AND status IN ('FAILED','SKIPPED')");
    $u->execute([$emailId]);
    if ($u->rowCount() === 1) {
        log_admin_action($adminId, 'market.email.requeue', 'market_email', $emailId);
        market_email_fast_path();
        return true;
    }
    return false;
}

/** @return list<array<string,mixed>> withdrawals of every seller, newest first */
function market_admin_withdrawals(int $limit = 50): array
{
    return db()->query('SELECT w.*, s.name AS store, u.email FROM market_withdrawals w JOIN market_stores s ON s.seller_id = w.seller_id JOIN users u ON u.id = w.seller_id ORDER BY w.id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
}

/** Commission earned and sales by month for the finance tab. @return list<array{month:string, orders:int, sales:int, commission:int, delivery:int}> */
function market_admin_monthly(int $months = 6): array
{
    $st = db()->query("SELECT DATE_FORMAT(o.paid_at, '%Y-%m') AS m, COUNT(*) AS orders, SUM(o.merchandise_ugx - o.refunded_merch_ugx) AS sales, SUM(o.delivery_ugx) AS delivery
                       FROM market_orders o WHERE o.payment_status IN ('PAID','PARTIALLY_REFUNDED') AND o.fulfilment NOT IN ('CANCELLED','REFUNDED','REFUND_REQUESTED','PENDING_PAYMENT') AND o.paid_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL " . max(1, min(24, $months) - 1) . " MONTH)
                       GROUP BY m ORDER BY m DESC");
    $com = db()->query("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(platform_delta) AS c FROM market_ledger GROUP BY m")->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map(static fn ($r) => ['month' => (string) $r['m'], 'orders' => (int) $r['orders'], 'sales' => (int) $r['sales'], 'commission' => (int) ($com[$r['m']] ?? 0), 'delivery' => (int) $r['delivery']], $st->fetchAll());
}

/**
 * The Payment check: every promise OBI MARKET makes about money and emails, checked against the data right now.
 * @return array{overall:string, groups:array<string,list<array{label:string,status:string,detail:string}>>}
 */
function market_admin_verification(): array
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
    if (!market_ready()) {
        return ['overall' => 'fail', 'groups' => ['Setup' => [['label' => 'Database update', 'status' => 'fail', 'detail' => 'Migration 047 has not been run.']]]];
    }
    $isProd = defined('APP_ENV') && APP_ENV === 'production';
    foreach (market_admin_setup_check() as $c) {
        $line('Setup', $c['label'], $c['status'], $c['detail']);
    }
    $line('Setup', 'OBI MARKET switch', market_enabled() ? 'pass' : 'warn', market_enabled() ? 'ON: the shop is open to customers.' : 'OFF: customers see "opening soon". Sellers can set up and admins can preview.');
    $line('Setup', 'Seller withdrawals switch', market_withdraw_enabled() ? 'pass' : 'warn', market_withdraw_enabled() ? 'ON: sellers can withdraw released money.' : 'OFF: released money waits for sellers.');

    $P = 'Money is counted only on proof from ioTec';
    $noRef = $count("SELECT COUNT(*) FROM market_checkouts WHERE payment_status = 'SUCCESSFUL' AND (provider_reference IS NULL OR provider_reference = '')");
    $line($P, 'Every paid checkout has an ioTec reference', $noRef === 0 ? 'pass' : 'fail', $noRef === 0 ? 'None without one.' : $noRef . ' paid checkout(s) have no ioTec reference.');
    $fake = $count("SELECT COUNT(*) FROM market_checkouts WHERE payment_status = 'SUCCESSFUL' AND provider_reference LIKE 'FAKE%'");
    $line($P, 'No pretend payments counted as real', $isProd && $fake > 0 ? 'fail' : 'pass', $isProd && $fake > 0 ? $fake . ' paid checkout(s) carry a pretend payment reference on the live site.' : 'None on the live site.');
    $dup = $count('SELECT COUNT(*) FROM (SELECT provider_reference FROM market_checkouts WHERE provider_reference IS NOT NULL GROUP BY provider_reference HAVING COUNT(*) > 1) d');
    $line($P, 'No ioTec payment used twice', $dup === 0 ? 'pass' : 'fail', $dup === 0 ? 'Each ioTec reference belongs to one checkout.' : $dup . ' reference(s) are on more than one checkout.');
    $books = market_reconcile();
    $line($P, 'Sellers\' balances, ledger, orders and stock add up', $books === [] ? 'pass' : 'fail', $books === [] ? 'Every balance equals its ledger, every paid order was recorded once with the right split, no stock is oversold.' : count($books) . ' problem(s), for example: ' . $books[0]);
    $over = $count("SELECT COUNT(*) FROM market_variants WHERE reserved > stock OR stock < 0");
    $line($P, 'No item is oversold', $over === 0 ? 'pass' : 'fail', $over === 0 ? 'Stock is never below zero and never reserved beyond what exists.' : $over . ' item(s) are oversold.');
    $badSplit = $count('SELECT COUNT(*) FROM market_orders WHERE commission_ugx + seller_ugx <> merchandise_ugx');
    $line($P, 'Commission + seller share = merchandise on every order', $badSplit === 0 ? 'pass' : 'fail', $badSplit === 0 ? 'All orders split correctly.' : $badSplit . ' order(s) do not add up.');

    $W = 'Waiting or stuck';
    $n = market_admin_numbers();
    $line($W, 'Payments waiting more than 15 minutes', $n['payments_stuck'] === 0 ? 'pass' : 'warn', $n['payments_stuck'] === 0 ? 'None.' : $n['payments_stuck'] . ' waiting. The background job should settle them. If one stays, ioTec may be holding it.');
    $line($W, 'Refunds owed to customers', $n['refunds_owed'] === 0 ? 'pass' : 'warn', $n['refunds_owed'] === 0 ? 'None.' : $n['refunds_owed'] . ' refund(s) to send, UGX ' . number_format($n['refunds_owed_ugx']) . ' in total. Send the money by mobile money and mark it sent in Refunds.');
    $line($W, 'Problems reported by customers', $n['disputes'] === 0 ? 'pass' : 'warn', $n['disputes'] === 0 ? 'None open.' : $n['disputes'] . ' open: the seller is not paid for these until you decide (Orders > Problems).');
    $line($W, 'New orders nobody has started in 2 days', $n['new_unseen'] === 0 ? 'pass' : 'warn', $n['new_unseen'] === 0 ? 'None.' : $n['new_unseen'] . ' paid order(s) are still "New" after 2 days: sellers may be missing them. Check the order emails.');
    $line($W, 'Withdrawals processing more than 10 minutes', $n['wd_stuck'] === 0 ? 'pass' : 'warn', $n['wd_stuck'] === 0 ? 'None.' : $n['wd_stuck'] . ' need a check in Finance (look the reference up in the ioTec portal).');
    $line($W, 'Order emails that failed', $n['emails_failed'] === 0 ? 'pass' : 'warn', $n['emails_failed'] === 0 ? 'None. ' . $n['emails_waiting'] . ' waiting to go out.' : $n['emails_failed'] . ' failed after 3 tries: send them again in Emails (a seller may not know about an order).');
    $failedN = $count("SELECT COUNT(*) FROM market_checkouts WHERE payment_status IN ('FAILED','CANCELLED','EXPIRED') AND created_at > NOW() - INTERVAL 24 HOUR");
    $paidN = $count("SELECT COUNT(*) FROM market_checkouts WHERE payment_status = 'SUCCESSFUL' AND created_at > NOW() - INTERVAL 24 HOUR");
    $failing = $failedN >= 5 && $failedN > $paidN;
    $line($W, 'Payments failing in the last 24 hours', $failing ? 'warn' : 'pass', $paidN . ' paid, ' . $failedN . ' failed or expired.' . ($failing ? ' More fail than succeed: check ioTec and the ioTec wallet.' : ''));

    $R = 'Proof with real money';
    $realOrders = $count("SELECT COUNT(*) FROM market_checkouts WHERE payment_status = 'SUCCESSFUL' AND provider_reference NOT LIKE 'FAKE%'");
    $line($R, 'A real order has been paid', $realOrders > 0 ? 'pass' : 'warn', $realOrders > 0 ? $realOrders . ' real paid checkout(s).' : 'None yet. Make one small order yourself and see the money, the stock and the emails.');
    $realEmail = $count("SELECT COUNT(*) FROM market_emails WHERE kind = 'ORGANIZER' AND status = 'SENT'");
    $line($R, 'A seller "new order" email was sent', $realEmail > 0 ? 'pass' : 'warn', $realEmail > 0 ? $realEmail . ' sent.' : 'None yet. The seller test button (Store settings) and the first real order prove it arrives.');
    $realDone = $count("SELECT COUNT(*) FROM market_orders WHERE received_by = 'CUSTOMER'");
    $line($R, 'A customer has confirmed delivery', $realDone > 0 ? 'pass' : 'warn', $realDone > 0 ? $realDone . ' order(s) confirmed by the customer.' : 'None yet. Confirm your own test order and see the seller\'s money become available.');
    $realWd = $count("SELECT COUNT(*) FROM market_withdrawals WHERE status = 'PAID' AND provider_reference IS NOT NULL AND provider_reference NOT LIKE 'FAKE%'");
    $line($R, 'A real withdrawal has been paid out', $realWd > 0 ? 'pass' : 'warn', $realWd > 0 ? $realWd . ' real withdrawal(s) paid.' : 'None yet. Withdraw a small amount as a seller and see it arrive on the phone.');

    $L = 'Limits in force';
    $line($L, 'Buying', 'pass', 'At most ' . MARKET_STARTS_PER_10_MIN . ' payments per account per 10 minutes; ' . MARKET_OPEN_CHECKOUTS . ' waiting at once; ' . MARKET_FAILS_IN_A_ROW . ' failures in a row pause the account ' . MARKET_FAILS_PAUSE_MINUTES . ' minutes; ' . MARKET_PHONE_HOURLY_LIMIT . ' payments per phone number an hour; one payment up to UGX ' . number_format(MARKET_MAX_CHECKOUT_UGX) . '; UGX ' . number_format(MARKET_DAILY_CAP_UGX) . ' a day per account.');
    $line($L, 'Stock', 'pass', 'Items are held for ' . MARKET_PENDING_EXPIRY_MINUTES . ' minutes while a customer pays, sold only after ioTec confirms, and never go below zero.');
    $line($L, 'Delivery and release', 'pass', 'A seller is paid when the customer confirms delivery, or automatically ' . MARKET_AUTO_RELEASE_DAYS . ' days after the seller sent it (never while a problem is open).');
    $line($L, 'Withdrawals', 'pass', 'Smallest UGX ' . number_format(MARKET_WITHDRAW_MIN_UGX) . ', one at a time, only released money, sent once.');

    $overall = 'pass';
    foreach ($g as $rows) {
        foreach ($rows as $r) {
            if ($r['status'] === 'fail') {
                $overall = 'fail';
            } elseif ($r['status'] === 'warn' && $overall === 'pass') {
                $overall = 'warn';
            }
        }
    }
    return ['overall' => $overall, 'groups' => $g];
}

function market_admin_tabs(string $active): string
{
    $tabs = ['overview' => 'Overview', 'orders' => 'Orders', 'refunds' => 'Refunds', 'products' => 'Products', 'sellers' => 'Sellers', 'finance' => 'Finance', 'emails' => 'Emails', 'check' => 'Payment check'];
    $out = '<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap">';
    foreach ($tabs as $key => $label) {
        $out .= '<a class="btn ' . ($key === $active ? 'btn-purple' : 'btn-line') . '" href="/admin-market.php' . ($key === 'overview' ? '' : '?tab=' . $key) . '">' . htmlspecialchars($label) . '</a>';
    }
    return $out . '</div>';
}
