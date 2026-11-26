<?php
require_once __DIR__ . '/includes/bootstrap.php';

// OBI GIFTS MANAGEMENT (phase 2): the gift catalogue, the 40/60 revenue split with its history, and the master switch.
// Gifts are separate from tickets: nothing on this page reads or changes orders, tickets or ticket payouts.
$admin = require_admin_permission('gifts.view');
$adminId = (int) $admin['id'];
$ready = gifts_ready();
$canManage = admin_can('gifts.manage');
$canSplit = admin_can('gifts.commission');
$tab = in_array($_GET['tab'] ?? '', ['split', 'withdrawals'], true) ? $_GET['tab'] : 'catalogue';
$error = null;
$flash = $_SESSION['gift_admin_flash'] ?? null;
unset($_SESSION['gift_admin_flash']);
$go = static function (string $url, string $msg) {
    $_SESSION['gift_admin_flash'] = $msg;
    header('Location: ' . $url);
    exit;
};
$editing = null;          // the gift being edited, or [] for a new one
$form = [];               // what the form shows (kept when a save fails)

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['save_gift', 'set_active', 'move', 'toggle_switch'], true) && !$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage gifts.');
    }
    if (in_array($action, ['toggle_withdrawals', 'wd_mark_paid', 'wd_mark_failed', 'wd_approve', 'wd_decline', 'toggle_wd_approval'], true) && !$canSplit) {
        http_response_code(403);
        exit('Only the top-level admin can change gift withdrawals.');
    }
    if ($action === 'toggle_withdrawals') {
        $turnOn = !empty($_POST['on']);
        if ($turnOn && !payouts_iotec_configured()) {
            $go('/admin-gifts.php?tab=withdrawals', 'Cannot switch withdrawals on: the ioTec keys are missing from the server config.');
        }
        gift_withdraw_set_enabled($adminId, $turnOn);
        $go('/admin-gifts.php?tab=withdrawals', $turnOn ? 'Automatic gift withdrawals are switched ON.' : 'Automatic gift withdrawals are switched OFF.');
    }
    if ($action === 'toggle_wd_approval') {
        $turnOn = !empty($_POST['on']);
        gift_withdraw_set_approval($adminId, $turnOn);
        $go('/admin-gifts.php?tab=withdrawals', $turnOn ? 'Approval is ON: new withdrawals wait for you before any money is sent.' : 'Approval is OFF: withdrawals are sent automatically again. Ones already waiting still need you.');
    }
    if ($action === 'wd_approve') {
        $res = gift_withdraw_approve((int) ($_POST['id'] ?? 0), $adminId);
        $go('/admin-gifts.php?tab=withdrawals', $res['message']);
    }
    if ($action === 'wd_decline') {
        $res = gift_withdraw_decline((int) ($_POST['id'] ?? 0), $adminId, (string) ($_POST['reason'] ?? ''));
        $go('/admin-gifts.php?tab=withdrawals', $res['message']);
    }
    if ($action === 'wd_mark_paid') {
        $done = gift_withdraw_mark_paid((int) ($_POST['id'] ?? 0), $adminId);
        $go('/admin-gifts.php?tab=withdrawals', $done ? 'Marked paid. The organizer was told.' : 'That withdrawal is not waiting any more.');
    }
    if ($action === 'wd_mark_failed') {
        $why = trim((string) ($_POST['reason'] ?? ''));
        if ($why === '') {
            $go('/admin-gifts.php?tab=withdrawals', 'Give a reason first.');
        }
        $done = gift_withdraw_fail((int) ($_POST['id'] ?? 0), 'Checked by an admin: ' . $why, false, $adminId);
        $go('/admin-gifts.php?tab=withdrawals', $done ? 'Marked failed. The money is back with the organizer.' : 'That withdrawal is not waiting any more.');
    }
    if ($action === 'set_commission' && !$canSplit) {
        http_response_code(403);
        exit('Only the top-level admin can change the revenue split.');
    }
    if ($action === 'toggle_switch') {
        $turnOn = !empty($_POST['on']);
        if ($turnOn && !payouts_iotec_configured()) {
            $go('/admin-gifts.php', 'Cannot switch gifts on: the ioTec keys are missing from the server config.');
        }
        gift_set_enabled($adminId, $turnOn);
        $go('/admin-gifts.php', $turnOn ? 'OBI GIFTS is switched ON.' : 'OBI GIFTS is switched OFF.');
    } elseif ($action === 'save_gift') {
        $gid = (int) ($_POST['id'] ?? 0) ?: null;
        $res = gift_admin_save($adminId, $gid, $_POST, $_FILES['image'] ?? null, $_FILES['animation'] ?? null, !empty($_POST['remove_image']), !empty($_POST['remove_animation']), null, $_FILES['sound'] ?? null, !empty($_POST['remove_sound']));
        if ($res['ok']) {
            $go('/admin-gifts.php', $gid ? 'Gift saved.' : 'Gift added.');
        }
        $error = $res['error'];
        $editing = $gid ? (gift_find($gid) ?: []) : [];
        $form = $_POST;
    } elseif ($action === 'set_active') {
        gift_admin_set_active($adminId, (int) ($_POST['id'] ?? 0), !empty($_POST['active']));
        $go('/admin-gifts.php', !empty($_POST['active']) ? 'Gift is now visible to viewers.' : 'Gift hidden from viewers.');
    } elseif ($action === 'move') {
        gift_admin_move($adminId, (int) ($_POST['id'] ?? 0), ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down');
        $go('/admin-gifts.php', 'Order updated.');
    } elseif ($action === 'set_commission') {
        $res = gift_admin_set_commission($adminId, (string) ($_POST['platform_percent'] ?? ''), (string) ($_POST['reason'] ?? ''), ($_POST['when'] ?? 'now') === 'later' ? (string) ($_POST['effective_from'] ?? '') : null);
        if ($res['ok']) {
            $go('/admin-gifts.php?tab=split', 'The revenue split was changed (version ' . $res['version'] . '). It applies to new gifts only.');
        }
        $error = $res['error'];
        $tab = 'split';
        $form = $_POST;
    }
}

