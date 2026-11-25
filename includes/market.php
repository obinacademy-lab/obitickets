<?php
/**
 * OBI MARKET (ADR-003): the merchandise marketplace. This file: the switch, commission, stores, products and stock, the cart, checkout and the payment.
 * The order lifecycle, the seller's money and refunds are in market-orders.php.
 *
 * Rules that hold everywhere:
 *  - Every price, total, commission and stock figure is computed HERE from the database; nothing the browser sends is trusted.
 *  - Money is whole shillings. Commission is on the merchandise value only; the delivery fee goes 100% to the seller.
 *  - A customer pays ONCE (a "checkout"); it is split into one order per seller. Stock is reserved while paying, deducted once paid, released when it fails.
 *  - A payment is only believed when OUR server has asked ioTec and heard "paid" for the right amount, and it is settled exactly once.
 *  - Seller earnings, withdrawals and balances are kept apart from tickets and gifts.
 */

const MARKET_PENDING_EXPIRY_MINUTES = 10;
const MARKET_MIN_PRICE_UGX = 500;
const MARKET_MAX_PRICE_UGX = 5000000;
const MARKET_MAX_QTY_LINE = 20;
const MARKET_MAX_VARIANTS = 40;
const MARKET_MAX_CHECKOUT_UGX = 3000000;
const MARKET_DAILY_CAP_UGX = 5000000;
const MARKET_OPEN_CHECKOUTS = 2;            // payment prompts one customer may have waiting at once
const MARKET_STARTS_PER_10_MIN = 4;
const MARKET_FAILS_IN_A_ROW = 3;
const MARKET_FAILS_PAUSE_MINUTES = 30;
const MARKET_PHONE_HOURLY_LIMIT = 6;
const MARKET_AUTO_RELEASE_DAYS = 7;         // see market-orders.php

class MarketStockException extends RuntimeException
{
}

// ---------------------------------------------------------------------------------------------------- readiness and the switch

/** Migration 047 has run. */
function market_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'market_orders'")->fetch() && (bool) db()->query("SHOW COLUMNS FROM market_orders LIKE 'refunded_merch_ugx'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** OBI MARKET is really open: the tables, the ioTec keys and the admin switch. */
function market_enabled(): bool
{
    return market_ready() && payouts_iotec_configured() && get_setting('market_enabled', '0') === '1';
}

function market_set_enabled(int $adminId, bool $on): void
{
    set_setting($adminId, 'market_enabled', $on ? '1' : '0');
}

function market_money(int $n): string
{
    return 'UGX ' . number_format($n);
}

// ---------------------------------------------------------------------------------------------------- commission

/** The commission in force now: ['version' => int, 'bps' => int] (basis points: 1000 = 10%). Compared with the PHP clock, like the other versioned settings. */
function market_commission_current(): array
{
    $st = db()->prepare('SELECT version, commission_bps FROM market_commission_config WHERE effective_from <= ? ORDER BY effective_from DESC, version DESC LIMIT 1');
    $st->execute([date('Y-m-d H:i:s')]);
    $r = $st->fetch();
    return $r ? ['version' => (int) $r['version'], 'bps' => (int) $r['commission_bps']] : ['version' => 0, 'bps' => 1000];
}

/** Splits a merchandise value: commission rounds half up to a whole shilling, the seller gets the rest. @return array{commission:int, seller:int} */
function market_split(int $merchandise, int $bps): array
{
    $commission = intdiv($merchandise * $bps + 5000, 10000);
    return ['commission' => $commission, 'seller' => $merchandise - $commission];
}

/** @return list<array<string,mixed>> */
function market_commission_history(): array
{
    return db()->query('SELECT c.*, u.name AS admin_name FROM market_commission_config c LEFT JOIN users u ON u.id = c.changed_by ORDER BY c.version DESC')->fetchAll();
}

/**
 * Admin: a new commission version for NEW orders. "10", "7.5" (percent, at most 2 decimals, 0 to 50). Needs a reason; never in the past.
 * @return array{ok:bool, version?:int, error?:string}
 */
function market_commission_set(int $adminId, string $percent, string $reason, ?string $effectiveFrom = null): array
{
    $percent = str_replace(',', '.', trim($percent));
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $percent) || (float) $percent > 50) {
        return ['ok' => false, 'error' => 'Enter the commission as a percentage from 0 to 50 (for example 10 or 7.5).'];
    }
    if (trim($reason) === '') {
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
    $bps = (int) round((float) $percent * 100);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $v = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) + 1 FROM market_commission_config FOR UPDATE')->fetchColumn();
        $pdo->prepare('INSERT INTO market_commission_config (version, commission_bps, effective_from, changed_by, reason) VALUES (?, ?, ?, ?, ?)')->execute([$v, $bps, $at, $adminId, mb_substr(trim($reason), 0, 250)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_admin_action($adminId, 'market.commission', 'market_commission', $v, ['percent' => $percent, 'effective_from' => $at, 'reason' => trim($reason)]);
    return ['ok' => true, 'version' => $v];
}

// ---------------------------------------------------------------------------------------------------- stores

function market_store(int $sellerId): ?array
{
    $st = db()->prepare('SELECT * FROM market_stores WHERE seller_id = ?');
    $st->execute([$sellerId]);
    return $st->fetch() ?: null;
}

function market_store_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM market_stores WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function market_slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '', '-'));
    return $s !== '' ? mb_substr($s, 0, 60) : 'store';
}

/** The seller's store, created the first time (named after their organization). Only an active organizer account can sell. */
function market_store_ensure(int $sellerId): ?array
{
    if (!market_ready()) {
        return null;
    }
    if ($s = market_store($sellerId)) {
        return $s;
    }
    $u = db()->prepare("SELECT u.id, u.name, u.email, COALESCE(NULLIF(op.org_name, ''), u.name) AS org_name FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE u.id = ? AND u.role = 'ORGANIZER' AND u.account_status = 'ACTIVE'");
    $u->execute([$sellerId]);
    $row = $u->fetch();
    if (!$row) {
        return null;
    }
    $base = market_slugify((string) $row['org_name']);
    $slug = $base;
    for ($i = 2; db()->query('SELECT 1 FROM market_stores WHERE slug = ' . db()->quote($slug))->fetchColumn(); $i++) {
        $slug = $base . '-' . $i;
    }
    db()->prepare('INSERT INTO market_stores (seller_id, name, slug, contact_email) VALUES (?, ?, ?, ?)')->execute([$sellerId, mb_substr((string) $row['org_name'], 0, 80), $slug, $row['email']]);
    return market_store($sellerId);
}

/** A store that can sell right now. */
function market_store_active(?array $store): bool
{
    return $store !== null && $store['status'] === 'ACTIVE';
}

/**
 * Seller edits their store settings. @return array{ok:bool, error?:string}
 */
function market_store_update(int $sellerId, array $in): array
{
    $store = market_store_ensure($sellerId);
    if (!$store) {
        return ['ok' => false, 'error' => 'Only an organizer account can have a store.'];
    }
    $name = trim((string) ($in['name'] ?? $store['name']));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Give your store a name (2 to 80 letters).'];
    }
    $mode = (string) ($in['delivery_mode'] ?? $store['delivery_mode']);
    if (!in_array($mode, ['DELIVERY', 'COLLECTION', 'BOTH'], true)) {
        return ['ok' => false, 'error' => 'Choose how customers get their order.'];
    }
    $fee = filter_var($in['delivery_fee_ugx'] ?? $store['delivery_fee_ugx'], FILTER_VALIDATE_INT);
    if ($fee === false || $fee < 0 || $fee > 200000) {
        return ['ok' => false, 'error' => 'The delivery fee must be a whole number from 0 to UGX 200,000.'];
    }
    $email = trim((string) ($in['contact_email'] ?? $store['contact_email']));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'That contact email does not look right.'];
    }
    $phone = trim((string) ($in['contact_phone'] ?? $store['contact_phone']));
    $img = static fn (?string $p): ?string => $p !== null && preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $p) ? $p : null;
    db()->prepare('UPDATE market_stores SET name = ?, description = ?, logo_path = ?, banner_path = ?, contact_email = ?, contact_phone = ?, is_visible = ?, delivery_mode = ?, delivery_fee_ugx = ?, delivery_note = ?, policies = ? WHERE seller_id = ?')
        ->execute([$name, mb_substr(trim((string) ($in['description'] ?? $store['description'])), 0, 600) ?: null,
            array_key_exists('logo_path', $in) ? $img($in['logo_path']) : $store['logo_path'], array_key_exists('banner_path', $in) ? $img($in['banner_path']) : $store['banner_path'],
            $email !== '' ? $email : null, mb_substr($phone, 0, 32) ?: null, array_key_exists('is_visible', $in) ? (!empty($in['is_visible']) ? 1 : 0) : (int) $store['is_visible'], $mode, $fee,
            mb_substr(trim((string) ($in['delivery_note'] ?? $store['delivery_note'])), 0, 255) ?: null, trim((string) ($in['policies'] ?? $store['policies'])) ?: null, $sellerId]);
    return ['ok' => true];
}

