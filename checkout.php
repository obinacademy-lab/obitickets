<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Step 1: the event page's ticket form posts here. Stash the selection in
// the session and redirect to a plain GET — that way a login redirect (see
// require_login() below) or a page refresh never re-submits the form and
// never loses the cart.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_slug'])) {
    verify_csrf();
    $_SESSION['pending_checkout'] = [
        'event_slug' => $_POST['event_slug'],
        'qty' => $_POST['qty'] ?? [],
    ];
    header('Location: /checkout.php');
    exit;
}

$user = require_login(); // redirects to /login.php?next=/checkout.php if needed; the cart survives in the session either way

$pending = $_SESSION['pending_checkout'] ?? null;
if (!$pending) {
    header('Location: /');
    exit;
}

$event = get_event_by_slug($pending['event_slug']);
if (!$event) {
    unset($_SESSION['pending_checkout']);
    header('Location: /');
    exit;
}

$cart = resolve_checkout_cart($pending, (int) $user['id']);
if (isset($cart['error'])) {
    unset($_SESSION['pending_checkout']);
    $errorParam = match ($cart['error']) {
        'no_tickets_selected' => 'select_tickets',
        'event_ended' => 'ended',
        'limit_reached' => 'limit',
        default => 'sold_out',
    };
    header('Location: /event.php?slug=' . urlencode($pending['event_slug']) . '&error=' . $errorParam);
    exit;
}
['lineItems' => $lineItems, 'subtotal' => $subtotal, 'fee' => $fee, 'total' => $total, 'currency' => $currency] = $cart;
$isFree = !empty($cart['isFree']);
$paidTicketCount = (int) ($cart['paidTicketCount'] ?? array_sum(array_column($lineItems, 'quantity')));
$cartNotices = $cart['notices'] ?? [];
$ticketCount = array_sum(array_column($lineItems, 'quantity'));
try {
    moment_record_checkout((int) $event['id']); // only counts when the visitor came from a moment
} catch (Throwable $e) {
    error_log('[moments] checkout count failed: ' . $e->getMessage());
}

$pageTitle = 'Checkout — obitickets';
$hideWhatsApp = true; // keep the pay button and totals uncluttered
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec" style="max-width:640px; margin:0 auto">
    <h1 style="font-size:1.7rem">Checkout</h1>
    <p class="sub" style="text-align:left; margin-top:6px"><?= htmlspecialchars($event['title']) ?> &middot; <?= htmlspecialchars(format_event_date_range($event['starts_at'], $event['ends_at'])) ?></p>

    <div class="checkout-card" style="margin-top:24px">
      <h3 style="font-size:1rem; margin-bottom:14px">Your order</h3>
      <?php foreach ($lineItems as $item): ?>
        <div class="checkout-line">
          <span><?= (int) $item['quantity'] ?> &times; <?= htmlspecialchars($item['name']) ?></span>
          <span class="mono"><?= $item['line_total'] > 0 ? htmlspecialchars($currency . ' ' . number_format($item['line_total'], 0)) : 'Free' ?></span>
        </div>
      <?php endforeach; ?>
      <div class="checkout-line"><span>Service fee<?= $paidTicketCount > 0 ? ' (UGX ' . number_format(SERVICE_FEE_PER_TICKET) . ' &times; ' . $paidTicketCount . ' paid)' : '' ?></span><span class="mono"><?= $fee > 0 ? htmlspecialchars($currency . ' ' . number_format($fee, 0)) : htmlspecialchars($currency) . ' 0' ?></span></div>
      <div class="checkout-line total"><span><?= $isFree ? 'Total' : 'Pay now' ?></span><span class="mono"><?= $isFree ? 'Free' : htmlspecialchars($currency . ' ' . number_format($total, 0)) ?></span></div>
    </div>
    <?php foreach ($cartNotices as $n): ?><div class="alert alert-error" style="margin-top:14px"><?= htmlspecialchars($n) ?></div><?php endforeach; ?>

    <div data-payment-widget data-initiate-url="/api/initiate-payment.php" data-order-url="/order.php?id=" data-free="<?= $isFree ? '1' : '0' ?>" style="margin-top:24px">

      <?php if ($isFree): ?>
      <div data-state="phone">
        <div data-error class="alert alert-error hidden"></div>
        <button type="button" data-action="pay" class="btn btn-lg btn-block free-confirm" style="margin-top:4px">Confirm and get my ticket</button>
        <div class="secure-note"><svg width="13" height="13"><use href="#ic-shield"/></svg> No payment needed &middot; your ticket and QR code appear straight away</div>
      </div>
      <?php else: ?>
      <div data-state="phone">
        <div class="field">
          <label for="phone">Mobile money phone number</label>
          <input type="tel" id="phone" data-phone-input inputmode="tel" placeholder="07XX XXX XXX" autocomplete="tel">
          <div class="field-hint">MTN or Airtel &mdash; you'll get a prompt on this number to approve the payment.</div>
        </div>
        <div data-error class="alert alert-error hidden"></div>
        <button type="button" data-action="pay" class="btn btn-purple btn-lg btn-block" style="margin-top:16px">Pay <?= htmlspecialchars($currency . ' ' . number_format($total, 0)) ?></button>
        <div class="secure-note"><svg width="13" height="13"><use href="#ic-shield"/></svg> Secure checkout &middot; instant QR ticket</div>
      </div>
      <?php endif; ?>

      <div data-state="waiting" class="hidden pay-waiting">
        <div class="pay-spinner"></div>
        <p data-status-text>Starting payment&hellip;</p>
      </div>

      <div data-state="failed" class="hidden">
        <div class="alert alert-error" data-fail-text></div>
        <button type="button" data-action="retry" class="btn btn-line btn-lg btn-block" style="margin-top:16px">Try again</button>
      </div>

    </div>
  </section>
</div>

<script src="/assets/js/payment.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
