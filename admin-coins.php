<?php
require_once __DIR__ . '/includes/bootstrap.php';

// OBI COINS MANAGEMENT (phase 6): the switch, recharge packages, the accounting rate, wallets, purchases, who can receive gifts, and the money overview.
// Coins and gifts are separate from tickets: nothing on this page reads or changes orders, tickets or ticket payouts.
$admin = require_admin_permission('gifts.view');
$adminId = (int) $admin['id'];
$ready = coins_ready();
$canManage = admin_can('gifts.manage');       // packages, the switch, who can receive gifts
$canRefund = admin_can('gifts.refund');       // freeze a wallet, reverse a purchase
$canMoney = admin_can('gifts.commission');    // the accounting rate and adding or removing coins (top-level admin only)
$tabs = ['overview' => 'Overview', 'packages' => 'Packages', 'rate' => 'Accounting rate', 'wallets' => 'Wallets', 'purchases' => 'Purchases', 'recipients' => 'Who can receive gifts', 'alerts' => 'Alerts', 'check' => 'Payment check', 'history' => 'Change history'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'overview';
$error = null;
$flash = $_SESSION['coin_admin_flash'] ?? null;
unset($_SESSION['coin_admin_flash']);
$go = static function (string $url, string $msg) {
    $_SESSION['coin_admin_flash'] = $msg;
    header('Location: ' . $url);
    exit;
};
$deny = static function (string $why) {
    http_response_code(403);
    exit($why);
};
$form = [];
$editingPackage = null;   // package being edited, [] = new

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['toggle_coins', 'save_package', 'recipient', 'save_custom'], true) && !$canManage) { $deny('You do not have permission to manage OBI COINS.'); }
    if (in_array($action, ['wallet_status', 'reverse_purchase', 'recheck_payments'], true) && !$canRefund) { $deny('You do not have permission to freeze wallets or reverse purchases.'); }
    if (in_array($action, ['set_rate', 'adjust'], true) && !$canMoney) { $deny('Only the top-level admin can change the rate or add and remove OBI COINS.'); }

    if ($action === 'run_books' && !$canManage) { $deny('You do not have permission to run the books check.'); }

    if ($action === 'run_books') {
        $b = coin_books_scheduled(true);
        $go('/admin-coins.php?tab=check', 'Books check done: ' . $b['problem_count'] . ' problem(s), ' . $b['high_flags'] . ' high alert(s).' . ($b['alerted'] ? ' The admins were emailed.' : ''));
    } elseif ($action === 'recheck_payments') {
        $sw = coin_purchase_sweep(50, gift_status_fn());
        $go('/admin-coins.php?tab=check', 'Asked ioTec about every waiting payment: ' . $sw['checked'] . ' checked, ' . $sw['credited'] . ' credited.');
    } elseif ($action === 'toggle_coins') {
        $turnOn = !empty($_POST['on']);
        if ($turnOn && ($missing = coins_missing_setup())) {
            $go('/admin-coins.php', 'Cannot switch OBI COINS on yet: ' . implode('; ', $missing) . '.');
        }
        coin_set_enabled($adminId, $turnOn);
        log_admin_action($adminId, 'coins.switch', 'setting', null, ['on' => $turnOn]);
        $go('/admin-coins.php', $turnOn ? 'OBI COINS is switched ON. Gifts are now paid with OBI COINS.' : 'OBI COINS is switched OFF.');
    } elseif ($action === 'save_custom') {
        $res = coin_admin_set_custom($adminId, !empty($_POST['on']), (string) ($_POST['ugx_per_coin'] ?? ''));
        $go('/admin-coins.php?tab=packages', $res['ok'] ? (!empty($_POST['on']) ? '"Any amount" is ON.' : '"Any amount" is OFF.') : $res['error']);
    } elseif ($action === 'save_package') {
        $pid = (int) ($_POST['id'] ?? 0) ?: null;
        $res = coin_admin_save_package($adminId, $pid, $_POST);
        if ($res['ok']) {
            $go('/admin-coins.php?tab=packages', $pid ? 'Package saved.' : 'Package added.');
        }
        $error = $res['error'];
        $tab = 'packages';
        $editingPackage = $pid ? (coin_package_find($pid) ?: []) : [];
        $form = $_POST;
    } elseif ($action === 'set_rate') {
        $res = coin_rate_set($adminId, (string) ($_POST['ugx_per_coin'] ?? ''), (string) ($_POST['reason'] ?? ''), ($_POST['when'] ?? 'now') === 'later' ? (string) ($_POST['effective_from'] ?? '') : null);
        if ($res['ok']) {
            $go('/admin-coins.php?tab=rate', 'The accounting rate was changed (version ' . $res['version'] . '). It applies to new gifts only.');
        }
        $error = $res['error'];
        $tab = 'rate';
        $form = $_POST;
    } elseif ($action === 'adjust') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $coins = filter_var(trim((string) ($_POST['coins'] ?? '')), FILTER_VALIDATE_INT);
        $res = $coins === false ? ['ok' => false, 'error' => 'Enter a whole number of OBI COINS: positive to add, negative to remove.'] : coin_adjust($adminId, $uid, $coins, (string) ($_POST['reason'] ?? ''));
        if ($res['ok']) {
            $go('/admin-coins.php?tab=wallets&user=' . $uid, 'Done. The wallet now holds ' . number_format($res['balance']) . ' OBI COINS and the person was told.');
        }
        $error = $res['error'];
        $tab = 'wallets';
        $_GET['user'] = (string) $uid;
        $form = $_POST;
    } elseif ($action === 'wallet_status') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $res = coin_wallet_set_status($adminId, $uid, ($_POST['status'] ?? '') === 'FROZEN' ? 'FROZEN' : 'ACTIVE', (string) ($_POST['reason'] ?? ''));
        if ($res['ok']) {
            $go('/admin-coins.php?tab=wallets&user=' . $uid, ($_POST['status'] ?? '') === 'FROZEN' ? 'Wallet frozen: the person cannot send gifts with it.' : 'Wallet is active again.');
        }
        $error = $res['error'];
        $tab = 'wallets';
        $_GET['user'] = (string) $uid;
    } elseif ($action === 'reverse_purchase') {
        $res = coin_purchase_reverse((int) ($_POST['id'] ?? 0), $adminId, (string) ($_POST['reason'] ?? ''));
        if ($res['ok']) {
            $go('/admin-coins.php?tab=purchases', 'Purchase reversed: the OBI COINS were taken back.');
        }
        $error = $res['error'];
        $tab = 'purchases';
    } elseif ($action === 'recipient') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $to = (string) ($_POST['status'] ?? '');
        $res = gift_recipient_set($adminId, $uid, $to, (string) ($_POST['reason'] ?? ''));
        if ($res['ok']) {
            $go('/admin-coins.php?tab=recipients&q=' . rawurlencode((string) ($_POST['q'] ?? '')), match ($to) { 'SUSPENDED' => 'Suspended: no new gifts and no new withdrawals for this account.', 'APPROVED' => 'Approved: this account can receive gifts.', default => 'Back to the default rule.' });
        }
        $error = $res['error'];
        $tab = 'recipients';
    }
}

