<?php
// JSON endpoint behind the OBI GIFTS sheet (phase 3). Three actions, all for a logged-in viewer:
//   catalog : the gifts a viewer can send, the recipient's name, the limits, and why a gift to them is not possible (if so)
//   start   : create a pending gift and ask ioTec to prompt the viewer's phone (price and split come from the database)
//   poll    : ask how that gift is doing; once ioTec confirmed (checked by OUR server) the gift is delivered and credited once
// Every rule lives in includes/gifts.php. Nothing here touches tickets. Gifts must be switched on by Admin.

set_time_limit(45);   // ioTec calls (token + collection or status) can each take up to 15s

require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);
$user = api_require_login();
$uid = (int) $user['id'];

if (!gifts_enabled()) {
    json_response(['error' => 'Gifts are not available right now.'], 503);
}
if (($user['account_status'] ?? 'ACTIVE') !== 'ACTIVE') {
    json_response(['error' => 'Your account cannot send gifts.'], 403);
}

$action = (string) ($body['action'] ?? '');
$coinsMode = function_exists('coins_enabled') && coins_enabled();   // OBI COINS on: gifts are paid from the wallet (ADR-002 decision 1)
$orgId = max(0, (int) ($body['organizer_id'] ?? 0));
$postId = !empty($body['post_id']) ? (int) $body['post_id'] : null;

try {
    switch ($action) {
        case 'catalog':
            $stmt = db()->prepare("SELECT COALESCE(NULLIF(op.org_name, ''), u.name) AS name, (op.verification_status = 'VERIFIED') AS verified
                                   FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE u.id = ? AND u.role = 'ORGANIZER'");
            $stmt->execute([$orgId]);
            $org = $stmt->fetch();
            if (!$org) {
                json_response(['error' => 'That organizer could not be found.'], 404);
            }
            json_response([
                'ok' => true,
                'organizer' => ['id' => $orgId, 'name' => $org['name'], 'verified' => (int) $org['verified'] === 1, 'avatar' => function_exists('user_avatar_url') ? user_avatar_url($orgId) : null],
                'recipientError' => gift_recipient_error($orgId, $uid, $postId),
                'mode' => $coinsMode ? 'coins' : 'direct',
                'gifts' => array_map(static fn ($g) => gift_public_shape($g, $coinsMode), $coinsMode ? array_values(array_filter(gift_catalog_active(), static fn ($g) => (int) ($g['price_coins'] ?? 0) > 0)) : gift_catalog_active()),
                'wallet' => $coinsMode ? ['balance' => coin_wallet($uid)['balance']] : null,
                'limits' => ['min' => GIFT_MIN_UGX, 'max' => GIFT_MAX_PAYMENT_UGX, 'daily' => GIFT_DAILY_CAP_UGX, 'maxQty' => GIFT_MAX_QUANTITY],
                'phone' => $coinsMode ? '' : gift_phone_hint($user),
            ]);

        case 'start':
            if ($coinsMode) {
                // paid from the wallet: one atomic step on the server, delivered at once (no phone prompt, nothing left waiting)
                $res = coin_gift_send($user, $orgId, max(0, (int) ($body['gift_id'] ?? 0)), (int) ($body['quantity'] ?? 1), !empty($body['anonymous']), $postId, isset($body['key']) ? (string) $body['key'] : null);
                if (!$res['ok']) {
                    if (!empty($res['insufficient'])) {
                        json_response(['error' => $res['error'], 'insufficient' => true, 'balance' => $res['balance'], 'needed' => $res['needed']], 402);
                    }
                    json_response(['error' => $res['error']], 400);
                }
                json_response(['ok' => true, 'coins' => true, 'id' => $res['id'], 'reference' => $res['reference'], 'spent' => $res['coins'], 'balance_after' => $res['balance_after'], 'delivery' => gift_delivery_payload((int) $res['id'])]);
            }
            $res = gift_start(
                $user,
                $orgId,
                max(0, (int) ($body['gift_id'] ?? 0)),
                (int) ($body['quantity'] ?? 1),
                !empty($body['anonymous']),
                trim((string) ($body['phone'] ?? '')),
                $postId,
                isset($body['key']) ? (string) $body['key'] : null,
                isset($body['method']) ? (string) $body['method'] : null,
                gift_collect_fn()
            );
            if (!$res['ok']) {
                json_response(['error' => $res['error']], 400);
            }
            json_response(['ok' => true, 'id' => $res['id'], 'reference' => $res['reference'], 'gross' => $res['gross']]);

        case 'poll':
            $id = max(0, (int) ($body['id'] ?? 0));
            $r = gift_poll($id, $uid, gift_status_fn());
            $out = ['ok' => true, 'status' => $r['status'], 'message' => $r['message']];
            if ($r['status'] === 'PAID') {
                $out['delivery'] = gift_delivery_payload($id);
            }
            json_response($out);

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (RuntimeException $e) {
    json_response(['error' => 'Gift not found.'], 404);
} catch (Throwable $e) {
    error_log('[gifts api] ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
