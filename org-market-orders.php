<?php
require_once __DIR__ . '/includes/bootstrap.php';

/* OBI MARKET, the seller's orders (ADR-003, phase 4): tabs by what needs doing, search by order number / customer / phone, a date range. Paid orders only. */
$ctx = require_organizer_access('market.view');
$sellerId = $ctx['organizer_id'];
$store = market_ready() ? market_store_ensure($sellerId) : null;
$groups = ['all' => 'All', 'new' => 'New', 'processing' => 'Preparing', 'sent' => 'Sent', 'done' => 'Delivered', 'problem' => 'Problems', 'cancelled' => 'Cancelled'];
$tabIn = (string) ($_GET['tab'] ?? 'all');
$tab = isset($groups[$tabIn]) ? $tabIn : 'all';
$f = ['group' => $tab, 'q' => trim((string) ($_GET['q'] ?? '')), 'from' => (string) ($_GET['from'] ?? ''), 'to' => (string) ($_GET['to'] ?? '')];
$page = max(1, (int) ($_GET['page'] ?? 1));
$counts = $store ? market_seller_order_counts($sellerId) : [];
$list = $store ? market_seller_orders($sellerId, $f, $page, 20) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$link = static fn (array $o): string => '/org-market-orders.php?' . http_build_query(array_filter($o + ['tab' => $tab, 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to']], static fn ($v) => $v !== null && $v !== '' && $v !== 'all' && $v !== 1));

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = 'Orders';
render_organizer_head('market-orders', $ctx);
?>

<div class="admin-page-head"><div><h1>Orders</h1><p>Merchandise orders from your customers. Start preparing new ones as soon as you can.</p></div></div>

<?php if (!$store): ?>
  <div class="om-note warn">OBI MARKET is not available yet. Please check back soon.</div>
<?php else: ?>
<nav class="om-tabs" aria-label="Order status">
  <?php foreach ($groups as $k => $label): ?><a href="<?= $h($link(['tab' => $k, 'page' => null])) ?>" class="<?= $tab === $k ? 'is-on' : '' ?>"><?= $h($label) ?> <b><?= (int) ($counts[$k] ?? 0) ?></b></a><?php endforeach; ?>
</nav>
<form class="om-filters" method="get" action="/org-market-orders.php">
  <input type="hidden" name="tab" value="<?= $h($tab) ?>">
  <label>Search<input type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Order number, name or phone" maxlength="60"></label>
  <label>From<input type="date" name="from" value="<?= $h($f['from']) ?>"></label>
  <label>To<input type="date" name="to" value="<?= $h($f['to']) ?>"></label>
  <button class="om-btn" type="submit">Filter</button>
</form>

<?php if (!$list['rows']): ?>
  <div class="om-empty"><b><?= $counts && $counts['all'] > 0 ? 'No orders match that' : 'No orders yet' ?></b><?= $counts && $counts['all'] > 0 ? 'Try another tab or clear the search.' : 'When a customer pays for one of your products, the order shows here and you get a notification.' ?></div>
<?php else: ?>
<div class="admin-table-wrap"><table class="admin-table om-table">
  <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>You keep</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list['rows'] as $o): [$label, $tone] = market_status_label((string) $o['fulfilment'], (string) $o['payment_status']); $disputed = $o['dispute_at'] !== null && $o['received_by'] === null; ?>
    <tr>
      <td><a href="/org-market-order.php?n=<?= urlencode((string) $o['order_number']) ?>"><b><?= $h($o['order_number']) ?></b></a><small class="om-sub"><?= $h(date('j M Y, g:ia', strtotime((string) ($o['paid_at'] ?? $o['created_at'])))) ?></small></td>
      <td><?= $h($o['customer_name']) ?><small class="om-sub"><?= $o['delivery_method'] === 'COLLECTION' ? 'Collection' : 'Delivery' ?></small></td>
      <td><div class="om-prod"><span class="om-thumb"><?php if (market_img($o['first_image']) !== ''): ?><img src="<?= $h(market_img($o['first_image'])) ?>" alt="" loading="lazy"><?php endif; ?></span>
        <div><b><?= $h($o['first_name']) ?></b><small><?= (int) $o['items'] ?> item<?= (int) $o['items'] === 1 ? '' : 's' ?></small></div></div></td>
      <td class="mono">UGX <?= $num($o['total_ugx']) ?></td>
      <td class="mono">UGX <?= $num((int) $o['seller_ugx'] + (int) $o['delivery_ugx']) ?></td>
      <td><span class="om-badge <?= $h($tone) ?>"><?= $h($label) ?></span><?= $disputed ? ' <span class="om-badge bad">Problem reported</span>' : '' ?></td>
      <td><a class="om-btn" href="/org-market-order.php?n=<?= urlencode((string) $o['order_number']) ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php if ($list['pages'] > 1): ?>
  <nav class="om-pager" aria-label="Pages">
    <?php if ($list['page'] > 1): ?><a href="<?= $h($link(['page' => $list['page'] - 1])) ?>">&larr; Previous</a><?php endif; ?>
    <span>Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?> &middot; <?= $num($list['total']) ?> orders</span>
    <?php if ($list['page'] < $list['pages']): ?><a href="<?= $h($link(['page' => $list['page'] + 1])) ?>">Next &rarr;</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php render_organizer_foot(); ?>