if ($ready && $tab === 'packages' && $editingPackage === null) {
    if (isset($_GET['new'])) { $editingPackage = []; } elseif (!empty($_GET['edit'])) { $editingPackage = coin_package_find((int) $_GET['edit']) ?: null; }
}

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$ugx = static fn ($n): string => 'UGX ' . number_format((int) $n);
$rateText = static fn (int $e4): string => rtrim(rtrim(number_format($e4 / 10000, 4, '.', ''), '0'), '.');
$fv = static fn (string $k, $default = '') => $form[$k] ?? $default;
$rate = $ready ? coin_rate_current() : null;
$cfg = $ready ? gift_commission_current() : null;

$pageTitle = 'OBI COINS';
render_admin_head('gifts');
?>

<div class="admin-page-head">
  <div><h1>OBI COINS</h1><p>The virtual currency people use to send OBI GIFTS. People buy OBI COINS with mobile money; gifts are paid from the wallet. Completely separate from tickets.</p></div>
</div>

<?php if ($flash): ?><span data-flash="<?= $h($flash) ?>" hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= $h($error) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?= gift_admin_tabs('coins') ?>

<?php if (!$ready): ?>
  <div class="admin-card" style="border-left:4px solid var(--danger)">
    <h3 style="margin:0 0 6px">Database update needed</h3>
    <p style="margin:0">Run <b>migration/046_obi_coins.sql</b> in phpMyAdmin first. Until then this page has nothing to show.</p>
  </div>
<?php else: ?>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap" role="navigation" aria-label="OBI COINS sections">
  <?php foreach ($tabs as $k => $label): ?><a class="btn <?= $k === $tab ? 'btn-purple' : 'btn-line' ?>" style="padding:7px 13px; font-size:.84rem" href="/admin-coins.php<?= $k === 'overview' ? '' : '?tab=' . $k ?>"><?= $h($label) ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): /* ------------------------------------------------------------------ overview */
  $days = (int) ($_GET['days'] ?? 30);
  $o = coin_admin_overview($days);
  $days = $o['days'];
  $live = coins_enabled();
  $setting = get_setting('coins_enabled', '0') === '1';
  $missing = coins_missing_setup();
  $problems = coin_reconcile();
  $tile = static function (string $label, string $value, string $sub = '') use ($h): string {
      return '<div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">' . $h($label) . '</span></div><span class="num" style="font-size:1.5rem">' . $value . '</span>' . ($sub !== '' ? '<span class="sub">' . $sub . '</span>' : '') . '</div>';
  };
