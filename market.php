<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET (ADR-003, phase 3): the public shop window. Hero + search, category tiles, filters and sorting, the product grid, pages.
 * A store's own window is the same page with ?store=<slug>. Until an admin switches OBI MARKET on, the public sees "opening soon";
 * an admin sees the real shop as a preview (nothing can be bought). Prices and stock come from includes/market.php.
 */
$user = current_user();
$ready = market_ready();
$open = $ready && market_enabled();
$preview = $ready && !$open && $user !== null && $user['role'] === 'ADMIN';
$visible = $open || $preview;

$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['category'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['newest', 'price_asc', 'price_desc', 'popular'], true) ? (string) $_GET['sort'] : 'newest';
$min = max(0, (int) preg_replace('/\D/', '', (string) ($_GET['min'] ?? '')));
$max = max(0, (int) preg_replace('/\D/', '', (string) ($_GET['max'] ?? '')));
$inStock = !empty($_GET['in_stock']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$storeSlug = trim((string) ($_GET['store'] ?? ''));
$store = $visible && $storeSlug !== '' ? market_store_by_slug($storeSlug) : null;
if ($store && !market_store_active($store)) {
    $store = null;
}

$cats = $visible ? market_categories() : [];
$filters = ['q' => $q, 'category' => $cat, 'sort' => $sort, 'min' => $min, 'max' => $max, 'in_stock' => $inStock];
if ($store) {
    $filters['seller'] = (int) $store['seller_id'];
}
$list = $visible ? market_products_list($filters, $page, 24) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$cartCount = $user ? market_cart_count((int) $user['id']) : 0;
$num = static fn ($n): string => number_format((int) $n);
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$filtered = $q !== '' || $cat !== '' || $min > 0 || $max > 0 || $inStock;
$qs = static function (array $over) use ($q, $cat, $sort, $min, $max, $inStock, $storeSlug): string {
    $p = array_filter(['q' => $q, 'category' => $cat, 'sort' => $sort !== 'newest' ? $sort : '', 'min' => $min ?: '', 'max' => $max ?: '', 'in_stock' => $inStock ? 1 : '', 'store' => $storeSlug] , static fn ($v) => $v !== '' && $v !== null);
    foreach ($over as $k => $v) { if ($v === null || $v === '') { unset($p[$k]); } else { $p[$k] = $v; } }
    return '/market.php' . ($p ? '?' . http_build_query($p) : '');
};
$catIcon = ['t-shirts' => 'hanger', 'hoodies' => 'hanger', 'caps' => 'tag', 'accessories' => 'bolt', 'artist-merch' => 'music', 'event-merch' => 'ticket', 'other' => 'grid'];

$pageTitle = ($store ? $store['name'] . ' — ' : '') . 'OBI MARKET — obitickets';
$bodyClass = 'classic-page app-dark mk-page';
$hideWhatsApp = true;
$rcTabForce = 'discover';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/market.css?v=<?= @filemtime(__DIR__ . '/assets/css/market.css') ?: time() ?>">

<div class="wrap mk">
  <div class="ad-top">
    <a class="ad-icon" href="/discover.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1><?= $store ? $h($store['name']) : 'OBI MARKET' ?></h1>
    <?php if ($visible): ?>
      <a class="ad-icon mk-cartbtn" href="/market-cart.php" aria-label="Cart, <?= $cartCount ?> items"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 11.2a1 1 0 001 .8h8.9a1 1 0 001-.8L20 8H6.2"/><circle cx="9.5" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg><b class="mk-count" id="mkCount"<?= $cartCount ? '' : ' hidden' ?>><?= $cartCount ?></b></a>
      <?php if ($user): ?><a class="ad-icon" href="/market-orders.php" aria-label="My orders"><svg width="20" height="20" aria-hidden="true"><use href="#ic-receipt"/></svg></a><?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (!$visible): ?>
    <section class="mk-soon">
      <i><svg width="34" height="34" aria-hidden="true"><use href="#ic-hanger"/></svg></i>
      <h2>OBI MARKET is opening soon</h2>
      <p>Official merchandise from the organizers and artists you follow: T-shirts, hoodies, caps and more, paid with MTN or Airtel and delivered to you.</p>
      <a class="mk-btn" href="/discover.php">Browse events meanwhile</a>
    </section>
  <?php else: ?>
    <?php if ($preview): ?><div class="mk-preview"><b>Preview.</b> OBI MARKET is switched off for the public. You see it as an admin; nothing can be bought until you switch it on in Admin &rarr; Settings.</div><?php endif; ?>

    <?php if ($store): ?>
      <section class="mk-store">
        <?php if (market_img($store['banner_path'] ?? '') !== ''): ?><img class="mk-store-banner" src="<?= $h(market_img($store['banner_path'])) ?>" alt=""><?php endif; ?>
        <div class="mk-store-in">
          <?php if (market_img($store['logo_path'] ?? '') !== ''): ?><img class="mk-store-logo" src="<?= $h(market_img($store['logo_path'])) ?>" alt=""><?php else: ?><span class="mk-store-logo mk-store-initial"><?= $h(mb_strtoupper(mb_substr((string) $store['name'], 0, 1))) ?></span><?php endif; ?>
          <div><b><?= $h($store['name']) ?></b><?php if (!empty($store['description'])): ?><p><?= $h($store['description']) ?></p><?php endif; ?></div>
        </div>
        <a class="mk-link" href="/market.php">&larr; All of OBI MARKET</a>
      </section>
    <?php else: ?>
      <section class="mk-hero">
        <div class="mk-hero-tx">
          <small>OBI MARKET</small>
          <h2>Official merch from the events you love</h2>
          <p>Shop T-shirts, hoodies, caps and more from organizers and artists. Pay with MTN or Airtel.</p>
        </div>
        <form class="mk-search" action="/market.php" method="get" role="search">
          <svg width="18" height="18" aria-hidden="true"><use href="#ic-search"/></svg>
          <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search products, stores or events" aria-label="Search OBI MARKET" maxlength="80">
          <button type="submit">Search</button>
        </form>
      </section>

      <?php if ($cats): ?>
      <nav class="mk-cats" aria-label="Categories">
        <a class="mk-cat<?= $cat === '' ? ' on' : '' ?>" href="<?= $h($qs(['category' => null, 'page' => null])) ?>"><i><svg width="22" height="22" aria-hidden="true"><use href="#ic-grid"/></svg></i><span>All</span></a>
        <?php foreach ($cats as $c): ?>
          <a class="mk-cat<?= $cat === $c['slug'] ? ' on' : '' ?>" href="<?= $h($qs(['category' => $c['slug'], 'page' => null])) ?>"><i><svg width="22" height="22" aria-hidden="true"><use href="#ic-<?= $catIcon[$c['slug']] ?? 'tag' ?>"/></svg></i><span><?= $h($c['name']) ?></span></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>

    <form class="mk-bar" method="get" action="/market.php">
      <?php if ($storeSlug !== ''): ?><input type="hidden" name="store" value="<?= $h($storeSlug) ?>"><?php endif; ?>
      <?php if ($cat !== ''): ?><input type="hidden" name="category" value="<?= $h($cat) ?>"><?php endif; ?>
      <?php if ($store): ?><input class="mk-q" type="search" name="q" value="<?= $h($q) ?>" placeholder="Search this store" aria-label="Search this store" maxlength="80"><?php elseif ($q !== ''): ?><input type="hidden" name="q" value="<?= $h($q) ?>"><?php endif; ?>
      <label>Sort
        <select name="sort" onchange="this.form.submit()">
          <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest</option>
          <option value="popular"<?= $sort === 'popular' ? ' selected' : '' ?>>Most popular</option>
          <option value="price_asc"<?= $sort === 'price_asc' ? ' selected' : '' ?>>Price: low to high</option>
          <option value="price_desc"<?= $sort === 'price_desc' ? ' selected' : '' ?>>Price: high to low</option>
        </select>
      </label>
      <label>From <input type="text" inputmode="numeric" name="min" value="<?= $min ?: '' ?>" placeholder="UGX" size="7"></label>
      <label>To <input type="text" inputmode="numeric" name="max" value="<?= $max ?: '' ?>" placeholder="UGX" size="7"></label>
      <label class="mk-chk"><input type="checkbox" name="in_stock" value="1"<?= $inStock ? ' checked' : '' ?>> In stock</label>
      <button type="submit">Apply</button>
      <?php if ($filtered): ?><a class="mk-clear" href="<?= $h('/market.php' . ($storeSlug !== '' ? '?store=' . urlencode($storeSlug) : '')) ?>">Clear</a><?php endif; ?>
    </form>

    <p class="mk-meta"><?= $num($list['total']) ?> product<?= $list['total'] === 1 ? '' : 's' ?><?= $q !== '' ? ' for &ldquo;' . $h($q) . '&rdquo;' : '' ?></p>

    <?php if (!$list['rows']): ?>
      <div class="mk-empty">
        <b><?= $filtered ? 'Nothing matches that' : 'No products yet' ?></b>
        <?= $filtered ? 'Try a different word, or clear the filters.' : 'Organizers and artists are setting up their stores. Check back soon.' ?>
        <?php if ($filtered): ?><a class="mk-btn" href="/market.php">Show everything</a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="mk-grid">
        <?php foreach ($list['rows'] as $p): $img = market_img($p['image']); ?>
        <a class="mk-card" href="/market-product.php?id=<?= (int) $p['id'] ?>">
          <span class="mk-pic">
            <?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="<?= $h($p['name']) ?>" loading="lazy"><?php else: ?><i><svg width="34" height="34" aria-hidden="true"><use href="#ic-hanger"/></svg></i><?php endif; ?>
            <?php if (!$p['in_stock']): ?><em class="mk-tag out">Sold out</em><?php elseif ($p['available'] <= 5): ?><em class="mk-tag low">Only <?= (int) $p['available'] ?> left</em><?php elseif ($p['featured']): ?><em class="mk-tag">Featured</em><?php endif; ?>
          </span>
          <span class="mk-info">
            <small><?= $h($p['store']) ?></small>
            <b><?= $h($p['name']) ?></b>
            <span class="mk-price">UGX <?= $num($p['price_from']) ?></span>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php if ($list['pages'] > 1): ?>
      <nav class="mk-pages" aria-label="Pages">
        <?php if ($list['page'] > 1): ?><a href="<?= $h($qs(['page' => $list['page'] - 1])) ?>">&larr; Previous</a><?php endif; ?>
        <span>Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
        <?php if ($list['page'] < $list['pages']): ?><a href="<?= $h($qs(['page' => $list['page'] + 1])) ?>">Next &rarr;</a><?php endif; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
