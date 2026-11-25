<?php
// JSON endpoint behind the OBI COINS wallet (ADR-002, phase 3). Four actions, all for a logged-in person's OWN wallet:
//   packages : the balance, the recharge packages that can be bought, the suggested phone number, and any payment still waiting
//   start    : create a purchase and ask ioTec to prompt the person's phone (price, coins and bonus come from the database)
//   poll     : ask how that purchase is doing; the coins are credited only after OUR server has asked ioTec and heard "paid", once
//   history  : the wallet history (paged)
// Every rule lives in includes/coins.php. Nothing here touches tickets. Buying needs OBI COINS switched on by Admin; looking at the wallet and
// finishing a payment already in flight work even if it was switched off a moment ago, so nobody pays and then cannot get their coins.

set_time_limit(45);   // ioTec calls (token + collection or status) can each take up to 15s

require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);
$user = api_require_login();
$uid = (int) $user['id'];

if (!coins_ready()) {
    json_response(['error' => 'OBI COINS is not available right now.'], 503);
}

$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'packages':
            $open = coin_purchase_open($uid);
            json_response([
                'ok' => true,
                'enabled' => coins_enabled(),
                'wallet' => coin_wallet($uid),
                'packages' => array_map('coin_package_public', coin_packages_active()),
                'limits' => ['min' => COIN_MIN_PURCHASE_UGX, 'max' => COIN_MAX_PURCHASE_UGX, 'daily' => COIN_DAILY_PURCHASE_CAP_UGX],
                'phone' => coin_phone_hint($user),
                'open' => $open ? ['id' => (int) $open['id'], 'price' => (int) $open['price_ugx'], 'coins' => (int) $open['coins'] + (int) $open['bonus_coins'], 'phone' => coin_phone_display($open['phone'])] : null,
            ]);

        case 'start':
            if (!coins_enabled()) {
                json_response(['error' => 'OBI COINS is not available right now.'], 503);
            }
            if (($user['account_status'] ?? 'ACTIVE') !== 'ACTIVE') {
                json_response(['error' => 'Your account cannot buy OBI COINS.'], 403);
            }
            $res = coin_purchase_start(
                $user,
                max(0, (int) ($body['package_id'] ?? 0)),
                trim((string) ($body['phone'] ?? '')),
                isset($body['key']) ? (string) $body['key'] : null,
                isset($body['method']) ? (string) $body['method'] : null,
                gift_collect_fn()
            );
            if (!$res['ok']) {
                if (!empty($res['throttled']) && function_exists('ipguard_note')) {
                    ipguard_note('pay_refused');
                }
                json_response(['error' => $res['error']], !empty($res['throttled']) ? 429 : 400);
            }
            json_response(['ok' => true, 'id' => $res['id'], 'reference' => $res['reference'], 'price' => $res['price'], 'coins' => $res['coins'], 'bonus' => $res['bonus']]);

        case 'poll':
            $id = max(0, (int) ($body['id'] ?? 0));
            // the same purchase is not re-checked with ioTec more often than every COIN_POLL_MIN_SECONDS by one visitor (a page polls every few seconds)
            $lastPoll = (float) ($_SESSION['coin_poll_at'][$id] ?? 0);
            $nowMs = microtime(true);
            if ($nowMs - $lastPoll < COIN_POLL_MIN_SECONDS) {
                json_response(['ok' => true, 'status' => 'PENDING', 'message' => null, 'balance' => coin_wallet($uid)['balance']]);
            }
            $_SESSION['coin_poll_at'] = array_slice(($_SESSION['coin_poll_at'] ?? []) + [$id => $nowMs], -20, null, true);
            $_SESSION['coin_poll_at'][$id] = $nowMs;
            $r = coin_purchase_poll($id, $uid, gift_status_fn());
            $out = ['ok' => true, 'status' => $r['status'], 'message' => $r['message'], 'balance' => $r['balance']];
            if ($r['status'] === 'PAID') {
                $p = db()->prepare('SELECT coins, bonus_coins, price_ugx FROM coin_purchases WHERE id = ? AND user_id = ?');
                $p->execute([$id, $uid]);
                if ($row = $p->fetch()) {
                    $out['credited'] = (int) $row['coins'] + (int) $row['bonus_coins'];
                    $out['price'] = (int) $row['price_ugx'];
                }
            }
            json_response($out);

        case 'history':
            $h = coin_wallet_history($uid, 20, max(0, (int) ($body['before'] ?? 0)));
            json_response(['ok' => true, 'rows' => $h['rows'], 'next' => $h['next']]);

        case 'gifts':
            $g = gift_sent_history($uid, 15, max(0, (int) ($body['before'] ?? 0)));
            json_response(['ok' => true, 'rows' => $g['rows'], 'next' => $g['next']]);

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (RuntimeException $e) {
    json_response(['error' => 'Purchase not found.'], 404);
} catch (Throwable $e) {
    error_log('[coins api] ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