?>
  <div class="admin-card" style="margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
    <div style="min-width:min(260px,100%); flex:1">
      <h3 style="margin:0 0 4px">OBI COINS for viewers: <span class="admin-badge <?= $live ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $live ? 'ON' : 'OFF' ?></span></h3>
      <p class="muted" style="margin:0; font-size:0.86rem">
        <?php if ($live): ?>On: people can buy OBI COINS and send OBI GIFTS from their wallet. The old direct payment for gifts is switched off while this is on.
        <?php elseif ($missing): ?>Still needed before it can be switched on: <b><?= $h(implode('; ', $missing)) ?></b>.
        <?php else: ?>Everything needed is in place. Switch it on only after you have checked the rate and prices and made one small real purchase and gift yourself.<?php endif; ?>
      </p>
    </div>
    <?php if ($canManage): ?>
      <form method="post" data-confirm="<?= $setting ? 'Switch OBI COINS OFF? Nobody can buy coins or send coin gifts until you switch it on again. Coins already in wallets stay safe.' : 'Switch OBI COINS ON? People will pay real money for OBI COINS and gifts will be paid from the wallet.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_coins"><?php if (!$setting): ?><input type="hidden" name="on" value="1"><?php endif; ?>
        <button type="submit" class="btn <?= $setting ? 'btn-line' : 'btn-purple' ?>" <?= !$setting && $missing ? 'disabled' : '' ?>><?= $setting ? 'Switch OFF' : 'Switch ON' ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php $flagsAll = coin_admin_flags(); $flagsHigh = count(array_filter($flagsAll, static fn ($f) => $f['severity'] === 'high')); $booksLast = json_decode((string) get_setting('coin_books_last', ''), true); ?>
  <?php if ($flagsAll): ?>
    <div class="admin-card" style="margin-bottom:18px; border-left:4px solid <?= $flagsHigh ? 'var(--danger)' : 'var(--warn)' ?>">
      <p style="margin:0"><b><?= count($flagsAll) ?></b> alert<?= count($flagsAll) === 1 ? '' : 's' ?> to look at<?= $flagsHigh ? ' (<b>' . $flagsHigh . '</b> high)' : '' ?>. <a href="/admin-coins.php?tab=alerts">Open alerts</a></p>
    </div>
  <?php endif; ?>
  <?php if ($problems): ?>
    <div class="admin-card" style="border-left:4px solid var(--danger); margin-bottom:18px">
      <h3 style="margin:0 0 6px">The books check found <?= count($problems) ?> problem<?= count($problems) === 1 ? '' : 's' ?></h3>
      <ul style="margin:0; padding-left:18px"><?php foreach (array_slice($problems, 0, 8) as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?></ul>
    </div>
  <?php else: ?>
    <p class="muted" style="margin:-6px 0 14px; font-size:.84rem">Books check: every wallet, purchase and coin gift adds up.<?= is_array($booksLast) && !empty($booksLast['at']) ? ' The background job last checked at ' . $h(date('d M, H:i', strtotime((string) $booksLast['at']))) . '.' : ' The background job has not run the scheduled check yet.' ?> <a href="/admin-coins.php?tab=check">Payment check</a></p>
  <?php endif; ?>

  <?php if ($o['review_waiting'] > 0 || $o['waiting'] > 0): ?>
    <div class="admin-card" style="margin-bottom:18px; border-left:4px solid var(--warn)">
      <?php if ($o['review_waiting'] > 0): ?><p style="margin:0 0 4px"><b><?= (int) $o['review_waiting'] ?></b> gift withdrawal<?= $o['review_waiting'] === 1 ? ' is' : 's are' ?> waiting for your approval. <a href="/admin-gifts.php?tab=withdrawals">Open withdrawals</a></p><?php endif; ?>
      <?php if ($o['waiting'] > 0): ?><p style="margin:0"><b><?= (int) $o['waiting'] ?></b> coin payment<?= $o['waiting'] === 1 ? ' is' : 's are' ?> waiting for the phone to confirm. <a href="/admin-coins.php?tab=purchases&amp;status=waiting">See them</a></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap; align-items:center">
    <span class="muted" style="font-size:.84rem">Showing:</span>
    <?php foreach (COIN_ADMIN_RANGES as $d => $label): ?><a class="btn <?= $d === $days ? 'btn-purple' : 'btn-line' ?>" style="padding:5px 11px; font-size:.8rem" href="/admin-coins.php?days=<?= (int) $d ?>"><?= $h($label) ?></a><?php endforeach; ?>
  </div>

  <h3 style="margin:18px 0 10px">Money from coin purchases</h3>
  <div class="admin-kpi-grid">
    <?= $tile('Received', $ugx($o['bought']['ugx']), (int) $o['bought']['n'] . ' paid purchase' . ($o['bought']['n'] === 1 ? '' : 's')) ?>
    <?= $tile('OBI COINS sold', number_format($o['bought']['coins']), '+ ' . number_format($o['bought']['bonus']) . ' bonus given') ?>
    <?= $tile('Payment fees (ioTec)', $ugx($o['bought']['fees']), 'an obitickets cost') ?>
    <?= $tile('Purchases reversed', (string) (int) $o['reversed']['n'], $o['reversed']['n'] ? $ugx($o['reversed']['ugx']) . ' &middot; ' . number_format($o['reversed']['coins']) . ' coins taken back' : 'none') ?>
  </div>

  <h3 style="margin:22px 0 10px">OBI COINS</h3>
  <div class="admin-kpi-grid">
    <?= $tile('Issued', number_format($o['bought']['coins'] + $o['bought']['bonus'] + $o['adjust_added']), 'bought + bonus + added by you (' . number_format($o['adjust_added']) . ')') ?>
    <?= $tile('Spent on gifts', number_format($o['coins_spent']), number_format($o['coins_refunded']) . ' returned after reversed gifts') ?>
    <?= $tile('Held in wallets now', number_format($o['held_coins']), (int) $o['held_wallets'] . ' wallet' . ($o['held_wallets'] === 1 ? '' : 's') . ($o['held_ugx'] !== null ? ' &middot; worth ' . $ugx($o['held_ugx']) . ' at today\'s rate' : '') . ($o['frozen_wallets'] ? ' &middot; ' . (int) $o['frozen_wallets'] . ' frozen' : '')) ?>
    <?= $tile('Removed by you', number_format($o['adjust_removed']), 'adjustments with a reason') ?>
  </div>

  <h3 style="margin:22px 0 10px">Gifts paid with OBI COINS</h3>
  <div class="admin-kpi-grid">
    <?= $tile('Gifts sent', (string) (int) $o['gifts']['n'], number_format($o['gifts']['coins']) . ' OBI COINS') ?>
    <?= $tile('Value of those gifts', $ugx($o['gifts']['gross']), 'in UGX at the rate of the day each was sent') ?>
    <?= $tile('obitickets share', $ugx($o['gifts']['platform']), 'after purchase fees: ' . $ugx($o['platform_after_fees'])) ?>
    <?= $tile('Organizers\' share', $ugx($o['gifts']['organizer']), (int) $o['gifts_reversed']['n'] . ' gift' . ($o['gifts_reversed']['n'] === 1 ? '' : 's') . ' reversed (' . number_format($o['gifts_reversed']['coins']) . ' coins returned)') ?>
  </div>

  <h3 style="margin:22px 0 10px">What obitickets owes organizers right now</h3>
  <div class="admin-kpi-grid">
    <?= $tile('Available to withdraw', $ugx($o['owed']['available'])) ?>
    <?= $tile('Being sent / waiting', $ugx($o['owed']['reserved']), 'set aside for withdrawals') ?>
    <?= $tile('Not yet available', $ugx($o['owed']['pending'])) ?>
    <?= $tile('Paid out', $ugx($o['paid_out']['ugx']), (int) $o['paid_out']['n'] . ' withdrawal' . ($o['paid_out']['n'] === 1 ? '' : 's') . ' in this time') ?>
  </div>
  <p class="muted" style="font-size:.82rem; margin:14px 0 0">Current accounting rate: <b><?= $rate ? '1 OBI COIN = UGX ' . $h($rateText((int) $rate['rate_e4'])) : 'not set' ?></b> &middot; revenue split: <b><?= $h(rtrim(rtrim(number_format($cfg['platform_bps'] / 100, 2), '0'), '.')) ?>% obitickets / <?= $h(rtrim(rtrim(number_format($cfg['organizer_bps'] / 100, 2), '0'), '.')) ?>% organizer</b>. Money shown is the value stored on each gift when it was sent, so changing the rate later never rewrites these numbers.</p>

<?php elseif ($tab === 'packages'): /* ---------------------------------------------------------------- packages */
  $pkgs = db()->query('SELECT * FROM coin_packages ORDER BY sort_order, id')->fetchAll();
?>
  <?php if ($editingPackage !== null && $canManage): $isNew = !$editingPackage; ?>
    <div class="admin-card" style="margin-bottom:18px">
      <h3 style="margin:0 0 14px"><?= $isNew ? 'Add a package' : 'Edit ' . $h($editingPackage['name']) ?></h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_package"><input type="hidden" name="id" value="<?= $isNew ? '' : (int) $editingPackage['id'] ?>">
        <div class="admin-grid-2">
          <div>
            <div class="admin-form-row"><label>Name</label><input type="text" name="name" maxlength="60" required value="<?= $h($fv('name', $editingPackage['name'] ?? '')) ?>" placeholder="e.g. Popular"></div>
            <div class="admin-form-row"><label>Short description (optional)</label><input type="text" name="description" maxlength="250" value="<?= $h($fv('description', $editingPackage['description'] ?? '')) ?>"></div>
            <div class="admin-form-row"><label>OBI COINS the person gets</label><input type="number" name="coins" min="1" step="1" required value="<?= $h($fv('coins', $editingPackage['coins'] ?? '')) ?>"></div>
            <div class="admin-form-row"><label>Bonus OBI COINS (optional)</label><input type="number" name="bonus_coins" min="0" step="1" value="<?= $h($fv('bonus_coins', $editingPackage['bonus_coins'] ?? 0)) ?>"></div>
          </div>
          <div>
            <div class="admin-form-row"><label>Price in UGX</label><input type="number" name="price_ugx" min="1" max="<?= COIN_MAX_PURCHASE_UGX ?>" step="1" value="<?= $h($fv('price_ugx', $editingPackage['price_ugx'] ?? '')) ?>" placeholder="Not set yet"></div>
            <p class="muted" style="font-size:.8rem; margin:-4px 0 12px">What the person pays by mobile money, between UGX 1 and UGX <?= number_format(COIN_MAX_PURCHASE_UGX) ?>. A new price applies to <b>new purchases only</b>; every purchase keeps the price it was bought at. A package cannot be shown without a price.</p>
            <div class="admin-form-row"><label>Order (smaller shows first)</label><input type="number" name="sort_order" step="1" value="<?= $h($fv('sort_order', $editingPackage['sort_order'] ?? 100)) ?>"></div>
            <label style="display:flex; align-items:center; gap:8px; margin:6px 0 8px"><input type="checkbox" name="is_active" value="1" <?= ($form ? !empty($form['is_active']) : !empty($editingPackage['is_active'])) ? 'checked' : '' ?>> Shown to people (can be bought)</label>
            <label style="display:flex; align-items:center; gap:8px; margin:0 0 14px"><input type="checkbox" name="is_featured" value="1" <?= ($form ? !empty($form['is_featured']) : !empty($editingPackage['is_featured'])) ? 'checked' : '' ?>> Highlighted as the popular choice</label>
          </div>
        </div>
        <div style="display:flex; gap:10px"><button class="btn btn-purple" type="submit"><?= $isNew ? 'Add package' : 'Save changes' ?></button><a class="btn btn-line" href="/admin-coins.php?tab=packages">Cancel</a></div>
      </form>
    </div>
  <?php endif; ?>
  <?php $cs = coin_custom_settings(); $pkgs = array_values(array_filter($pkgs, static fn ($p) => $p['slug'] !== COIN_CUSTOM_SLUG)); ?>
  <div class="admin-card" style="margin-bottom:18px">
    <h3 style="margin:0 0 6px">Any amount: <span class="admin-badge <?= $cs['on'] ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $cs['on'] ? 'ON' : 'OFF' ?></span></h3>
    <p class="muted" style="font-size:.84rem; margin:0 0 12px">People can type the number of OBI COINS they want instead of picking a package. They pay that number &times; the price per coin below (rounded up to a whole shilling), between UGX <?= number_format(COIN_MIN_PURCHASE_UGX) ?> and UGX <?= number_format(COIN_MAX_PURCHASE_UGX) ?> per payment<?php if ($cs['price_e4']): ?>, so from <?= number_format($cs['min_coins']) ?> to <?= number_format($cs['max_coins']) ?> OBI COINS<?php endif; ?>. At most UGX <?= number_format(COIN_DAILY_PURCHASE_CAP_UGX) ?> per person per 24 hours, packages included.</p>
    <?php if ($cs['package_id'] === null): ?>
      <p style="margin:0">Run <b>migration/050_obi_coins_big.sql</b> first.</p>
    <?php elseif ($canManage): ?>
      <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_custom">
        <div class="admin-form-row" style="margin:0"><label>Price of 1 OBI COIN (UGX)</label><input type="text" name="ugx_per_coin" inputmode="decimal" value="<?= $h($cs['price_e4'] ? rtrim(rtrim(number_format($cs['price_e4'] / 10000, 4, '.', ''), '0'), '.') : '') ?>" style="width:140px" required></div>
        <label style="display:flex; align-items:center; gap:8px; margin:0 0 10px"><input type="checkbox" name="on" value="1" <?= $cs['on'] ? 'checked' : '' ?>> Allow any amount</label>
        <button class="btn btn-purple" type="submit" style="margin-bottom:6px">Save</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="admin-card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px">
      <h3 style="margin:0">Recharge packages</h3>
      <?php if ($canManage): ?><a class="btn btn-purple" href="/admin-coins.php?tab=packages&amp;new=1">Add a package</a><?php endif; ?>
    </div>
    <p class="muted" style="font-size:.84rem; margin:0 0 12px">People only see a package that is switched on <b>and</b> has a UGX price. A package without a price stays hidden until you set one.</p>
    <div class="admin-table-wrap" style="border:none; box-shadow:none">
      <table class="admin-table">
        <thead><tr><th>Package</th><th style="text-align:right">OBI COINS</th><th style="text-align:right">Bonus</th><th style="text-align:right">Price</th><th style="text-align:right">UGX per coin</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($pkgs as $p): $total = (int) $p['coins'] + (int) $p['bonus_coins']; $visible = (int) $p['is_active'] === 1 && $p['price_ugx'] !== null; ?>
            <tr>
              <td><b><?= $h($p['name']) ?></b><?= (int) $p['is_featured'] ? ' <span class="admin-badge admin-badge-warn">Popular</span>' : '' ?><br><span class="muted" style="font-size:.78rem"><?= $h($p['description']) ?></span></td>
              <td class="mono" style="text-align:right"><?= number_format((int) $p['coins']) ?></td>
              <td class="mono" style="text-align:right"><?= (int) $p['bonus_coins'] ? '+' . number_format((int) $p['bonus_coins']) : '—' ?></td>
              <td class="mono" style="text-align:right"><?= $p['price_ugx'] !== null ? $ugx($p['price_ugx']) : '<span class="muted">not set</span>' ?></td>
              <td class="mono muted" style="text-align:right"><?= $p['price_ugx'] !== null && $total > 0 ? $h(rtrim(rtrim(number_format((int) $p['price_ugx'] / $total, 4, '.', ''), '0'), '.')) : '—' ?></td>
              <td><span class="admin-badge <?= $visible ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $visible ? 'Shown' : ((int) $p['is_active'] ? 'Needs a price' : 'Hidden') ?></span></td>
              <?php if ($canManage): ?><td><a class="btn btn-line" href="/admin-coins.php?tab=packages&amp;edit=<?= (int) $p['id'] ?>">Edit</a></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="font-size:.82rem; margin:12px 0 0">The price of each <b>gift</b> in OBI COINS is set in <a href="/admin-gifts.php">Gift catalogue</a>.</p>
  </div>

<?php elseif ($tab === 'rate'): /* ------------------------------------------------------------------- rate */
  $history = coin_rate_history();
  $now = date('Y-m-d H:i:s');
  $exCoins = 1000;
  $exUgx = $rate ? coin_unit_ugx($exCoins, (int) $rate['rate_e4']) : null;
  $exSplit = $exUgx !== null ? gift_split($exUgx, $cfg['platform_bps'], $cfg['organizer_bps']) : null;
?>
  <div class="admin-kpi-grid" style="margin-bottom:12px">
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Accounting rate</span></div><span class="num" style="font-size:1.4rem"><?= $rate ? '1 coin = UGX ' . $h($rateText((int) $rate['rate_e4'])) : 'Not set' ?></span><span class="sub"><?= $rate ? 'Version ' . (int) $rate['version'] : 'Gifts cannot be sent with OBI COINS until it is set' ?></span></div>
    <?php if ($exSplit): ?>
      <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Example: a gift worth 1,000 coins</span></div><span class="num" style="font-size:1.4rem"><?= $ugx($exUgx) ?></span><span class="sub">organizer <?= $ugx($exSplit['organizer']) ?> &middot; obitickets <?= $ugx($exSplit['platform']) ?></span></div>
    <?php endif; ?>
  </div>
  <p class="muted" style="margin:0 0 18px; font-size:.86rem">The accounting rate says how many UGX one OBI COIN is worth <b>when a gift is valued</b>, to work out the organizer's earnings and the revenue split. People never see it: they only see coin prices. It is not the price people pay for coins (that is set per package).</p>

  <div class="admin-grid-2">
    <div class="admin-card">
      <h3 style="margin:0 0 14px">Change the rate</h3>
      <?php if ($canMoney): ?>
        <form method="post" data-confirm="Change the accounting rate for NEW gifts? Gifts already sent keep the rate they were sent with.">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_rate">
          <div class="admin-form-row"><label>UGX one OBI COIN is worth</label><input type="text" inputmode="decimal" name="ugx_per_coin" required value="<?= $h($fv('ugx_per_coin', $rate ? $rateText((int) $rate['rate_e4']) : '')) ?>" placeholder="e.g. 1 or 0.85"></div>
          <p class="muted" style="font-size:.82rem; margin:-4px 0 12px">A number above zero with at most 4 decimals.</p>
          <div class="admin-form-row"><label>Reason (required, kept in the history)</label><input type="text" name="reason" required maxlength="250" value="<?= $h($fv('reason')) ?>" placeholder="e.g. Launch rate"></div>
          <div class="admin-form-row"><label>When does it start?</label>
            <select name="when" id="rWhen"><option value="now" <?= $fv('when', 'now') === 'now' ? 'selected' : '' ?>>Right now</option><option value="later" <?= $fv('when') === 'later' ? 'selected' : '' ?>>At a later date and time</option></select>
          </div>
          <div class="admin-form-row" id="rLater" <?= $fv('when') === 'later' ? '' : 'hidden' ?>><label>Start at</label><input type="datetime-local" name="effective_from" value="<?= $h($fv('effective_from')) ?>"></div>
          <button class="btn btn-purple btn-block" type="submit">Save the rate</button>
        </form>
        <script>(function () { var w = document.getElementById('rWhen'), l = document.getElementById('rLater'); w.addEventListener('change', function () { l.hidden = w.value !== 'later'; }); })();</script>
      <?php else: ?><p class="muted" style="margin:0">Only the top-level admin can change the rate. The history is on the right.</p><?php endif; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 14px">Rules that never change</h3>
      <ul style="margin:0; padding-left:18px; line-height:1.7">
        <li>A change affects <b>new gifts only</b>. Every gift keeps the rate and the UGX value it was sent with.</li>
        <li>A change can start now or later, never in the past.</li>
        <li>Every change is saved with who made it, when, and why.</li>
        <li>OBI COINS can never be withdrawn or sent to another person. Only organizer earnings (in UGX) can be withdrawn.</li>
      </ul>
    </div>
  </div>

  <div class="admin-card" style="margin-top:18px">
    <h3 style="margin:0 0 14px">History of the rate</h3>
    <?php if (!$history): ?><p class="muted" style="margin:0">No rate has been set yet.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none">
        <table class="admin-table">
          <thead><tr><th>Version</th><th>1 coin =</th><th>Starts</th><th>Changed by</th><th>Reason</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($history as $r): $state = $r['effective_from'] > $now ? 'SCHEDULED' : ($rate && (int) $rate['version'] === (int) $r['version'] ? 'IN_FORCE' : 'OLD'); ?>
              <tr>
                <td class="mono">v<?= (int) $r['version'] ?></td>
                <td class="mono">UGX <?= $h($rateText((int) $r['rate_e4'])) ?></td>
                <td class="muted mono"><?= $h(date('d M Y, H:i', strtotime((string) $r['effective_from']))) ?></td>
                <td><?= $r['admin_name'] ? $h($r['admin_name']) : '<span class="muted">System</span>' ?></td>
                <td class="muted"><?= $h($r['reason']) ?></td>
                <td><span class="admin-badge <?= $state === 'IN_FORCE' ? 'admin-badge-success' : ($state === 'SCHEDULED' ? 'admin-badge-warn' : 'admin-badge-muted') ?>"><?= $state === 'IN_FORCE' ? 'In force' : ($state === 'SCHEDULED' ? 'Scheduled' : 'Replaced') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'wallets'): /* ----------------------------------------------------------------- wallets */
  $uid = (int) ($_GET['user'] ?? 0);
  $detail = $uid > 0 ? coin_admin_wallet_detail($uid) : null;
  $q = trim((string) ($_GET['q'] ?? ''));
