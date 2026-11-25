<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET, the seller's overview (ADR-003, phase 4): four figures, the sales chart, the commission card, the latest orders, low stock and the balance.
 * Every number is scoped to the signed-in organizer's own store (includes/market-seller.php). Opens even before OBI MARKET is switched on for customers, so a
 * seller can set up first.
 */
$ctx = require_organizer_access('market.view');
$sellerId = $ctx['organizer_id'];
if (!market_ready()) {
    http_response_code(503);
}
$store = market_ready() ? market_store_ensure($sellerId) : null;
$range = market_seller_range($_GET);
$ov = $store ? market_seller_overview($sellerId, $range) : null;
$chartCur = $store ? market_seller_chart($sellerId, $range['from'], $range['to']) : [];
$prevTo = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
$chartPrev = $store ? market_seller_chart($sellerId, date('Y-m-d', strtotime($prevTo . ' -' . ($range['days'] - 1) . ' days')), $prevTo) : [];
$hasSales = $ov && array_sum(array_column($chartCur, 'g')) > 0;
$recent = $store ? market_seller_recent_orders($sellerId, 6) : [];
$low = $store ? market_seller_low_stock($sellerId, 6) : [];
$cfg = market_commission_current();
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$pct = rtrim(rtrim(number_format($cfg['bps'] / 100, 2), '0'), '.');
$trend = static function (int $now, int $prev): string {
    if ($prev <= 0) { return $now > 0 ? '<span class="ov-up">New</span>' : '<span class="om-mute">No change</span>'; }
    $d = (int) round(($now - $prev) / $prev * 100);
    return $d >= 0 ? '<span class="ov-up">&uarr; ' . $d . '%</span> vs before' : '<span class="ov-down">&darr; ' . abs($d) . '%</span> vs before';
};
$canManage = organizer_can($ctx, 'market.manage');
$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = 'OBI MARKET';
render_organizer_head('market', $ctx);
?>

<?php if (!$store): ?>
  <div class="om-note warn">OBI MARKET is not available yet. Please check back soon.</div>
<?php else: ?>
<header class="ov-top">
  <div>
    <p class="ov-eyebrow"><?= $h(date('D j M')) ?></p>
    <h1>OBI MARKET</h1>
    <p class="ov-lede"><?= $h($store['name']) ?><?= $ov['orders_new'] > 0 ? ' &middot; ' . (int) $ov['orders_new'] . ' new order' . ($ov['orders_new'] === 1 ? '' : 's') . ' waiting for you' : '' ?></p>
  </div>
  <div class="om-top-act">
    <div class="ov-seg" role="group" aria-label="Period">
      <?php foreach ([7, 30, 90] as $d): ?><a href="/org-market.php?days=<?= $d ?>" class="<?= $range['key'] === (string) $d ? 'is-on' : '' ?>"><?= $d ?> days</a><?php endforeach; ?>
      <button type="button" class="<?= $range['key'] === 'custom' ? 'is-on' : '' ?>" id="omCustomBtn" aria-expanded="<?= $range['key'] === 'custom' ? 'true' : 'false' ?>">Custom</button>
    </div>
    <?php if ($canManage): ?><a class="ov-btn ov-red" href="/org-market-product.php">+ Add product</a><?php endif; ?>
  </div>
</header>
<form class="om-custom" id="omCustom" method="get" action="/org-market.php"<?= $range['key'] === 'custom' ? '' : ' hidden' ?>>
  <label>From <input type="date" name="from" value="<?= $h($range['from']) ?>" max="<?= $h(date('Y-m-d')) ?>"></label>
  <label>To <input type="date" name="to" value="<?= $h($range['to']) ?>" max="<?= $h(date('Y-m-d')) ?>"></label>
  <button type="submit" class="ov-btn ov-ghost">Show</button>
</form>

<?php if (!market_enabled()): ?>
  <div class="om-note warn"><b>OBI MARKET is not open to customers yet.</b> You can set up your store and add products now. Customers will be able to buy once it opens.</div>
<?php endif; ?>

<div class="ov-kpis om-kpis">
  <div class="ov-kpi"><span class="kl">Total sales</span><b class="kv">UGX <?= $num($ov['cur']['sales']) ?></b><span class="kd"><?= $trend($ov['cur']['sales'], $ov['prev']['sales']) ?></span></div>
  <div class="ov-kpi"><span class="kl">Orders</span><b class="kv"><?= $num($ov['cur']['orders']) ?></b><span class="kd"><?= $trend($ov['cur']['orders'], $ov['prev']['orders']) ?></span></div>
  <div class="ov-kpi"><span class="kl">Products</span><b class="kv"><?= $num($ov['products_active']) ?></b><span class="kd"><?= $num($ov['products_total']) ?> in total<?= $ov['products_draft'] ? ' &middot; ' . (int) $ov['products_draft'] . ' draft' : '' ?></span></div>
  <div class="ov-kpi"><span class="kl">You keep</span><b class="kv">UGX <?= $num($ov['cur']['net']) ?></b><span class="kd"><?= $trend($ov['cur']['net'], $ov['prev']['net']) ?></span></div>
