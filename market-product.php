<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET product page (ADR-003, phase 3): gallery, store, price, size and colour choice with what is left, quantity, Add to cart / Buy now,
 * delivery terms, description, the events it belongs to. The variants are embedded as JSON for assets/js/market.js; the server re-checks
 * everything when the item is added and again at checkout, so the page is only a convenience.
 */
$user = current_user();
$visible = market_visible($user);
$open = $visible && market_enabled();
$p = $visible ? market_product_public(max(0, (int) ($_GET['id'] ?? 0))) : null;
if (!$p) {
    http_response_code($visible ? 404 : 200);
}
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$cartCount = $user ? market_cart_count((int) $user['id']) : 0;
$own = $p && $user && (int) $user['id'] === (int) $p['seller_id'];
$modeText = $p ? match ($p['delivery_mode']) { 'DELIVERY' => 'Delivery only', 'COLLECTION' => 'Collection only', default => 'Delivery or collection' } : '';

$pageTitle = ($p ? $p['name'] . ' — ' : '') . 'OBI MARKET — obitickets';
// link previews (WhatsApp, Facebook, ...): the product picture, its price and store
$shareUrl = $p ? rtrim(APP_URL, '/') . '/market-product.php?id=' . (int) $p['id'] : '';
if ($p) {
    $ogUrl = $shareUrl;
    $pageDescription = 'UGX ' . number_format((int) $p['price_from']) . ' · ' . $p['store'] . ' on OBI MARKET. ' . mb_substr(trim((string) $p['description']), 0, 140);
    $ogMain = market_img($p['images'][0] ?? '');
    if ($ogMain !== '' && ($ogSize = @getimagesize(__DIR__ . $ogMain))) {
        $ogImage = rtrim(APP_URL, '/') . $ogMain;
        [$ogImageW, $ogImageH] = [(int) $ogSize[0], (int) $ogSize[1]];
        $ogImageType = (string) $ogSize['mime'];
        $ogImageAlt = (string) $p['name'];
    }
}
$bodyClass = 'classic-page app-dark mk-page';
$hideWhatsApp = true;
$rcTabForce = 'discover';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/market.css?v=<?= @filemtime(__DIR__ . '/assets/css/market.css') ?: time() ?>">

<div class="wrap mk">
  <div class="ad-top">
    <a class="ad-icon" href="/market.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>OBI MARKET</h1>
    <?php if ($visible): ?><a class="ad-icon mk-cartbtn" href="/market-cart.php" aria-label="Cart"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 11.2a1 1 0 001 .8h8.9a1 1 0 001-.8L20 8H6.2"/><circle cx="9.5" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg><b class="mk-count" id="mkCount"<?= $cartCount ? '' : ' hidden' ?>><?= $cartCount ?></b></a><?php endif; ?>
  </div>

<?php if (!$visible): ?>
  <section class="mk-soon"><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-hanger"/></svg></i><h2>OBI MARKET is opening soon</h2><p>Official merchandise from the organizers and artists you follow.</p><a class="mk-btn" href="/discover.php">Browse events meanwhile</a></section>
<?php elseif (!$p): ?>
  <section class="mk-soon"><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-search"/></svg></i><h2>This product is not available</h2><p>It may have sold out of the shop, been hidden by its seller, or the link is wrong.</p><a class="mk-btn" href="/market.php">Back to OBI MARKET</a></section>
