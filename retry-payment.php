<?php
require_once __DIR__ . '/includes/bootstrap.php';

/**
 * The "pay now" link in the payment follow-up email. Signed and time-limited (see recovery_retry_url()); it only
 * rebuilds the cart a failed order held and sends the buyer to the normal checkout page, so nothing is paid or
 * reserved here and the checkout page still re-reads prices and stock itself. The buyer has to be signed in as the
 * account that placed the order.
 */
function retry_page(string $title, string $message, string $href, string $label): never
{
    $pageTitle = $title . ' — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap" style="max-width:520px; margin:56px auto; padding:0 16px; text-align:center">'
        . '<h1 style="font-size:1.6rem; margin:0 0 10px">' . htmlspecialchars($title) . '</h1>'
        . '<p style="color:#6B6577; margin:0 0 24px">' . htmlspecialchars($message) . '</p>'
        . '<a class="btn btn-purple" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$orderId = (int) ($_GET['o'] ?? 0);
$expires = (int) ($_GET['x'] ?? 0);
$sig = (string) ($_GET['s'] ?? '');

if (!recovery_link_valid($orderId, $expires, $sig)) {
    retry_page('This link has expired', 'Tickets sell out and prices can change, so these links only work for a couple of days. You can still pick your tickets again.', '/', 'Browse events');
}

$user = require_login(); // signs in first, then comes straight back here

$stmt = db()->prepare('SELECT o.*, e.slug AS event_slug FROM orders o JOIN events e ON e.id = o.event_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    retry_page('We could not find that order', 'Please pick your tickets again from the event page.', '/', 'Browse events');
}
if ((int) $order['user_id'] !== (int) $user['id']) {
    retry_page('This link belongs to another account', 'Sign out and sign in with the account you used to order, then open the link in your email again.', '/logout.php', 'Sign out');
}
// Already got their tickets (this order, or a later one for the same event)? Show those instead of charging twice.
$paid = db()->prepare("SELECT id FROM orders WHERE user_id = ? AND event_id = ? AND status = 'PAID' ORDER BY id DESC LIMIT 1");
$paid->execute([$user['id'], $order['event_id']]);
if ($paidId = $paid->fetchColumn()) {
    header('Location: /order.php?id=' . (int) $paidId);
    exit;
}

$cart = recovery_cart_for_order($orderId);
$eventUrl = '/event.php?slug=' . urlencode((string) $order['event_slug']);
if (!$cart) {
    header('Location: ' . $eventUrl);
    exit;
}
$resolved = resolve_checkout_cart($cart, (int) $user['id']);
if (isset($resolved['error'])) {
    $reason = $resolved['error'] === 'event_ended' ? 'ended' : ($resolved['error'] === 'limit_reached' ? 'limit' : 'sold_out');
    header('Location: ' . $eventUrl . '&error=' . $reason);
    exit;
}

// Same session cart the event page's form builds, so checkout.php takes it from here with the selection already made.
$_SESSION['pending_checkout'] = $cart;
if (!empty($order['phone'])) {
    $_SESSION['checkout_phone_hint'] = (string) $order['phone'];
}
header('Location: /checkout.php');
exit;
