<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET cart (ADR-003, phase 3): the signed-in customer's items grouped by store, priced from the database right now (never from the browser).
 * +/- and remove call api/market.php (assets/js/market.js) and reload. A line that cannot be bought says why and blocks checkout until it is fixed.
 */
$user = require_login();
$uid = (int) $user['id'];
$visible = market_visible($user);
$open = $visible && market_enabled();
$quote = $visible ? market_checkout_quote($uid, null, 'DELIVERY') : ['stores' => [], 'lines' => [], 'merch' => 0, 'delivery' => 0, 'total' => 0, 'buyable' => false, 'methods' => [], 'problems' => []];
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$cartCount = market_cart_count($uid);

$pageTitle = 'Your cart — OBI MARKET';
$bodyClass = 'classic-page app-dark mk-page';
$hideWhatsApp = true;
$rcTabForce = 'discover';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/market.css?v=<?= @filemtime(__DIR__ . '/assets/css/market.css') ?: time() ?>">

<div class="wrap mk mk-narrow">
  <div class="ad-top">
    <a class="ad-icon" href="/market.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Your cart</h1>
    <a class="ad-icon" href="/market-orders.php" aria-label="My orders"><svg width="20" height="20" aria-hidden="true"><use href="#ic-receipt"/></svg></a>
  </div>

<?php if (!$visible): ?>
  <section class="mk-soon"><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-hanger"/></svg></i><h2>OBI MARKET is opening soon</h2><a class="mk-btn" href="/discover.php">Browse events meanwhile</a></section>
<?php elseif (!$quote['lines']): ?>
  <section class="mk-soon"><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-hanger"/></svg></i><h2>Your cart is empty</h2><p>Find something you like and add it here.</p><a class="mk-btn" href="/market.php">Shop OBI MARKET</a></section>
<?php else: ?>
  <?php if (!$open): ?><div class="mk-preview"><b>Preview.</b> OBI MARKET is switched off for the public, so checkout is closed.</div><?php endif; ?>
  <div class="mk-msg err" id="mkMsg" role="alert" hidden></div>
  <?php foreach ($quote['stores'] as $s): ?>
  <section class="mk-group">
    <header><a href="/market.php?store=<?= urlencode((string) $s['slug']) ?>"><svg width="15" height="15" aria-hidden="true"><use href="#ic-tag"/></svg><?= $h($s['store']) ?></a>
      <small><?= $s['mode'] === 'COLLECTION' ? 'Collection only' : ($s['mode'] === 'DELIVERY' ? 'Delivery only' : 'Delivery or collection') ?></small></header>
    <?php foreach ($s['lines'] as $l): $img = market_img($l['image']); ?>
    <div class="mk-line<?= $l['problem'] ? ' bad' : '' ?>" data-variant="<?= (int) $l['variant_id'] ?>" data-max="<?= (int) min(MARKET_MAX_QTY_LINE, max(1, $l['available'])) ?>">
      <a class="mk-lpic" href="/market-product.php?id=<?= (int) $l['product_id'] ?>"><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="" loading="lazy"><?php else: ?><i><svg width="26" height="26" aria-hidden="true"><use href="#ic-hanger"/></svg></i><?php endif; ?></a>
      <div class="mk-lbody">
        <a class="mk-lname" href="/market-product.php?id=<?= (int) $l['product_id'] ?>"><?= $h($l['name']) ?></a>
        <?php if ($l['label'] !== ''): ?><small><?= $h($l['label']) ?></small><?php endif; ?>
        <span class="mk-lprice">UGX <?= $num($l['unit']) ?></span>
        <?php if ($l['problem']): ?><em class="mk-problem"><?= $h($l['problem']) ?></em><?php endif; ?>
        <div class="mk-lrow">
          <div class="mk-step sm"><button type="button" data-d="-1" aria-label="One less">&minus;</button><output><?= (int) $l['quantity'] ?></output><button type="button" data-d="1" aria-label="One more">+</button></div>
          <button type="button" class="mk-remove" data-remove>Remove</button>
        </div>
      </div>
      <b class="mk-ltotal">UGX <?= $num($l['line']) ?></b>
    </div>
    <?php endforeach; ?>
    <footer><span>Merchandise from <?= $h($s['store']) ?></span><b>UGX <?= $num($s['merch']) ?></b></footer>
  </section>
  <?php endforeach; ?>

  <section class="mk-sum">
    <div><span>Merchandise</span><b>UGX <?= $num($quote['merch']) ?></b></div>
    <div><span>Delivery</span><b>Worked out at checkout</b></div>
    <div class="tot"><span>Subtotal</span><b>UGX <?= $num($quote['merch']) ?></b></div>
    <?php if ($quote['problems']): ?><p class="mk-problem-note">Fix the items marked above to continue.</p><?php endif; ?>
    <?php if ($quote['buyable'] && $open): ?>
      <a class="mk-btn big" href="/market-checkout.php">Checkout</a>
    <?php else: ?>
      <button type="button" class="mk-btn big" disabled><?= $open ? 'Checkout' : 'Checkout is closed' ?></button>
    <?php endif; ?>
    <a class="mk-link" href="/market.php">&larr; Keep shopping</a>
  </section>
  <script src="/assets/js/market.js?v=<?= @filemtime(__DIR__ . '/assets/js/market.js') ?: time() ?>" defer></script>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