?>
  <?php if ($detail): $u = $detail['user']; $w = $detail['wallet']; ?>
    <p style="margin:0 0 12px"><a href="/admin-coins.php?tab=wallets<?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">&larr; All wallets</a></p>
    <div class="admin-kpi-grid" style="margin-bottom:18px">
      <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl"><?= $h($u['name']) ?></span></div><span class="num" style="font-size:1.5rem"><?= number_format($w['balance']) ?> coins</span>
        <span class="sub"><?= $h($u['email']) ?><?= $u['phone'] ? ' &middot; ' . $h($u['phone']) : '' ?> &middot; <?= $h(ucfirst(strtolower($u['role']))) ?> &middot; account <?= $h(strtolower($u['account_status'])) ?></span></div>
      <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Bought / bonus</span></div><span class="num" style="font-size:1.5rem"><?= number_format($w['purchased']) ?> / <?= number_format($w['bonus']) ?></span></div>
      <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Spent</span></div><span class="num" style="font-size:1.5rem"><?= number_format($w['spent']) ?></span><span class="sub"><?= (int) $detail['gifts']['n'] ?> gift<?= (int) $detail['gifts']['n'] === 1 ? '' : 's' ?> sent</span></div>
      <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Wallet</span></div><span class="num" style="font-size:1.5rem"><?= $w['status'] === 'FROZEN' ? 'Frozen' : 'Active' ?></span><span class="sub"><?= $detail['recipient'] === 'SUSPENDED' ? 'Gift account suspended' : ($detail['recipient'] === 'APPROVED' ? 'Approved to receive gifts' : '') ?></span></div>
    </div>
    <div class="admin-grid-2">
      <div class="admin-card">
        <h3 style="margin:0 0 12px">Add or remove OBI COINS</h3>
        <?php if ($canMoney): ?>
          <form method="post" data-confirm="Change this wallet? The person is told and the change is kept in the records.">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <div class="admin-form-row"><label>OBI COINS (positive adds, negative removes)</label><input type="text" inputmode="numeric" name="coins" required value="<?= $h($fv('coins')) ?>" placeholder="e.g. 500 or -200"></div>
            <div class="admin-form-row"><label>Reason (required; the person sees it)</label><input type="text" name="reason" required maxlength="200" value="<?= $h($fv('reason')) ?>" placeholder="e.g. Refund for a failed payment"></div>
            <button class="btn btn-purple btn-block" type="submit">Apply</button>
          </form>
        <?php else: ?><p class="muted" style="margin:0">Only the top-level admin can add or remove OBI COINS.</p><?php endif; ?>
      </div>
      <div class="admin-card">
        <h3 style="margin:0 0 12px"><?= $w['status'] === 'FROZEN' ? 'Wallet is frozen' : 'Freeze this wallet' ?></h3>
        <?php if ($canRefund): ?>
          <p class="muted" style="font-size:.84rem; margin:0 0 10px">A frozen wallet cannot send gifts. The coins stay where they are.</p>
          <form method="post" data-confirm="<?= $w['status'] === 'FROZEN' ? 'Make this wallet active again?' : 'Freeze this wallet? The person cannot send gifts with it.' ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="wallet_status"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="status" value="<?= $w['status'] === 'FROZEN' ? 'ACTIVE' : 'FROZEN' ?>">
            <div class="admin-form-row"><label>Reason (kept in the records)</label><input type="text" name="reason" required maxlength="200"></div>
            <button class="btn <?= $w['status'] === 'FROZEN' ? 'btn-purple' : 'btn-line' ?> btn-block" type="submit"><?= $w['status'] === 'FROZEN' ? 'Make active' : 'Freeze wallet' ?></button>
          </form>
        <?php else: ?><p class="muted" style="margin:0">You do not have permission to freeze wallets.</p><?php endif; ?>
      </div>
    </div>
    <div class="admin-card" style="margin-top:18px">
      <h3 style="margin:0 0 12px">Wallet history <span class="muted" style="font-weight:500">(latest 25)</span></h3>
      <?php if (!$detail['ledger']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
          <thead><tr><th>When</th><th>What</th><th style="text-align:right">OBI COINS</th><th style="text-align:right">Balance after</th><th>Note</th></tr></thead>
          <tbody><?php foreach ($detail['ledger'] as $r): ?>
            <tr><td class="muted mono"><?= $h(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td><td><?= $h($r['label']) ?></td>
              <td class="mono" style="text-align:right; color:<?= $r['coins'] > 0 ? 'var(--success)' : 'inherit' ?>"><?= ($r['coins'] > 0 ? '+' : '') . number_format($r['coins']) ?></td>
              <td class="mono" style="text-align:right"><?= number_format($r['balance_after']) ?></td><td class="muted" style="font-size:.8rem"><?= $h($r['note']) ?> <span class="mono"><?= $h($r['reference']) ?></span></td></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
    <div class="admin-card" style="margin-top:18px">
      <h3 style="margin:0 0 12px">Purchases <span class="muted" style="font-weight:500">(latest 15)</span></h3>
      <?php if (!$detail['purchases']): ?><p class="muted" style="margin:0">No purchases.</p><?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
          <thead><tr><th>When</th><th>Package</th><th style="text-align:right">OBI COINS</th><th style="text-align:right">Paid</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($detail['purchases'] as $p): ?>
            <tr><td class="muted mono"><?= $h(date('d M, H:i', strtotime((string) $p['created_at']))) ?></td><td><?= $h($p['package_name']) ?></td>
              <td class="mono" style="text-align:right"><?= number_format((int) $p['coins'] + (int) $p['bonus_coins']) ?></td><td class="mono" style="text-align:right"><?= $ugx($p['price_ugx']) ?></td>
              <td><span class="admin-badge <?= $p['payment_status'] === 'SUCCESSFUL' ? 'admin-badge-success' : (in_array($p['payment_status'], ['PENDING', 'PROCESSING'], true) ? 'admin-badge-warn' : 'admin-badge-muted') ?>"><?= $h(ucfirst(strtolower($p['payment_status']))) ?></span></td></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  <?php else: $wallets = coin_admin_wallets($q); ?>
    <?php if ($uid > 0): ?><div class="admin-card" style="margin-bottom:14px"><p style="margin:0">That account was not found.</p></div><?php endif; ?>
    <div class="admin-card">
      <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px"><input type="hidden" name="tab" value="wallets">
        <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search by name, email, phone or account number" style="flex:1; min-width:220px"><button class="btn btn-purple" type="submit">Search</button><?php if ($q !== ''): ?><a class="btn btn-line" href="/admin-coins.php?tab=wallets">Clear</a><?php endif; ?>
      </form>
      <h3 style="margin:0 0 10px"><?= $q === '' ? 'Wallets with the most OBI COINS' : 'Accounts matching “' . $h($q) . '”' ?></h3>
      <?php if (!$wallets): ?><p class="muted" style="margin:0"><?= $q === '' ? 'No wallets yet. They appear when someone buys OBI COINS.' : 'Nobody matches.' ?></p><?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
          <thead><tr><th>Account</th><th>Role</th><th style="text-align:right">OBI COINS</th><th style="text-align:right">Bought</th><th style="text-align:right">Spent</th><th>Wallet</th><th></th></tr></thead>
          <tbody><?php foreach ($wallets as $r): ?>
            <tr><td><b><?= $h($r['name']) ?></b><br><span class="muted" style="font-size:.78rem"><?= $h($r['email']) ?> &middot; #<?= (int) $r['id'] ?></span></td><td class="muted"><?= $h(ucfirst(strtolower($r['role']))) ?></td>
              <td class="mono" style="text-align:right"><b><?= number_format($r['balance']) ?></b></td><td class="mono muted" style="text-align:right"><?= number_format($r['purchased']) ?></td><td class="mono muted" style="text-align:right"><?= number_format($r['spent']) ?></td>
              <td><span class="admin-badge <?= $r['status'] === 'FROZEN' ? 'admin-badge-danger' : 'admin-badge-muted' ?>"><?= $r['status'] === 'FROZEN' ? 'Frozen' : 'Active' ?></span></td>
              <td><a class="btn btn-line" href="/admin-coins.php?tab=wallets&amp;user=<?= (int) $r['id'] ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">Open</a></td></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'purchases'): /* -------------------------------------------------------------- purchases */
  $pStatus = in_array($_GET['status'] ?? '', ['paid', 'waiting', 'failed', 'reversed'], true) ? $_GET['status'] : 'all';
  $pq = trim((string) ($_GET['q'] ?? ''));
  $before = max(0, (int) ($_GET['before'] ?? 0));
  $list = coin_admin_purchases($pStatus, $pq, $before);
  $stLabel = ['paid' => 'Paid', 'waiting' => 'Waiting', 'failed' => 'Failed', 'reversed' => 'Reversed', 'all' => 'All'];
  $badge = ['SUCCESSFUL' => 'admin-badge-success', 'PENDING' => 'admin-badge-warn', 'PROCESSING' => 'admin-badge-warn', 'REVERSED' => 'admin-badge-danger'];
?>
  <div class="admin-card">
    <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px"><input type="hidden" name="tab" value="purchases">
      <select name="status"><?php foreach ($stLabel as $k => $l): ?><option value="<?= $h($k) ?>" <?= $k === $pStatus ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
      <input type="search" name="q" value="<?= $h($pq) ?>" placeholder="Reference, name, email or phone" style="flex:1; min-width:200px"><button class="btn btn-purple" type="submit">Filter</button>
    </form>
    <?php if (!$list['rows']): ?><p class="muted" style="margin:0">No purchases match.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
        <thead><tr><th>When</th><th>Person</th><th>Package</th><th style="text-align:right">OBI COINS</th><th style="text-align:right">Paid</th><th>Status</th><?php if ($canRefund): ?><th></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($list['rows'] as $p): ?>
          <tr>
            <td class="muted" style="white-space:nowrap"><?= $h(date('d M, H:i', strtotime((string) $p['created_at']))) ?><br><span class="mono" style="font-size:.74rem"><?= $h($p['reference']) ?></span></td>
            <td><a href="/admin-coins.php?tab=wallets&amp;user=<?= (int) $p['user_id'] ?>"><?= $h($p['user_name']) ?></a><br><span class="muted mono" style="font-size:.76rem"><?= $h($p['phone']) ?> <?= $p['payment_method'] === 'AIRTEL_MONEY' ? 'Airtel' : 'MTN' ?></span></td>
            <td><?= $h($p['package_name']) ?></td>
            <td class="mono" style="text-align:right"><?= number_format((int) $p['coins'] + (int) $p['bonus_coins']) ?></td>
            <td class="mono" style="text-align:right"><?= $ugx($p['price_ugx']) ?></td>
            <td><span class="admin-badge <?= $badge[$p['payment_status']] ?? 'admin-badge-muted' ?>"><?= $h(ucfirst(strtolower($p['payment_status']))) ?></span><?php if ($p['status_message'] && $p['payment_status'] !== 'SUCCESSFUL'): ?><br><span class="muted" style="font-size:.76rem"><?= $h($p['status_message']) ?></span><?php endif; ?></td>
            <?php if ($canRefund): ?><td>
              <?php if ($p['payment_status'] === 'SUCCESSFUL'): ?>
                <form method="post" style="display:inline-flex; gap:4px" data-confirm="Reverse this purchase? The OBI COINS are taken back from the wallet. Give the person their money back yourself in the ioTec portal if that is right.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="reverse_purchase"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <input type="text" name="reason" required maxlength="150" placeholder="Why" style="width:120px"><button class="btn btn-line" type="submit">Reverse</button>
                </form>
              <?php endif; ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?></tbody></table></div>
      <?php if ($list['next']): ?><p style="margin:14px 0 0"><a class="btn btn-line" href="/admin-coins.php?tab=purchases&amp;status=<?= $h($pStatus) ?>&amp;q=<?= rawurlencode($pq) ?>&amp;before=<?= (int) $list['next'] ?>">Show older</a></p><?php endif; ?>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'recipients'): /* ------------------------------------------------------------ recipients */
  $rq = trim((string) ($_GET['q'] ?? ''));
  $rec = coin_admin_recipients($rq);
  $recForm = static function (int $uid, string $to, string $label, string $confirm, string $cls) use ($h, $rq): string {
      return '<form method="post" style="display:inline-flex; gap:4px; margin:2px 0" data-confirm="' . $h($confirm) . '">' . csrf_field() . '<input type="hidden" name="action" value="recipient"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="status" value="' . $h($to) . '"><input type="hidden" name="q" value="' . $h($rq) . '">'
           . '<input type="text" name="reason" required maxlength="200" placeholder="Reason" style="width:130px"><button class="btn ' . $cls . '" type="submit">' . $h($label) . '</button></form>';
  };
