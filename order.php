<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$orderId = (int) ($_GET['id'] ?? 0);
$order = $orderId ? get_order_for_user($orderId, (int) $user['id']) : null;

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found — obitickets';
    $bodyClass = 'classic-page';
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

$isFreeOrder = (float) $order['total_amount'] <= 0.0;
$orderEvent = events_online_ready() ? get_event_by_id((int) $order['event_id']) : null;
$orderIsOnline = $orderEvent && event_is_online($orderEvent);
$orderIsOnlineOnly = $orderEvent && event_is_online_only($orderEvent);
$joinAccess = ($isPaid && $orderIsOnline) ? get_online_access($orderEvent, $user) : null;
$orderWhere = $orderIsOnlineOnly ? event_online_label($orderEvent) : $order['venue_name'] . ($orderIsOnline ? ' + online' : '');

// The flyer for the top card (any event, online or not)
$orderEventFull = $orderEvent ?: get_event_by_id((int) $order['event_id']);
$orderFlyer = (string) ($orderEventFull['banner_image'] ?? '');
$orderFlyer = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $orderFlyer) ? $orderFlyer : '';
$statusTone = ['PAID' => 'g', 'PENDING' => 'y', 'FAILED' => 'r', 'CANCELLED' => 'r', 'REFUNDED' => 'n'][$order['status']] ?? 'n';

$pageTitle = 'Order #' . $order['id'] . ' — obitickets';
// The dark app look (owner's mockup, 11/18): the event card, the order, then one ticket per QR. The QR itself stays
// dark on a white square so every gate scanner reads it. Payment checks and ticket data are unchanged.
$bodyClass = 'classic-page app-dark tk-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/ticket-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/ticket-app.css') ?: time() ?>">

<div class="wrap tk">
  <div class="ad-top">
    <a class="ad-icon" href="/my-tickets.php" aria-label="Back to my tickets"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1><?= $ticketCount === 1 ? 'Your Ticket' : 'Your Tickets' ?></h1>
  </div>

  <a class="tk-event" href="/event.php?slug=<?= urlencode($order['event_slug']) ?>">
    <span class="tk-event-art"><?php if ($orderFlyer !== ''): ?><img src="<?= htmlspecialchars($orderFlyer) ?>" alt=""><?php endif; ?></span>
    <span class="tk-event-tx">
      <b><?= htmlspecialchars($order['event_title']) ?></b>
      <small><?= htmlspecialchars(date('D j M Y · g:i A', strtotime($order['event_starts_at']))) ?></small>
      <small><?= htmlspecialchars($orderWhere) ?></small>
    </span>
  </a>

  <?php if ($justPaid): ?>
    <div class="tk-note tk-ok"><?= $isFreeOrder ? "🎉 You're in &mdash; your free ticket is ready below." : '🎉 Payment confirmed &mdash; your tickets are ready below.' ?></div>
  <?php endif; ?>
  <?php if ($checkNotice): ?>
    <div class="tk-note tk-<?= $checkNotice[0] ?>"><?= htmlspecialchars($checkNotice[1]) ?></div>
  <?php endif; ?>

  <?php if ($joinAccess): ?>
    <div class="tk-join"><?= render_join_card_html($orderEvent, $joinAccess) ?></div>
  <?php endif; ?>

  <?php if ($order['status'] === 'PENDING' && !$checkNotice): ?>
    <div class="tk-note tk-warn"><strong>We're still confirming this payment.</strong> If you approved the prompt on your phone, tap the button below to check &mdash; and please don't pay again.</div>
  <?php elseif ($order['status'] === 'FAILED' && $canRecheck): ?>
    <?php /* Failed by our own timeout, not iotec's say-so — it may actually have been paid, so don't claim it didn't. */ ?>
    <div class="tk-note tk-warn"><strong>We couldn't confirm this payment.</strong> The payment request timed out. If you were charged, use the button below and we'll check with your mobile money provider.</div>
  <?php elseif ($order['status'] === 'FAILED'): ?>
    <div class="tk-note tk-error"><strong>This payment didn't go through.</strong> <?= $failedReason ? htmlspecialchars($failedReason) : 'The payment request timed out.' ?></div>
  <?php endif; ?>

  <?php if ($canRecheck): ?>
    <form method="post" class="tk-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="recheck_payment">
      <button class="tk-btn tk-red" type="submit"><?= $order['status'] === 'FAILED' ? 'I was charged — check again' : 'Check payment status' ?></button>
    </form>
  <?php endif; ?>
  <?php if ($order['status'] === 'FAILED'): ?>
    <a class="tk-btn" href="/event.php?slug=<?= urlencode($order['event_slug']) ?>">Try again</a>
  <?php endif; ?>

  <?php if ($ticketCount > 0): ?>
    <div class="tk-list">
      <?php $n = 0; foreach ($order['items'] as $item): ?>
        <?php foreach ($item['tickets'] as $ticket): $n++; ?>
          <article class="tk-ticket">
            <div class="tk-ticket-top">
              <div>
                <span class="tk-tier"><?= htmlspecialchars($item['tier_name']) ?></span>
                <?php if ($ticketCount > 1): ?><small><?= $n ?> of <?= $ticketCount ?></small><?php endif; ?>
              </div>
              <span class="tk-pill tk-<?= $ticket['status'] === 'VALID' || $ticket['status'] === 'ACTIVE' ? 'g' : 'n' ?>"><?= htmlspecialchars($ticket['status']) ?></span>
            </div>
            <div class="tk-cut" aria-hidden="true"></div>
            <?php if (!$orderIsOnlineOnly): ?><div class="tk-qr"><div class="ticket-issued-qr" data-code="<?= htmlspecialchars($ticket['ticket_code']) ?>" role="img" aria-label="QR code for ticket <?= htmlspecialchars($ticket['ticket_code']) ?>"></div></div><?php endif; ?>
            <div class="ticket-issued-code tk-code mono"><?= htmlspecialchars($ticket['ticket_code']) ?></div>
            <div class="tk-hint"><?= $orderIsOnlineOnly ? 'Online ticket. Use the Join button above to enter the meeting.' : ($orderIsOnline ? 'Show this QR code at the venue. Use the Join button above for the online room.' : 'Show this QR code at the gate. Turn your screen brightness up.') ?></div>
          </article>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="tk-sum">
    <div><span>Order number</span><b class="mono">#<?= (int) $order['id'] ?></b></div>
    <div><span>Status</span><b><span class="tk-pill tk-<?= $statusTone ?>"><?= htmlspecialchars($order['status']) ?></span></b></div>
    <?php if (!$isFreeOrder): ?><div><span>Paid via</span><b><?= htmlspecialchars(str_replace('_', ' ', $order['payment_method'] ?? '')) ?></b></div><?php endif; ?>
    <div class="tk-total"><span><?= $isPaid && !$isFreeOrder ? 'Total paid' : 'Total' ?></span><b><?php if ($isFreeOrder): ?><span>Free</span><?php else: ?><?= htmlspecialchars($order['currency'] . ' ' . number_format((float) $order['total_amount'], 0)) ?><?php endif; ?></b></div>
  </div>

  <a class="tk-btn" href="/my-tickets.php">Back to my tickets</a>
</div>

<script src="/assets/js/join-card.js?v=<?= @filemtime(__DIR__ . '/assets/js/join-card.js') ?: time() ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  document.querySelectorAll('.ticket-issued-qr').forEach(function (el) {
    new QRCode(el, {
      text: el.getAttribute('data-code'),
      width: 200,
      height: 200,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
