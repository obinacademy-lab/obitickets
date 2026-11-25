<?php
declare(strict_types=1);

/**
 * OBI MARKET (ADR-003, phase 4): what the seller's dashboard shows. Read-only queries, every one scoped to ONE seller_id (the signed-in organizer's own account):
 * the overview figures and chart, the product list with status tabs, the orders list and one order in full, the earnings statement.
 * Money words: "sales" = merchandise value of paid orders (delivery fees are the seller's own and shown apart); "net" = what the seller keeps after obitickets'
 * commission, refunds taken off. Orders that were cancelled or fully refunded never count as sales.
 */

/** Orders that count as sales: paid, and not cancelled or fully refunded. */
const MARKET_SALE_SQL = "o.payment_status IN ('PAID','PARTIALLY_REFUNDED') AND o.fulfilment NOT IN ('CANCELLED','REFUNDED','REFUND_REQUESTED','PENDING_PAYMENT')";

/** Parses a date range from the page (7 / 30 / 90 days, or from + to), never more than a year. @return array{from:string, to:string, days:int, key:string} */
function market_seller_range(array $q): array
{
    $today = date('Y-m-d');
    $from = isset($q['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q['from']) ? (string) $q['from'] : null;
    $to = isset($q['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q['to']) ? (string) $q['to'] : null;
    if ($from && $to && $from <= $to && strtotime($to) <= strtotime($today) + 86400) {
        $days = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days > 366) {
            $from = date('Y-m-d', strtotime($to . ' -365 days'));
            $days = 366;
        }
        return ['from' => $from, 'to' => $to, 'days' => $days, 'key' => 'custom'];
    }
    $days = in_array((int) ($q['days'] ?? 30), [7, 30, 90], true) ? (int) ($q['days'] ?? 30) : 30;
    return ['from' => date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days')), 'to' => $today, 'days' => $days, 'key' => (string) $days];
}

/**
 * The overview figures for a range, and the same-length range just before it for the "up/down" comparison.
 * @return array<string,mixed>
 */
function market_seller_overview(int $sellerId, array $range): array
{
    $pdo = db();
    $one = static function (string $from, string $to) use ($pdo, $sellerId): array {
        $st = $pdo->prepare('SELECT COUNT(*) AS orders, COALESCE(SUM(o.merchandise_ugx - o.refunded_merch_ugx), 0) AS sales, COALESCE(SUM(o.delivery_ugx), 0) AS delivery,
                                    COALESCE(SUM(o.commission_ugx), 0) AS commission, COALESCE(SUM(o.seller_ugx), 0) AS net_merch
                             FROM market_orders o WHERE o.seller_id = ? AND ' . MARKET_SALE_SQL . ' AND DATE(o.paid_at) BETWEEN ? AND ?');
        $st->execute([$sellerId, $from, $to]);
        $r = $st->fetch();
        return ['orders' => (int) $r['orders'], 'sales' => (int) $r['sales'], 'delivery' => (int) $r['delivery'], 'commission' => (int) $r['commission'], 'net' => (int) $r['net_merch'] + (int) $r['delivery']];
    };
    $prevTo = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($range['days'] - 1) . ' days'));
    $cnt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'ACTIVE') AS active, SUM(status = 'DRAFT') AS draft FROM market_products WHERE seller_id = ? AND status NOT IN ('REMOVED','ARCHIVED')");
    $cnt->execute([$sellerId]);
    $p = $cnt->fetch();
    $new = $pdo->prepare("SELECT COUNT(*) FROM market_orders WHERE seller_id = ? AND fulfilment = 'NEW' AND payment_status IN ('PAID','PARTIALLY_REFUNDED')");
    $new->execute([$sellerId]);
    $ship = $pdo->prepare("SELECT COUNT(*) FROM market_orders WHERE seller_id = ? AND fulfilment IN ('PROCESSING') AND payment_status IN ('PAID','PARTIALLY_REFUNDED')");
    $ship->execute([$sellerId]);
    return ['cur' => $one($range['from'], $range['to']), 'prev' => $one($prevFrom, $prevTo), 'products_total' => (int) $p['total'], 'products_active' => (int) $p['active'], 'products_draft' => (int) $p['draft'],
        'orders_new' => (int) $new->fetchColumn(), 'orders_processing' => (int) $ship->fetchColumn(), 'balance' => market_balance($sellerId)];
}

/** Sales per day (merchandise value) for the chart: one entry for EVERY day of the range. @return list<array{d:string,g:int}> */
function market_seller_chart(int $sellerId, string $from, string $to): array
{
    $st = db()->prepare('SELECT DATE(o.paid_at) AS d, SUM(o.merchandise_ugx - o.refunded_merch_ugx) AS g FROM market_orders o WHERE o.seller_id = ? AND ' . MARKET_SALE_SQL . ' AND DATE(o.paid_at) BETWEEN ? AND ? GROUP BY DATE(o.paid_at)');
    $st->execute([$sellerId, $from, $to]);
    $by = array_column($st->fetchAll(), 'g', 'd');
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out[] = ['d' => $d, 'g' => (int) ($by[$d] ?? 0)];
    }
    return $out;
}

/** Products the seller should look at: stock at or under its own low-stock level (but still selling), and sold-out ones. @return list<array<string,mixed>> */
function market_seller_low_stock(int $sellerId, int $limit = 8): array
{
    $st = db()->prepare("SELECT p.id, p.name, p.low_stock_threshold, p.primary_image,
                                (SELECT COALESCE(SUM(v.stock - v.reserved), 0) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS available
                         FROM market_products p WHERE p.seller_id = ? AND p.status = 'ACTIVE'
                         HAVING available <= p.low_stock_threshold ORDER BY available ASC, p.id DESC LIMIT " . max(1, min(30, $limit)));
    $st->execute([$sellerId]);
    return $st->fetchAll();
}

/** The most recent orders (paid or not yet fulfilled), for the overview. */
function market_seller_recent_orders(int $sellerId, int $limit = 6): array
{
    return market_seller_orders($sellerId, [], 1, $limit)['rows'];
}

/** Product counts per status tab, plus "out of stock". @return array<string,int> */
function market_seller_product_counts(int $sellerId): array
{
    $st = db()->prepare("SELECT p.status, COUNT(*) AS n FROM market_products p WHERE p.seller_id = ? AND p.status <> 'REMOVED' GROUP BY p.status");
    $st->execute([$sellerId]);
    $c = ['ALL' => 0, 'ACTIVE' => 0, 'DRAFT' => 0, 'INACTIVE' => 0, 'ARCHIVED' => 0, 'OUT' => 0];
    foreach ($st->fetchAll() as $r) {
        $c[$r['status']] = (int) $r['n'];
        if ($r['status'] !== 'ARCHIVED') {
            $c['ALL'] += (int) $r['n'];
        }
    }
    $o = db()->prepare("SELECT COUNT(*) FROM market_products p WHERE p.seller_id = ? AND p.status = 'ACTIVE'
                        AND (SELECT COALESCE(SUM(v.stock - v.reserved), 0) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1) <= 0");
    $o->execute([$sellerId]);
    $c['OUT'] = (int) $o->fetchColumn();
    return $c;
}

/**
 * The seller's product list. $status: ALL (everything but archived) | ACTIVE | DRAFT | INACTIVE | ARCHIVED | OUT (live but nothing left) | REMOVED is never shown.
 * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int}
 */
function market_seller_products(int $sellerId, string $status = 'ALL', string $q = '', int $page = 1, int $per = 20): array
{
    $where = ['p.seller_id = ?', "p.status <> 'REMOVED'"];
    $args = [$sellerId];
    $avail = '(SELECT COALESCE(SUM(v.stock - v.reserved), 0) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1)';
    if (in_array($status, ['ACTIVE', 'DRAFT', 'INACTIVE', 'ARCHIVED'], true)) {
        $where[] = 'p.status = ?';
        $args[] = $status;
    } elseif ($status === 'OUT') {
        $where[] = "p.status = 'ACTIVE' AND $avail <= 0";
    } else {
        $where[] = "p.status <> 'ARCHIVED'";
    }
    if (trim($q) !== '') {
        $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.tags LIKE ?)';
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        array_push($args, $like, $like, $like);
    }
    $from = 'FROM market_products p JOIN market_categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where);
    $cnt = db()->prepare("SELECT COUNT(*) $from");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = db()->prepare("SELECT p.id, p.name, p.status, p.primary_image, p.sold_count, p.low_stock_threshold, p.updated_at, c.name AS category,
                                COALESCE((SELECT MIN(v.price_ugx) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1), p.base_price_ugx) AS price_from,
                                COALESCE((SELECT MAX(v.price_ugx) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1), p.base_price_ugx) AS price_to,
                                (SELECT COUNT(*) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variants,
                                (SELECT COALESCE(SUM(v.stock), 0) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS stock,
                                $avail AS available $from ORDER BY p.updated_at DESC, p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** One of the seller's products for the edit form, or null. @return array<string,mixed>|null */
function market_seller_product(int $sellerId, int $productId): ?array
{
    $st = db()->prepare("SELECT * FROM market_products WHERE id = ? AND seller_id = ? AND status <> 'REMOVED'");
    $st->execute([$productId, $sellerId]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $v = db()->prepare('SELECT id, size, colour, sku, price_ugx, stock, reserved FROM market_variants WHERE product_id = ? AND is_active = 1 ORDER BY id');
    $v->execute([$productId]);
    $p['variants'] = $v->fetchAll();
    $i = db()->prepare('SELECT path FROM market_product_images WHERE product_id = ? ORDER BY sort_order, id');
    $i->execute([$productId]);
    $p['images'] = $i->fetchAll(PDO::FETCH_COLUMN);
    $e = db()->prepare('SELECT event_id FROM market_product_events WHERE product_id = ?');
    $e->execute([$productId]);
    $p['event_ids'] = array_map('intval', $e->fetchAll(PDO::FETCH_COLUMN));
    return $p;
}

/**
 * The seller's orders (paid ones, plus ones still waiting for payment are NOT shown: nothing to do yet). $f: group (new | processing | sent | done | problem | cancelled | all),
 * q (order number, customer name, phone), from, to.
 * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int}
 */
function market_seller_orders(int $sellerId, array $f = [], int $page = 1, int $per = 20): array
{
    $where = ['o.seller_id = ?', "o.payment_status NOT IN ('PENDING','FAILED','EXPIRED')"];
    $args = [$sellerId];
    $groups = ['new' => "o.fulfilment = 'NEW'", 'processing' => "o.fulfilment = 'PROCESSING'", 'sent' => "o.fulfilment IN ('SHIPPED','READY_FOR_COLLECTION')", 'done' => "o.fulfilment = 'COMPLETED'",
        'problem' => "(o.dispute_at IS NOT NULL AND o.received_by IS NULL) OR o.fulfilment = 'REFUND_REQUESTED'", 'cancelled' => "o.fulfilment IN ('CANCELLED','REFUNDED')"];
    $g = (string) ($f['group'] ?? 'all');
    if (isset($groups[$g])) {
        $where[] = '(' . $groups[$g] . ')';
    }
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(o.order_number LIKE ? OR c.customer_name LIKE ? OR c.customer_phone LIKE ? OR c.delivery_phone LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'])) {
        $where[] = 'DATE(o.created_at) >= ?';
        $args[] = (string) $f['from'];
    }
    if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'])) {
        $where[] = 'DATE(o.created_at) <= ?';
        $args[] = (string) $f['to'];
    }
    $from = 'FROM market_orders o JOIN market_checkouts c ON c.id = o.checkout_id WHERE ' . implode(' AND ', $where);
    $cnt = db()->prepare("SELECT COUNT(*) $from");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = db()->prepare("SELECT o.id, o.order_number, o.merchandise_ugx, o.delivery_ugx, o.total_ugx, o.commission_ugx, o.seller_ugx, o.fulfilment, o.payment_status, o.delivery_method, o.earnings_state,
                                o.created_at, o.paid_at, o.dispute_at, o.received_by, c.customer_name,
                                (SELECT COALESCE(SUM(i.quantity), 0) FROM market_order_items i WHERE i.order_id = o.id) AS items,
                                (SELECT i.product_name FROM market_order_items i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS first_name,
                                (SELECT i.image_path FROM market_order_items i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS first_image
                         $from ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Order counts per orders-page tab. @return array<string,int> */
function market_seller_order_counts(int $sellerId): array
{
    $st = db()->prepare("SELECT SUM(1) AS a, SUM(fulfilment = 'NEW') AS n, SUM(fulfilment = 'PROCESSING') AS p, SUM(fulfilment IN ('SHIPPED','READY_FOR_COLLECTION')) AS s, SUM(fulfilment = 'COMPLETED') AS d,
                                SUM((dispute_at IS NOT NULL AND received_by IS NULL) OR fulfilment = 'REFUND_REQUESTED') AS x, SUM(fulfilment IN ('CANCELLED','REFUNDED')) AS c
                         FROM market_orders WHERE seller_id = ? AND payment_status NOT IN ('PENDING','FAILED','EXPIRED')");
    $st->execute([$sellerId]);
    $r = $st->fetch();
    return ['all' => (int) $r['a'], 'new' => (int) $r['n'], 'processing' => (int) $r['p'], 'sent' => (int) $r['s'], 'done' => (int) $r['d'], 'problem' => (int) $r['x'], 'cancelled' => (int) $r['c']];
}

/** One order in full for its seller (customer and delivery details only once it is paid). Null when it is not theirs. @return array<string,mixed>|null */
function market_seller_order(int $sellerId, string $orderNumber): ?array
{
    $st = db()->prepare("SELECT o.*, c.customer_name, c.customer_email, c.customer_phone, c.delivery_name, c.delivery_phone, c.delivery_address, c.delivery_district, c.delivery_note AS customer_note,
                                c.payment_method, c.reference AS checkout_reference
                         FROM market_orders o JOIN market_checkouts c ON c.id = o.checkout_id WHERE o.order_number = ? AND o.seller_id = ? AND o.payment_status NOT IN ('PENDING','FAILED','EXPIRED')");
    $st->execute([$orderNumber, $sellerId]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    $i = db()->prepare('SELECT product_id, variant_id, product_name, variant_label, sku, image_path, unit_price_ugx, quantity, line_ugx FROM market_order_items WHERE order_id = ? ORDER BY id');
    $i->execute([(int) $o['id']]);
    $h = db()->prepare("SELECT to_status, actor_role, note, created_at FROM market_order_history WHERE order_id = ? AND to_status <> 'PENDING_PAYMENT' ORDER BY id");
    $h->execute([(int) $o['id']]);
    $o['items'] = $i->fetchAll();
    $o['history'] = $h->fetchAll();
    return $o;
}

/** The status moves a seller may make from here, as [target, button label, needs a note?, tone]. */
function market_seller_next_steps(array $o): array
{
    $paid = in_array($o['payment_status'], ['PAID', 'PARTIALLY_REFUNDED'], true);
    if (!$paid) {
        return [];
    }
    $collect = $o['delivery_method'] === 'COLLECTION';
    return match ($o['fulfilment']) {
        'NEW' => [['PROCESSING', 'Start preparing', false, 'primary'], ['CANCELLED', 'Cancel and refund', true, 'danger']],
        'PROCESSING' => [$collect ? ['READY_FOR_COLLECTION', 'Ready for collection', false, 'primary'] : ['SHIPPED', 'Mark as sent', false, 'primary'], ['CANCELLED', 'Cancel and refund', true, 'danger']],
        'SHIPPED', 'READY_FOR_COLLECTION' => [['CANCELLED', 'Cancel and refund', true, 'danger']],
        default => [],
    };
}

/** The earnings statement: the seller's ledger, newest first. @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} */
function market_seller_ledger(int $sellerId, int $page = 1, int $per = 25): array
{
    $cnt = db()->prepare('SELECT COUNT(*) FROM market_ledger WHERE seller_id = ?');
    $cnt->execute([$sellerId]);
    $total = (int) $cnt->fetchColumn();
    $per = max(1, min(100, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = db()->prepare('SELECT l.id, l.entry_type, l.pending_delta, l.available_delta, l.reserved_delta, l.withdrawn_delta, l.platform_delta, l.note, l.created_at, o.order_number
                         FROM market_ledger l LEFT JOIN market_orders o ON o.id = l.order_id WHERE l.seller_id = ? ORDER BY l.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute([$sellerId]);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** The words for a ledger row: [label, signed amount to show (what happened to the seller's money), tone]. */
function market_ledger_words(array $r): array
{
    $net = (int) $r['pending_delta'] + (int) $r['available_delta'];
    return match ($r['entry_type']) {
        'SALE' => ['Sale' . ($r['order_number'] ? ' ' . $r['order_number'] : ''), $net, 'ok'],
        'RELEASE' => ['Released to you' . ($r['order_number'] ? ' (' . $r['order_number'] . ')' : ''), 0, 'ok'],
        'REFUND' => ['Refund' . ($r['order_number'] ? ' ' . $r['order_number'] : ''), $net, 'bad'],
        'WITHDRAW_RESERVE' => ['Withdrawal requested', (int) $r['available_delta'], 'wait'],
        'WITHDRAW_PAID' => ['Withdrawal paid', 0, 'ok'],
        'WITHDRAW_RETURN' => ['Withdrawal returned', (int) $r['available_delta'], 'wait'],
        default => ['Adjustment', $net, 'wait'],
    };
}

/** Whether to show the OBI MARKET menu to this organizer: the tables exist (so sellers can set up before it opens to customers). */
function market_org_menu_visible(int $organizerId): bool
{
    return market_ready();
}