?>
  <div class="admin-card" style="margin-bottom:18px">
    <h3 style="margin:0 0 6px">The rule</h3>
    <p class="muted" style="margin:0; font-size:.86rem">By default any organizer with a live video can receive OBI GIFTS. Here you can <b>suspend</b> an account (no new gifts and no new withdrawals; its history and balance stay) or <b>approve</b> another kind of account to receive gifts. Every change needs a reason and is kept in the change history.</p>
  </div>
  <div class="admin-card" style="margin-bottom:18px">
    <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px"><input type="hidden" name="tab" value="recipients">
      <input type="search" name="q" value="<?= $h($rq) ?>" placeholder="Find an account by name, organization, email or number" style="flex:1; min-width:220px"><button class="btn btn-purple" type="submit">Search</button>
    </form>
    <?php if ($rq !== ''): ?>
      <?php if (!$rec['found']): ?><p class="muted" style="margin:0">Nobody matches.</p><?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
          <thead><tr><th>Account</th><th>Role</th><th style="text-align:right">Gift earnings</th><th>Now</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
          <tbody><?php foreach ($rec['found'] as $r): ?>
            <tr><td><b><?= $h($r['org_name'] !== '' ? $r['org_name'] : $r['name']) ?></b><br><span class="muted" style="font-size:.78rem"><?= $h($r['email']) ?> &middot; #<?= (int) $r['id'] ?></span></td><td class="muted"><?= $h(ucfirst(strtolower($r['role']))) ?></td>
              <td class="mono" style="text-align:right"><?= $ugx($r['earned']) ?></td>
              <td><span class="admin-badge <?= $r['status'] === 'SUSPENDED' ? 'admin-badge-danger' : ($r['status'] === 'APPROVED' ? 'admin-badge-success' : 'admin-badge-muted') ?>"><?= $r['status'] === 'SUSPENDED' ? 'Suspended' : ($r['status'] === 'APPROVED' ? 'Approved' : 'Default rule') ?></span></td>
              <?php if ($canManage): ?><td>
                <?php if ($r['status'] !== 'SUSPENDED') { echo $recForm((int) $r['id'], 'SUSPENDED', 'Suspend', 'Suspend this account? It cannot receive new gifts or make new withdrawals.', 'btn-line'); } ?>
                <?php if ($r['status'] !== 'APPROVED') { echo $recForm((int) $r['id'], 'APPROVED', 'Approve', 'Let this account receive OBI GIFTS?', 'btn-line'); } ?>
                <?php if ($r['status'] !== null) { echo $recForm((int) $r['id'], 'DEFAULT', 'Back to default', 'Remove the special setting and go back to the default rule?', 'btn-line'); } ?>
              </td><?php endif; ?></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="admin-card">
    <h3 style="margin:0 0 12px">Accounts you have set</h3>
    <?php if (!$rec['set']): ?><p class="muted" style="margin:0">None. Everyone follows the default rule.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
        <thead><tr><th>Account</th><th>Setting</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
        <tbody><?php foreach ($rec['set'] as $r): ?>
          <tr><td><b><?= $h($r['name']) ?></b><br><span class="muted" style="font-size:.78rem"><?= $h($r['email']) ?> &middot; #<?= (int) $r['id'] ?></span></td>
            <td><span class="admin-badge <?= $r['status'] === 'SUSPENDED' ? 'admin-badge-danger' : 'admin-badge-success' ?>"><?= $r['status'] === 'SUSPENDED' ? 'Suspended' : 'Approved' ?></span></td>
            <td class="muted"><?= $h($r['reason']) ?></td><td><?= $r['admin_name'] ? $h($r['admin_name']) : '<span class="muted">—</span>' ?></td><td class="muted mono"><?= $h(date('d M Y', strtotime((string) $r['updated_at']))) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'alerts'): /* -------------------------------------------------------------------------- alerts */
  $flags = coin_admin_flags();
  $sevBadge = ['high' => 'admin-badge-danger', 'medium' => 'admin-badge-warn', 'low' => 'admin-badge-muted'];
