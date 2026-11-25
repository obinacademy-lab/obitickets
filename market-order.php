<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * One OBI MARKET order for its own customer (ADR-003, phase 3): what was bought, where it is going, the tracking, and the two things only the customer can do:
 * "I received my order" (which releases the seller's earnings) and "Report a problem" (which freezes them until an admin decides).
 * Nobody else can open the page: an order number that is not yours looks exactly like one that does not exist.
 */
$user = require_login();
$uid = (int) $user['id'];
$o = market_customer_order(trim((string) ($_GET['n'] ?? '')), $uid);
if (!$o) {
    http_response_code(404);
}
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$status = $o ? market_status_label((string) $o['fulfilment'], (string) $o['payment_status']) : ['', ''];
$sent = $o && in_array($o['fulfilment'], ['SHIPPED', 'READY_FOR_COLLECTION'], true);
$disputed = $o && $o['dispute_at'] !== null && $o['received_by'] === null;
$steps = ['NEW' => 'Order received', 'PROCESSING' => 'Being prepared', 'SHIPPED' => 'On its way', 'READY_FOR_COLLECTION' => 'Ready for collection', 'COMPLETED' => 'Delivered'];
$words = ['NEW' => 'Order received', 'PROCESSING' => 'Being prepared', 'SHIPPED' => 'Sent by the seller', 'READY_FOR_COLLECTION' => 'Ready for collection', 'COMPLETED' => 'Delivered', 'CANCELLED' => 'Cancelled', 'REFUND_REQUESTED' => 'Refund in progress', 'REFUNDED' => 'Refunded'];

$pageTitle = ($o ? $o['order_number'] . ' — ' : '') . 'OBI MARKET — obitickets';
$bodyClass = 'classic-page app-dark mk-page';
$hideWhatsApp = true;
$rcTabForce = 'tickets';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/market.css?v=<?= @filemtime(__DIR__ . '/assets/css/market.css') ?: time() ?>">

<div class="wrap mk mk-narrow">
  <div class="ad-top">
    <a class="ad-icon" href="/market-orders.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1><?= $o ? $h($o['order_number']) : 'Order' ?></h1>
  </div>
<?php if (!$o): ?>
  <section class="mk-soon"><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-search"/></svg></i><h2>We could not find that order</h2><p>Check the link, or open it from your orders list.</p><a class="mk-btn" href="/market-orders.php">My orders</a></section>
<?php else: ?>
  <?php if (!empty($_GET['paid'])): ?><div class="mk-msg ok">Thank you! Your payment was received and your order is confirmed. The seller has been told and will prepare it.</div><?php endif; ?>
  <div class="mk-msg err" id="mkMsg" role="alert" hidden></div>

  <section class="mk-card2">
    <div class="mk-ohead"><div><small>Sold by</small><a href="/market.php?store=<?= urlencode((string) $o['store_slug']) ?>"><b><?= $h($o['store_name']) ?></b></a></div><span class="mk-badge <?= $h($status[1]) ?>"><?= $h($status[0]) ?></span></div>
    <?php if ($o['payment_status'] === 'PENDING'): ?><p class="mk-hint">We are waiting for your mobile money payment. If you approved it, refresh in a moment. A payment that is not approved within <?= (int) MARKET_PENDING_EXPIRY_MINUTES ?> minutes is cancelled and nothing is charged.</p><?php endif; ?>
    <?php if (in_array($o['payment_status'], ['PARTIALLY_REFUNDED', 'REFUNDED'], true)): ?><p class="mk-hint">Refunded: UGX <?= $num($o['refunded_ugx']) ?>. Refunds are sent back to your mobile money by the OBI MARKET team.</p><?php endif; ?>
  </section>

  <?php if ($sent && !$disputed): ?>
  <section class="mk-card2 mk-confirm">
    <h3><?= $o['fulfilment'] === 'SHIPPED' ? 'Did your order arrive?' : 'Have you collected your order?' ?></h3>
    <p>When you confirm, the seller is paid for this order. <?php if ($o['release_after']): ?>If you do not answer, it is confirmed automatically on <b><?= $h(date('j M Y', strtotime((string) $o['release_after']))) ?></b>.<?php endif; ?></p>
    <div class="mk-actions">
      <button type="button" class="mk-btn" id="mkReceived" data-order="<?= (int) $o['id'] ?>">I received my order</button>
      <button type="button" class="mk-btn line" id="mkProblemBtn">Report a problem</button>
    </div>
    <form class="mk-problem-form" id="mkProblemForm" hidden>
      <label>What went wrong?<textarea name="reason" rows="3" maxlength="240" placeholder="Tell us what happened (it never arrived, wrong item, damaged...)"></textarea></label>
      <button type="submit" class="mk-btn" data-order="<?= (int) $o['id'] ?>">Send report</button>
    </form>
  </section>
  <?php elseif ($disputed): ?>
  <section class="mk-card2"><h3>Problem reported</h3><p class="mk-hint">You told us: &ldquo;<?= $h($o['dispute_reason']) ?>&rdquo;. The seller is not paid for this order until the OBI MARKET team has looked into it. We will contact you.</p></section>
  <?php endif; ?>

  <section class="mk-card2">
    <h3>Tracking</h3>
    <ol class="mk-track">
      <?php foreach ($o['history'] as $e): ?>
      <li><b><?= $h($words[$e['to_status']] ?? ucfirst(strtolower(str_replace('_', ' ', (string) $e['to_status'])))) ?></b><small><?= $h(date('j M, g:ia', strtotime((string) $e['created_at']))) ?><?= $e['actor_role'] === 'SELLER' ? ' &middot; by the seller' : '' ?></small><?php if (!empty($e['note'])): ?><span><?= $h($e['note']) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section class="mk-card2">
    <h3>What you ordered</h3>
    <?php foreach ($o['items'] as $i): $img = market_img($i['image_path']); ?>
    <div class="mk-line"><span class="mk-lpic"><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="" loading="lazy"><?php else: ?><i><svg width="24" height="24" aria-hidden="true"><use href="#ic-hanger"/></svg></i><?php endif; ?></span>
      <div class="mk-lbody"><a class="mk-lname" href="/market-product.php?id=<?= (int) $i['product_id'] ?>"><?= $h($i['product_name']) ?></a><?php if ($i['variant_label'] !== ''): ?><small><?= $h($i['variant_label']) ?></small><?php endif; ?><small><?= (int) $i['quantity'] ?> &times; UGX <?= $num($i['unit_price_ugx']) ?></small></div>
      <b class="mk-ltotal">UGX <?= $num($i['line_ugx']) ?></b></div>
    <?php endforeach; ?>
    <div class="mk-sumline"><span>Merchandise</span><span>UGX <?= $num($o['merchandise_ugx']) ?></span></div>
    <div class="mk-sumline"><span>Delivery</span><span><?= (int) $o['delivery_ugx'] > 0 ? 'UGX ' . $num($o['delivery_ugx']) : 'Free' ?></span></div>
    <div class="mk-sumline tot"><span>Total paid</span><b>UGX <?= $num($o['total_ugx']) ?></b></div>
  </section>

  <section class="mk-card2">
    <h3><?= $o['delivery_method'] === 'COLLECTION' ? 'Collection' : 'Delivery to' ?></h3>
    <?php if ($o['delivery_method'] === 'COLLECTION'): ?>
      <p class="mk-hint">You collect this order from the seller.<?= !empty($o['delivery_note']) ? ' ' . $h($o['delivery_note']) : '' ?></p>
    <?php else: ?>
      <p class="mk-addr"><b><?= $h($o['delivery_name']) ?></b><br><?= $h($o['delivery_phone']) ?><br><?= $h($o['delivery_address']) ?>, <?= $h($o['delivery_district']) ?><?php if (!empty($o['customer_note'])): ?><br><small>Your note: <?= $h($o['customer_note']) ?></small><?php endif; ?></p>
    <?php endif; ?>
    <p class="mk-fine">Order <?= $h($o['order_number']) ?> &middot; placed <?= $h(date('j M Y, g:ia', strtotime((string) $o['created_at']))) ?> &middot; paid with <?= $o['payment_method'] === 'AIRTEL_MONEY' ? 'Airtel Money' : 'MTN MoMo' ?></p>
  </section>
  <script type="application/json" id="mkData">{}</script>
  <script src="/assets/js/market.js?v=<?= @filemtime(__DIR__ . '/assets/js/market.js') ?: time() ?>" defer></script>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
