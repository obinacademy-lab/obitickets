<?php
// iotec calls (token + collection) can each take up to 15s; guard our own
// budget explicitly rather than depend on the host's ini default.
set_time_limit(45);

require __DIR__ . '/../includes/bootstrap.php';

$user = api_require_login();
$body = json_body();
api_csrf_verify($body);

$phone = trim((string) ($body['phone'] ?? ''));
if (strlen(preg_replace('/\D/', '', $phone)) < 9) {
    json_response(['error' => 'Enter a valid phone number.'], 400);
}

$pending = $_SESSION['pending_checkout'] ?? null;
if (!$pending) {
    json_response(['error' => 'Your cart has expired. Please start again.'], 400);
}

$cart = resolve_checkout_cart($pending);
if (isset($cart['error'])) {
    json_response(['error' => 'This event or ticket selection is no longer available.'], 400);
}

try {
    $orderId = create_pending_order(
        (int) $user['id'],
        (int) $cart['event']['id'],
        $cart['lineItems'],
        $cart['subtotal'],
        $cart['fee'],
        $cart['total'],
        $cart['currency'],
        $phone
    );
} catch (InsufficientTicketsException $e) {
    unset($_SESSION['pending_checkout']);
    json_response(['error' => $e->getMessage()], 400);
}

unset($_SESSION['pending_checkout']);

// create_pending_order() reports an immediate iotec failure (bad
// credentials, network error) by leaving the order FAILED rather than
// throwing — surface that here instead of telling the browser to start
// polling an order that already isn't going anywhere.
$order = get_order_for_user($orderId, (int) $user['id']);
if ($order && $order['status'] === 'FAILED') {
    json_response(['error' => $order['status_message'] ?? "We couldn't start the mobile money payment. Please try again."], 400);
}

json_response(['orderId' => $orderId]);
