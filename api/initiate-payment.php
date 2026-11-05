<?php
// iotec calls (token + collection) can each take up to 15s; guard our own
// budget explicitly rather than depend on the host's ini default.
set_time_limit(45);

require __DIR__ . '/../includes/bootstrap.php';

$user = api_require_login();
$body = json_body();
api_csrf_verify($body);

$phone = trim((string) ($body['phone'] ?? ''));

$pending = $_SESSION['pending_checkout'] ?? null;
if (!$pending) {
    json_response(['error' => 'Your cart has expired. Please start again.'], 400);
}

$cart = resolve_checkout_cart($pending, (int) $user['id']);
if (isset($cart['error'])) {
    if ($cart['error'] === 'limit_reached') {
        json_response(['error' => $cart['notices'][0] ?? 'You have reached the per-person ticket limit for this event.'], 400);
    }
    if ($cart['error'] === 'event_ended') {
        unset($_SESSION['pending_checkout']);
        json_response(['error' => 'This event has ended, so tickets are no longer on sale.'], 400);
    }
    json_response(['error' => 'This event or ticket selection is no longer available.'], 400);
}

// A cart that totals zero (only free tickets) needs no phone number and no payment.
if (empty($cart['isFree']) && strlen(preg_replace('/\D/', '', $phone)) < 9) {
    json_response(['error' => 'Enter a valid phone number.'], 400);
}

// Abuse guard: a prompt goes to whatever number is typed, so cap how often a number or an account can start payments.
if (empty($cart['isFree'])) {
    $blocked = payguard_check((int) $user['id'], $phone);
    if ($blocked) {
        error_log('[payguard] blocked user ' . (int) $user['id'] . ' (' . $blocked['code'] . ')');
        payguard_alert_if_needed();
        json_response(['error' => $blocked['message']], 429);
    }
}

try {
    $orderId = create_pending_order(
        (int) $user['id'],
        (int) $cart['event']['id'],
        (string) $cart['event']['title'],
        $cart['lineItems'],
        $cart['subtotal'],
        $cart['fee'],
        $cart['total'],
        $cart['currency'],
        !empty($cart['isFree']) ? '' : $phone
    );
} catch (InsufficientTicketsException $e) {
    unset($_SESSION['pending_checkout']);
    json_response(['error' => $e->getMessage()], 400);
}

payguard_alert_if_needed(); // many failures in a short time emails the admins (never blocks the payment)

// If the buyer came from a moment's Get tickets bar, credit the sale to it (never blocks the payment).
try {
    moment_attribute_order($orderId, (int) $cart['event']['id']);
} catch (Throwable $e) {
    error_log('[moments] attribution failed: ' . $e->getMessage());
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

json_response(['orderId' => $orderId, 'free' => !empty($cart['isFree'])]);
