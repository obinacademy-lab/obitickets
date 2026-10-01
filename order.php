<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$orderId = (int) ($_GET['id'] ?? 0);
$order = $orderId ? get_order_for_user($orderId, (int) $user['id']) : null;

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found — obitickets';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <section class="auth-section">
        <div class="auth-card">
          <h1>Order not found</h1>
          <p class="sub">This order doesn't exist, or isn't yours.</p>
          <a class="btn btn-purple btn-block btn-lg" href="/my-tickets.php" style="margin-top:24px">Back to my tickets</a>
        </div>
      </section>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// "Check payment status" — each click asks iotec, so a short per-order
// throttle keeps a mashed button from hammering their API.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recheck_payment') {
    verify_csrf();
    $last = (int) ($_SESSION['payment_recheck_at'][$orderId] ?? 0);
    if (time() - $last < 8) {
        header('Location: /order.php?id=' . $orderId . '&check=throttled');
        exit;
    }
    $_SESSION['payment_recheck_at'][$orderId] = time();
    $outcome = recheck_order_payment((int) $user['id'], $orderId);
    header('Location: /order.php?id=' . $orderId . ($outcome === 'paid' ? '&success=1' : '&check=' . $outcome));
    exit;
}

$justPaid = isset($_GET['success']);

$checkMessages = [
    'pending' => ['warn', "Still waiting for confirmation from your mobile money provider. If you approved the prompt on your phone, give it a minute and check again — please don't pay a second time."],
    'failed' => ['error', "We couldn't find a completed payment for this order. If money left your wallet, please contact us with order #" . (int) $order['id'] . " and we'll sort it out."],
    'refund_needed' => ['error', "Your payment was received, but these tickets sold out before we could confirm it. We've flagged order #" . (int) $order['id'] . " for a refund. If you haven't heard from us within a day, please get in touch."],
    'unavailable' => ['warn', "We couldn't reach the payment provider just now. Please try again in a minute."],
    'throttled' => ['warn', 'You just checked — give it a few seconds and try again.'],
];
$checkNotice = $checkMessages[$_GET['check'] ?? ''] ?? null;

$isPaid = $order['status'] === 'PAID';
$canRecheck = order_can_recheck_payment($order);
$ticketCount = 0;
foreach ($order['items'] as $item) {
    $ticketCount += count($item['tickets']);
}
$failedReason = $order['status_message'] && $order['status_message'] !== STALE_ORDER_EXPIRY_MESSAGE
    ? $order['status_message']
    : null;

$pageTitle = 'Order #' . $order['id'] . ' — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec" style="max-width:640px; margin:0 auto">

    <?php if ($justPaid): ?>
      <div class="alert alert-success" style="margin-bottom:20px">🎉 Payment confirmed &mdash; your tickets are ready below.</div>
    <?php endif; ?>
    <?php if ($checkNotice): ?>
      <div class="alert alert-<?= $checkNotice[0] ?>" style="margin-bottom:20px"><?= htmlspecialchars($checkNotice[1]) ?></div>
    <?php endif; ?>

    <h1 style="font-size:1.6rem"><?= htmlspecialchars($order['event_title']) ?></h1>
    <p class="sub" style="text-align:left; margin-top:6px">
      <?= htmlspecialchars(date('D j M Y, g:ia', strtotime($order['event_starts_at']))) ?> &middot; <?= htmlspecialchars($order['venue_name']) ?>
    </p>

    <div class="checkout-card" style="margin-top:20px">
      <div class="checkout-line"><span>Order number</span><span class="mono">#<?= (int) $order['id'] ?></span></div>
      <div class="checkout-line"><span>Status</span><span class="mono"><?= htmlspecialchars($order['status']) ?></span></div>
      <div class="checkout-line"><span>Paid via</span><span class="mono"><?= htmlspecialchars(str_replace('_', ' ', $order['payment_method'] ?? '')) ?></span></div>
      <div class="checkout-line total"><span><?= $isPaid ? 'Total paid' : 'Total' ?></span><span class="mono"><?= htmlspecialchars($order['currency'] . ' ' . number_format((float) $order['total_amount'], 0)) ?></span></div>
    </div>

    <?php if ($order['status'] === 'PENDING' && !$checkNotice): ?>
      <div class="alert alert-warn" style="margin-top:20px">
        <strong>We're still confirming this payment.</strong>
        If you approved the prompt on your phone, tap the button below to check &mdash; and please don't pay again.
      </div>
    <?php elseif ($order['status'] === 'FAILED' && $canRecheck): ?>
      <?php /* Failed by our own timeout, not iotec's say-so — it may actually have been paid, so don't claim it didn't. */ ?>
      <div class="alert alert-warn" style="margin-top:20px">
        <strong>We couldn't confirm this payment.</strong>
        The payment request timed out. If you were charged, use the button below and we'll check with your mobile money provider.
      </div>
    <?php elseif ($order['status'] === 'FAILED'): ?>
      <div class="alert alert-error" style="margin-top:20px">
        <strong>This payment didn't go through.</strong>
        <?= $failedReason ? htmlspecialchars($failedReason) : 'The payment request timed out.' ?>
      </div>
    <?php endif; ?>

    <?php if ($canRecheck): ?>
      <form method="post" style="margin-top:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="recheck_payment">
        <button class="btn btn-purple btn-block" type="submit"><?= $order['status'] === 'FAILED' ? 'I was charged — check again' : 'Check payment status' ?></button>
      </form>
    <?php endif; ?>

    <?php if ($order['status'] === 'FAILED'): ?>
      <a class="btn btn-line btn-block" href="/event.php?slug=<?= urlencode($order['event_slug']) ?>" style="margin-top:12px">Try again</a>
    <?php endif; ?>

    <?php if ($ticketCount > 0): ?>
    <h2 style="font-size:1.1rem; margin-top:32px; margin-bottom:14px">Your tickets</h2>
    <div style="display:flex; flex-direction:column; gap:14px">
      <?php foreach ($order['items'] as $item): ?>
        <?php foreach ($item['tickets'] as $ticket): ?>
          <div class="ticket-issued">
            <div class="ticket-issued-top">
              <div>
                <div class="ticket-issued-tier"><?= htmlspecialchars($item['tier_name']) ?></div>
                <div class="ticket-issued-event"><?= htmlspecialchars($order['event_title']) ?></div>
              </div>
              <span class="tag-pill" style="background:var(--purple-tint); color:var(--purple-deep)"><?= htmlspecialchars($ticket['status']) ?></span>
            </div>
            <div class="ticket-issued-divider"></div>
            <div class="ticket-issued-qr" data-code="<?= htmlspecialchars($ticket['ticket_code']) ?>"></div>
            <div class="ticket-issued-code mono"><?= htmlspecialchars($ticket['ticket_code']) ?></div>
            <div class="ticket-issued-hint">Show this QR code at the gate</div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a class="btn btn-line btn-block" href="/my-tickets.php" style="margin-top:28px">Back to my tickets</a>
  </section>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  document.querySelectorAll('.ticket-issued-qr').forEach(function (el) {
    new QRCode(el, {
      text: el.getAttribute('data-code'),
      width: 128,
      height: 128,
      colorDark: '#1C1526',
      colorLight: '#ffffff',
    });
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
