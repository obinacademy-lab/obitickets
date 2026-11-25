<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET, "My products" (ADR-003, phase 4): status tabs with counts, search, and quick actions (hide / show / archive / restore).
 * Nothing is ever deleted: a product with orders must stay in the records. Only the seller's own products appear and can be changed.
 */
$ctx = require_organizer_access('market.view');
$sellerId = $ctx['organizer_id'];
$canManage = organizer_can($ctx, 'market.manage');
$store = market_ready() ? market_store_ensure($sellerId) : null;

if ($store && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to change products.');
    }
    $to = ['show' => 'ACTIVE', 'hide' => 'INACTIVE', 'archive' => 'ARCHIVED', 'restore' => 'DRAFT'][(string) ($_POST['action'] ?? '')] ?? null;
    if ($to === null) {
        $_SESSION['om_flash'] = ['bad', 'Unknown action.'];
    } else {
        $r = market_product_set_status($sellerId, (int) ($_POST['id'] ?? 0), $to);
        $_SESSION['om_flash'] = $r['ok'] ? ['ok', ['ACTIVE' => 'The product is live.', 'INACTIVE' => 'The product is hidden from customers.', 'ARCHIVED' => 'The product was archived.', 'DRAFT' => 'The product is back as a draft.'][$to]] : ['bad', $r['error']];
    }
    $keep = array_filter(array_intersect_key($_POST, ['tab' => 1, 'q' => 1, 'page' => 1]), static fn ($v) => (string) $v !== '' && (string) $v !== 'ALL');
    header('Location: /org-market-products.php' . ($keep ? '?' . http_build_query($keep) : ''));
    exit;
}
$flash = $_SESSION['om_flash'] ?? null;
unset($_SESSION['om_flash']);