// ---------------------------------------------------------------------------------------------------- categories and products

/** @return list<array{id:int, slug:string, name:string}> */
function market_categories(bool $activeOnly = true): array
{
    return db()->query('SELECT id, slug, name FROM market_categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll();
}

function market_variant_label(string $size, string $colour): string
{
    return trim($size . ($size !== '' && $colour !== '' ? ' / ' : '') . $colour);
}

/**
 * Creates or edits a product with its variants and stock. $in: name, category_id, description, price_ugx, sku, brand, tags, low_stock_threshold,
 * variants (list of size, colour, price_ugx?, stock, sku?) OR stock (a plain product), event_ids, publish (true = ACTIVE, false = DRAFT).
 * The seller can only touch their own products and attach their own events. Publishing needs at least one picture.
 * @return array{ok:bool, id?:int, error?:string}
 */
function market_product_save(int $sellerId, ?int $productId, array $in): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e];
    if (!market_ready() || !($store = market_store_ensure($sellerId)) || !market_store_active($store)) {
        return $fail('Your store is not available.');
    }
    $pdo = db();
    $old = null;
    if ($productId !== null) {
        $st = $pdo->prepare('SELECT * FROM market_products WHERE id = ? AND seller_id = ?');
        $st->execute([$productId, $sellerId]);
        $old = $st->fetch();
        if (!$old || in_array($old['status'], ['REMOVED'], true)) {
            return $fail('That product does not exist.');
        }
    }
    $name = trim((string) ($in['name'] ?? ''));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
        return $fail('Give the product a name (3 to 120 letters).');
    }
    $cat = $pdo->prepare('SELECT id FROM market_categories WHERE id = ? AND is_active = 1');
    $cat->execute([(int) ($in['category_id'] ?? 0)]);
    if (!($catId = (int) $cat->fetchColumn())) {
        return $fail('Choose a category.');
    }
    $price = filter_var($in['price_ugx'] ?? '', FILTER_VALIDATE_INT);
    if ($price === false || $price < MARKET_MIN_PRICE_UGX || $price > MARKET_MAX_PRICE_UGX) {
        return $fail('The price must be a whole number from UGX ' . number_format(MARKET_MIN_PRICE_UGX) . ' to UGX ' . number_format(MARKET_MAX_PRICE_UGX) . '.');
    }
    $desc = trim((string) ($in['description'] ?? ''));
    if (mb_strlen($desc) > 4000) {
        return $fail('The description is too long.');
    }
    $low = filter_var($in['low_stock_threshold'] ?? 5, FILTER_VALIDATE_INT);
    if ($low === false || $low < 0 || $low > 1000) {
        return $fail('The low-stock level must be a whole number.');
    }
    // variants
    $variants = [];
    if (!empty($in['variants']) && is_array($in['variants'])) {
        if (count($in['variants']) > MARKET_MAX_VARIANTS) {
            return $fail('A product can have at most ' . MARKET_MAX_VARIANTS . ' variants.');
        }
        foreach ($in['variants'] as $v) {
            $size = mb_substr(trim((string) ($v['size'] ?? '')), 0, 20);
            $colour = mb_substr(trim((string) ($v['colour'] ?? '')), 0, 30);
            $stock = filter_var($v['stock'] ?? 0, FILTER_VALIDATE_INT);
            $vp = ($v['price_ugx'] ?? '') === '' ? $price : filter_var($v['price_ugx'], FILTER_VALIDATE_INT);
            if ($stock === false || $stock < 0 || $stock > 100000) {
                return $fail('Stock must be a whole number (0 or more) for every variant.');
            }
            if ($vp === false || $vp < MARKET_MIN_PRICE_UGX || $vp > MARKET_MAX_PRICE_UGX) {
                return $fail('Every variant price must be a whole number from UGX ' . number_format(MARKET_MIN_PRICE_UGX) . ' to UGX ' . number_format(MARKET_MAX_PRICE_UGX) . '.');
            }
            $key = strtolower($size . '|' . $colour);
            if (isset($variants[$key])) {
                return $fail('The variant ' . market_variant_label($size, $colour) . ' is listed twice.');
            }
            $variants[$key] = ['size' => $size, 'colour' => $colour, 'stock' => $stock, 'price' => $vp, 'sku' => mb_substr(trim((string) ($v['sku'] ?? '')), 0, 60) ?: null];
        }
    } else {
        $stock = filter_var($in['stock'] ?? 0, FILTER_VALIDATE_INT);
        if ($stock === false || $stock < 0 || $stock > 100000) {
            return $fail('Stock must be a whole number (0 or more).');
        }
        $variants['|'] = ['size' => '', 'colour' => '', 'stock' => $stock, 'price' => $price, 'sku' => null];
    }
    // events: only this seller's own
    $eventIds = [];
    foreach ((array) ($in['event_ids'] ?? []) as $eid) {
        $eid = (int) $eid;
        if ($eid > 0) {
            $own = $pdo->prepare('SELECT 1 FROM events WHERE id = ? AND organizer_id = ?');
            $own->execute([$eid, $sellerId]);
            if (!$own->fetchColumn()) {
                return $fail('You can only link a product to your own events.');
            }
            $eventIds[$eid] = true;
        }
    }
    $publish = !empty($in['publish']);
    $pdo->beginTransaction();
    try {
        if ($old === null) {
            $pdo->prepare("INSERT INTO market_products (seller_id, category_id, name, description, base_price_ugx, sku, brand, tags, status, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?)")
                ->execute([$sellerId, $catId, $name, $desc ?: null, $price, mb_substr(trim((string) ($in['sku'] ?? '')), 0, 60) ?: null, mb_substr(trim((string) ($in['brand'] ?? '')), 0, 80) ?: null, mb_substr(trim((string) ($in['tags'] ?? '')), 0, 255) ?: null, $low]);
            $productId = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE market_products SET category_id = ?, name = ?, description = ?, base_price_ugx = ?, sku = ?, brand = ?, tags = ?, low_stock_threshold = ? WHERE id = ?')
                ->execute([$catId, $name, $desc ?: null, $price, mb_substr(trim((string) ($in['sku'] ?? '')), 0, 60) ?: null, mb_substr(trim((string) ($in['brand'] ?? '')), 0, 80) ?: null, mb_substr(trim((string) ($in['tags'] ?? '')), 0, 255) ?: null, $low, $productId]);
        }
        // variants: update the ones that match by size+colour, add new ones, retire the ones that were taken out (kept if they were ever sold or are being paid for)
        $existing = [];
        $ev = $pdo->prepare('SELECT * FROM market_variants WHERE product_id = ? ORDER BY id FOR UPDATE');
        $ev->execute([$productId]);
        foreach ($ev->fetchAll() as $row) {
            $existing[strtolower($row['size'] . '|' . $row['colour'])] = $row;
        }
        foreach ($variants as $key => $v) {
            if (isset($existing[$key])) {
                $row = $existing[$key];
                if ($v['stock'] < (int) $row['reserved']) {
                    throw new MarketStockException('Stock for ' . (market_variant_label($row['size'], $row['colour']) ?: 'this product') . ' cannot go below ' . (int) $row['reserved'] . ': that many are being paid for right now.');
                }
                if ((int) $row['stock'] !== $v['stock']) {
                    market_stock_log($pdo, (int) $row['id'], $v['stock'] - (int) $row['stock'], 0, 'seller_edit', null, null, $sellerId);
                }
                $pdo->prepare('UPDATE market_variants SET price_ugx = ?, stock = ?, sku = ?, is_active = 1 WHERE id = ?')->execute([$v['price'], $v['stock'], $v['sku'], (int) $row['id']]);
                unset($existing[$key]);
            } else {
                $pdo->prepare('INSERT INTO market_variants (product_id, size, colour, sku, price_ugx, stock) VALUES (?, ?, ?, ?, ?, ?)')->execute([$productId, $v['size'], $v['colour'], $v['sku'], $v['price'], $v['stock']]);
                if ($v['stock'] > 0) {
                    market_stock_log($pdo, (int) $pdo->lastInsertId(), $v['stock'], 0, 'created', null, null, $sellerId);
                }
            }
        }
        foreach ($existing as $row) {
            $used = $pdo->prepare('SELECT 1 FROM market_order_items WHERE variant_id = ? LIMIT 1');
            $used->execute([(int) $row['id']]);
            if ($used->fetchColumn() || (int) $row['reserved'] > 0) {
                $pdo->prepare('UPDATE market_variants SET is_active = 0 WHERE id = ?')->execute([(int) $row['id']]);
            } else {
                $pdo->prepare('DELETE FROM market_variants WHERE id = ?')->execute([(int) $row['id']]);
            }
        }
        // events
        $pdo->prepare('DELETE FROM market_product_events WHERE product_id = ?')->execute([$productId]);
        foreach (array_keys($eventIds) as $eid) {
            $pdo->prepare('INSERT INTO market_product_events (product_id, event_id) VALUES (?, ?)')->execute([$productId, $eid]);
        }
        $pdo->commit();
    } catch (MarketStockException $e) {
        $pdo->rollBack();
        return $fail($e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[market] could not save product: ' . $e->getMessage());
        return $fail('Something went wrong saving the product. Please try again.');
    }
    $res = market_product_set_status($sellerId, $productId, $publish ? 'ACTIVE' : (($old['status'] ?? 'DRAFT') === 'ACTIVE' ? 'ACTIVE' : (($old['status'] ?? 'DRAFT') === 'INACTIVE' ? 'INACTIVE' : 'DRAFT')));
    return $res['ok'] ? ['ok' => true, 'id' => $productId] : ['ok' => true, 'id' => $productId, 'warning' => $res['error']];
}