<?php else: ?>
  <?php if (!$open): ?><div class="mk-preview"><b>Preview.</b> OBI MARKET is switched off for the public. Nothing can be bought yet.</div><?php endif; ?>
  <?php $imgs = array_values(array_filter(array_map('market_img', $p['images']))); ?>
  <article class="mk-pdp">
    <div class="mk-gallery">
      <div class="mk-main">
        <?php if ($imgs): ?><img id="mkMain" src="<?= $h($imgs[0]) ?>" alt="<?= $h($p['name']) ?>"><?php else: ?><i><svg width="56" height="56" aria-hidden="true"><use href="#ic-hanger"/></svg></i><?php endif; ?>
      </div>
      <?php if (count($imgs) > 1): ?>
      <div class="mk-thumbs" role="list">
        <?php foreach ($imgs as $i => $src): ?><button type="button" class="mk-thumb<?= $i === 0 ? ' on' : '' ?>" data-src="<?= $h($src) ?>" aria-label="Picture <?= $i + 1 ?>"><img src="<?= $h($src) ?>" alt="" loading="lazy"></button><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="mk-buy">
      <a class="mk-seller" href="/market.php?store=<?= urlencode($p['store_slug']) ?>"><svg width="15" height="15" aria-hidden="true"><use href="#ic-tag"/></svg><?= $h($p['store']) ?></a>
      <h2><?= $h($p['name']) ?></h2>
      <div class="mk-pricebox"><b id="mkPrice">UGX <?= $num($p['price_from']) ?></b><?php if ($p['price_to'] > $p['price_from']): ?><span id="mkPriceNote">from, depends on the option</span><?php endif; ?></div>
      <p class="mk-stock" id="mkStock"><?= $p['in_stock'] ? ($p['low_stock'] ? 'Only ' . (int) $p['available'] . ' left' : 'In stock') : 'Sold out' ?></p>

      <div id="mkOptions"></div>

      <?php if ($own): ?>
        <div class="mk-note">This is your own product, so you cannot buy it. <a href="/org-market.php">Manage it in your dashboard</a>.</div>
      <?php elseif ($p['in_stock'] && $open): ?>
        <div class="mk-qty" id="mkQtyRow"><span>Quantity</span><div class="mk-step"><button type="button" id="mkMinus" aria-label="One less">&minus;</button><output id="mkQty">1</output><button type="button" id="mkPlus" aria-label="One more">+</button></div></div>
        <div class="mk-msg" id="mkMsg" role="status" aria-live="polite" hidden></div>
        <div class="mk-actions">
          <button type="button" class="mk-btn line" id="mkAdd">Add to cart</button>
          <button type="button" class="mk-btn" id="mkBuy">Buy now</button>
        </div>
      <?php elseif (!$p['in_stock']): ?>
        <div class="mk-note out">Sold out. Check back later or look at the seller's other products.</div>
      <?php endif; ?>

      <ul class="mk-facts">
        <li><svg width="17" height="17" aria-hidden="true"><use href="#ic-pin"/></svg><span><b><?= $h($modeText) ?></b><?php if ($p['delivery_mode'] !== 'COLLECTION'): ?> &middot; delivery fee <?= (int) $p['delivery_fee'] > 0 ? 'UGX ' . $num($p['delivery_fee']) : 'free' ?><?php endif; ?><?php if (!empty($p['delivery_note'])): ?><br><small><?= $h($p['delivery_note']) ?></small><?php endif; ?></span></li>
        <li><svg width="17" height="17" aria-hidden="true"><use href="#ic-shield"/></svg><span><b>Pay with MTN or Airtel</b><br><small>The seller is paid only after you confirm you received your order.</small></span></li>
      </ul>
      <section class="mk-share" aria-label="Share this product">
        <h3>Share this product</h3>
        <div class="mk-share-row">
          <a class="mk-sh wa" href="https://wa.me/?text=<?= rawurlencode($p['name'] . ' - UGX ' . number_format((int) $p['price_from']) . ' on OBI MARKET: ' . $shareUrl) ?>" target="_blank" rel="noopener" data-share="whatsapp"><i><svg width="20" height="20" aria-hidden="true"><use href="#ic-whatsapp"/></svg></i><span>WhatsApp</span></a>
          <a class="mk-sh fb" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($shareUrl) ?>" target="_blank" rel="noopener" data-share="facebook"><i><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 21v-7.5h2.5l.4-3h-2.9V8.6c0-.9.3-1.5 1.5-1.5h1.5V4.4c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2.4H8v3h2.6V21z"/></svg></i><span>Facebook</span></a>
          <button type="button" class="mk-sh ig" data-share="instagram"><i><svg width="20" height="20" aria-hidden="true"><use href="#ic-instagram"/></svg></i><span>Instagram</span></button>
          <button type="button" class="mk-sh tt" data-share="tiktok"><i><svg width="20" height="20" aria-hidden="true"><use href="#ic-tiktok"/></svg></i><span>TikTok</span></button>
          <button type="button" class="mk-sh cp" data-share="copy"><i><svg width="20" height="20" aria-hidden="true"><use href="#ic-share"/></svg></i><span>Copy link</span></button>
        </div>
        <p class="mk-share-msg" id="mkShareMsg" role="status" aria-live="polite" hidden></p>
      </section>

      <?php if (trim((string) $p['description']) !== ''): ?><section class="mk-desc"><h3>About this product</h3><p><?= nl2br($h($p['description'])) ?></p></section><?php endif; ?>
      <?php if (!empty($p['events'])): ?>
      <section class="mk-desc"><h3>From these events</h3><p class="mk-events"><?php foreach ($p['events'] as $e): ?><a href="/event.php?slug=<?= urlencode((string) $e['slug']) ?>"><?= $h($e['title']) ?></a><?php endforeach; ?></p></section>
      <?php endif; ?>
      <?php if (trim((string) $p['policies']) !== ''): ?><section class="mk-desc"><h3>Store policies</h3><p><?= nl2br($h($p['policies'])) ?></p></section><?php endif; ?>
    </div>
  </article>
  <script type="application/json" id="mkData"><?= json_encode(['id' => $p['id'], 'variants' => $p['variants'], 'max' => MARKET_MAX_QTY_LINE, 'canBuy' => $open && !$own && $p['in_stock'], 'signedIn' => $user !== null, 'share' => ['url' => $shareUrl, 'title' => (string) $p['name'], 'text' => $p['name'] . ' - UGX ' . number_format((int) $p['price_from']) . ' on OBI MARKET', 'image' => market_img($p['images'][0] ?? '')], 'next' => '/market-product.php?id=' . (int) $p['id']], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <script src="/assets/js/market.js?v=<?= @filemtime(__DIR__ . '/assets/js/market.js') ?: time() ?>" defer></script>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