$tabIn = strtoupper((string) ($_GET['tab'] ?? 'ALL'));
$tab = in_array($tabIn, ['ALL', 'ACTIVE', 'DRAFT', 'INACTIVE', 'OUT', 'ARCHIVED'], true) ? $tabIn : 'ALL';
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$counts = $store ? market_seller_product_counts($sellerId) : [];
$list = $store ? market_seller_products($sellerId, $tab, $q, $page, 20) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$tabs = ['ALL' => 'All', 'ACTIVE' => 'Active', 'DRAFT' => 'Drafts', 'INACTIVE' => 'Hidden', 'OUT' => 'Out of stock', 'ARCHIVED' => 'Archived'];
$statusWords = ['ACTIVE' => ['Active', 'ok'], 'DRAFT' => ['Draft', 'wait'], 'INACTIVE' => ['Hidden', 'wait'], 'ARCHIVED' => ['Archived', 'bad']];
$link = static fn (array $o): string => '/org-market-products.php?' . http_build_query(array_filter(['tab' => $o['tab'] ?? null, 'q' => $o['q'] ?? null, 'page' => $o['page'] ?? null], static fn ($v) => $v !== null && $v !== '' && $v !== 'ALL' && $v !== 1));

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = 'Products';
render_organizer_head('market-products', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Products</h1><p>Everything you sell on OBI MARKET.</p></div>
  <?php if ($canManage && $store): ?><a class="btn btn-purple" href="/org-market-product.php">+ Add product</a><?php endif; ?>
</div>

<?php if (!$store): ?>
  <div class="om-note warn">OBI MARKET is not available yet. Please check back soon.</div>
<?php else: ?>
<?php if ($flash): ?><div class="om-note <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if (!market_enabled()): ?><div class="om-note warn"><b>OBI MARKET is not open to customers yet.</b> Products you publish will go on sale as soon as it opens.</div><?php endif; ?>

<nav class="om-tabs" aria-label="Product status">
  <?php foreach ($tabs as $k => $label): ?><a href="<?= $h($link(['tab' => $k, 'q' => $q])) ?>" class="<?= $tab === $k ? 'is-on' : '' ?>"><?= $h($label) ?> <b><?= (int) ($counts[$k] ?? 0) ?></b></a><?php endforeach; ?>
</nav>
<form class="om-filters" method="get" action="/org-market-products.php">
  <input type="hidden" name="tab" value="<?= $h($tab) ?>">
  <label>Search<input type="search" name="q" value="<?= $h($q) ?>" placeholder="Product name or SKU" maxlength="80"></label>
  <button class="om-btn" type="submit">Search</button>
</form>

<?php if (!$list['rows']): ?>
  <div class="om-empty"><b><?= $q !== '' ? 'No products match that' : ($tab === 'ALL' ? 'You have no products yet' : 'Nothing here') ?></b>
    <?= $q !== '' ? 'Try a different word.' : ($tab === 'ALL' ? 'Add your first T-shirt, hoodie, cap or anything else you sell.' : 'Products with this status will show here.') ?>
    <?php if ($tab === 'ALL' && $q === '' && $canManage): ?><p><a class="btn btn-purple" href="/org-market-product.php">+ Add your first product</a></p><?php endif; ?></div>
<?php else: ?>
<div class="admin-table-wrap"><table class="admin-table om-table">
  <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Sold</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list['rows'] as $p): [$sl, $st] = $statusWords[$p['status']] ?? [$p['status'], 'wait']; $avail = (int) $p['available']; ?>
    <tr>
      <td><div class="om-prod"><span class="om-thumb"><?php if (market_img($p['primary_image']) !== ''): ?><img src="<?= $h(market_img($p['primary_image'])) ?>" alt="" loading="lazy"><?php endif; ?></span>
        <div><b><?= $h($p['name']) ?></b><small><?= (int) $p['variants'] > 1 ? (int) $p['variants'] . ' options' : 'Single option' ?></small></div></div></td>
      <td><?= $h($p['category']) ?></td>
      <td class="mono">UGX <?= $num($p['price_from']) ?><?= $p['price_to'] > $p['price_from'] ? '+' : '' ?></td>
      <td><?= $avail <= 0 ? '<span class="om-badge bad">Out</span>' : ($avail <= (int) $p['low_stock_threshold'] ? '<span class="om-badge wait">' . $avail . ' left</span>' : $avail) ?></td>
      <td><?= (int) $p['sold_count'] ?></td>
      <td><span class="om-badge <?= $h($st) ?>"><?= $h($sl) ?></span></td>
      <td><div class="om-actions">
        <?php if ($canManage): ?>
          <a class="om-btn" href="/org-market-product.php?id=<?= (int) $p['id'] ?>">Edit</a>
          <form method="post" action="/org-market-products.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="tab" value="<?= $h($tab) ?>"><input type="hidden" name="q" value="<?= $h($q) ?>"><input type="hidden" name="page" value="<?= (int) $list['page'] ?>"><input type="hidden" name="back" value="1">
            <?php if ($p['status'] === 'ACTIVE'): ?><button class="om-btn" name="action" value="hide">Hide</button>
            <?php elseif ($p['status'] === 'ARCHIVED'): ?><button class="om-btn" name="action" value="restore">Restore</button>
            <?php else: ?><button class="om-btn primary" name="action" value="show">Publish</button><?php endif; ?>
            <?php if ($p['status'] !== 'ARCHIVED'): ?><button class="om-btn danger" name="action" value="archive" onclick="return confirm('Archive this product? Customers will no longer see it. Past orders are kept.')">Archive</button><?php endif; ?>
          </form>
        <?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php if ($list['pages'] > 1): ?>
  <nav class="om-pager" aria-label="Pages">
    <?php if ($list['page'] > 1): ?><a href="<?= $h($link(['tab' => $tab, 'q' => $q, 'page' => $list['page'] - 1])) ?>">&larr; Previous</a><?php endif; ?>
    <span>Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?> &middot; <?= $num($list['total']) ?> products</span>
    <?php if ($list['page'] < $list['pages']): ?><a href="<?= $h($link(['tab' => $tab, 'q' => $q, 'page' => $list['page'] + 1])) ?>">Next &rarr;</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php render_organizer_foot(); ?>