if ($ready && $editing === null && $tab === 'catalogue') {
    if (isset($_GET['new'])) {
        $editing = [];
    } elseif (!empty($_GET['edit'])) {
        $editing = gift_find((int) $_GET['edit']) ?: null;
    }
}

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$cfg = $ready ? gift_commission_current() : null;
$gifts = $ready ? gift_catalog_all() : [];
$coinsOn = $ready && function_exists('coins_ready') && coins_ready();
$switchSetting = $ready && get_setting('gifts_enabled', '0') === '1';
$switchLive = $ready && gifts_enabled();
$fv = static fn (string $k, $default = '') => $form[$k] ?? $default;   // a form value: what was typed (after an error) or the saved one
$v49 = $ready && gifts_v49();   // ADR-004 fields (category, sound, length, signature) exist
$catFilter = $v49 && isset(GIFT_CATEGORIES[$_GET['cat'] ?? '']) ? (string) $_GET['cat'] : '';
$catCounts = [];
foreach ($gifts as $g) { $c = gift_category_of($g); $catCounts[$c] = ($catCounts[$c] ?? 0) + 1; }
// what the animation preview needs (the same shape the viewer's sheet gets)
$previewShape = static fn (array $g): array => gift_public_shape($g, true) + ['priceUgx' => (int) $g['price_ugx']];

$pageTitle = 'OBI GIFTS';
render_admin_head('gifts');
?>

<div class="admin-page-head">
  <div><h1>OBI GIFTS</h1><p>Virtual gifts viewers send to organizers. The catalogue and the revenue split live here. Gifts are completely separate from tickets.</p></div>
</div>

<?php if ($flash): ?><span data-flash="<?= $h($flash) ?>" hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= $h($error) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$ready): ?>
  <div class="admin-card" style="border-left:4px solid var(--danger)">
    <h3 style="margin:0 0 6px">Database update needed</h3>
    <p style="margin:0">Run <b>migration/045_ob_gifts.sql</b> in phpMyAdmin first. Until then this page has nothing to show, and nothing else on the site is affected.</p>
  </div>
<?php else: ?>

<div class="admin-card" style="margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
  <div style="min-width:min(240px,100%); flex:1">
    <h3 style="margin:0 0 4px">Gifts for viewers: <span class="admin-badge <?= $switchLive ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $switchLive ? 'ON' : 'OFF' ?></span></h3>
    <p class="muted" style="margin:0; font-size:0.86rem">
      <?php if ($switchSetting && !payouts_iotec_configured()): ?>The switch is on, but the ioTec keys are missing from the server config, so gifts stay off.
      <?php elseif ($switchLive): ?>The switch is on: viewers can see the Gift button and pay for gifts with real money.
      <?php else: ?>The switch is off: no viewer can see or send gifts. Turn it on only after the viewer screens and a small real test payment are done.<?php endif; ?>
    </p>
  </div>
  <?php if ($canManage): ?>
    <form method="post" data-confirm="<?= $switchLive || $switchSetting ? 'Switch OBI GIFTS OFF?' : 'Switch OBI GIFTS ON? Viewers will be able to buy gifts with real money.' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="toggle_switch"><?php if (!$switchSetting): ?><input type="hidden" name="on" value="1"><?php endif; ?>
      <button type="submit" class="btn <?= $switchSetting ? 'btn-line' : 'btn-purple' ?>"><?= $switchSetting ? 'Switch OFF' : 'Switch ON' ?></button>
    </form>
  <?php endif; ?>
</div>

<?= gift_admin_tabs($tab) ?>

