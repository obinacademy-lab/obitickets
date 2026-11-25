<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET, store settings (ADR-003, phase 4): store name and address, logo and banner, how customers get orders (delivery, collection or both) and the delivery fee
 * (all of it is the seller's: no commission), contact details, store policies, and whether the store is shown. Only the owner or a manager may change it.
 */
$ctx = require_organizer_access('market.manage');
$sellerId = $ctx['organizer_id'];
$store = market_ready() ? market_store_ensure($sellerId) : null;
$error = null;

if ($store && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_email') {
    verify_csrf();
    $r = market_email_send_test($sellerId);
    $_SESSION['om_flash'] = [$r['ok'] ? 'ok' : 'bad', $r['message']];
    header('Location: /org-market-store.php#orderEmails');
    exit;
}
if ($store && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = ['name' => (string) ($_POST['name'] ?? ''), 'description' => (string) ($_POST['description'] ?? ''), 'delivery_mode' => (string) ($_POST['delivery_mode'] ?? ''), 'delivery_fee_ugx' => trim((string) ($_POST['delivery_fee_ugx'] ?? '0')),
        'delivery_note' => (string) ($_POST['delivery_note'] ?? ''), 'contact_email' => (string) ($_POST['contact_email'] ?? ''), 'contact_phone' => (string) ($_POST['contact_phone'] ?? ''), 'policies' => (string) ($_POST['policies'] ?? ''),
        'is_visible' => !empty($_POST['is_visible'])];
    $warn = [];
    foreach (['logo' => 'logo_path', 'banner' => 'banner_path'] as $field => $col) {
        if (!empty($_POST['remove_' . $field])) {
            $in[$col] = null;
        }
        [$ok, $path, $err] = market_image_upload($_FILES[$field] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!$ok) {
            $warn[] = $err;
        } elseif ($path) {
            $in[$col] = $path;
        }
    }
    $r = market_store_update($sellerId, $in);
    if ($r['ok']) {
        $_SESSION['om_flash'] = $warn ? ['warn', 'Saved, but: ' . implode(' ', array_unique($warn))] : ['ok', 'Your store settings were saved.'];
        header('Location: /org-market-store.php');
        exit;
    }
    $error = $r['error'];
    $store = array_merge($store, $in);   // keep what was typed
}
$flash = $_SESSION['om_flash'] ?? null;
unset($_SESSION['om_flash']);
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$logo = $store ? market_img($store['logo_path'] ?? '') : '';
$banner = $store ? market_img($store['banner_path'] ?? '') : '';

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = 'Store settings';
render_organizer_head('market-store', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Store settings</h1><p>How your store looks and how customers get their orders.</p></div>
  <?php if ($store): ?><a class="btn btn-ghost" href="/market.php?store=<?= urlencode((string) $store['slug']) ?>" target="_blank" rel="noopener">View my store</a><?php endif; ?>
</div>

<?php if (!$store): ?>
  <div class="om-note warn">OBI MARKET is not available yet. Please check back soon.</div>
<?php else: ?>
<?php if ($flash): ?><div class="om-note <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if ($error): ?><div class="om-note bad" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php if ($store['status'] === 'SUSPENDED'): ?><div class="om-note bad"><b>Your store is suspended.</b> Customers cannot see or buy your products. Please contact support.</div><?php endif; ?>

<form class="om-form" method="post" enctype="multipart/form-data" action="/org-market-store.php" novalidate>
  <?= csrf_field() ?>
  <section class="om-card">
    <h3>Your store</h3><p>Customers see this on your store page and on every product.</p>
    <label class="om-field"><span>Store name</span><input name="name" value="<?= $h($store['name']) ?>" maxlength="80" required></label>
    <label class="om-field"><span>About your store</span><textarea name="description" rows="3" maxlength="600" placeholder="Who you are and what you sell"><?= $h($store['description']) ?></textarea></label>
    <div class="om-row2">
      <div class="om-field"><span>Logo</span>
        <?php if ($logo !== ''): ?><div class="om-logo"><span class="om-thumb"><img src="<?= $h($logo) ?>" alt="Your logo"></span><label><input type="checkbox" name="remove_logo" value="1"> Remove</label></div><?php endif; ?>
        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"><small>A square picture works best.</small></div>
      <div class="om-field"><span>Banner</span>
        <?php if ($banner !== ''): ?><img class="om-banner" src="<?= $h($banner) ?>" alt="Your banner"><label><input type="checkbox" name="remove_banner" value="1"> Remove</label><?php endif; ?>
        <input type="file" name="banner" accept="image/jpeg,image/png,image/webp"><small>A wide picture, shown at the top of your store.</small></div>
    </div>
    <div class="om-events" style="margin-top:6px"><label><input type="checkbox" name="is_visible" value="1"<?= (int) $store['is_visible'] ? ' checked' : '' ?>> Show my store and products to customers</label></div>
  </section>

  <section class="om-card">
    <h3>Delivery</h3><p>You deliver (or hand over) the orders yourself. The delivery fee is yours: obitickets takes no commission on it.</p>
    <label class="om-field"><span>How customers get their order</span><select name="delivery_mode">
      <option value="BOTH"<?= $store['delivery_mode'] === 'BOTH' ? ' selected' : '' ?>>Delivery or collection (customer chooses)</option>
      <option value="DELIVERY"<?= $store['delivery_mode'] === 'DELIVERY' ? ' selected' : '' ?>>Delivery only</option>
      <option value="COLLECTION"<?= $store['delivery_mode'] === 'COLLECTION' ? ' selected' : '' ?>>Collection only</option></select></label>
    <div class="om-row2">
      <label class="om-field"><span>Delivery fee (UGX)</span><input name="delivery_fee_ugx" value="<?= $h($store['delivery_fee_ugx']) ?>" inputmode="numeric"><small>0 means free delivery.</small></label>
      <label class="om-field"><span>Delivery note (optional)</span><input name="delivery_note" value="<?= $h($store['delivery_note']) ?>" maxlength="255" placeholder="e.g. Delivered within 3 days in Kampala"></label>
    </div>
  </section>

  <section class="om-card">
    <h3>Contact and policies</h3><p>Customers see your policies on your products. Your contact details are used for order questions.</p>
    <div class="om-row2">
      <label class="om-field"><span>Contact email</span><input name="contact_email" type="email" value="<?= $h($store['contact_email']) ?>" maxlength="190"></label>
      <label class="om-field"><span>Contact phone</span><input name="contact_phone" type="tel" value="<?= $h($store['contact_phone']) ?>" maxlength="32" placeholder="0772 123 456"></label>
    </div>
    <label class="om-field"><span>Store policies</span><textarea name="policies" rows="4" maxlength="2000" placeholder="Returns, exchanges, delivery times, care instructions..."><?= $h($store['policies']) ?></textarea></label>
  </section>

  <div class="om-savebar"><button class="om-btn primary" type="submit">Save settings</button></div>
</form>

<form class="om-form" method="post" action="/org-market-store.php" id="orderEmails" style="margin-top:18px">
  <?= csrf_field() ?><input type="hidden" name="action" value="test_email">
  <section class="om-card">
    <h3>Order emails</h3>
    <p>Every time a customer pays for one of your products you get an email straight away with the order, the customer, where to deliver and what you keep. It goes to your account email<?= $store['contact_email'] && strcasecmp((string) $store['contact_email'], (string) (current_user()['email'] ?? '')) !== 0 ? ' and a copy to ' . $h($store['contact_email']) : '' ?>. You also get a notification in the dashboard. These emails cannot be switched off, so you never miss an order.</p>
    <button class="om-btn" type="submit">Send me a test email</button>
  </section>
</form>
<?php endif; ?>

<?php render_organizer_foot(); ?>