?>
  <div class="admin-card" style="margin-bottom:18px">
    <h3 style="margin:0 0 6px">Alerts</h3>
    <p class="muted" style="margin:0; font-size:.86rem">Patterns worth a look: payments that keep failing, one phone paying for many accounts, bursts of gifts, buying coins with the organizer's own payout number, repeated reversals. They are <b>signals, not proof</b>: nothing is blocked or changed by them. Open the person's wallet to freeze it, or suspend an organizer under Who can receive gifts. An alert disappears by itself when the pattern is gone.</p>
  </div>
  <div class="admin-card">
    <?php if (!$flags): ?><p style="margin:0">Nothing to look at right now.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
        <thead><tr><th>How serious</th><th>What</th><th>Who</th><th></th></tr></thead>
        <tbody><?php foreach ($flags as $f): ?>
          <tr>
            <td><span class="admin-badge <?= $sevBadge[$f['severity']] ?>"><?= $h(ucfirst($f['severity'])) ?></span></td>
            <td><b><?= $h($f['title']) ?></b><br><span class="muted" style="font-size:.82rem"><?= $h($f['detail']) ?></span></td>
            <td><?= $f['user_id'] ? $h($f['user_name']) . ' <span class="muted" style="font-size:.78rem">#' . (int) $f['user_id'] . '</span>' : '—' ?></td>
            <td><?php if ($f['user_id']): ?><a class="btn btn-line" href="/admin-coins.php?tab=wallets&amp;user=<?= (int) $f['user_id'] ?>">Open wallet</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'check'): /* ---------------------------------------------------------------------- payment check */
  $ver = coin_admin_verification();
  $booksLast = json_decode((string) get_setting('coin_books_last', ''), true);
  $mark = ['pass' => ['Pass', 'admin-badge-success'], 'warn' => ['Look', 'admin-badge-warn'], 'fail' => ['Fix', 'admin-badge-danger']];
