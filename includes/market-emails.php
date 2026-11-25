<?php
declare(strict_types=1);

/**
 * OBI MARKET (ADR-003, phase 6): the emails around a merchandise order.
 *
 *  - ORGANIZER  "NEW MERCHANDISE ORDER RECEIVED — OBI MARKET": to the seller the moment an order is PAID (items, customer, delivery, money split, a direct link).
 *  - CUSTOMER   "YOUR OBI MARKET ORDER IS CONFIRMED!": to the customer, with what they ordered and a link to track it.
 *  - ORGANIZER_COPY: the same seller email to the store's contact address, when that differs from the account email.
 *  - SHIPPED / CANCELLED: to the customer when the seller sends the order (or has it ready to collect), or cancels it (refund on its way).
 *
 * HOW. The same queue-then-send design as the gift emails, kept in the market_emails table (one row per order and kind: the unique key means an email is never queued twice).
 * Paying never waits for an email service: settling an order only INSERTs queue rows. The email goes out a moment later, right after the page has answered the customer
 * (market_email_fast_path), and in any case within 5 minutes by the scheduled job (cron/process-gifts.php), which also retries anything that failed.
 * Exactly one sender wins a row (UPDATE QUEUED -> SENDING). A row claimed and never finished (a crash) goes back in the queue after MARKET_EMAIL_CLAIM_STALE_MIN.
 * A failed send is tried again up to MARKET_EMAIL_ATTEMPTS times, then FAILED and the admins are told. A failure NEVER touches the order, the stock or the money.
 * Every send is also in the Admin Email log (resend_send writes email_log). Reserved and demo addresses are SKIPPED (they can never arrive).
 * The organizer emails cannot be switched off: a seller must always hear about a paid order.
 */

const MARKET_EMAIL_ATTEMPTS = 3;
const MARKET_EMAIL_CLAIM_STALE_MIN = 10;
const MARKET_EMAIL_KINDS = ['ORGANIZER', 'ORGANIZER_COPY', 'CUSTOMER', 'SHIPPED', 'CANCELLED'];

function market_email_log_kind(string $kind): string
{
    return 'market_' . strtolower($kind);
}

/**
 * Puts a row in the queue (never twice for the same order and kind). Never throws, never sends. Returns whether a new row was added.
 */