<?php if ($tab === 'catalogue'): ?>
  <link rel="stylesheet" href="/assets/css/gifts.css?v=<?= @filemtime(__DIR__ . '/assets/css/gifts.css') ?: time() ?>">
  <script src="/assets/js/gift-art.js?v=<?= @filemtime(__DIR__ . '/assets/js/gift-art.js') ?: time() ?>"></script>
  <script src="/assets/js/gift-sound.js?v=<?= @filemtime(__DIR__ . '/assets/js/gift-sound.js') ?: time() ?>"></script>
  <script src="/assets/js/gifts.js?v=<?= @filemtime(__DIR__ . '/assets/js/gifts.js') ?: time() ?>"></script>

  <?php if ($editing !== null && $canManage): $isNew = !$editing; ?>
    <div class="admin-card" style="margin-bottom:18px">
      <h3 style="margin:0 0 14px"><?= $isNew ? 'Add a gift' : 'Edit ' . $h($editing['name']) ?></h3>
      <form method="post" enctype="multipart/form-data" id="giftForm">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_gift"><input type="hidden" name="id" value="<?= $isNew ? '' : (int) $editing['id'] ?>">
        <div class="admin-grid-2">
          <div>
            <div class="admin-form-row"><label>Name</label><input type="text" name="name" id="gName" maxlength="60" required value="<?= $h($fv('name', $editing['name'] ?? '')) ?>" placeholder="e.g. Diamond"></div>
            <div class="admin-form-row"><label>Price (UGX)</label><input type="number" name="price_ugx" id="gPrice" min="1" max="<?= GIFT_MAX_PAYMENT_UGX ?>" step="1" required value="<?= $h($fv('price_ugx', $editing['price_ugx'] ?? '')) ?>"></div>
            <p class="muted" style="font-size:0.8rem; margin:-4px 0 12px">Up to UGX <?= number_format(GIFT_MAX_PAYMENT_UGX) ?>. This is only the price for paying a gift directly by mobile money; under UGX <?= number_format(GIFT_MIN_UGX) ?> the gift can <b>only be sent with OBI COINS</b>. A new price applies to <b>new gifts only</b>; gifts already sent keep the price they were bought at.</p>
            <?php if ($coinsOn): ?>
              <div class="admin-form-row"><label>Price in OBI COINS</label><input type="number" name="price_coins" min="1" max="9999999" step="1" value="<?= $h($fv('price_coins', $editing['price_coins'] ?? ($isNew ? '' : ''))) ?>" placeholder="Leave empty to keep it out of OBI COINS"></div>
              <p class="muted" style="font-size:0.8rem; margin:-4px 0 12px">This is the only price viewers see once OBI COINS is on. A gift with no coin price is not offered then. A new price applies to <b>new gifts only</b>.</p>
            <?php endif; ?>
            <div class="admin-form-row"><label>Emoji (shown when there is no picture)</label><input type="text" name="emoji" id="gEmoji" maxlength="16" value="<?= $h($fv('emoji', $editing['emoji'] ?? '')) ?>" placeholder="e.g. 💎"></div>
            <div class="admin-form-row"><label>Description (optional)</label><input type="text" name="description" maxlength="255" value="<?= $h($fv('description', $editing['description'] ?? '')) ?>"></div>
            <div class="admin-form-row"><label>Animation style</label>
              <select name="animation_type" id="gAnim">
                <?php foreach (GIFT_ANIMATIONS as $k => $label): ?><option value="<?= $h($k) ?>" <?= $fv('animation_type', $editing['animation_type'] ?? 'pop') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php if ($v49): ?>
              <input type="hidden" name="v49_form" value="1">
              <div class="admin-form-row"><label>Category</label>
                <select name="category" id="gCat"><?php foreach (GIFT_CATEGORIES as $k => $label): ?><option value="<?= $h($k) ?>" <?= $fv('category', $editing ? gift_category_of($editing) : 'EVERYDAY') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select>
              </div>
              <div class="admin-form-row"><label>Animation length (seconds)</label><input type="number" name="duration_s" id="gDur" min="1" max="12" step="0.1" value="<?= $h($fv('duration_s', !empty($editing['duration_ms']) ? round((int) $editing['duration_ms'] / 1000, 1) : '')) ?>" placeholder="Empty = Everyday 2, Popular 3.2, Premium 5, Elite 8"></div>
              <div class="admin-form-row"><label>Sound</label>
                <div style="display:flex; gap:8px; align-items:center">
                  <select name="sound_style" id="gSound" style="flex:1"><?php foreach (GIFT_SOUNDS as $k => $label): ?><option value="<?= $h($k) ?>" <?= $fv('sound_style', $editing['sound_style'] ?? 'pop') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select>
                  <button type="button" class="btn btn-line" id="gSoundTry">&#9654; Hear it</button>
                </div>
              </div>
              <p class="muted" style="font-size:0.8rem; margin:-4px 0 12px">Built-in sounds are made by the viewer's phone itself (nothing to license). An uploaded sound file (right) plays instead. Viewers can mute gift sounds.</p>
              <label style="display:flex; align-items:center; gap:8px; margin:6px 0 8px"><input type="checkbox" name="is_featured" value="1" <?= ($form ? !empty($form['is_featured']) : !empty($editing['is_featured'])) ? 'checked' : '' ?>> Featured (a star on the card)</label>
              <label style="display:flex; align-items:center; gap:8px; margin:0 0 12px"><input type="checkbox" name="is_signature" value="1" <?= ($form ? !empty($form['is_signature']) : !empty($editing['is_signature'])) ? 'checked' : '' ?>> Signature gift (only one gift can be: ticking this removes the mark from the other)</label>
            <?php endif; ?>
            <div class="admin-form-row"><label>Order (smaller shows first)</label><input type="number" name="sort_order" step="1" value="<?= $h($fv('sort_order', $editing['sort_order'] ?? '')) ?>" placeholder="Leave empty to put it last"></div>
            <label style="display:flex; align-items:center; gap:8px; margin:6px 0 14px"><input type="checkbox" name="is_active" value="1" <?= ($form ? !empty($form['is_active']) : ($isNew ? true : !empty($editing['is_active']))) ? 'checked' : '' ?>> Visible to viewers</label>
          </div>
          <div>
            <div class="admin-form-row"><label>Picture (PNG, WEBP, GIF or JPG, up to 1 MB)</label><input type="file" name="image" accept="image/png,image/webp,image/gif,image/jpeg" id="gImage"></div>
            <?php if (!$isNew && $editing['image_path']): ?><label style="display:flex; align-items:center; gap:8px; margin:-4px 0 12px"><input type="checkbox" name="remove_image" value="1"> Remove the current picture <img src="<?= $h($editing['image_path']) ?>" alt="" style="height:28px; border-radius:6px"></label><?php endif; ?>
            <div class="admin-form-row"><label>Animated file (optional: WEBP, GIF or PNG, up to 2 MB)</label><input type="file" name="animation" accept="image/png,image/webp,image/gif"></div>
            <?php if (!$isNew && $editing['animation_asset']): ?><label style="display:flex; align-items:center; gap:8px; margin:-4px 0 12px"><input type="checkbox" name="remove_animation" value="1"> Remove the current animated file <img src="<?= $h($editing['animation_asset']) ?>" alt="" style="height:28px; border-radius:6px"></label><?php endif; ?>
            <?php if ($v49): ?>
              <div class="admin-form-row"><label>Sound file (optional: MP3, OGG or WAV, up to <?= (int) (GIFT_SOUND_MAX_BYTES / 1024) ?> KB)</label><input type="file" name="sound" accept=".mp3,.ogg,.wav,audio/mpeg,audio/ogg,audio/wav"></div>
              <?php if (!$isNew && !empty($editing['sound_asset'])): ?><div style="display:flex; align-items:center; gap:8px; margin:-4px 0 12px; flex-wrap:wrap"><audio controls preload="none" src="<?= $h($editing['sound_asset']) ?>" style="height:32px"></audio><label style="display:flex; align-items:center; gap:6px"><input type="checkbox" name="remove_sound" value="1"> Remove this sound file</label></div><?php endif; ?>
              <p class="muted" style="font-size:0.8rem; margin:-4px 0 12px">Only use a sound you made or have the rights to.</p>
            <?php endif; ?>
            <div class="muted" style="font-size:0.8rem; margin-bottom:8px">SVG files are not accepted (they can carry scripts). If there is no animated file, the animation style above plays with the picture or emoji.</div>
            <div class="admin-label" style="font-weight:700; margin:8px 0">How viewers see it</div>
            <div id="gPreview" style="width:170px; text-align:center; padding:16px; border-radius:18px; background:#1D1518; border:1px solid #2B2024; color:#fff">
              <div id="gPrevArt" data-slug="<?= $h($editing['slug'] ?? '') ?>" style="font-size:2.6rem; line-height:1.2; min-height:3.1rem; display:grid; place-items:center"><?php if (!$isNew && $editing['image_path']): ?><img src="<?= $h($editing['image_path']) ?>" alt="" style="height:48px"><?php else: ?><?= $h($fv('emoji', $editing['emoji'] ?? '')) ?><?php endif; ?></div>
              <b id="gPrevName" style="display:block"><?= $h($fv('name', $editing['name'] ?? 'Gift name')) ?></b>
              <div id="gPrevPrice" style="color:#FBBF24; font-weight:800">UGX <?= number_format((int) $fv('price_ugx', $editing['price_ugx'] ?? 0)) ?></div>
            </div>
            <?php if ($v49): ?><button type="button" class="btn btn-line" id="gPlay" style="margin-top:10px">&#9654; Play the animation</button><p class="muted" style="font-size:0.78rem; margin:6px 0 0">Plays with the settings in this form (save to keep them). A new picture shows after saving.</p><?php endif; ?>
          </div>
        </div>
        <div style="display:flex; gap:10px; margin-top:6px">
          <button class="btn btn-purple" type="submit"><?= $isNew ? 'Add gift' : 'Save changes' ?></button>
          <a class="btn btn-line" href="/admin-gifts.php">Cancel</a>
        </div>
      </form>
    </div>
    <script>
    (function () {
      var n = document.getElementById('gName'), p = document.getElementById('gPrice'), e = document.getElementById('gEmoji'), f = document.getElementById('gImage');
      var art = document.getElementById('gPrevArt'), pn = document.getElementById('gPrevName'), pp = document.getElementById('gPrevPrice');
      var shownImage = art.querySelector('img') ? art.querySelector('img').src : '';
      var slug = art.getAttribute('data-slug') || '';
      function paint() {
        pn.textContent = n.value || 'Gift name';
        pp.textContent = 'UGX ' + (Number(p.value) || 0).toLocaleString('en-US');
        if (shownImage) { art.innerHTML = '<img src="' + shownImage + '" alt="" style="height:48px">'; }
        else if (window.ObGiftArt && slug && ObGiftArt.has(slug)) { art.innerHTML = ObGiftArt.svg(slug); var sv = art.querySelector('svg'); sv.style.width = '64px'; sv.style.height = '64px'; }
        else { art.textContent = e.value; }
      }
      var base = <?= json_encode($editing ? $previewShape($editing) : ['slug' => '', 'image' => null, 'animAsset' => null, 'soundAsset' => null], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      var val = function (id) { var x = document.getElementById(id); return x ? x.value : ''; };
      var chk = function (nm) { var x = document.querySelector('input[name="' + nm + '"]'); return !!(x && x.checked); };
      var tryBtn = document.getElementById('gSoundTry');
      if (tryBtn) { tryBtn.addEventListener('click', function () { if (!window.ObGiftSound) { return; } ObGiftSound.set(true); ObGiftSound.unlock(); ObGiftSound.play(val('gSound'), null); }); }
      var playBtn = document.getElementById('gPlay');
      if (playBtn) {
        playBtn.addEventListener('click', function () {
          if (!window.ObGifts) { return; }
          if (window.ObGiftSound) { ObGiftSound.unlock(); }
          var g = { slug: slug, name: n.value || 'Gift', emoji: e.value, image: base.image, animAsset: base.animAsset, soundAsset: base.soundAsset, anim: val('gAnim'), category: val('gCat'),
                    ms: Math.round((Number(val('gDur')) || 0) * 1000) || 0, sound: val('gSound'), signature: chk('is_signature') };
          var coinsInput = document.querySelector('input[name="price_coins"]');
          ObGifts.play(document.body, { gift: g, quantity: 1, sender: 'A fan', recipient: 'Your organizer', coins: coinsInput && coinsInput.value ? Number(coinsInput.value) : null }, true);
        });
      }
      paint();
      [n, p, e].forEach(function (x) { x.addEventListener('input', paint); });
      f.addEventListener('change', function () {
        if (f.files && f.files[0]) { var r = new FileReader(); r.onload = function (ev) { shownImage = ev.target.result; paint(); }; r.readAsDataURL(f.files[0]); }
      });
    })();
    </script>
  <?php endif; ?>

  <div class="admin-card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px">
      <h3 style="margin:0">Gift catalogue <span class="muted" style="font-weight:500">(<?= count($gifts) ?> gifts, <?= count(array_filter($gifts, static fn ($g) => $g['is_active'])) ?> visible)</span></h3>
      <?php if ($canManage): ?><a class="btn btn-purple" href="/admin-gifts.php?new=1">Add a gift</a><?php endif; ?>
    </div>
    <?php if ($v49): ?>
      <div style="display:flex; gap:6px; flex-wrap:wrap; margin:0 0 12px">
        <a class="btn <?= $catFilter === '' ? 'btn-purple' : 'btn-line' ?>" href="/admin-gifts.php" style="padding:4px 12px">All (<?= count($gifts) ?>)</a>
        <?php foreach (GIFT_CATEGORIES as $k => $label): ?><a class="btn <?= $catFilter === $k ? 'btn-purple' : 'btn-line' ?>" href="/admin-gifts.php?cat=<?= $h($k) ?>" style="padding:4px 12px"><?= $h($label) ?> (<?= (int) ($catCounts[$k] ?? 0) ?>)</a><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none">
      <table class="admin-table">
        <thead><tr><th>Order</th><th>Gift</th><?php if ($v49): ?><th>Category</th><?php endif; ?><th>Price</th><?php if ($coinsOn): ?><th>OBI COINS</th><?php endif; ?><th>Animation</th><?php if ($v49): ?><th>Sound</th><?php endif; ?><th>Status</th><th>Sent</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($gifts as $i => $g): if ($catFilter !== '' && gift_category_of($g) !== $catFilter) { continue; } ?>
            <tr>
              <td style="white-space:nowrap">
                <?php if ($canManage): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="dir" value="up"><button class="btn btn-line" type="submit" title="Move up" <?= $i === 0 ? 'disabled' : '' ?> style="padding:2px 9px">↑</button></form>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="dir" value="down"><button class="btn btn-line" type="submit" title="Move down" <?= $i === count($gifts) - 1 ? 'disabled' : '' ?> style="padding:2px 9px">↓</button></form>
                <?php else: ?><?= $i + 1 ?><?php endif; ?>
              </td>
              <td><span class="og-list-art" data-slug="<?= $h($g['slug']) ?>" style="display:inline-grid; place-items:center; width:34px; height:34px; font-size:1.5rem; vertical-align:middle; margin-right:8px"><?php if ($g['image_path']): ?><img src="<?= $h($g['image_path']) ?>" alt="" style="height:30px; vertical-align:middle"><?php else: ?><?= $h($g['emoji']) ?><?php endif; ?></span><b><?= $h($g['name']) ?></b><?php if (!empty($g['is_signature'])): ?> <span class="admin-badge admin-badge-success">Signature</span><?php elseif (!empty($g['is_featured'])): ?> <span class="admin-badge admin-badge-muted">Featured</span><?php endif; ?><div class="muted" style="font-size:0.78rem"><?= $h($g['slug']) ?></div></td>
              <?php if ($v49): ?><td><?= $h(GIFT_CATEGORIES[gift_category_of($g)]) ?><div class="muted" style="font-size:0.78rem"><?= number_format(gift_duration_ms($g) / 1000, 1) ?> s</div></td><?php endif; ?>
              <td class="mono" style="font-weight:700">UGX <?= number_format((int) $g['price_ugx']) ?><?php if ((int) $g['price_ugx'] < GIFT_MIN_UGX): ?><div class="muted" style="font-size:0.74rem; font-weight:500">coins only</div><?php endif; ?></td>
              <?php if ($coinsOn): ?><td class="mono" style="font-weight:700"><?= $g['price_coins'] !== null ? number_format((int) $g['price_coins']) : '<span class="muted">none</span>' ?></td><?php endif; ?>
              <td class="muted"><?= $h(GIFT_ANIMATIONS[$g['animation_type']] ?? $g['animation_type']) ?><?= $g['animation_asset'] ? ' + file' : '' ?></td>
              <?php if ($v49): ?><td class="muted"><?= !empty($g['sound_asset']) ? 'Uploaded file' : $h(GIFT_SOUNDS[$g['sound_style'] ?? ''] ?? 'None') ?></td><?php endif; ?>
              <td><span class="admin-badge <?= $g['is_active'] ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $g['is_active'] ? 'Visible' : 'Hidden' ?></span></td>
              <td class="muted mono"><?= (int) $g['sent_count'] ?><?php if ($g['sent_count']): ?> · UGX <?= number_format((int) $g['sent_gross']) ?><?php endif; ?></td>
              <?php if ($canManage): ?>
                <td style="white-space:nowrap">
                  <a class="btn btn-line" href="/admin-gifts.php?edit=<?= (int) $g['id'] ?>">Edit</a>
                  <form method="post" style="display:inline" data-confirm="<?= $g['is_active'] ? 'Hide this gift from viewers? Past gifts stay in the records.' : 'Show this gift to viewers?' ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><?php if (!$g['is_active']): ?><input type="hidden" name="active" value="1"><?php endif; ?>
                    <button class="btn btn-line" type="submit"><?= $g['is_active'] ? 'Hide' : 'Show' ?></button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
    (function () { if (!window.ObGiftArt) { return; } Array.prototype.forEach.call(document.querySelectorAll('.og-list-art'), function (s) { if (s.querySelector('img')) { return; } var slug = s.getAttribute('data-slug'); if (ObGiftArt.has(slug)) { s.innerHTML = ObGiftArt.svg(slug); var v = s.querySelector('svg'); v.style.width = '34px'; v.style.height = '34px'; } }); })();
    </script>
    <p class="muted" style="font-size:0.82rem; margin:12px 0 0">Gifts are never deleted: sent gifts refer to them, so a gift you no longer want is hidden instead. Only visible gifts appear to viewers.</p>
  </div>

<?php elseif ($tab === 'withdrawals'): /* ------------------------------------------------ withdrawals */
  $wdLive = gift_withdraw_enabled();
  $wdSetting = get_setting('gift_withdrawals_enabled', '0') === '1';
  $wdRows = gift_withdraw_admin_list(60);
  $wdProcessing = count(array_filter($wdRows, static fn ($r) => $r['status'] === 'PROCESSING'));
  $wdStuck = count(array_filter($wdRows, static fn ($r) => $r['status'] === 'PROCESSING' && (int) $r['age_min'] >= 10));
  $wdPaidToday = (int) db()->query("SELECT COALESCE(SUM(amount_ugx), 0) FROM gift_withdrawals WHERE status = 'PAID' AND completed_at >= CURDATE()")->fetchColumn();
  $wdBadge = ['PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'REJECTED' => 'admin-badge-danger', 'CANCELLED' => 'admin-badge-danger', 'PENDING_REVIEW' => 'admin-badge-warn'];
  $wdApproval = gift_withdraw_approval_required();
  $wdQueue = gift_withdraw_pending_review();
?>
  <div class="admin-card" style="margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
    <div style="min-width:min(240px,100%); flex:1">
      <h3 style="margin:0 0 4px">Automatic gift withdrawals: <span class="admin-badge <?= $wdLive ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $wdLive ? 'ON' : 'OFF' ?></span></h3>
      <p class="muted" style="margin:0; font-size:0.86rem">
        <?php if ($wdSetting && !payouts_iotec_configured()): ?>The switch is on, but the ioTec keys are missing from the server config, so withdrawals stay off.
        <?php elseif ($wdLive): ?>Organizers can withdraw their gift earnings and the money is sent to their mobile money at once, with no click from you. obitickets pays the ioTec fees.
        <?php else: ?>Off: organizers see their gift balance but cannot withdraw it yet. Turn it on after your first real gift has arrived and you have checked the numbers.<?php endif; ?>
      </p>
      <p class="muted" style="margin:6px 0 0; font-size:0.82rem">Being sent now: <b><?= $wdProcessing ?></b> &middot; waiting more than 10 minutes: <b><?= $wdStuck ?></b> &middot; sent today: <b>UGX <?= number_format($wdPaidToday) ?></b></p>
    </div>
    <?php if ($canSplit): ?>
      <form method="post" data-confirm="<?= $wdSetting ? 'Switch automatic gift withdrawals OFF? Organizers will not be able to withdraw until you switch it on again.' : 'Switch automatic gift withdrawals ON? Real money will be sent from your ioTec wallet as soon as an organizer withdraws.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_withdrawals"><?php if (!$wdSetting): ?><input type="hidden" name="on" value="1"><?php endif; ?>
        <button type="submit" class="btn <?= $wdSetting ? 'btn-line' : 'btn-purple' ?>"><?= $wdSetting ? 'Switch OFF' : 'Switch ON' ?></button>
      </form>
    <?php else: ?><span class="muted" style="font-size:.82rem">Only the top-level admin can change this switch.</span><?php endif; ?>
  </div>

  <div class="admin-card" style="margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
    <div style="min-width:min(240px,100%); flex:1">
      <h3 style="margin:0 0 4px">Approve each withdrawal first: <span class="admin-badge <?= $wdApproval ? 'admin-badge-warn' : 'admin-badge-muted' ?>"><?= $wdApproval ? 'ON' : 'OFF' ?></span></h3>
      <p class="muted" style="margin:0; font-size:0.86rem"><?= $wdApproval ? 'On: when an organizer withdraws, the money is set aside and waits here until you approve it. Nothing is sent without your click.' : 'Off: withdrawals are sent at once, with no click from you. Turn this on if you want to check each one first.' ?></p>
    </div>
    <?php if ($canSplit): ?>
      <form method="post" data-confirm="<?= $wdApproval ? 'Stop asking for approval? New withdrawals will be sent automatically again. Ones already waiting still need your decision.' : 'Ask for your approval on every new withdrawal? Organizers will wait until you approve.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_wd_approval"><?php if (!$wdApproval): ?><input type="hidden" name="on" value="1"><?php endif; ?>
        <button type="submit" class="btn <?= $wdApproval ? 'btn-line' : 'btn-purple' ?>"><?= $wdApproval ? 'Turn approval OFF' : 'Turn approval ON' ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($wdQueue): ?>
    <div class="admin-card" style="margin-bottom:18px; border-left:4px solid var(--warn)">
      <h3 style="margin:0 0 6px">Waiting for your approval (<?= count($wdQueue) ?>)</h3>
      <p class="muted" style="margin:0 0 12px; font-size:0.84rem">The money is already set aside for the organizer. Approve to send it to their mobile money now, or decline to give it straight back (they will read your reason).</p>
      <div class="admin-table-wrap" style="border:none; box-shadow:none">
        <table class="admin-table">
          <thead><tr><th>Asked</th><th>Organizer</th><th style="text-align:right">Amount</th><th>Send to</th><th>Earned / withdrawn so far</th><?php if ($canSplit): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($wdQueue as $q): ?>
              <tr>
                <td class="muted" style="white-space:nowrap"><?= $h(date('d M, H:i', strtotime((string) $q['requested_at']))) ?></td>
                <td><?= $h($q['organizer']) ?></td>
                <td class="mono" style="text-align:right; white-space:nowrap"><b>UGX <?= number_format((int) $q['amount_ugx']) ?></b></td>
                <td style="white-space:nowrap"><?= $h($q['destination']) ?> <span class="muted" style="font-size:.78rem"><?= $q['method'] === 'AIRTEL_MONEY' ? 'Airtel' : 'MTN' ?></span></td>
                <td class="muted mono" style="font-size:.8rem">UGX <?= number_format((int) $q['earned']) ?> / <?= number_format((int) $q['withdrawn']) ?></td>
                <?php if ($canSplit): ?><td style="white-space:nowrap">
                  <form method="post" style="display:inline" data-confirm="Approve and send UGX <?= number_format((int) $q['amount_ugx']) ?> to <?= $h($q['destination']) ?> now?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="wd_approve"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>"><button class="btn btn-purple" type="submit">Approve</button>
                  </form>
                  <form method="post" style="display:inline-flex; gap:4px" data-confirm="Decline this withdrawal? The money goes back to the organizer and they will read your reason.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="wd_decline"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
                    <input type="text" name="reason" required maxlength="150" placeholder="Reason (they will read it)" style="width:170px"><button class="btn btn-line" type="submit">Decline</button>
                  </form>
                </td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="admin-card">
    <h3 style="margin:0 0 6px">Gift withdrawals</h3>
    <p class="muted" style="margin:0 0 14px; font-size:0.84rem">The newest 60. A withdrawal is normally "Paid" within a minute. If one stays "Processing" for more than 10 minutes, ioTec did not answer: open the ioTec portal, look for the reference below, and then mark it <b>paid</b> (the money left) or <b>failed</b> (it did not, the money goes back to the organizer). It is never sent a second time.</p>
    <?php if (!$wdRows): ?>
      <p class="muted" style="margin:0">No gift withdrawals yet.</p>
    <?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>When</th><th>Organizer</th><th style="text-align:right">Amount</th><th>Sent to</th><th>Status</th><th>ioTec reference</th><?php if ($canSplit): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($wdRows as $w): $stuck = $w['status'] === 'PROCESSING' && (int) $w['age_min'] >= 10; ?>
              <tr>
                <td class="muted" style="white-space:nowrap"><?= $h(date('d M, H:i', strtotime((string) $w['requested_at']))) ?></td>
                <td><?= $h($w['organizer']) ?></td>
                <td class="mono" style="text-align:right; white-space:nowrap">UGX <?= number_format((int) $w['amount_ugx']) ?></td>
                <td style="white-space:nowrap"><?= $h($w['destination']) ?> <span class="muted" style="font-size:.78rem"><?= $w['method'] === 'AIRTEL_MONEY' ? 'Airtel' : 'MTN' ?></span></td>
                <td><span class="admin-badge <?= $wdBadge[$w['status']] ?? ($stuck ? 'admin-badge-danger' : 'admin-badge-warn') ?>"><?= $h($stuck ? 'Needs a check' : ucfirst(strtolower(str_replace('_', ' ', (string) $w['status'])))) ?></span><?php if ($w['reason']): ?><br><span class="muted" style="font-size:.76rem"><?= $h($w['reason']) ?></span><?php endif; ?></td>
                <td class="muted mono" style="font-size:.76rem">OGW-<?= (int) $w['id'] ?><?php if ($w['provider_reference']): ?><br><?= $h($w['provider_reference']) ?><?php endif; ?></td>
                <?php if ($canSplit): ?>
                  <td style="white-space:nowrap">
                    <?php if ($w['status'] === 'PROCESSING'): ?>
                      <form method="post" style="display:inline" data-confirm="Mark this withdrawal PAID? Only do this after you saw in the ioTec portal that the money was sent.">
                        <?= csrf_field() ?><input type="hidden" name="action" value="wd_mark_paid"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                        <button class="btn btn-line" type="submit">Mark paid</button>
                      </form>
                      <form method="post" style="display:inline-flex; gap:4px" data-confirm="Mark this withdrawal FAILED and return the money to the organizer? Only do this after you saw in the ioTec portal that nothing was sent.">
                        <?= csrf_field() ?><input type="hidden" name="action" value="wd_mark_failed"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                        <input type="text" name="reason" required maxlength="150" placeholder="Why (e.g. not in ioTec)" style="width:150px">
                        <button class="btn btn-line" type="submit">Mark failed</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: /* ------------------------------------------------ revenue split */
  $history = gift_commission_history();
  $audit = gift_audit_recent(15);
  $pp = $cfg['platform_bps'] / 100; $op = $cfg['organizer_bps'] / 100;
?>
  <div class="admin-kpi-grid" style="margin-bottom:18px">
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">obitickets share</span></div><span class="num"><?= $h(rtrim(rtrim(number_format($pp, 2), '0'), '.')) ?>%</span></div>
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Organizer share</span></div><span class="num"><?= $h(rtrim(rtrim(number_format($op, 2), '0'), '.')) ?>%</span></div>
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">In force since</span></div><span class="num" style="font-size:1.1rem">Version <?= (int) $cfg['version'] ?></span></div>
  </div>
  <p class="muted" style="margin:-6px 0 18px; font-size:0.86rem">Example on a UGX 10,000 gift: the organizer gets UGX <?= number_format(gift_split(10000, $cfg['platform_bps'], $cfg['organizer_bps'])['organizer']) ?> and obitickets UGX <?= number_format(gift_split(10000, $cfg['platform_bps'], $cfg['organizer_bps'])['platform']) ?>. ioTec's collection fee comes out of obitickets' share, so the organizer always gets exactly their percentage.</p>

  <div class="admin-grid-2">
    <div class="admin-card">
      <h3 style="margin:0 0 14px">Change the split</h3>
      <?php if ($canSplit): ?>
        <form method="post" data-confirm="Change the revenue split for NEW gifts? Gifts already sent keep their old split.">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_commission">
          <div class="admin-form-row"><label>obitickets share (%)</label><input type="text" inputmode="decimal" name="platform_percent" id="cPct" required value="<?= $h($fv('platform_percent', rtrim(rtrim(number_format($pp, 2), '0'), '.'))) ?>"></div>
          <p class="muted" style="font-size:0.84rem; margin:-4px 0 12px">The organizer gets <b id="cOrg"><?= $h(rtrim(rtrim(number_format($op, 2), '0'), '.')) ?></b>%.</p>
          <div class="admin-form-row"><label>Reason (required, kept in the history)</label><input type="text" name="reason" required maxlength="255" value="<?= $h($fv('reason')) ?>" placeholder="e.g. Launch promotion for organizers"></div>
          <div class="admin-form-row"><label>When does it start?</label>
            <select name="when" id="cWhen"><option value="now" <?= $fv('when', 'now') === 'now' ? 'selected' : '' ?>>Right now</option><option value="later" <?= $fv('when') === 'later' ? 'selected' : '' ?>>At a later date and time</option></select>
          </div>
          <div class="admin-form-row" id="cLater" <?= $fv('when') === 'later' ? '' : 'hidden' ?>><label>Start at</label><input type="datetime-local" name="effective_from" value="<?= $h($fv('effective_from')) ?>"></div>
          <button class="btn btn-purple btn-block" type="submit">Change the split</button>
        </form>
        <script>
        (function () {
          var p = document.getElementById('cPct'), o = document.getElementById('cOrg'), w = document.getElementById('cWhen'), l = document.getElementById('cLater');
          p.addEventListener('input', function () { var v = parseFloat(String(p.value).replace(',', '.')); o.textContent = isNaN(v) ? '?' : (Math.round((100 - v) * 100) / 100); });
          w.addEventListener('change', function () { l.hidden = w.value !== 'later'; });
        })();
        </script>
      <?php else: ?>
        <p class="muted" style="margin:0">Only the top-level admin can change the revenue split. You can see the history on the right.</p>
      <?php endif; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 14px">Rules that never change</h3>
      <ul style="margin:0; padding-left:18px; line-height:1.7">
        <li>A change affects <b>new gifts only</b>. Every sent gift keeps the split it was bought under.</li>
        <li>A change can start now or later, never in the past.</li>
        <li>Every change is saved with who made it, when, and why.</li>
        <li>The two shares always add up to 100%, in whole shillings.</li>
      </ul>
    </div>
  </div>

  <div class="admin-card" style="margin-top:18px">
    <h3 style="margin:0 0 14px">History of the split</h3>
    <div class="admin-table-wrap" style="border:none; box-shadow:none">
      <table class="admin-table">
        <thead><tr><th>Version</th><th>obitickets</th><th>Organizer</th><th>Starts</th><th>Changed by</th><th>Reason</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($history as $r): ?>
            <tr>
              <td class="mono">v<?= (int) $r['version'] ?></td>
              <td class="mono"><?= $h(rtrim(rtrim(number_format($r['platform_bps'] / 100, 2), '0'), '.')) ?>%</td>
              <td class="mono"><?= $h(rtrim(rtrim(number_format($r['organizer_bps'] / 100, 2), '0'), '.')) ?>%</td>
              <td class="muted mono"><?= (int) $r['version'] === 1 ? 'The beginning' : $h(date('d M Y, H:i', strtotime((string) $r['effective_from']))) ?></td>
              <td><?= $r['admin_name'] ? $h($r['admin_name']) : '<span class="muted">System</span>' ?></td>
              <td class="muted"><?= $h($r['reason']) ?></td>
              <td><span class="admin-badge <?= $r['state'] === 'IN_FORCE' ? 'admin-badge-success' : ($r['state'] === 'SCHEDULED' ? 'admin-badge-warn' : 'admin-badge-muted') ?>"><?= $r['state'] === 'IN_FORCE' ? 'In force' : ($r['state'] === 'SCHEDULED' ? 'Scheduled' : 'Replaced') ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="admin-card" style="margin-top:18px">
    <h3 style="margin:0 0 14px">Recent gift settings activity</h3>
    <?php if (!$audit): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none">
        <table class="admin-table">
          <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
          <tbody>
            <?php foreach ($audit as $a): ?>
              <tr><td class="muted mono"><?= $h(date('d M, H:i', strtotime((string) $a['created_at']))) ?></td><td><?= $h($a['admin_name'] ?? 'System') ?></td><td class="mono"><?= $h($a['action']) ?></td><td class="muted" style="max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= $h(mb_substr((string) $a['details'], 0, 140)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
<?php endif; /* ready */ ?>

<?php render_admin_foot(); ?>