?>
  <div class="admin-card" style="margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
    <div style="min-width:min(260px,100%); flex:1">
      <h3 style="margin:0 0 4px">Payment check: <span class="admin-badge <?= $mark[$ver['overall']][1] ?>"><?= $h($ver['overall'] === 'pass' ? 'All good' : ($ver['overall'] === 'warn' ? 'Some things to look at' : 'Something needs fixing')) ?></span></h3>
      <p class="muted" style="margin:0; font-size:.86rem">Every promise OBI COINS makes about money, checked against the data right now. <b>Fix</b> means do not take more money until it is fixed; <b>Look</b> means worth a look (including the real-money tests only you can do). The background job also runs the books check every <?= (int) COIN_BOOKS_EVERY_HOURS ?> hours and emails the admins if something does not add up<?= is_array($booksLast) && !empty($booksLast['at']) ? ' (last: ' . $h(date('d M, H:i', strtotime((string) $booksLast['at']))) . ')' : '' ?>.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
      <?php if ($canManage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run_books"><button class="btn btn-line" type="submit">Run the books check now</button></form><?php endif; ?>
      <?php if ($canRefund): ?><form method="post" data-confirm="Ask ioTec about every payment that is still waiting, and credit the ones that were paid?"><?= csrf_field() ?><input type="hidden" name="action" value="recheck_payments"><button class="btn btn-purple" type="submit">Check waiting payments now</button></form><?php endif; ?>
    </div>
  </div>
  <?php foreach ($ver['groups'] as $group => $rows): ?>
    <div class="admin-card" style="margin-bottom:18px">
      <h3 style="margin:0 0 12px"><?= $h($group) ?></h3>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table"><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td style="white-space:nowrap; width:1%"><span class="admin-badge <?= $mark[$r['status']][1] ?>"><?= $h($mark[$r['status']][0]) ?></span></td><td><b><?= $h($r['label']) ?></b><br><span class="muted" style="font-size:.82rem"><?= $h($r['detail']) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
  <?php endforeach; ?>

<?php else: /* ------------------------------------------------------------------------------------------ history */
  $audit = coin_admin_audit(60);
?>
  <div class="admin-card">
    <h3 style="margin:0 0 6px">Change history</h3>
    <p class="muted" style="margin:0 0 14px; font-size:.84rem">Every change to OBI COINS, gift prices, who can receive gifts, wallet adjustments and withdrawal approval, with who made it. The newest 60.</p>
    <?php if (!$audit): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
        <tbody><?php foreach ($audit as $a): ?>
          <tr><td class="muted mono" style="white-space:nowrap"><?= $h(date('d M, H:i', strtotime((string) $a['created_at']))) ?></td><td><?= $h($a['admin_name'] ?? 'System') ?></td><td class="mono" style="font-size:.78rem"><?= $h($a['action']) ?><?= $a['entity_id'] ? ' #' . (int) $a['entity_id'] : '' ?></td>
            <td class="muted" style="max-width:360px; overflow-wrap:anywhere; font-size:.78rem"><?= $h((string) $a['details']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php endif; /* ready */ ?>

<?php render_admin_foot(); ?>