</div>

<div class="ov-cols">
  <section class="ov-card">
    <div class="ov-ch">
      <div><h2>Sales</h2><p>Merchandise sold per day, <?= $h(date('j M', strtotime($range['from']))) ?> to <?= $h(date('j M', strtotime($range['to']))) ?></p></div>
      <?php if ($hasSales): ?><div class="ov-legend"><span><i></i>This period</span><span><i class="p"></i>Before</span></div><?php endif; ?>
    </div>
    <?php if ($hasSales): ?>
      <div class="ov-chart" data-chart data-compare="1" data-h="230" aria-label="Sales per day" role="img"></div>
    <?php else: ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-chart"/></svg><p>No sales in this period yet.<?= $ov['products_active'] === 0 && $canManage ? ' Add your first product to get started.' : '' ?></p><?php if ($ov['products_active'] === 0 && $canManage): ?><a class="ov-btn ov-red" href="/org-market-product.php">Add a product</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <section class="ov-card om-commission">
    <div class="ov-ch"><div><h2>Commission</h2><p>How each sale is shared</p></div></div>
    <div class="om-rate"><b><?= $h($pct) ?>%</b><span>obitickets keeps this share of the merchandise price. You keep the rest.</span></div>
    <div class="om-example">
      <div><span>Product sold for</span><b>UGX 100,000</b></div>
      <div><span>obitickets (<?= $h($pct) ?>%)</span><b>&minus; UGX <?= $num(market_split(100000, $cfg['bps'])['commission']) ?></b></div>
      <div class="tot"><span>You keep</span><b>UGX <?= $num(market_split(100000, $cfg['bps'])['seller']) ?></b></div>
    </div>
    <p class="om-fine">Your delivery fee is not commissioned: you keep all of it. Commission paid in this period: <b>UGX <?= $num($ov['cur']['commission']) ?></b>.</p>
  </section>
</div>

<div class="om-balance">
  <div><span>Waiting for delivery confirmation</span><b>UGX <?= $num($ov['balance']['pending']) ?></b></div>
  <div><span>Ready to withdraw</span><b class="ok">UGX <?= $num($ov['balance']['available']) ?></b></div>
  <div><span>Withdrawn so far</span><b>UGX <?= $num($ov['balance']['withdrawn']) ?></b></div>
  <a class="ov-btn ov-ghost" href="/org-market-earnings.php">Earnings &amp; withdraw</a>
</div>

<div class="ov-cols">
  <section class="ov-card">
    <div class="ov-ch"><div><h2>Recent orders</h2><p>The latest from your customers</p></div><a class="om-more" href="/org-market-orders.php">View all</a></div>
    <?php if (!$recent): ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-receipt"/></svg><p>No orders yet. They will show here as soon as a customer pays.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap"><table class="admin-table om-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $o): [$label, $tone] = market_status_label((string) $o['fulfilment'], (string) $o['payment_status']); ?>
          <tr><td><a href="/org-market-order.php?n=<?= urlencode((string) $o['order_number']) ?>"><b><?= $h($o['order_number']) ?></b></a><small class="om-sub"><?= $h(date('j M', strtotime((string) $o['created_at']))) ?></small></td>
              <td><?= $h($o['customer_name']) ?></td><td class="mono">UGX <?= $num($o['total_ugx']) ?></td><td><span class="om-badge <?= $h($tone) ?>"><?= $h($label) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="ov-card">
    <div class="ov-ch"><div><h2>Low stock</h2><p>Products running out</p></div><a class="om-more" href="/org-market-products.php">Products</a></div>
    <?php if (!$low): ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-check"/></svg><p>Nothing is running low.</p></div>
    <?php else: foreach ($low as $p): ?>
      <a class="om-low" href="/org-market-product.php?id=<?= (int) $p['id'] ?>">
        <span class="om-thumb"><?php if (market_img($p['primary_image']) !== ''): ?><img src="<?= $h(market_img($p['primary_image'])) ?>" alt=""><?php endif; ?></span>
        <span><b><?= $h($p['name']) ?></b><small><?= (int) $p['available'] <= 0 ? 'Sold out' : (int) $p['available'] . ' left' ?></small></span>
        <em class="om-badge <?= (int) $p['available'] <= 0 ? 'bad' : 'wait' ?>"><?= (int) $p['available'] <= 0 ? 'Out' : 'Low' ?></em>
      </a>
    <?php endforeach; endif; ?>
  </section>
</div>

<?php if ($hasSales): ?>
  <script type="application/json" id="ovData"><?= json_encode(['currency' => 'UGX', 'cur' => $chartCur, 'prev' => $chartPrev], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <script src="/assets/js/org-overview.js?v=<?= @filemtime(__DIR__ . '/assets/js/org-overview.js') ?: time() ?>"></script>
<?php endif; ?>
<script>
(function () { var b = document.getElementById('omCustomBtn'), f = document.getElementById('omCustom'); if (b && f) { b.addEventListener('click', function () { f.hidden = !f.hidden; b.setAttribute('aria-expanded', f.hidden ? 'false' : 'true'); }); } })();
</script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
