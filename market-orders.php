<?php
require_once __DIR__ . '/includes/bootstrap.php';

/* OBI MARKET: the customer's own orders, newest first (ADR-003, phase 3). Each opens market-order.php. */
$user = require_login();
$uid = (int) $user['id'];
$orders = market_ready() ? market_customer_orders($uid) : [];
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);

$pageTitle = 'My orders — OBI MARKET';
$bodyClass = 'classic-page app-dark mk-page';
$hideWhatsApp = true;
$rcTabForce = 'tickets';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/market.css?v=<?= @filemtime(__DIR__ . '/assets/css/market.css') ?: time() ?>">

<div class="wrap mk mk-narrow">
  <div class="ad-top">
    <a class="ad-icon" href="/market.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>My orders</h1>
  </div>
  <?php if (!$orders): ?>
    <section class="mk-soon"><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-receipt"/></svg></i><h2>No orders yet</h2><p>Your OBI MARKET orders will show here, with their tracking.</p><a class="mk-btn" href="/market.php">Shop OBI MARKET</a></section>
  <?php else: ?>
    <div class="mk-orders">
      <?php foreach ($orders as $o): [$label, $tone] = market_status_label((string) $o['fulfilment'], (string) $o['payment_status']); $img = market_img($o['first_image']); ?>
      <a class="mk-order" href="/market-order.php?n=<?= urlencode((string) $o['order_number']) ?>">
        <span class="mk-lpic"><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="" loading="lazy"><?php else: ?><i><svg width="24" height="24" aria-hidden="true"><use href="#ic-hanger"/></svg></i><?php endif; ?></span>
        <span class="mk-lbody">
          <b><?= $h($o['first_name']) ?><?= (int) $o['items'] > 1 ? ' + ' . ((int) $o['items'] - 1) . ' more' : '' ?></b>
          <small><?= $h($o['store_name']) ?> &middot; <?= $h($o['order_number']) ?> &middot; <?= $h(date('j M Y', strtotime((string) $o['created_at']))) ?></small>
          <span class="mk-badge <?= $h($tone) ?>"><?= $h($label) ?></span>
        </span>
        <b class="mk-ltotal">UGX <?= $num($o['total_ugx']) ?></b>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
