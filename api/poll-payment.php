<?php
// iotec calls (token + status check) can each take up to 15s; guard our own
// budget explicitly rather than depend on the host's ini default.
set_time_limit(45);

require __DIR__ . '/../includes/bootstrap.php';

$user = api_require_login();
$body = json_body();
api_csrf_verify($body);

$orderId = (int) ($body['orderId'] ?? 0);

try {
    $result = poll_order_payment((int) $user['id'], $orderId);
    json_response($result);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