/** @param list<string> $paths picture paths under /uploads, the first one is the main picture */
function market_product_images_set(int $sellerId, int $productId, array $paths): array
{
    $own = db()->prepare('SELECT 1 FROM market_products WHERE id = ? AND seller_id = ?');
    $own->execute([$productId, $sellerId]);
    if (!$own->fetchColumn()) {
        return ['ok' => false, 'error' => 'That product does not exist.'];
    }
    $clean = [];
    foreach ($paths as $p) {
        if (is_string($p) && preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $p) && count($clean) < 5) {
            $clean[] = $p;
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM market_product_images WHERE product_id = ?')->execute([$productId]);
    foreach ($clean as $i => $p) {
        $pdo->prepare('INSERT INTO market_product_images (product_id, path, sort_order) VALUES (?, ?, ?)')->execute([$productId, $p, $i]);
    }
    $pdo->prepare('UPDATE market_products SET primary_image = ? WHERE id = ?')->execute([$clean[0] ?? null, $productId]);
    $pdo->commit();
    return ['ok' => true, 'count' => count($clean)];
}

/**
 * The seller changes a product's status. DRAFT / ACTIVE / INACTIVE / ARCHIVED. There is no delete: a product with orders must stay in the records.
 * @return array{ok:bool, error?:string}
 */
function market_product_set_status(int $sellerId, int $productId, string $to): array
{
    if (!in_array($to, ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED'], true)) {
        return ['ok' => false, 'error' => 'Unknown status.'];
    }
    $st = db()->prepare('SELECT * FROM market_products WHERE id = ? AND seller_id = ?');
    $st->execute([$productId, $sellerId]);
    $p = $st->fetch();
    if (!$p || $p['status'] === 'REMOVED') {
        return ['ok' => false, 'error' => 'That product does not exist.'];
    }
    if ($to === 'ACTIVE') {
        if (!$p['primary_image']) {
            return ['ok' => false, 'error' => 'Add at least one picture before the product can go live.'];
        }
        $v = db()->prepare('SELECT COUNT(*) FROM market_variants WHERE product_id = ? AND is_active = 1');
        $v->execute([$productId]);
        if ((int) $v->fetchColumn() < 1) {
            return ['ok' => false, 'error' => 'The product needs at least one active size or colour.'];
        }
        $store = market_store($sellerId);
        if (!market_store_active($store)) {
            return ['ok' => false, 'error' => 'Your store is suspended.'];
        }
    }
    db()->prepare('UPDATE market_products SET status = ? WHERE id = ?')->execute([$to, $productId]);
    return ['ok' => true];
}

/** Admin: take a product down (REMOVED) or put it back (ACTIVE / INACTIVE), with a reason that is kept. */
function market_product_admin_set(int $adminId, int $productId, string $to, string $reason): array
{
    if (!in_array($to, ['REMOVED', 'ACTIVE', 'INACTIVE'], true) || trim($reason) === '') {
        return ['ok' => false, 'error' => 'Choose a status and give a reason.'];
    }
    $u = db()->prepare('UPDATE market_products SET status = ? WHERE id = ?');
    $u->execute([$to, $productId]);
    log_admin_action($adminId, 'market.product.' . strtolower($to), 'market_product', $productId, ['reason' => trim($reason)]);
    return ['ok' => true];
}

/**
 * One product as a customer sees it (only a live product of a live store), with its variants and what is available.
 * @return array<string,mixed>|null
 */
function market_product_public(int $productId): ?array
{
    $st = db()->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug, s.name AS store_name, s.slug AS store_slug, s.delivery_mode, s.delivery_fee_ugx, s.delivery_note, s.policies
                         FROM market_products p JOIN market_categories c ON c.id = p.category_id JOIN market_stores s ON s.seller_id = p.seller_id
                         WHERE p.id = ? AND p.status = 'ACTIVE' AND s.status = 'ACTIVE' AND s.is_visible = 1");
    $st->execute([$productId]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $v = db()->prepare('SELECT id, size, colour, sku, price_ugx, stock - reserved AS available FROM market_variants WHERE product_id = ? AND is_active = 1 ORDER BY id');
    $v->execute([$productId]);
    $variants = array_map(static fn ($r) => ['id' => (int) $r['id'], 'size' => (string) $r['size'], 'colour' => (string) $r['colour'], 'label' => market_variant_label((string) $r['size'], (string) $r['colour']),
        'sku' => $r['sku'], 'price' => (int) $r['price_ugx'], 'available' => max(0, (int) $r['available'])], $v->fetchAll());
    $img = db()->prepare('SELECT path FROM market_product_images WHERE product_id = ? ORDER BY sort_order, id');
    $img->execute([$productId]);
    $ev = db()->prepare("SELECT e.id, e.title, e.slug FROM market_product_events pe JOIN events e ON e.id = pe.event_id WHERE pe.product_id = ? AND e.status = 'PUBLISHED'");
    $ev->execute([$productId]);
    $prices = array_column($variants, 'price');
    $avail = array_sum(array_column($variants, 'available'));
    return ['id' => (int) $p['id'], 'seller_id' => (int) $p['seller_id'], 'name' => (string) $p['name'], 'description' => (string) $p['description'], 'category' => (string) $p['category_name'], 'category_slug' => (string) $p['category_slug'],
        'brand' => $p['brand'], 'store' => (string) $p['store_name'], 'store_slug' => (string) $p['store_slug'], 'price_from' => $prices ? min($prices) : (int) $p['base_price_ugx'], 'price_to' => $prices ? max($prices) : (int) $p['base_price_ugx'],
        'images' => $img->fetchAll(PDO::FETCH_COLUMN), 'variants' => $variants, 'available' => $avail, 'in_stock' => $avail > 0, 'low_stock' => $avail > 0 && $avail <= (int) $p['low_stock_threshold'],
        'delivery_mode' => (string) $p['delivery_mode'], 'delivery_fee' => (int) $p['delivery_fee_ugx'], 'delivery_note' => $p['delivery_note'], 'policies' => $p['policies'], 'events' => $ev->fetchAll(), 'featured' => (int) $p['is_featured'] === 1];
}

/**
 * The shop window: live products of live stores, with search, filters, sorting and pages.
 * $f: q, category (slug), seller, event, min, max, sort (newest | price_asc | price_desc | popular | featured), in_stock (bool)
 * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int}
 */
function market_products_list(array $f = [], int $page = 1, int $per = 24): array
{
    if (!market_ready()) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    }
    $per = max(1, min(60, $per));
    $page = max(1, $page);
    $where = ["p.status = 'ACTIVE'", "s.status = 'ACTIVE'", 's.is_visible = 1'];
    $args = [];
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(p.name LIKE ? OR p.tags LIKE ? OR p.brand LIKE ? OR s.name LIKE ? OR EXISTS (SELECT 1 FROM market_product_events pe JOIN events e ON e.id = pe.event_id WHERE pe.product_id = p.id AND e.title LIKE ?))';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if (($cat = trim((string) ($f['category'] ?? ''))) !== '') {
        $where[] = 'c.slug = ?';
        $args[] = $cat;
    }
    if (!empty($f['seller'])) {
        $where[] = 'p.seller_id = ?';
        $args[] = (int) $f['seller'];
    }
    if (!empty($f['event'])) {
        $where[] = 'EXISTS (SELECT 1 FROM market_product_events pe WHERE pe.product_id = p.id AND pe.event_id = ?)';
        $args[] = (int) $f['event'];
    }
    $minV = 'COALESCE((SELECT MIN(v.price_ugx) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1), p.base_price_ugx)';
    if (!empty($f['min'])) {
        $where[] = "$minV >= ?";
        $args[] = (int) $f['min'];
    }
    if (!empty($f['max'])) {
        $where[] = "$minV <= ?";
        $args[] = (int) $f['max'];
    }
    $availV = '(SELECT COALESCE(SUM(v.stock - v.reserved), 0) FROM market_variants v WHERE v.product_id = p.id AND v.is_active = 1)';
    if (!empty($f['in_stock'])) {
        $where[] = "$availV > 0";
    }
    $order = match ((string) ($f['sort'] ?? 'newest')) {
        'price_asc' => "$minV ASC, p.id DESC", 'price_desc' => "$minV DESC, p.id DESC", 'popular' => 'p.sold_count DESC, p.id DESC', 'featured' => 'p.is_featured DESC, p.id DESC', default => 'p.id DESC',
    };
    $from = 'FROM market_products p JOIN market_categories c ON c.id = p.category_id JOIN market_stores s ON s.seller_id = p.seller_id WHERE ' . implode(' AND ', $where);
    $cnt = db()->prepare("SELECT COUNT(*) $from");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $st = db()->prepare("SELECT p.id, p.name, p.primary_image, p.is_featured, p.sold_count, c.name AS category, s.name AS store, s.slug AS store_slug, p.seller_id, $minV AS price_from, $availV AS available $from ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $rows = array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'image' => $r['primary_image'], 'category' => (string) $r['category'], 'store' => (string) $r['store'], 'store_slug' => (string) $r['store_slug'],
        'seller_id' => (int) $r['seller_id'], 'price_from' => (int) $r['price_from'], 'available' => max(0, (int) $r['available']), 'in_stock' => (int) $r['available'] > 0, 'featured' => (int) $r['is_featured'] === 1, 'sold' => (int) $r['sold_count']], $st->fetchAll());
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

// ---------------------------------------------------------------------------------------------------- stock

function market_stock_log(PDO $pdo, int $variantId, int $dStock, int $dReserved, string $reason, ?int $checkoutId, ?int $orderId, ?int $actorId): void
{
    $pdo->prepare('INSERT INTO market_stock_log (variant_id, delta_stock, delta_reserved, reason, checkout_id, order_id, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$variantId, $dStock, $dReserved, $reason, $checkoutId, $orderId, $actorId]);
}

/**
 * The ONLY place a variant's stock or reservation changes (inside the caller's transaction). The row is locked, a change that would break
 * "0 <= reserved <= stock" throws, and every change is logged.
 */
function market_stock_adjust(PDO $pdo, int $variantId, int $dStock, int $dReserved, string $reason, ?int $checkoutId = null, ?int $orderId = null, ?int $actorId = null): void
{
    $st = $pdo->prepare('SELECT stock, reserved FROM market_variants WHERE id = ? FOR UPDATE');
    $st->execute([$variantId]);
    $v = $st->fetch();
    if (!$v) {
        throw new MarketStockException('Variant not found.');
    }
    $stock = (int) $v['stock'] + $dStock;
    $reserved = (int) $v['reserved'] + $dReserved;
    if ($stock < 0 || $reserved < 0 || $reserved > $stock) {
        throw new MarketStockException('Not enough stock.');
    }
    $pdo->prepare('UPDATE market_variants SET stock = ?, reserved = ? WHERE id = ?')->execute([$stock, $reserved, $variantId]);
    market_stock_log($pdo, $variantId, $dStock, $dReserved, $reason, $checkoutId, $orderId, $actorId);
}

// ---------------------------------------------------------------------------------------------------- the cart

/**
 * What is in a customer's cart, priced from the database right now, with a reason when a line cannot be bought.
 * @return array{lines:list<array<string,mixed>>, total:int, buyable:bool}
 */
function market_cart_lines(int $userId, ?array $only = null): array
{
    if (!market_ready()) {
        return ['lines' => [], 'total' => 0, 'buyable' => false];
    }
    if ($only === null) {
        $st = db()->prepare('SELECT variant_id, quantity FROM market_cart_items WHERE user_id = ? ORDER BY updated_at DESC, variant_id');
        $st->execute([$userId]);
        $raw = $st->fetchAll();
    } else {
        $raw = array_map(static fn ($l) => ['variant_id' => (int) $l['variant_id'], 'quantity' => (int) $l['quantity']], $only);
    }
    $lines = [];
    $total = 0;
    $buyable = $raw !== [];
    foreach ($raw as $r) {
        $q = db()->prepare("SELECT v.id, v.size, v.colour, v.sku, v.price_ugx, v.stock - v.reserved AS available, v.is_active AS v_active, p.id AS product_id, p.name, p.status, p.primary_image, p.seller_id,
                                   s.name AS store_name, s.slug AS store_slug, s.status AS store_status, s.is_visible, s.delivery_mode, s.delivery_fee_ugx
                            FROM market_variants v JOIN market_products p ON p.id = v.product_id JOIN market_stores s ON s.seller_id = p.seller_id WHERE v.id = ?");
        $q->execute([(int) $r['variant_id']]);
        $row = $q->fetch();
        if (!$row) {
            continue;
        }
        $qty = (int) $r['quantity'];
        $problem = null;
        if ($row['status'] !== 'ACTIVE' || !(int) $row['v_active'] || $row['store_status'] !== 'ACTIVE' || !(int) $row['is_visible']) {
            $problem = 'No longer available';
        } elseif ((int) $row['seller_id'] === $userId) {
            $problem = 'This is your own product';
        } elseif ($qty > (int) $row['available']) {
            $problem = (int) $row['available'] <= 0 ? 'Sold out' : 'Only ' . (int) $row['available'] . ' left';
        } elseif ($qty > MARKET_MAX_QTY_LINE) {
            $problem = 'At most ' . MARKET_MAX_QTY_LINE . ' of one item';
        }
        $lineTotal = (int) $row['price_ugx'] * $qty;
        if ($problem === null) {
            $total += $lineTotal;
        } else {
            $buyable = false;
        }
        $lines[] = ['variant_id' => (int) $row['id'], 'product_id' => (int) $row['product_id'], 'name' => (string) $row['name'], 'label' => market_variant_label((string) $row['size'], (string) $row['colour']), 'image' => $row['primary_image'],
            'seller_id' => (int) $row['seller_id'], 'store' => (string) $row['store_name'], 'store_slug' => (string) $row['store_slug'], 'unit' => (int) $row['price_ugx'], 'quantity' => $qty, 'line' => $lineTotal,
            'available' => max(0, (int) $row['available']), 'problem' => $problem, 'delivery_mode' => (string) $row['delivery_mode'], 'delivery_fee' => (int) $row['delivery_fee_ugx'], 'sku' => $row['sku']];
    }
    return ['lines' => $lines, 'total' => $total, 'buyable' => $buyable && $lines !== []];
}

/** Sets how many of a variant are in the cart (0 removes it). @return array{ok:bool, error?:string, quantity?:int} */
function market_cart_set(int $userId, int $variantId, int $quantity): array
{
    if (!market_ready()) {
        return ['ok' => false, 'error' => 'OBI MARKET is not available yet.'];
    }
    if ($quantity <= 0) {
        db()->prepare('DELETE FROM market_cart_items WHERE user_id = ? AND variant_id = ?')->execute([$userId, $variantId]);
        return ['ok' => true, 'quantity' => 0];
    }
    if ($quantity > MARKET_MAX_QTY_LINE) {
        return ['ok' => false, 'error' => 'You can add at most ' . MARKET_MAX_QTY_LINE . ' of one item.'];
    }
    $l = market_cart_lines($userId, [['variant_id' => $variantId, 'quantity' => $quantity]]);
    if (!$l['lines']) {
        return ['ok' => false, 'error' => 'That item does not exist.'];
    }
    if ($l['lines'][0]['problem'] !== null) {
        return ['ok' => false, 'error' => $l['lines'][0]['problem'] . '.'];
    }
    $n = db()->prepare('SELECT COUNT(*) FROM market_cart_items WHERE user_id = ? AND variant_id <> ?');
    $n->execute([$userId, $variantId]);
    if ((int) $n->fetchColumn() >= 30) {
        return ['ok' => false, 'error' => 'Your cart is full. Remove something first.'];
    }
    db()->prepare('INSERT INTO market_cart_items (user_id, variant_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')->execute([$userId, $variantId, $quantity]);
    return ['ok' => true, 'quantity' => $quantity];
}

function market_cart_count(int $userId): int
{
    if (!market_ready()) {
        return 0;
    }
    $st = db()->prepare('SELECT COALESCE(SUM(quantity), 0) FROM market_cart_items WHERE user_id = ?');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

// ---------------------------------------------------------------------------------------------------- checkout and payment

function market_new_reference(string $prefix): string
{
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(6)));
}

/**
 * The customer pays. Everything is re-read and re-priced here; stock is reserved in the same locked transaction; one order per seller is created
 * PENDING; then ioTec is asked to prompt the phone. Nothing is sold, no earnings exist and no email goes out until the payment is verified.
 *
 * $in: customer_name, customer_email, customer_phone, delivery_method (DELIVERY|COLLECTION), delivery_name, delivery_phone, delivery_address, delivery_district, delivery_note,
 *      phone (the mobile money number), method (optional), lines (optional list of variant_id + quantity for "Buy now"; otherwise the cart), key (idempotency)
 * @param callable|null $collect test hook with the signature of iotec_initiate_collection(); null = real ioTec (refused on a development computer)
 * @return array{ok:bool, error?:string, throttled?:bool, id?:int, reference?:string, total?:int, orders?:list<string>, reused?:bool}
 */
function market_checkout_start(array $user, array $in, ?callable $collect = null): array
{
    $fail = static fn (string $e, bool $throttled = false): array => ['ok' => false, 'error' => $e] + ($throttled ? ['throttled' => true] : []);
    if (!market_ready() || !market_enabled()) {
        return $fail('OBI MARKET is not available yet.');
    }
    if ($collect === null && gift_is_development()) {
        return $fail('Real payments are switched off on this development computer. Turn on GIFTS_FAKE_PAYMENTS=1 in .env to try OBI MARKET with pretend payments.');
    }
    $userId = (int) $user['id'];
    $name = trim((string) ($in['customer_name'] ?? ''));
    $email = trim((string) ($in['customer_email'] ?? ''));
    $cphone = trim((string) ($in['customer_phone'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return $fail('Enter your full name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $fail('Enter a valid email address for your order confirmation.');
    }
    if (!payout_valid_phone($cphone)) {
        return $fail('Enter a valid phone number so the seller can reach you, like 0772 123 456.');
    }
    $payPhone = trim((string) ($in['phone'] ?? ''));
    if (!payout_valid_phone($payPhone)) {
        return $fail('Enter a valid MTN or Airtel number to pay with, like 0772 123 456.');
    }
    $method = gift_detect_method($payPhone) ?? (in_array($in['method'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY'], true) ? (string) $in['method'] : null);
    if ($method === null) {
        return $fail('Use an MTN or Airtel mobile money number.');
    }
    $deliveryMethod = ($in['delivery_method'] ?? 'DELIVERY') === 'COLLECTION' ? 'COLLECTION' : 'DELIVERY';
    $dName = trim((string) ($in['delivery_name'] ?? $name));
    $dPhone = trim((string) ($in['delivery_phone'] ?? $cphone));
    $dAddress = trim((string) ($in['delivery_address'] ?? ''));
    $dDistrict = trim((string) ($in['delivery_district'] ?? ''));
    $dNote = mb_substr(trim((string) ($in['delivery_note'] ?? '')), 0, 255);
    if ($deliveryMethod === 'DELIVERY') {
        if (mb_strlen($dAddress) < 5 || mb_strlen($dAddress) > 255 || $dDistrict === '' || mb_strlen($dDistrict) > 80) {
            return $fail('Enter the delivery address and the district or city.');
        }
        if ($dName === '' || !payout_valid_phone($dPhone)) {
            return $fail('Enter the name and phone number of the person receiving the order.');
        }
    }
    $idem = ($in['key'] ?? '') !== '' ? mb_substr((string) $in['key'], 0, 64) : null;
    $payPhoneN = iotec_normalize_phone($payPhone);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);   // one checkout start at a time per customer: the limits cannot be raced
        if ($idem !== null) {
            $dup = $pdo->prepare('SELECT id, reference, total_ugx FROM market_checkouts WHERE user_id = ? AND idempotency_key = ?');
            $dup->execute([$userId, $idem]);
            if ($row = $dup->fetch()) {
                $pdo->commit();
                $nums = $pdo->prepare('SELECT order_number FROM market_orders WHERE checkout_id = ? ORDER BY id');
                $nums->execute([(int) $row['id']]);
                return ['ok' => true, 'id' => (int) $row['id'], 'reference' => $row['reference'], 'total' => (int) $row['total_ugx'], 'orders' => $nums->fetchAll(PDO::FETCH_COLUMN), 'reused' => true];
            }
        }
        // the limits (same ideas as the ticket payment guard)
        $throttle = static function (string $msg) use ($pdo): array {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $msg, 'throttled' => true];
        };
        $open = $pdo->prepare("SELECT COUNT(*) FROM market_checkouts WHERE user_id = ? AND payment_status IN ('PENDING','PROCESSING') AND created_at > NOW() - INTERVAL " . (int) MARKET_PENDING_EXPIRY_MINUTES . ' MINUTE');
        $open->execute([$userId]);
        if ((int) $open->fetchColumn() >= MARKET_OPEN_CHECKOUTS) {
            return $throttle('You have a payment waiting for approval on your phone. Approve it or wait a few minutes.');
        }
        $starts = $pdo->prepare('SELECT COUNT(*) FROM market_checkouts WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE');
        $starts->execute([$userId]);
        if ((int) $starts->fetchColumn() >= MARKET_STARTS_PER_10_MIN) {
            return $throttle('You have started several payments in a short time. Please wait a few minutes and try again.');
        }
        $last = $pdo->prepare('SELECT payment_status, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age FROM market_checkouts WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) MARKET_FAILS_IN_A_ROW);
        $last->execute([$userId]);
        $lastRows = $last->fetchAll();
        if (count($lastRows) === MARKET_FAILS_IN_A_ROW && $lastRows[0]['age'] < MARKET_FAILS_PAUSE_MINUTES
            && count(array_filter($lastRows, static fn ($r) => in_array($r['payment_status'], ['FAILED', 'CANCELLED', 'EXPIRED'], true))) === MARKET_FAILS_IN_A_ROW) {
            return $throttle('Your last ' . MARKET_FAILS_IN_A_ROW . ' payments did not go through, so ordering is paused for a little while. Check your number and balance and try again in about ' . MARKET_FAILS_PAUSE_MINUTES . ' minutes.');
        }
        $byPhone = $pdo->prepare('SELECT COUNT(*) FROM market_checkouts WHERE phone = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $byPhone->execute([$payPhoneN]);
        if ((int) $byPhone->fetchColumn() >= MARKET_PHONE_HOURLY_LIMIT) {
            return $throttle('Too many payments were started for this number in the last hour. Please try again later.');
        }
        // what is being bought: the cart (or the "Buy now" lines), re-read from the database, variants locked in a fixed order
        $only = null;
        if (!empty($in['lines']) && is_array($in['lines'])) {
            $only = [];
            foreach ($in['lines'] as $l) {
                $vid = (int) ($l['variant_id'] ?? 0);
                $only[$vid] = ['variant_id' => $vid, 'quantity' => (int) ($l['quantity'] ?? 0)];
            }
            $only = array_values($only);
        }
        $cart = market_cart_lines($userId, $only);
        if (!$cart['lines']) {
            $pdo->rollBack();
            return $fail('Your cart is empty.');
        }
        $ids = array_column($cart['lines'], 'variant_id');
        sort($ids);
        $pdo->query('SELECT id FROM market_variants WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id FOR UPDATE')->fetchAll();
        $cart = market_cart_lines($userId, $only);   // read again with the rows locked: this is the truth
        foreach ($cart['lines'] as $l) {
            if ($l['problem'] !== null || $l['quantity'] < 1) {
                $pdo->rollBack();
                return $fail($l['name'] . ($l['label'] !== '' ? ' (' . $l['label'] . ')' : '') . ': ' . ($l['problem'] ?? 'Choose how many') . '. Update your cart and try again.');
            }
        }
        // one order per seller
        $bySeller = [];
        foreach ($cart['lines'] as $l) {
            $bySeller[$l['seller_id']][] = $l;
        }
        $cfg = market_commission_current();
        $plan = [];
        $total = 0;
        foreach ($bySeller as $sellerId => $lines) {
            $mode = $lines[0]['delivery_mode'];
            if ($deliveryMethod === 'DELIVERY' && $mode === 'COLLECTION') {
                $pdo->rollBack();
                return $fail($lines[0]['store'] . ' does not deliver: choose collection for that order, or remove its items.');
            }
            if ($deliveryMethod === 'COLLECTION' && $mode === 'DELIVERY') {
                $pdo->rollBack();
                return $fail($lines[0]['store'] . ' only delivers: choose delivery, or remove its items.');
            }
            $merch = array_sum(array_column($lines, 'line'));
            $fee = $deliveryMethod === 'DELIVERY' ? (int) $lines[0]['delivery_fee'] : 0;
            $split = market_split($merch, $cfg['bps']);
            $plan[(int) $sellerId] = ['lines' => $lines, 'merch' => $merch, 'fee' => $fee, 'total' => $merch + $fee, 'commission' => $split['commission'], 'seller' => $split['seller']];
            $total += $merch + $fee;
        }
        if ($total < MARKET_MIN_PRICE_UGX || $total > MARKET_MAX_CHECKOUT_UGX) {
            $pdo->rollBack();
            return $fail('One payment can be from UGX ' . number_format(MARKET_MIN_PRICE_UGX) . ' to UGX ' . number_format(MARKET_MAX_CHECKOUT_UGX) . '. Split your order into smaller ones.');
        }
        $day = $pdo->prepare("SELECT COALESCE(SUM(total_ugx), 0) FROM market_checkouts WHERE user_id = ? AND created_at >= NOW() - INTERVAL 24 HOUR AND payment_status IN ('PENDING','PROCESSING','SUCCESSFUL')");
        $day->execute([$userId]);
        if ((int) $day->fetchColumn() + $total > MARKET_DAILY_CAP_UGX) {
            $pdo->rollBack();
            return $fail('You have reached the daily limit for orders (UGX ' . number_format(MARKET_DAILY_CAP_UGX) . '). Try again tomorrow.');
        }
        // the checkout, its orders and items, and the reservation
        $ref = market_new_reference('MKC');
        $pdo->prepare('INSERT INTO market_checkouts (reference, idempotency_key, user_id, customer_name, customer_email, customer_phone, delivery_method, delivery_name, delivery_phone, delivery_address, delivery_district, delivery_note, payment_method, phone, total_ugx)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ref, $idem, $userId, $name, $email, $cphone, $deliveryMethod, $deliveryMethod === 'DELIVERY' ? $dName : null, $deliveryMethod === 'DELIVERY' ? $dPhone : null, $deliveryMethod === 'DELIVERY' ? $dAddress : null,
                $deliveryMethod === 'DELIVERY' ? $dDistrict : null, $dNote ?: null, $method, $payPhoneN, $total]);
        $checkoutId = (int) $pdo->lastInsertId();
        $numbers = [];
        foreach ($plan as $sellerId => $o) {
            $pdo->prepare('INSERT INTO market_orders (order_number, checkout_id, seller_id, customer_id, merchandise_ugx, delivery_ugx, discount_ugx, total_ugx, config_version, commission_bps, commission_ugx, seller_ugx, delivery_method)
                           VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)')
                ->execute(['TMP-' . bin2hex(random_bytes(5)), $checkoutId, $sellerId, $userId, $o['merch'], $o['fee'], $o['total'], $cfg['version'], $cfg['bps'], $o['commission'], $o['seller'], $deliveryMethod]);
            $orderId = (int) $pdo->lastInsertId();
            $number = 'OBM-' . (1000 + $orderId);
            $pdo->prepare('UPDATE market_orders SET order_number = ? WHERE id = ?')->execute([$number, $orderId]);
            $numbers[] = $number;
            foreach ($o['lines'] as $l) {
                $pdo->prepare('INSERT INTO market_order_items (order_id, product_id, variant_id, product_name, variant_label, sku, image_path, unit_price_ugx, quantity, line_ugx) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$orderId, $l['product_id'], $l['variant_id'], $l['name'], $l['label'], $l['sku'], $l['image'], $l['unit'], $l['quantity'], $l['line']]);
                market_stock_adjust($pdo, $l['variant_id'], 0, $l['quantity'], 'reserve', $checkoutId, $orderId, $userId);
            }
            market_history_add($pdo, $orderId, null, 'PENDING_PAYMENT', $userId, 'CUSTOMER', 'Checkout started');
        }
        $pdo->commit();
    } catch (MarketStockException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return $fail('Someone else just bought the last of an item in your cart. Please update your cart and try again.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[market] could not create checkout: ' . $e->getMessage());
        return $fail('Something went wrong. Nothing was charged. Please try again.');
    }
    try {
        $res = ($collect ?? 'iotec_initiate_collection')((float) $total, $payPhone, 'MKT-' . $checkoutId, 'obitickets OBI MARKET ' . $ref);
        $pdo->prepare("UPDATE market_checkouts SET provider_reference = ?, payment_status = 'PROCESSING' WHERE id = ? AND payment_status = 'PENDING'")->execute([(string) $res['transactionId'], $checkoutId]);
    } catch (Throwable $e) {
        error_log('[market] initiate collection failed for checkout ' . $checkoutId . ': ' . $e->getMessage());
        market_checkout_fail($checkoutId, "We couldn't start the mobile money payment. Please try again.");
        return $fail("We couldn't start the mobile money payment. Nothing was charged. Please try again.");
    }
    return ['ok' => true, 'id' => $checkoutId, 'reference' => $ref, 'total' => $total, 'orders' => $numbers];
}

/** Appends one row to an order's status history. */
function market_history_add(PDO $pdo, int $orderId, ?string $from, string $to, ?int $actorId, string $role, ?string $note = null): void
{
    $pdo->prepare('INSERT INTO market_order_history (order_id, from_status, to_status, actor_id, actor_role, note) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $from, $to, $actorId, $role, $note !== null ? mb_substr($note, 0, 255) : null]);
}

/**
 * The payment did not happen (failed, cancelled or the time ran out): the reserved stock goes back, the orders are cancelled. Safe to call twice.
 * @param string $status FAILED | CANCELLED | EXPIRED
 */
function market_checkout_fail(int $checkoutId, string $message, string $status = 'FAILED'): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM market_checkouts WHERE id = ? FOR UPDATE');
        $st->execute([$checkoutId]);
        $c = $st->fetch();
        if (!$c || !in_array($c['payment_status'], ['PENDING', 'PROCESSING'], true)) {
            $pdo->rollBack();
            return false;
        }
        $orders = $pdo->prepare("SELECT id FROM market_orders WHERE checkout_id = ? AND fulfilment = 'PENDING_PAYMENT' ORDER BY id FOR UPDATE");
        $orders->execute([$checkoutId]);
        foreach ($orders->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $items = $pdo->prepare('SELECT variant_id, quantity FROM market_order_items WHERE order_id = ? ORDER BY variant_id');
            $items->execute([$orderId]);
            foreach ($items->fetchAll() as $it) {
                market_stock_adjust($pdo, (int) $it['variant_id'], 0, -(int) $it['quantity'], 'release', $checkoutId, (int) $orderId, null);
            }
            $pdo->prepare('UPDATE market_orders SET payment_status = ?, fulfilment = ? WHERE id = ?')->execute([$status === 'EXPIRED' ? 'EXPIRED' : 'FAILED', 'CANCELLED', $orderId]);
            market_history_add($pdo, (int) $orderId, 'PENDING_PAYMENT', 'CANCELLED', null, 'SYSTEM', $status === 'EXPIRED' ? 'The payment was not made in time' : 'The payment did not go through');
        }
        $pdo->prepare('UPDATE market_checkouts SET payment_status = ?, status_message = ? WHERE id = ?')->execute([$status, mb_substr($message, 0, 250), $checkoutId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return true;
}

/**
 * The payment is verified as paid: ONE transaction turns the reservation into a sale, confirms the orders, writes the seller's pending earnings and
 * obitickets' commission, and queues the emails. Exactly once, however many times it is called. A payment that arrives after the checkout expired
 * is honoured if the stock can be taken again, otherwise the orders are marked as needing a refund (the money is never lost track of).
 */
function market_checkout_settle(int $checkoutId, ?string $message = null, ?int $gatewayFee = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $paidOrders = [];
    try {
        $st = $pdo->prepare('SELECT * FROM market_checkouts WHERE id = ? FOR UPDATE');
        $st->execute([$checkoutId]);
        $c = $st->fetch();
        if (!$c) {
            $pdo->rollBack();
            return false;
        }
        if ($c['payment_status'] === 'SUCCESSFUL') {
            $pdo->rollBack();
            return true;   // already settled: nothing to do twice
        }
        if (!in_array($c['payment_status'], ['PENDING', 'PROCESSING', 'EXPIRED'], true)) {
            $pdo->rollBack();
            return false;
        }
        $late = $c['payment_status'] === 'EXPIRED';
        $orders = $pdo->prepare('SELECT * FROM market_orders WHERE checkout_id = ? ORDER BY id FOR UPDATE');
        $orders->execute([$checkoutId]);
        $orders = $orders->fetchAll();
        // can all the stock still be had? (always true for a payment that was not late: it is reserved)
        $soldOut = false;
        $itemsByOrder = [];
        foreach ($orders as $o) {
            $it = $pdo->prepare('SELECT * FROM market_order_items WHERE order_id = ? ORDER BY variant_id');
            $it->execute([(int) $o['id']]);
            $itemsByOrder[(int) $o['id']] = $it->fetchAll();
        }
        if ($late) {
            $need = [];
            foreach ($itemsByOrder as $items) {
                foreach ($items as $i) { $need[(int) $i['variant_id']] = ($need[(int) $i['variant_id']] ?? 0) + (int) $i['quantity']; }
            }
            ksort($need);
            foreach ($need as $vid => $qty) {
                $v = $pdo->prepare('SELECT stock - reserved AS available, is_active FROM market_variants WHERE id = ? FOR UPDATE');
                $v->execute([$vid]);
                $row = $v->fetch();
                if (!$row || (int) $row['available'] < $qty) { $soldOut = true; }
            }
        }
        foreach ($orders as $o) {
            $orderId = (int) $o['id'];
            if ($late && $soldOut) {
                // the money arrived but the goods are gone: no sale, no earnings; a refund is owed
                $pdo->prepare("UPDATE market_orders SET payment_status = 'PAID', paid_at = NOW(), fulfilment = 'REFUND_REQUESTED', earnings_state = 'NONE', seller_note = ? WHERE id = ?")
                    ->execute(['Paid after the checkout expired and the stock was gone: refund owed', $orderId]);
                market_history_add($pdo, $orderId, $o['fulfilment'], 'REFUND_REQUESTED', null, 'SYSTEM', 'Paid after the checkout expired and the stock was gone: refund owed');
                continue;
            }
            foreach ($itemsByOrder[$orderId] as $i) {
                if ($late) {
                    market_stock_adjust($pdo, (int) $i['variant_id'], -(int) $i['quantity'], 0, 'sale_late', $checkoutId, $orderId, null);
                } else {
                    market_stock_adjust($pdo, (int) $i['variant_id'], -(int) $i['quantity'], -(int) $i['quantity'], 'sale', $checkoutId, $orderId, null);
                }
                $pdo->prepare('UPDATE market_products SET sold_count = sold_count + ? WHERE id = ?')->execute([(int) $i['quantity'], (int) $i['product_id']]);
            }
            $pdo->prepare("UPDATE market_orders SET payment_status = 'PAID', paid_at = NOW(), fulfilment = 'NEW', earnings_state = 'PENDING' WHERE id = ?")->execute([$orderId]);
            $sellerShare = (int) $o['total_ugx'] - (int) $o['commission_ugx'];   // the seller's 90% plus the delivery fee (not commissioned)
            market_ledger_add($pdo, (int) $o['seller_id'], 'SALE', $orderId, null, $sellerShare, 0, 0, 0, (int) $o['commission_ugx'], 'sale-' . $orderId, 'Order ' . $o['order_number'], null);
            market_history_add($pdo, $orderId, $o['fulfilment'], 'NEW', null, 'SYSTEM', $late ? 'Payment confirmed (it arrived after the checkout expired)' : 'Payment confirmed');
            $paidOrders[] = ['id' => $orderId, 'seller_id' => (int) $o['seller_id'], 'number' => (string) $o['order_number'], 'total' => (int) $o['total_ugx']];
        }
        $pdo->prepare("UPDATE market_checkouts SET payment_status = 'SUCCESSFUL', paid_at = NOW(), status_message = ?, gateway_fee_ugx = ?, paid_late = ? WHERE id = ?")
            ->execute([$message !== null ? mb_substr($message, 0, 250) : null, $gatewayFee, $late ? 1 : 0, $checkoutId]);
        if (!($late && $soldOut)) {
            $vids = [];
            foreach ($itemsByOrder as $items) { foreach ($items as $i) { $vids[] = (int) $i['variant_id']; } }
            if ($vids) {
                $pdo->prepare('DELETE FROM market_cart_items WHERE user_id = ? AND variant_id IN (' . implode(',', array_map('intval', $vids)) . ')')->execute([(int) $c['user_id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    // after the money is safely recorded: tell people (a failure here never undoes the sale)
    foreach ($paidOrders as $po) {
        try {
            create_notification($po['seller_id'], 'market_order', 'New merchandise order ' . $po['number'], 'UGX ' . number_format($po['total']) . ' from a customer on OBI MARKET.', '/org-market-order.php?id=' . $po['id']);
        } catch (Throwable $e) {
            error_log('[market] seller notification failed: ' . $e->getMessage());
        }
        if (function_exists('market_email_queue')) {
            market_email_queue($po['id']);
        }
    }
    return true;
}

/**
 * What ioTec says about a checkout, believed only after OUR server asked. The paid amount must equal the total.
 * @return array{status:string, message:?string}   status: PENDING | PAID | FAILED
 */
function market_checkout_resolve(array $c, ?callable $statusCheck = null): array
{
    $out = static fn (string $s, ?string $m = null): array => ['status' => $s, 'message' => $m];
    $state = (string) $c['payment_status'];
    if ($state === 'SUCCESSFUL') {
        return $out('PAID');
    }
    if (in_array($state, ['FAILED', 'CANCELLED'], true)) {
        return $out('FAILED', $c['status_message']);
    }
    $stale = strtotime((string) $c['created_at']) < time() - MARKET_PENDING_EXPIRY_MINUTES * 60;
    if (empty($c['provider_reference'])) {
        if ($stale && $state !== 'EXPIRED') {
            market_checkout_fail((int) $c['id'], 'The payment request expired. Please try again.', 'EXPIRED');
            return $out('FAILED', 'The payment request expired. Please try again.');
        }
        return $state === 'EXPIRED' ? $out('FAILED', $c['status_message']) : $out('PENDING');
    }
    if ($skip = gift_skip_real_check($statusCheck, (string) $c['provider_reference'], gift_is_development(), gift_fake_allowed())) {
        if ($skip === 'fake_reference') {
            gift_log(null, 'fake_reference_ignored', 'market checkout ' . $c['id'] . ': a pretend payment reference was seen where pretend payments are not allowed');
        }
        return $out($state === 'EXPIRED' ? 'FAILED' : 'PENDING', $state === 'EXPIRED' ? $c['status_message'] : null);
    }
    try {
        $r = ($statusCheck ?? 'iotec_check_collection_status')((string) $c['provider_reference']);
    } catch (Throwable $e) {
        error_log('[market] status check failed for checkout ' . $c['id'] . ': ' . $e->getMessage());
        return $state === 'EXPIRED' ? $out('FAILED', $c['status_message']) : $out('PENDING');
    }
    $status = (string) ($r['status'] ?? '');
    if ($status === 'Success') {
        if (isset($r['amount']) && $r['amount'] !== null && (int) round((float) $r['amount']) !== (int) $c['total_ugx']) {
            gift_log(null, 'market_amount_mismatch', 'checkout ' . $c['id'] . ': ioTec collected ' . $r['amount'] . ', the order total is ' . $c['total_ugx']);
            return $out('PENDING', 'This payment is being checked.');
        }
        $fee = isset($r['charge']) && $r['charge'] !== null ? (int) round((float) $r['charge']) : null;
        market_checkout_settle((int) $c['id'], $r['statusMessage'] ?? null, $fee);
        return $out('PAID', $r['statusMessage'] ?? null);
    }
    if ($state === 'EXPIRED') {
        return $out('FAILED', $c['status_message']);
    }
    if ($status === 'Failed') {
        $msg = (string) ($r['statusMessage'] ?? 'The payment was not completed.');
        market_checkout_fail((int) $c['id'], $msg);
        return $out('FAILED', $msg);
    }
    if ($stale) {
        market_checkout_fail((int) $c['id'], 'The payment request expired. Please try again.', 'EXPIRED');
        return $out('FAILED', 'The payment request expired. Please try again.');
    }
    return $out('PENDING', $r['statusMessage'] ?? null);
}

/** The customer's page asks how their payment is doing (only their own). */
function market_checkout_poll(int $checkoutId, int $userId, ?callable $statusCheck = null): array
{
    $st = db()->prepare('SELECT * FROM market_checkouts WHERE id = ? AND user_id = ?');
    $st->execute([$checkoutId, $userId]);
    $c = $st->fetch();
    if (!$c) {
        throw new RuntimeException('Checkout not found.');
    }
    $res = market_checkout_resolve($c, $statusCheck);
    $o = db()->prepare('SELECT order_number FROM market_orders WHERE checkout_id = ? ORDER BY id');
    $o->execute([$checkoutId]);
    $res['orders'] = $o->fetchAll(PDO::FETCH_COLUMN);
    return $res;
}

/**
 * The background job: settles checkouts whose customer closed the app before the payment finished, expires prompts nobody approved, and re-checks
 * checkouts that expired in the last 24 hours (money can arrive late).
 * @return array{checked:int, settled:int}
 */
function market_sweep(int $limit = 50, ?callable $statusCheck = null): array
{
    if (!market_ready()) {
        return ['checked' => 0, 'settled' => 0];
    }
    $rows = db()->query("SELECT * FROM market_checkouts WHERE payment_status IN ('PENDING','PROCESSING') OR (payment_status = 'EXPIRED' AND provider_reference IS NOT NULL AND created_at > NOW() - INTERVAL 24 HOUR) ORDER BY id LIMIT " . (int) $limit)->fetchAll();
    $settled = 0;
    foreach ($rows as $c) {
        $before = $c['payment_status'];
        $res = market_checkout_resolve($c, $statusCheck);
        if ($res['status'] === 'PAID' && $before !== 'SUCCESSFUL') {
            $settled++;
        }
    }
    return ['checked' => count($rows), 'settled' => $settled];
}

// ---------------------------------------------------------------------------------------------------- what the public pages need

/** The shop window is open to everyone when OBI MARKET is switched on; until then only an admin can look (a preview, nothing can be bought). */
function market_visible(?array $user): bool
{
    return market_ready() && (market_enabled() || ($user !== null && ($user['role'] ?? '') === 'ADMIN'));
}

/**
 * What a checkout would cost, per seller, WITHOUT writing anything: the cart (or the "Buy now" lines) priced from the database, the delivery fee of each store for the chosen
 * method, and whether each store can serve that method. market_checkout_start() does the same sums again under locks: this is only for showing the customer.
 * @return array{stores:list<array<string,mixed>>, lines:list<array<string,mixed>>, merch:int, delivery:int, total:int, buyable:bool, methods:list<string>, problems:list<string>}
 */
function market_checkout_quote(int $userId, ?array $only, string $method): array
{
    $method = $method === 'COLLECTION' ? 'COLLECTION' : 'DELIVERY';
    $cart = market_cart_lines($userId, $only);
    $stores = [];
    $problems = [];
    foreach ($cart['lines'] as $l) {
        $sid = $l['seller_id'];
        if (!isset($stores[$sid])) {
            $stores[$sid] = ['seller_id' => $sid, 'store' => $l['store'], 'slug' => $l['store_slug'], 'mode' => $l['delivery_mode'], 'fee' => (int) $l['delivery_fee'], 'merch' => 0, 'lines' => []];
        }
        $stores[$sid]['merch'] += $l['problem'] === null ? $l['line'] : 0;
        $stores[$sid]['lines'][] = $l;
        if ($l['problem'] !== null) {
            $problems[] = $l['name'] . ': ' . $l['problem'];
        }
    }
    $methods = ['DELIVERY', 'COLLECTION'];
    foreach ($stores as &$s) {
        $s['can_deliver'] = $s['mode'] !== 'COLLECTION';
        $s['can_collect'] = $s['mode'] !== 'DELIVERY';
        $s['delivery'] = $method === 'DELIVERY' ? $s['fee'] : 0;
        $s['total'] = $s['merch'] + $s['delivery'];
        if (!$s['can_deliver']) { $methods = array_values(array_diff($methods, ['DELIVERY'])); }
        if (!$s['can_collect']) { $methods = array_values(array_diff($methods, ['COLLECTION'])); }
        if ($method === 'DELIVERY' && !$s['can_deliver']) { $problems[] = $s['store'] . ' does not deliver.'; }
        if ($method === 'COLLECTION' && !$s['can_collect']) { $problems[] = $s['store'] . ' only delivers.'; }
    }
    unset($s);
    $merch = array_sum(array_column($stores, 'merch'));
    $delivery = array_sum(array_column($stores, 'delivery'));
    return ['stores' => array_values($stores), 'lines' => $cart['lines'], 'merch' => $merch, 'delivery' => $delivery, 'total' => $merch + $delivery,
        'buyable' => $cart['buyable'] && $problems === [] && $methods !== [], 'methods' => $methods, 'problems' => array_values(array_unique($problems))];
}

/** A stored product picture path made safe for an img src (only our own /uploads files; anything else shows the placeholder). */
function market_img(?string $path): string
{
    $p = (string) $path;
    return preg_match('#^/uploads/[A-Za-z0-9._/\-]+$#', $p) === 1 && !str_contains($p, '..') ? $p : '';
}

/** The words for an order's state, as the customer reads them: [label, tone]. */
function market_status_label(string $fulfilment, string $payment = 'PAID'): array
{
    if ($payment === 'PENDING') { return ['Waiting for payment', 'wait']; }
    if (in_array($payment, ['FAILED', 'EXPIRED'], true)) { return ['Payment not completed', 'bad']; }
    return match ($fulfilment) {
        'NEW' => ['Order received', 'new'], 'PROCESSING' => ['Being prepared', 'new'], 'SHIPPED' => ['On its way', 'go'], 'READY_FOR_COLLECTION' => ['Ready for collection', 'go'],
        'COMPLETED' => ['Delivered', 'ok'], 'CANCELLED' => ['Cancelled', 'bad'], 'REFUND_REQUESTED' => ['Refund in progress', 'bad'], 'REFUNDED' => ['Refunded', 'bad'], default => ['Processing', 'new'],
    };
}
