<?php
// JSON endpoint behind OBI MARKET's customer screens (ADR-003, phase 3). Every action is for the signed-in person's OWN cart and orders:
//   cart_add / cart_set : put an item in the cart / set how many (0 removes it); the price is never taken from the browser
//   start               : create the checkout (re-priced and stock-reserved by includes/market.php) and ask ioTec to prompt the phone
//   poll                : ask how that payment is doing; the order is confirmed only after OUR server has asked ioTec and heard "paid", once
//   received / problem  : the customer confirms delivery (which releases the seller's earnings) or reports a problem (which freezes them)
// Looking at the cart and at your orders works even if OBI MARKET was switched off a moment ago; buying needs it switched on.

set_time_limit(45);   // ioTec calls (token + collection or status) can each take up to 15s

require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);
$user = api_require_login();
$uid = (int) $user['id'];

if (!market_ready()) {
    json_response(['error' => 'OBI MARKET is not available right now.'], 503);
}

$action = (string) ($body['action'] ?? '');
$int = static fn (string $k): int => max(0, (int) ($body[$k] ?? 0));

try {
    switch ($action) {
        case 'cart_add':
        case 'cart_set':
            if (!market_visible($user)) {
                json_response(['error' => 'OBI MARKET is not open yet.'], 503);
            }
            if (($user['account_status'] ?? 'ACTIVE') !== 'ACTIVE') {
                json_response(['error' => 'Your account cannot order right now.'], 403);
            }
            $vid = $int('variant_id');
            $qty = $int('quantity');
            if ($action === 'cart_add') {
                $have = db()->prepare('SELECT quantity FROM market_cart_items WHERE user_id = ? AND variant_id = ?');
                $have->execute([$uid, $vid]);
                $qty += (int) ($have->fetchColumn() ?: 0);
            }
            $r = market_cart_set($uid, $vid, $qty);
            if (!$r['ok']) {
                json_response(['error' => $r['error']], 400);
            }
            json_response(['ok' => true, 'quantity' => $r['quantity'], 'count' => market_cart_count($uid)]);

        case 'start':
            if (!market_enabled()) {
                json_response(['error' => 'OBI MARKET is not available right now.'], 503);
            }
            if (($user['account_status'] ?? 'ACTIVE') !== 'ACTIVE') {
                json_response(['error' => 'Your account cannot order right now.'], 403);
            }
            $in = [];
            foreach (['customer_name', 'customer_email', 'customer_phone', 'delivery_method', 'delivery_name', 'delivery_phone', 'delivery_address', 'delivery_district', 'delivery_note', 'phone', 'method', 'key'] as $k) {
                if (isset($body[$k]) && is_scalar($body[$k])) {
                    $in[$k] = (string) $body[$k];
                }
            }
            if (!empty($body['lines']) && is_array($body['lines'])) {   // "Buy now": just these items, not the whole cart
                $in['lines'] = [];
                foreach (array_slice($body['lines'], 0, 30) as $l) {
                    if (is_array($l)) {
                        $in['lines'][] = ['variant_id' => (int) ($l['variant_id'] ?? 0), 'quantity' => (int) ($l['quantity'] ?? 0)];
                    }
                }
            }
            $res = market_checkout_start($user, $in, gift_collect_fn());
            if (!$res['ok']) {
                if (!empty($res['throttled']) && function_exists('ipguard_note')) {
                    ipguard_note('pay_refused');
                }
                json_response(['error' => $res['error']], !empty($res['throttled']) ? 429 : 400);
            }
            json_response(['ok' => true, 'id' => $res['id'], 'reference' => $res['reference'], 'total' => $res['total'], 'orders' => $res['orders']]);

        case 'poll':
            $id = $int('id');
            // one checkout is not re-checked with ioTec more often than every 2 seconds by one visitor (the page polls every few seconds)
            $lastPoll = (float) ($_SESSION['market_poll_at'][$id] ?? 0);
            $nowMs = microtime(true);
            if ($nowMs - $lastPoll < 2.0) {
                json_response(['ok' => true, 'status' => 'PENDING', 'message' => null]);
            }
            $_SESSION['market_poll_at'] = array_slice(($_SESSION['market_poll_at'] ?? []) + [$id => $nowMs], -20, null, true);
            $_SESSION['market_poll_at'][$id] = $nowMs;
            $r = market_checkout_poll($id, $uid, gift_status_fn());
            $out = ['ok' => true, 'status' => $r['status'], 'message' => $r['message']];
            if ($r['status'] === 'PAID') {
                $out['orders'] = $r['orders'];
            }
            json_response($out);

        case 'received':
        case 'problem':
            $o = market_order_find($int('order_id'));
            if (!$o || (int) $o['customer_id'] !== $uid) {
                json_response(['error' => 'Order not found.'], 404);
            }
            $r = $action === 'received' ? market_order_confirm_received($uid, (int) $o['id']) : market_order_report_problem($uid, (int) $o['id'], (string) ($body['reason'] ?? ''));
            if (!$r['ok']) {
                json_response(['error' => $r['error']], 400);
            }
            json_response(['ok' => true]);

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (RuntimeException $e) {
    json_response(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('[market api] ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