function market_email_enqueue(int $orderId, string $kind, string $to): bool
{
    try {
        if (!market_ready() || !in_array($kind, MARKET_EMAIL_KINDS, true)) {
            return false;
        }
        $st = db()->prepare('INSERT IGNORE INTO market_emails (order_id, kind, to_email) VALUES (?, ?, ?)');
        $st->execute([$orderId, $kind, mb_substr(trim($to), 0, 191)]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('[market] could not queue the ' . $kind . ' email for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Called once when an order has been paid (market_checkout_settle): queues the seller's email (and the copy to the contact address), the customer's confirmation
 * and any low-stock alerts, then arms the fast path. Never throws.
 */
function market_email_queue(int $orderId): bool
{
    try {
        $ctx = market_email_context($orderId);
        if (!$ctx) {
            return false;
        }
        $o = $ctx['order'];
        market_email_enqueue($orderId, 'ORGANIZER', (string) $ctx['seller_email']);
        $contact = trim((string) ($ctx['store']['contact_email'] ?? ''));
        if ($contact !== '' && strcasecmp($contact, (string) $ctx['seller_email']) !== 0 && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            market_email_enqueue($orderId, 'ORGANIZER_COPY', $contact);
        }
        market_email_enqueue($orderId, 'CUSTOMER', (string) $o['customer_email']);
        market_low_stock_alerts($ctx);
        market_email_fast_path();
        return true;
    } catch (Throwable $e) {
        error_log('[market] could not queue the emails for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/** To the customer when the seller sends the order, has it ready to collect, or cancels it. Called by market_order_notify_customer(). Never throws. */
function market_email_queue_status(int $orderId, string $to): bool
{
    try {
        $kind = in_array($to, ['SHIPPED', 'READY_FOR_COLLECTION'], true) ? 'SHIPPED' : ($to === 'CANCELLED' ? 'CANCELLED' : null);
        if ($kind === null) {
            return false;
        }
        $ctx = market_email_context($orderId);
        if (!$ctx) {
            return false;
        }
        $added = market_email_enqueue($orderId, $kind, (string) $ctx['order']['customer_email']);
        if ($added) {
            market_email_fast_path();
        }
        return $added;
    } catch (Throwable $e) {
        error_log('[market] could not queue the status email for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends the queue a moment after the page has already answered the customer, so the seller's email arrives within seconds. It only works where the server can finish
 * the response first (php-fpm or LiteSpeed); anywhere else, and on the command line, it does nothing and the 5-minute job does the sending. Returns whether it was armed.
 */
function market_email_fast_path(): bool
{
    static $armed = false;
    if ($armed || PHP_SAPI === 'cli') {
        return false;
    }
    $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
    if ($finish === null) {
        return false;
    }
    $armed = true;
    register_shutdown_function(static function () use ($finish): void {
        try {
            ignore_user_abort(true);
            @set_time_limit(60);
            $finish();
            market_email_send_queued(8);
        } catch (Throwable $e) {
            error_log('[market] fast market email failed: ' . $e->getMessage());
        }
    });
    return true;
}

/**
 * Everything an email needs about one order. Null when the order does not exist or is not paid (nothing is ever emailed about an unpaid order).
 * @return array<string,mixed>|null
 */
function market_email_context(int $orderId): ?array
{
    $st = db()->prepare("SELECT o.*, c.customer_name, c.customer_email, c.customer_phone, c.delivery_name, c.delivery_phone, c.delivery_address, c.delivery_district, c.delivery_note AS customer_note,
                                c.payment_method, c.reference AS checkout_reference
                         FROM market_orders o JOIN market_checkouts c ON c.id = o.checkout_id WHERE o.id = ? AND o.payment_status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED')");
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    $i = db()->prepare('SELECT i.product_id, i.variant_id, i.product_name, i.variant_label, i.unit_price_ugx, i.quantity, i.line_ugx FROM market_order_items i WHERE i.order_id = ? ORDER BY i.id');
    $i->execute([$orderId]);
    $items = $i->fetchAll();
    $store = market_store((int) $o['seller_id']) ?: [];
    $u = db()->prepare('SELECT email, name FROM users WHERE id = ?');
    $u->execute([(int) $o['seller_id']]);
    $seller = $u->fetch() ?: ['email' => '', 'name' => ''];
    $low = [];
    foreach ($items as $it) {
        $l = db()->prepare("SELECT p.name, p.low_stock_threshold, v.size, v.colour, v.stock - v.reserved AS available FROM market_variants v JOIN market_products p ON p.id = v.product_id WHERE v.id = ? AND p.status = 'ACTIVE' AND v.is_active = 1");
        $l->execute([(int) $it['variant_id']]);
        $row = $l->fetch();
        if ($row && (int) $row['available'] <= (int) $row['low_stock_threshold']) {
            $low[] = ['product_id' => (int) $it['product_id'], 'variant_id' => (int) $it['variant_id'], 'name' => (string) $row['name'], 'label' => market_variant_label((string) $row['size'], (string) $row['colour']), 'available' => max(0, (int) $row['available'])];
        }
    }
    return ['order' => $o, 'items' => $items, 'store' => $store, 'seller_email' => (string) $seller['email'], 'seller_name' => (string) $seller['name'], 'low' => $low];
}

/** A bell notification to the seller for each product this order left low or out, at most one per product per day. Never throws. */
function market_low_stock_alerts(array $ctx): void
{
    $sellerId = (int) $ctx['order']['seller_id'];
    foreach ($ctx['low'] as $l) {
        try {
            $title = ($l['available'] <= 0 ? 'Sold out: ' : 'Low stock: ') . $l['name'] . ($l['label'] !== '' ? ' (' . $l['label'] . ')' : '');
            $dup = db()->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'market_order' AND title = ? AND created_at >= NOW() - INTERVAL 1 DAY LIMIT 1");
            $dup->execute([$sellerId, mb_substr($title, 0, 190)]);
            if (!$dup->fetchColumn()) {
                create_notification($sellerId, 'market_order', mb_substr($title, 0, 190), $l['available'] <= 0 ? 'There are none left to sell. Add stock to keep selling.' : 'Only ' . $l['available'] . ' left. Add stock before it sells out.', '/org-market-product.php?id=' . $l['product_id']);
            }
        } catch (Throwable $e) {
            error_log('[market] low-stock alert failed: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------------------------------- the emails themselves

/** The shared look. $title, $intro and $body are HTML built with escaped text; $text is the same message as plain text. */
function market_email_shell(string $title, string $intro, string $body, string $button, string $href, string $foot): string
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:580px;margin:0 auto;padding:24px;color:#16111C">'
        . '<p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.08em;color:#E5383B">OBI MARKET</p>'
        . '<h2 style="margin:0 0 8px;font-size:21px">' . $title . '</h2><p style="margin:0 0 18px;color:#4A4455;line-height:1.5">' . $intro . '</p>' . $body
        . '<p style="margin:24px 0 0"><a href="' . $h($href) . '" style="display:inline-block;background:#E5383B;color:#fff;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:10px">' . $h($button) . '</a></p>'
        . '<p style="margin:22px 0 0;font-size:12px;color:#8A8494;line-height:1.5">' . $foot . '</p></div>';
}

function market_email_money(int $n): string
{
    return 'UGX ' . number_format($n);
}

/** @return list<string> */
function market_email_item_lines(array $items): array
{
    return array_map(static fn ($i) => (int) $i['quantity'] . ' x ' . $i['product_name'] . ($i['variant_label'] !== '' ? ' (' . $i['variant_label'] . ')' : '') . ' - ' . market_email_money((int) $i['unit_price_ugx']) . ' each = ' . market_email_money((int) $i['line_ugx']), $items);
}

function market_email_items_html(array $items): string
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    $rows = '';
    foreach ($items as $i) {
        $rows .= '<tr><td style="padding:8px 8px 8px 0;border-top:1px solid #ECE8F1"><b>' . $h($i['product_name']) . '</b>' . ($i['variant_label'] !== '' ? '<br><span style="color:#6B6577;font-size:13px">' . $h($i['variant_label']) . '</span>' : '') . '</td>'
            . '<td style="padding:8px;border-top:1px solid #ECE8F1;text-align:center;white-space:nowrap">&times; ' . (int) $i['quantity'] . '</td>'
            . '<td style="padding:8px 0 8px 8px;border-top:1px solid #ECE8F1;text-align:right;white-space:nowrap">' . $h(market_email_money((int) $i['unit_price_ugx'])) . '</td></tr>';
    }
    return '<table style="border-collapse:collapse;width:100%;font-size:15px;margin:0 0 6px"><tr><td style="padding:0 8px 6px 0;color:#6B6577;font-size:12px;text-transform:uppercase">Item</td><td style="padding:0 8px 6px;color:#6B6577;font-size:12px;text-align:center">QTY</td><td style="padding:0 0 6px 8px;color:#6B6577;font-size:12px;text-align:right">PRICE</td></tr>' . $rows . '</table>';
}

function market_email_kv_html(array $pairs): string
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    $out = '<table style="border-collapse:collapse;font-size:15px;margin:0 0 14px">';
    foreach ($pairs as [$k, $v, $bold]) {
        $out .= '<tr><td style="padding:5px 18px 5px 0;color:#6B6577;vertical-align:top;white-space:nowrap">' . $h($k) . '</td><td style="padding:5px 0;' . ($bold ? 'font-weight:700' : 'font-weight:600') . '">' . $h($v) . '</td></tr>';
    }
    return $out . '</table>';
}

/**
 * Builds one email. @return array{0:string, 1:string, 2:string} [subject, html, plain text]
 */
function market_email_render(string $kind, array $ctx): array
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    $o = $ctx['order'];
    $base = rtrim(APP_URL, '/');
    $num = (string) $o['order_number'];
    $paidWith = $o['payment_method'] === 'AIRTEL_MONEY' ? 'Airtel Money' : 'MTN MoMo';
    $when = date('j M Y, g:ia', strtotime((string) ($o['paid_at'] ?? $o['created_at'])));
    $deliver = $o['delivery_method'] === 'COLLECTION';
    $addr = $deliver ? 'Collection: the customer will collect the order' : trim((string) $o['delivery_name'] . ', ' . (string) $o['delivery_phone'] . ', ' . (string) $o['delivery_address'] . ', ' . (string) $o['delivery_district']);
    $pct = rtrim(rtrim(number_format((int) $o['commission_bps'] / 100, 2), '0'), '.');
    $keep = (int) $o['seller_ugx'] + (int) $o['delivery_ugx'];
    $lines = market_email_item_lines($ctx['items']);

    if (in_array($kind, ['ORGANIZER', 'ORGANIZER_COPY'], true)) {
        $link = $base . '/org-market-order.php?n=' . rawurlencode($num);
        $pairs = [
            ['Order number', $num, true], ['Date and time', $when, false], ['Customer', (string) $o['customer_name'], true], ['Phone', (string) $o['customer_phone'], false], ['Email', (string) $o['customer_email'], false],
            [$deliver ? 'Collection' : 'Deliver to', $addr, false],
        ];
        if (!$deliver && trim((string) $o['customer_note']) !== '') {
            $pairs[] = ['Customer note', (string) $o['customer_note'], false];
        }
        $money = [
            ['Merchandise', market_email_money((int) $o['merchandise_ugx']), false], ['Delivery fee (all yours)', market_email_money((int) $o['delivery_ugx']), false],
            ['Customer paid', market_email_money((int) $o['total_ugx']) . ' by ' . $paidWith, false], ['obitickets commission (' . $pct . '%)', '- ' . market_email_money((int) $o['commission_ugx']), false],
            ['You keep', market_email_money($keep), true], ['Payment status', 'Paid', true], ['Order status', 'New: ready for you to prepare', false],
        ];
        $lowHtml = '';
        $lowText = '';
        foreach ($ctx['low'] as $l) {
            $lowHtml .= '<li>' . $h($l['name'] . ($l['label'] !== '' ? ' (' . $l['label'] . ')' : '')) . ': ' . ($l['available'] <= 0 ? '<b>sold out</b>' : 'only <b>' . (int) $l['available'] . '</b> left') . '</li>';
            $lowText .= '- ' . $l['name'] . ($l['label'] !== '' ? ' (' . $l['label'] . ')' : '') . ': ' . ($l['available'] <= 0 ? 'sold out' : 'only ' . $l['available'] . ' left') . "\n";
        }
        $body = '<h3 style="margin:18px 0 8px;font-size:15px">Order</h3>' . market_email_kv_html($pairs) . '<h3 style="margin:18px 0 8px;font-size:15px">Items to prepare</h3>' . market_email_items_html($ctx['items'])
            . '<h3 style="margin:18px 0 8px;font-size:15px">Money</h3>' . market_email_kv_html($money)
            . ($lowHtml !== '' ? '<div style="margin:14px 0;padding:12px 14px;border-radius:10px;background:#FFF7E6;color:#8A5A00;font-size:14px"><b>Running low after this order:</b><ul style="margin:6px 0 0;padding-left:18px">' . $lowHtml . '</ul></div>' : '');
        $html = market_email_shell('New merchandise order received', 'You have a new paid order on OBI MARKET. The customer has paid, so you can prepare it now.', $body, 'View order', $link,
            'Your money for this order is released to your balance when the customer confirms they received it (or automatically after ' . (int) MARKET_AUTO_RELEASE_DAYS . ' days). obitickets &middot; OBI MARKET seller notification.');
        $text = "NEW MERCHANDISE ORDER RECEIVED — OBI MARKET\n\nOrder: $num\nDate: $when\nCustomer: {$o['customer_name']}\nPhone: {$o['customer_phone']}\nEmail: {$o['customer_email']}\n" . ($deliver ? '' : 'Deliver to: ' . $addr . "\n") . ($deliver ? "Collection: the customer will collect the order\n" : '')
            . "\nItems:\n" . implode("\n", $lines) . "\n\nMerchandise: " . market_email_money((int) $o['merchandise_ugx']) . "\nDelivery fee: " . market_email_money((int) $o['delivery_ugx']) . "\nCustomer paid: " . market_email_money((int) $o['total_ugx']) . " by $paidWith\nobitickets commission ($pct%): -"
            . market_email_money((int) $o['commission_ugx']) . "\nYou keep: " . market_email_money($keep) . "\nPayment status: Paid\nOrder status: New\n" . ($lowText !== '' ? "\nRunning low after this order:\n" . $lowText : '') . "\nView the order: $link\n";
        return ['NEW MERCHANDISE ORDER RECEIVED — OBI MARKET (' . $num . ')', $html, $text];
    }

    $link = $base . '/market-order.php?n=' . rawurlencode($num);
    $store = (string) ($ctx['store']['name'] ?? 'the seller');
    if ($kind === 'CUSTOMER') {
        $pairs = [['Order number', $num, true], ['Sold by', $store, false], ['Date', $when, false], [$deliver ? 'Collection' : 'Delivery to', $addr, false], ['Delivery fee', (int) $o['delivery_ugx'] > 0 ? market_email_money((int) $o['delivery_ugx']) : 'Free', false],
            ['Total paid', market_email_money((int) $o['total_ugx']) . ' by ' . $paidWith, true]];
        $html = market_email_shell('Your order is confirmed!', 'Thank you ' . $h(explode(' ', trim((string) $o['customer_name']))[0]) . '! Your payment was received and <b>' . $h($store) . '</b> has been told to prepare your order.',
            market_email_kv_html($pairs) . '<h3 style="margin:18px 0 8px;font-size:15px">What you ordered</h3>' . market_email_items_html($ctx['items']), 'Track your order', $link,
            'When your order arrives, open it and tap <b>I received my order</b>. The seller is paid only after you confirm. If something is wrong, you can report a problem on the same page. obitickets &middot; OBI MARKET.');
        $text = "YOUR OBI MARKET ORDER IS CONFIRMED!\n\nThank you! Your payment was received and $store has been told to prepare your order.\n\nOrder: $num\nSold by: $store\nTotal paid: " . market_email_money((int) $o['total_ugx']) . " by $paidWith\n" . ($deliver ? "Collection\n" : 'Delivery to: ' . $addr . "\n")
            . "\nItems:\n" . implode("\n", $lines) . "\n\nTrack your order: $link\n\nWhen it arrives, open it and tap \"I received my order\". The seller is paid only after you confirm.\n";
        return ['YOUR OBI MARKET ORDER IS CONFIRMED! (' . $num . ')', $html, $text];
    }
    if ($kind === 'SHIPPED') {
        $ready = $o['fulfilment'] === 'READY_FOR_COLLECTION' || $deliver;
        $title = $ready ? 'Your order is ready to collect' : 'Your order is on its way';
        $note = trim((string) ($ctx['status_note'] ?? ''));
        $html = market_email_shell($title, $h($store) . ($ready ? ' has your order ' : ' has sent your order ') . '<b>' . $h($num) . '</b>.' . ($note !== '' ? '<br><br><i>' . $h($note) . '</i>' : ''),
            market_email_items_html($ctx['items']), 'Open my order', $link, 'After you ' . ($ready ? 'collect' : 'receive') . ' it, open the order and tap <b>I received my order</b>. The seller is paid after you confirm. If something is wrong, report a problem on the same page. obitickets &middot; OBI MARKET.');
        $text = $title . "\n\n$store " . ($ready ? 'has your order ' : 'has sent your order ') . "$num.\n" . ($note !== '' ? "Note from the seller: $note\n" : '') . "\n" . implode("\n", $lines) . "\n\nOpen my order: $link\nAfter you " . ($ready ? 'collect' : 'receive') . " it, tap \"I received my order\".\n";
        return [$title . ' (' . $num . ')', $html, $text];
    }
    // CANCELLED
    $note = trim((string) ($ctx['status_note'] ?? ''));
    $html = market_email_shell('Your order was cancelled', $h($store) . ' cancelled order <b>' . $h($num) . '</b>.' . ($note !== '' ? ' Reason: <i>' . $h($note) . '</i>.' : ''),
        '<p style="margin:0 0 14px;line-height:1.5">You will get a full refund of <b>' . $h(market_email_money((int) $o['total_ugx'])) . '</b> to your mobile money number. The OBI MARKET team sends refunds by hand, so it can take a little while. Nothing else is needed from you.</p>' . market_email_items_html($ctx['items']),
        'See my order', $link, 'Questions about your refund? Reply to this email or contact support. obitickets &middot; OBI MARKET.');
    $text = "Your order was cancelled\n\n$store cancelled order $num." . ($note !== '' ? " Reason: $note" : '') . "\nYou will get a full refund of " . market_email_money((int) $o['total_ugx']) . " to your mobile money number.\n\nSee my order: $link\n";
    return ['Your OBI MARKET order was cancelled (' . $num . ')', $html, $text];
}

// ---------------------------------------------------------------------------------------------------- sending

/**
 * The scheduled job (and the fast path) call this: sends what is waiting.
 * $send($to, $subject, $html, $logKind, $text): bool is for tests; the real one is resend_send.
 * @return array{sent:int, skipped:int, failed:int, retry:int, waiting:int}
 */
function market_email_send_queued(int $limit = 50, ?callable $send = null): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retry' => 0, 'waiting' => 0];
    if (!market_ready()) {
        return $out;
    }
    $send = $send ?? static fn (string $to, string $subject, string $html, string $kind, string $text): bool => resend_send($to, $subject, $html, $kind, null, null, $text);
    $pdo = db();
    // claimed for sending long ago and never finished (the sender crashed): back in the queue
    $pdo->prepare("UPDATE market_emails SET status = 'QUEUED', last_error = 'The sender stopped before finishing: trying again' WHERE status = 'SENDING' AND claimed_at < NOW() - INTERVAL " . (int) MARKET_EMAIL_CLAIM_STALE_MIN . ' MINUTE')->execute();
    $rows = $pdo->query("SELECT id, order_id, kind, to_email, attempts FROM market_emails WHERE status = 'QUEUED' ORDER BY id LIMIT " . max(1, min(500, $limit)))->fetchAll();
    foreach ($rows as $r) {
        $claim = $pdo->prepare("UPDATE market_emails SET status = 'SENDING', claimed_at = NOW(), attempts = attempts + 1 WHERE id = ? AND status = 'QUEUED'");
        $claim->execute([(int) $r['id']]);
        if ($claim->rowCount() !== 1) {
            continue;   // another sender has this one
        }
        $attempt = (int) $r['attempts'] + 1;
        $set = static function (string $status, ?string $error) use ($pdo, $r): void {
            $pdo->prepare('UPDATE market_emails SET status = ?, last_error = ?, sent_at = IF(? = \'SENT\', NOW(), sent_at) WHERE id = ?')->execute([$status, $error !== null ? mb_substr($error, 0, 250) : null, $status, (int) $r['id']]);
        };
        try {
            if (!gift_email_deliverable((string) $r['to_email'])) {
                $set('SKIPPED', 'Reserved, demo or invalid address: it can never arrive');
                $out['skipped']++;
                continue;
            }
            $ctx = market_email_context((int) $r['order_id']);
            if (!$ctx) {
                $set('SKIPPED', 'The order is not paid, so nothing is sent');
                $out['skipped']++;
                continue;
            }
            if (in_array($r['kind'], ['SHIPPED', 'CANCELLED'], true)) {
                $n = $pdo->prepare('SELECT note FROM market_order_history WHERE order_id = ? AND to_status IN (' . ($r['kind'] === 'SHIPPED' ? "'SHIPPED','READY_FOR_COLLECTION'" : "'CANCELLED'") . ') ORDER BY id DESC LIMIT 1');
                $n->execute([(int) $r['order_id']]);
                $ctx['status_note'] = (string) ($n->fetchColumn() ?: '');
            }
            [$subject, $html, $text] = market_email_render((string) $r['kind'], $ctx);
            $ok = $send((string) $r['to_email'], $subject, $html, market_email_log_kind((string) $r['kind']), $text);
            if ($ok) {
                $set('SENT', null);
                $out['sent']++;
                continue;
            }
            $err = 'The email service did not accept the message (attempt ' . $attempt . ')';
        } catch (Throwable $e) {
            $err = 'Error while sending (attempt ' . $attempt . '): ' . $e->getMessage();
            error_log('[market] email ' . $r['id'] . ' failed: ' . $e->getMessage());
        }
        if ($attempt >= MARKET_EMAIL_ATTEMPTS) {
            $set('FAILED', $err);
            $out['failed']++;
            try {
                gift_wd_alert_admins('OBI MARKET email could not be sent (order ' . $r['order_id'] . ')', 'The ' . $r['kind'] . ' email for OBI MARKET order #' . $r['order_id'] . ' to ' . $r['to_email'] . ' failed ' . MARKET_EMAIL_ATTEMPTS . ' times: ' . $err . ' The order itself is fine. Check the Email log in Admin.');
            } catch (Throwable $e2) {
                error_log('[market] could not alert admins about a failed email: ' . $e2->getMessage());
            }
        } else {
            $set('QUEUED', $err);
            $out['retry']++;
        }
    }
    $out['waiting'] = (int) $pdo->query("SELECT COUNT(*) FROM market_emails WHERE status = 'QUEUED'")->fetchColumn();
    return $out;
}

// ---------------------------------------------------------------------------------------------------- "send me a test"

/**
 * Sends the seller a sample "new order" email (made-up order, clearly marked TEST) at their account address, so they can check it arrives and is not in spam.
 * Nothing is created: no order, no queue row, no money. At most 3 a day per seller.
 * @param callable|null $send test hook: ($to, $subject, $html, $logKind, $text): bool
 * @return array{ok:bool, message:string}
 */
function market_email_send_test(int $sellerId, ?callable $send = null): array
{
    $u = db()->prepare("SELECT email, name FROM users WHERE id = ? AND role = 'ORGANIZER'");
    $u->execute([$sellerId]);
    $seller = $u->fetch();
    $store = market_store($sellerId);
    if (!$seller || !$store) {
        return ['ok' => false, 'message' => 'Only a seller can send a test email.'];
    }
    $to = (string) $seller['email'];
    if (!gift_email_deliverable($to)) {
        return ['ok' => false, 'message' => 'Your account email address cannot receive mail, so no test was sent.'];
    }
    $key = 'market_test_email_' . $sellerId . '_' . date('Y-m-d');
    $count = (int) get_setting($key, '0');
    if ($count >= 3) {
        return ['ok' => false, 'message' => 'You have already sent 3 test emails today. Try again tomorrow.'];
    }
    $now = date('Y-m-d H:i:s');
    $order = ['order_number' => 'OBM-TEST', 'paid_at' => $now, 'created_at' => $now, 'customer_name' => 'Test Customer', 'customer_email' => $to, 'customer_phone' => '0772 000 000', 'delivery_method' => 'DELIVERY', 'delivery_name' => 'Test Customer',
        'delivery_phone' => '0772 000 000', 'delivery_address' => 'Plot 1, Sample Street', 'delivery_district' => 'Kampala', 'customer_note' => 'This is only a test', 'payment_method' => 'MTN_MOMO', 'merchandise_ugx' => 100000, 'delivery_ugx' => (int) $store['delivery_fee_ugx'],
        'total_ugx' => 100000 + (int) $store['delivery_fee_ugx'], 'commission_bps' => market_commission_current()['bps'], 'commission_ugx' => market_split(100000, market_commission_current()['bps'])['commission'],
        'seller_ugx' => market_split(100000, market_commission_current()['bps'])['seller'], 'fulfilment' => 'NEW'];
    $ctx = ['order' => $order, 'items' => [['product_id' => 0, 'variant_id' => 0, 'product_name' => 'Sample T-Shirt', 'variant_label' => 'M / Black', 'unit_price_ugx' => 50000, 'quantity' => 2, 'line_ugx' => 100000]], 'store' => $store,
        'seller_email' => $to, 'seller_name' => (string) $seller['name'], 'low' => []];
    [$subject, $html, $text] = market_email_render('ORGANIZER', $ctx);
    $subject = 'TEST: ' . $subject;
    $html = '<p style="font-family:Arial,sans-serif;max-width:580px;margin:0 auto;padding:12px 24px;background:#FFF7E6;color:#8A5A00;font-size:14px"><b>This is a test.</b> It shows what you receive when a customer pays. No real order was made.</p>' . $html;
    $text = "THIS IS A TEST. No real order was made.\n\n" . $text;
    $send = $send ?? static fn (string $to, string $subject, string $html, string $kind, string $text): bool => resend_send($to, $subject, $html, $kind, null, null, $text);
    try {
        $ok = $send($to, $subject, $html, 'market_test', $text);
    } catch (Throwable $e) {
        error_log('[market] test email failed: ' . $e->getMessage());
        $ok = false;
    }
    if (!$ok) {
        return ['ok' => false, 'message' => 'The email service did not accept the test message. Please try again in a few minutes.'];
    }
    coin_quiet_setting($key, (string) ($count + 1));
    return ['ok' => true, 'message' => 'Sent! Check the inbox of ' . $to . ' (and the spam folder) in a minute.'];
}
