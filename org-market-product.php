<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET, add or edit a product (ADR-003, phase 4): basics, price, stock (one number, or a size/colour table with stock and an optional price for each),
 * up to five pictures, the events it belongs to, and Save as draft / Publish. The seller can only open and change their OWN products and link their OWN events;
 * all the rules (price range, duplicate variants, no stock below what customers are paying for right now, a picture before it goes live) are in includes/market.php.
 */
$ctx = require_organizer_access('market.manage');
$sellerId = $ctx['organizer_id'];
$store = market_ready() ? market_store_ensure($sellerId) : null;
if (!$store) {
    http_response_code(503);
}
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$product = $store && $id > 0 ? market_seller_product($sellerId, $id) : null;
if ($store && $id > 0 && !$product) {
    http_response_code(404);
}
$cats = $store ? market_categories() : [];
$events = $store ? db()->prepare('SELECT id, title FROM events WHERE organizer_id = ? ORDER BY starts_at DESC LIMIT 100') : null;
if ($events) {
    $events->execute([$sellerId]);
    $events = $events->fetchAll();
}
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$error = null;
$f = [];   // what the form shows (the saved product, or what was just typed when something was wrong)

if ($store && ($id === 0 || $product) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $f = [
        'name' => (string) ($_POST['name'] ?? ''), 'category_id' => (int) ($_POST['category_id'] ?? 0), 'description' => (string) ($_POST['description'] ?? ''), 'price_ugx' => trim((string) ($_POST['price_ugx'] ?? '')),
        'tags' => (string) ($_POST['tags'] ?? ''), 'sku' => (string) ($_POST['sku'] ?? ''), 'low_stock_threshold' => trim((string) ($_POST['low_stock_threshold'] ?? '5')), 'stock' => trim((string) ($_POST['stock'] ?? '0')),
        'mode' => ($_POST['mode'] ?? 'single') === 'variants' ? 'variants' : 'single', 'event_ids' => array_map('intval', (array) ($_POST['event_ids'] ?? [])),
    ];
    $variants = [];
    foreach ((array) ($_POST['v_size'] ?? []) as $i => $size) {
        $row = ['size' => (string) $size, 'colour' => (string) ($_POST['v_colour'][$i] ?? ''), 'stock' => trim((string) ($_POST['v_stock'][$i] ?? '0')), 'price_ugx' => trim((string) ($_POST['v_price'][$i] ?? '')), 'sku' => (string) ($_POST['v_sku'][$i] ?? '')];
        if (trim($row['size']) === '' && trim($row['colour']) === '' && $row['stock'] === '' && $row['price_ugx'] === '') {
            continue;   // an empty row the seller never used
        }
        $variants[] = $row;
    }
    $f['variants'] = $variants;
    $in = $f;
    if ($f['mode'] === 'variants') {
        unset($in['stock']);
        if (!$variants) {
            $error = 'Add at least one size or colour, or choose "One option" for a product without sizes.';
        }
    } else {
        unset($in['variants']);
    }
    $in['publish'] = false;
    if ($error === null) {
        $r = market_product_save($sellerId, $product ? (int) $product['id'] : null, $in);
        if (!$r['ok']) {
            $error = $r['error'];
        } else {
            $pid = (int) $r['id'];
            // pictures: the ones kept (in the order sent, the chosen main one first), then the new uploads, five at most
            $current = $product['images'] ?? [];
            $keep = array_values(array_filter(array_map('strval', (array) ($_POST['keep'] ?? [])), static fn ($p) => in_array($p, $current, true)));
            $main = (string) ($_POST['main'] ?? '');
            if ($main !== '' && in_array($main, $keep, true)) {
                $keep = array_merge([$main], array_values(array_diff($keep, [$main])));
            }
            $uploadErrors = [];
            foreach (market_files_list($_FILES['images'] ?? null) as $file) {
                if (count($keep) >= 5) {
                    $uploadErrors[] = 'You can have at most 5 pictures; the extra ones were not added.';
                    break;
                }
                [$ok, $path, $err] = market_image_upload($file);
                if (!$ok) {
                    $uploadErrors[] = $err;
                } elseif ($path) {
                    $keep[] = $path;
                }
            }
            market_product_images_set($sellerId, $pid, $keep);
            $msg = $product ? 'Your changes were saved.' : 'The product was saved as a draft.';
            $kind = 'ok';
            if (($_POST['action'] ?? '') === 'publish') {
                $p = market_product_set_status($sellerId, $pid, 'ACTIVE');
                if ($p['ok']) {
                    $msg = 'The product is live' . (market_enabled() ? ' on OBI MARKET.' : '. Customers will see it when OBI MARKET opens.');
                } else {
                    $msg = 'Saved, but not published: ' . $p['error'];
                    $kind = 'warn';
                }
            }
            if ($uploadErrors) {
                $msg .= ' ' . implode(' ', array_unique($uploadErrors));
                $kind = $kind === 'ok' ? 'warn' : $kind;
            }
            $_SESSION['om_flash'] = [$kind, $msg];
            header('Location: ' . ($kind === 'ok' ? '/org-market-products.php' : '/org-market-product.php?id=' . $pid));
            exit;
        }
    }
}
if (!$f && $product) {
    $f = ['name' => $product['name'], 'category_id' => (int) $product['category_id'], 'description' => (string) $product['description'], 'price_ugx' => (string) $product['base_price_ugx'], 'tags' => (string) $product['tags'],
        'sku' => (string) $product['sku'], 'low_stock_threshold' => (string) $product['low_stock_threshold'], 'event_ids' => $product['event_ids'], 'stock' => '0', 'variants' => [], 'mode' => 'single'];
    $vs = $product['variants'];
    if (count($vs) === 1 && $vs[0]['size'] === '' && $vs[0]['colour'] === '') {
        $f['stock'] = (string) $vs[0]['stock'];
    } elseif ($vs) {
        $f['mode'] = 'variants';
        $f['variants'] = array_map(static fn ($v) => ['size' => $v['size'], 'colour' => $v['colour'], 'stock' => (string) $v['stock'], 'price_ugx' => (int) $v['price_ugx'] === (int) $product['base_price_ugx'] ? '' : (string) $v['price_ugx'], 'sku' => (string) $v['sku']], $vs);
    }
}
$f += ['name' => '', 'category_id' => 0, 'description' => '', 'price_ugx' => '', 'tags' => '', 'sku' => '', 'low_stock_threshold' => '5', 'stock' => '0', 'mode' => 'single', 'variants' => [], 'event_ids' => []];
if (!$f['variants']) {
    $f['variants'] = [['size' => '', 'colour' => '', 'stock' => '', 'price_ugx' => '', 'sku' => '']];
}
$flash = $_SESSION['om_flash'] ?? null;
unset($_SESSION['om_flash']);
$images = $product['images'] ?? [];
$isLive = $product && $product['status'] === 'ACTIVE';

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = $product ? 'Edit product' : 'Add product';
render_organizer_head('market-products', $ctx);
?>

