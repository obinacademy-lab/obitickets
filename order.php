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

$justPaid = isset($_GET['success']);

$pageTitle = 'Order #' . $order['id'] . ' — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec" style="max-width:640px; margin:0 auto">

    <?php if ($justPaid): ?>
      <div class="alert alert-success" style="margin-bottom:20px">🎉 Payment confirmed &mdash; your tickets are ready below.</div>
    <?php endif; ?>

    <h1 style="font-size:1.6rem"><?= htmlspecialchars($order['event_title']) ?></h1>
    <p class="sub" style="text-align:left; margin-top:6px">
      <?= htmlspecialchars(date('D j M Y, g:ia', strtotime($order['event_starts_at']))) ?> &middot; <?= htmlspecialchars($order['venue_name']) ?>
    </p>

    <div class="checkout-card" style="margin-top:20px">
      <div class="checkout-line"><span>Order number</span><span class="mono">#<?= (int) $order['id'] ?></span></div>
      <div class="checkout-line"><span>Status</span><span class="mono"><?= htmlspecialchars($order['status']) ?></span></div>
      <div class="checkout-line"><span>Paid via</span><span class="mono"><?= htmlspecialchars(str_replace('_', ' ', $order['payment_method'] ?? '')) ?></span></div>
      <div class="checkout-line total"><span>Total paid</span><span class="mono"><?= htmlspecialchars($order['currency'] . ' ' . number_format((float) $order['total_amount'], 0)) ?></span></div>
    </div>

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