<div class="admin-page-head">
  <div><h1><?= $product ? 'Edit product' : 'Add new product' ?></h1><p><?= $product ? $h($product['name']) : 'Tell customers what you are selling.' ?></p></div>
  <a class="btn btn-ghost" href="/org-market-products.php">&larr; All products</a>
</div>

<?php if (!$store): ?>
  <div class="om-note warn">OBI MARKET is not available yet. Please check back soon.</div>
<?php elseif ($id > 0 && !$product): ?>
  <div class="om-empty"><b>We could not find that product</b>It may belong to another store or no longer exist.<p><a class="btn btn-purple" href="/org-market-products.php">Back to products</a></p></div>
<?php else: ?>
<?php if ($flash): ?><div class="om-note <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if ($error): ?><div class="om-note bad" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php if ($product && in_array($product['status'], ['INACTIVE', 'ARCHIVED'], true)): ?><div class="om-note warn">This product is <?= $product['status'] === 'ARCHIVED' ? 'archived' : 'hidden' ?>. Customers cannot see it.</div><?php endif; ?>

<form class="om-form" method="post" enctype="multipart/form-data" action="/org-market-product.php<?= $product ? '?id=' . (int) $product['id'] : '' ?>" id="omProductForm" novalidate>
  <?= csrf_field() ?>
  <?php if ($product): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>

  <section class="om-card">
    <h3>Basics</h3><p>The name and description customers see.</p>
    <label class="om-field"><span>Product name</span><input name="name" value="<?= $h($f['name']) ?>" maxlength="120" required placeholder="e.g. Afro Nights Tour T-Shirt"></label>
    <div class="om-row2">
      <label class="om-field"><span>Category</span><select name="category_id" required><option value="">Choose a category</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $f['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= $h($c['name']) ?></option><?php endforeach; ?></select></label>
      <label class="om-field"><span>Price (UGX)</span><input name="price_ugx" value="<?= $h($f['price_ugx']) ?>" inputmode="numeric" required placeholder="45000"><small>From UGX <?= number_format(MARKET_MIN_PRICE_UGX) ?>. You can set a different price for a size or colour below.</small></label>
    </div>
    <label class="om-field"><span>Description</span><textarea name="description" rows="5" maxlength="4000" placeholder="Material, fit, what is printed, how to wash it..."><?= $h($f['description']) ?></textarea></label>
    <div class="om-row2">
      <label class="om-field"><span>Search words (optional)</span><input name="tags" value="<?= $h($f['tags']) ?>" maxlength="255" placeholder="tour, black, cotton"></label>
      <label class="om-field"><span>SKU (optional)</span><input name="sku" value="<?= $h($f['sku']) ?>" maxlength="60"></label>
    </div>
  </section>

  <section class="om-card">
    <h3>Pictures</h3><p>Up to 5 pictures, JPG, PNG or WEBP under 5MB. The first one is the main picture on the shop.</p>
    <?php if ($images): ?>
    <div class="om-images">
      <?php foreach ($images as $i => $path): ?>
        <div class="om-img<?= $i === 0 ? ' main' : '' ?>"><img src="<?= $h(market_img($path)) ?>" alt="Picture <?= $i + 1 ?>"><?php if ($i === 0): ?><em>Main</em><?php endif; ?>
          <label><input type="checkbox" name="keep[]" value="<?= $h($path) ?>" checked> Keep</label></div>
      <?php endforeach; ?>
    </div>
    <?php if (count($images) > 1): ?><label class="om-field"><span>Main picture</span><select name="main"><?php foreach ($images as $i => $path): ?><option value="<?= $h($path) ?>"<?= $i === 0 ? ' selected' : '' ?>>Picture <?= $i + 1 ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <?php endif; ?>
    <label class="om-field"><span><?= $images ? 'Add more pictures' : 'Choose pictures' ?></span><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple><small>A product needs at least one picture before it can go live.</small></label>
  </section>

  <section class="om-card">
    <h3>Stock</h3><p>How many you have. Customers can never buy more than what is in stock.</p>
    <div class="om-switch" role="radiogroup" aria-label="Stock type">
      <label><input type="radio" name="mode" value="single"<?= $f['mode'] === 'single' ? ' checked' : '' ?>><span>One option (no sizes)</span></label>
      <label><input type="radio" name="mode" value="variants"<?= $f['mode'] === 'variants' ? ' checked' : '' ?>><span>Sizes and colours</span></label>
    </div>
    <div id="omSingle"<?= $f['mode'] === 'single' ? '' : ' hidden' ?>>
      <label class="om-field"><span>Items in stock</span><input name="stock" value="<?= $h($f['stock']) ?>" inputmode="numeric" placeholder="20"></label>
    </div>
    <div id="omVariants"<?= $f['mode'] === 'variants' ? '' : ' hidden' ?>>
      <div class="om-vwrap"><table class="om-variants"><thead><tr><th>Size</th><th>Colour</th><th>In stock</th><th>Price (if different)</th><th>SKU</th><th></th></tr></thead>
        <tbody id="omVBody">
        <?php foreach ($f['variants'] as $v): ?>
          <tr><td><input name="v_size[]" value="<?= $h($v['size']) ?>" maxlength="20" placeholder="M"></td><td><input name="v_colour[]" value="<?= $h($v['colour']) ?>" maxlength="30" placeholder="Black"></td>
              <td><input name="v_stock[]" value="<?= $h($v['stock']) ?>" inputmode="numeric" placeholder="10"></td><td><input name="v_price[]" value="<?= $h($v['price_ugx']) ?>" inputmode="numeric" placeholder="Same as above"></td>
              <td><input name="v_sku[]" value="<?= $h($v['sku'] ?? '') ?>" maxlength="60"></td><td><button type="button" class="om-x" aria-label="Remove this row">&times;</button></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <button type="button" class="om-btn" id="omAddRow">+ Add size or colour</button>
      <p class="om-fine">Leave the colour empty for sizes only, or the size empty for colours only. Up to <?= (int) MARKET_MAX_VARIANTS ?> rows.</p>
    </div>
    <label class="om-field" style="margin-top:14px"><span>Warn me when this many are left</span><input name="low_stock_threshold" value="<?= $h($f['low_stock_threshold']) ?>" inputmode="numeric" style="max-width:140px"></label>
  </section>

  <?php if ($events): ?>
  <section class="om-card">
    <h3>Link to your events (optional)</h3><p>Show this product on the event's page, so fans can buy merchandise from the event they love.</p>
    <div class="om-events"><?php foreach ($events as $e): ?><label><input type="checkbox" name="event_ids[]" value="<?= (int) $e['id'] ?>"<?= in_array((int) $e['id'], $f['event_ids'], true) ? ' checked' : '' ?>> <?= $h($e['title']) ?></label><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <div class="om-savebar">
    <?php if ($isLive): ?>
      <button class="om-btn primary" name="action" value="save">Save changes</button>
    <?php else: ?>
      <button class="om-btn" name="action" value="draft">Save as draft</button>
      <button class="om-btn primary" name="action" value="publish">Publish product</button>
    <?php endif; ?>
  </div>
</form>

<script>
(function () {
  var form = document.getElementById('omProductForm'); if (!form) { return; }
  var single = document.getElementById('omSingle'), vars = document.getElementById('omVariants'), body = document.getElementById('omVBody');
  function mode() { var r = form.querySelector('input[name="mode"]:checked'); return r ? r.value : 'single'; }
  function paint() { var v = mode() === 'variants'; single.hidden = v; vars.hidden = !v; }
  Array.prototype.forEach.call(form.querySelectorAll('input[name="mode"]'), function (r) { r.addEventListener('change', paint); });
  function bindRemove(tr) { var x = tr.querySelector('.om-x'); if (x) { x.addEventListener('click', function () { if (body.rows.length > 1) { tr.remove(); } else { Array.prototype.forEach.call(tr.querySelectorAll('input'), function (i) { i.value = ''; }); } }); } }
  Array.prototype.forEach.call(body.rows, bindRemove);
  document.getElementById('omAddRow').addEventListener('click', function () {
    if (body.rows.length >= <?= (int) MARKET_MAX_VARIANTS ?>) { return; }
    var tr = body.rows[0].cloneNode(true);
    Array.prototype.forEach.call(tr.querySelectorAll('input'), function (i) { i.value = ''; });
    body.appendChild(tr); bindRemove(tr); tr.querySelector('input').focus();
  });
  paint();
})();
</script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
