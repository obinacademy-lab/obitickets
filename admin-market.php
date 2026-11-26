<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET admin (ADR-003, phase 7): the switches, the numbers and what needs a person, every order, refunds to send, products (take down / feature), sellers
 * (suspend / restore), finance (commission with its history, monthly figures, withdrawals), the order email queue, and the Payment check.
 * Permissions: market.view = look; market.manage = switch the shop, moderate products and stores, resend emails; market.refund = refunds; market.commission (top-level
 * admin only) = the commission, the withdrawals switch and settling stuck withdrawals. Every change is in the audit log.
 */
$admin = require_admin_permission('market.view');
$adminId = (int) $admin['id'];
$ready = market_ready();
$canManage = admin_can('market.manage');
$canRefund = admin_can('market.refund');
$canMoney = admin_can('market.commission');
$tabs = ['overview', 'orders', 'refunds', 'products', 'sellers', 'finance', 'emails', 'check'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? (string) $_GET['tab'] : 'overview';
$flash = $_SESSION['market_admin_flash'] ?? null;
unset($_SESSION['market_admin_flash']);
$go = static function (string $tab, string $msg, string $extra = ''): void {
    $_SESSION['market_admin_flash'] = $msg;
    header('Location: /admin-market.php' . ($tab === 'overview' ? '' : '?tab=' . $tab) . $extra);
    exit;
};
$deny = static function (string $why): void {
    http_response_code(403);
    exit($why);
};

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);
    switch ($action) {
        case 'toggle_market':
            $canManage || $deny('You do not have permission to switch OBI MARKET.');
            $on = !empty($_POST['on']);
            if ($on) {
                $blockers = array_values(array_filter(market_admin_setup_check(), static fn ($c) => $c['status'] === 'fail'));
                if ($blockers) {
                    $go('overview', 'Cannot open OBI MARKET yet: ' . $blockers[0]['label'] . '. ' . $blockers[0]['detail']);
                }
            }
            market_set_enabled($adminId, $on);
            log_admin_action($adminId, 'market.switch', 'setting', null, ['on' => $on]);
            $go('overview', $on ? 'OBI MARKET is OPEN: customers can see and buy products.' : 'OBI MARKET is CLOSED: customers see "opening soon". Orders already paid carry on as normal.');
            // no break: $go exits
        case 'toggle_withdrawals':
            $canMoney || $deny('Only the top-level admin can switch seller withdrawals.');
            $on = !empty($_POST['on']);
            if ($on && !payouts_iotec_configured()) {
                $go('finance', 'Cannot switch withdrawals on: the ioTec keys are missing from the server config.');
            }
            market_withdraw_set_enabled($adminId, $on);
            $go('finance', $on ? 'Seller withdrawals are ON: sellers can take out released money.' : 'Seller withdrawals are OFF. Money already being sent is not affected.');
        case 'set_commission':
            $canMoney || $deny('Only the top-level admin can change the commission.');
            $later = ($_POST['when'] ?? 'now') === 'later';
            $eff = trim((string) ($_POST['effective_from'] ?? ''));
            if ($later && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $eff)) {
                $go('finance', 'Choose the date and time the new commission starts.');
            }
            $r = market_commission_set($adminId, (string) ($_POST['percent'] ?? ''), $reason, $later ? str_replace('T', ' ', $eff) . ':00' : null);
            $go('finance', $r['ok'] ? 'The commission was changed. It applies to NEW orders only; every past order keeps the rate it was bought at.' : ($r['error'] ?? 'Could not change the commission.'));
        case 'wd_paid':
        case 'wd_failed':
            $canMoney || $deny('Only the top-level admin can settle withdrawals.');
            if ($action === 'wd_failed' && $reason === '') {
                $go('finance', 'Give a reason first.');
            }
            $done = $action === 'wd_paid' ? market_withdraw_mark_paid($id, $adminId) : market_withdraw_fail($id, 'Checked by an admin: ' . $reason, false, $adminId);
            $go('finance', $done ? ($action === 'wd_paid' ? 'Marked paid. The seller was told.' : 'Marked failed. The money is back in the seller\'s balance.') : 'That withdrawal is not waiting any more.');
        case 'product_remove':
        case 'product_restore':
            $canManage || $deny('You do not have permission to moderate products.');
            $r = market_product_admin_set($adminId, $id, $action === 'product_remove' ? 'REMOVED' : 'INACTIVE', $reason);
            $go('products', $r['ok'] ? ($action === 'product_remove' ? 'The product was taken down. The seller cannot put it back.' : 'The product is back with the seller as hidden: they can publish it again.') : ($r['error'] ?? 'Could not change it.'), '&q=' . rawurlencode((string) ($_POST['q'] ?? '')));
        case 'feature':
        case 'unfeature':
            $canManage || $deny('You do not have permission to feature products.');
            $ok = market_admin_feature($adminId, $id, $action === 'feature');
            $go('products', $ok ? ($action === 'feature' ? 'Featured: it shows first and carries a Featured tag.' : 'No longer featured.') : 'Only a live product can be featured.');
        case 'store_suspend':
        case 'store_restore':
            $canManage || $deny('You do not have permission to change stores.');
            $r = market_admin_store_status($adminId, $id, $action === 'store_suspend' ? 'SUSPENDED' : 'ACTIVE', $reason);
            $go('sellers', $r['ok'] ? ($action === 'store_suspend' ? 'The store is suspended: nothing of it is shown or sold, and its withdrawals are on hold.' : 'The store is active again.') : ($r['error'] ?? 'Could not change it.'));
        case 'refund_sent':
            $canRefund || $deny('You do not have permission to record refunds.');
            $r = market_refund_mark_paid($adminId, $id, max(0, (int) ($_POST['amount'] ?? 0)));
            $go('refunds', $r['ok'] ? 'Recorded: the refund was sent to the customer.' : ($r['error'] ?? 'Could not record it.'));
        case 'email_requeue':
            $canManage || $deny('You do not have permission to resend emails.');
            $go('emails', market_admin_requeue_email($adminId, $id) ? 'Back in the queue: it goes out within 5 minutes (or straight away on the live site).' : 'Only a failed or skipped email can be sent again.', '&status=' . rawurlencode((string) ($_POST['status'] ?? 'ALL')));
        default:
            $go($tab, 'Unknown action.');
    }
}

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$ugx = static fn ($n): string => 'UGX ' . number_format((int) $n);
$mark = ['pass' => ['Pass', 'admin-badge-success'], 'warn' => ['Look', 'admin-badge-warn'], 'fail' => ['Fix', 'admin-badge-danger']];
$page = max(1, (int) ($_GET['page'] ?? 1));
$pager = static function (array $list, array $keep) use ($h): string {
    if ($list['pages'] <= 1) {
        return '';
    }
    $link = static fn (int $p): string => '/admin-market.php?' . http_build_query($keep + ['page' => $p]);
    return '<div style="display:flex; gap:12px; align-items:center; justify-content:center; margin-top:14px">' . ($list['page'] > 1 ? '<a class="btn btn-line" href="' . $h($link($list['page'] - 1)) . '">&larr; Previous</a>' : '')
        . '<span class="muted">Page ' . (int) $list['page'] . ' of ' . (int) $list['pages'] . ' &middot; ' . number_format($list['total']) . '</span>' . ($list['page'] < $list['pages'] ? '<a class="btn btn-line" href="' . $h($link($list['page'] + 1)) . '">Next &rarr;</a>' : '') . '</div>';
};
$statusBadge = static function (string $ful, string $pay) use ($h): string {
    [$label, $tone] = market_status_label($ful, $pay);
    $cls = ['ok' => 'admin-badge-success', 'go' => 'admin-badge-purple', 'new' => 'admin-badge-warn', 'wait' => 'admin-badge-warn', 'bad' => 'admin-badge-danger'][$tone] ?? 'admin-badge-muted';
    return '<span class="admin-badge ' . $cls . '">' . $h($label) . '</span>';
};

$pageTitle = 'OBI MARKET';
render_admin_head('market');
?>

<div class="admin-page-head">
  <div><h1>OBI MARKET</h1><p>Merchandise sold by organizers and artists. obitickets keeps the commission on the merchandise; delivery fees go to the seller. Separate from tickets, gifts and coins.</p></div>
</div>
<?php if ($flash): ?><span data-flash="<?= $h($flash) ?>" hidden></span><?php endif; ?>

<?php if (!$ready): ?>
  <div class="admin-card" style="border-left:4px solid var(--danger)"><h3 style="margin:0 0 6px">Database update needed</h3><p style="margin:0">Run <b>migration/047_obi_market.sql</b> in phpMyAdmin first.</p></div>
<?php else:
  $switchOn = market_enabled();
  $switchSetting = get_setting('market_enabled', '0') === '1';
?>
<div class="admin-card" style="margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
  <div style="min-width:min(240px,100%); flex:1">
    <h3 style="margin:0 0 4px">The shop for customers: <span class="admin-badge <?= $switchOn ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $switchOn ? 'OPEN' : 'CLOSED' ?></span></h3>
    <p class="muted" style="margin:0; font-size:.86rem">
      <?php if ($switchSetting && !$switchOn): ?>The switch is on, but the ioTec keys are missing from the server config, so the shop stays closed.
      <?php elseif ($switchOn): ?>Customers can browse, buy and pay with MTN or Airtel. The OBI MARKET link is in the menu, on event pages and on organizer profiles.
      <?php else: ?>Customers see "opening soon" and nothing can be bought. Sellers can already set up their stores, and you can look at the shop as a preview. Open it after a small real test order.<?php endif; ?>
    </p>
  </div>
  <?php if ($canManage): ?>
    <form method="post" data-confirm="<?= $switchSetting ? 'Close OBI MARKET? Customers will see &quot;opening soon&quot;. Orders already paid carry on.' : 'Open OBI MARKET? Customers will be able to buy merchandise with real money.' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="toggle_market"><?php if (!$switchSetting): ?><input type="hidden" name="on" value="1"><?php endif; ?>
      <button type="submit" class="btn <?= $switchSetting ? 'btn-line' : 'btn-purple' ?>"><?= $switchSetting ? 'Close the shop' : 'Open the shop' ?></button>
    </form>
  <?php endif; ?>
</div>

<?= market_admin_tabs($tab) ?>

<?php if ($tab === 'overview'): $n = market_admin_numbers(); $setup = market_admin_setup_check(); ?>
  <div class="admin-kpi-grid" style="margin-bottom:18px">
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Sales, last 30 days</span></div><span class="num"><?= $ugx($n['sales_30']) ?></span><span class="sub"><?= $num($n['orders_30']) ?> orders &middot; <?= $ugx($n['sales_all']) ?> all time</span></div>
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">obitickets commission</span></div><span class="num"><?= $ugx($n['commission_30']) ?></span><span class="sub">last 30 days &middot; <?= $ugx($n['commission_all']) ?> all time (after refunds)</span></div>
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Held for sellers</span></div><span class="num"><?= $ugx($n['sellers_pending'] + $n['sellers_available'] + $n['sellers_reserved']) ?></span><span class="sub"><?= $ugx($n['sellers_pending']) ?> waiting for delivery &middot; <?= $ugx($n['sellers_available']) ?> released</span></div>
    <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Stores and products</span></div><span class="num"><?= $num($n['stores']) ?></span><span class="sub"><?= $num($n['products']) ?> live products &middot; <?= $ugx($n['sellers_withdrawn']) ?> paid out</span></div>
  </div>
  <div class="admin-grid-2">
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Needs a person</h3>
      <?php $todo = array_filter([
          [$n['disputes'], 'problem(s) reported by customers', '/admin-market.php?tab=orders&group=problem'],
          [$n['refunds_owed'], 'refund(s) to send (' . $ugx($n['refunds_owed_ugx']) . ')', '/admin-market.php?tab=refunds'],
          [$n['wd_stuck'], 'withdrawal(s) processing for more than 10 minutes', '/admin-market.php?tab=finance'],
          [$n['emails_failed'], 'order email(s) that failed', '/admin-market.php?tab=emails&status=FAILED'],
          [$n['payments_stuck'], 'payment(s) waiting more than 15 minutes', '/admin-market.php?tab=check'],
          [$n['new_unseen'], 'paid order(s) nobody started in 2 days', '/admin-market.php?tab=orders&group=new'],
      ], static fn ($x) => $x[0] > 0); ?>
      <?php if (!$todo): ?><p class="muted" style="margin:0">Nothing is waiting for you.</p><?php else: ?>
        <?php foreach ($todo as [$count, $what, $href]): ?><div class="admin-detail-row"><span class="k"><a href="<?= $h($href) ?>"><?= (int) $count ?> <?= $h($what) ?></a></span><span class="v"><a class="btn btn-line" href="<?= $h($href) ?>">Open</a></span></div><?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Ready to open?</h3>
      <?php foreach ($setup as $c): ?><div class="admin-detail-row"><span class="k"><span class="admin-badge <?= $mark[$c['status']][1] ?>"><?= $mark[$c['status']][0] ?></span> <?= $h($c['label']) ?></span><span class="v muted" style="font-size:.8rem; max-width:55%; text-align:right"><?= $h($c['detail']) ?></span></div><?php endforeach; ?>
      <p class="muted" style="font-size:.82rem; margin:12px 0 0">Before opening: send yourself the seller test email (organizer dashboard &rarr; OBI MARKET &rarr; Store settings), add one small product, buy it, mark it sent, confirm you received it, and withdraw. The Payment check tab shows each step.</p>
    </div>
  </div>

<?php elseif ($tab === 'orders'):
  $groups = ['all' => 'All paid', 'new' => 'New', 'open' => 'Being prepared', 'sent' => 'Sent', 'done' => 'Delivered', 'problem' => 'Problems', 'cancelled' => 'Cancelled / refunded', 'unpaid' => 'Unpaid'];
  $g = isset($groups[$_GET['group'] ?? '']) ? (string) $_GET['group'] : 'all';
  $f = ['group' => $g, 'q' => trim((string) ($_GET['q'] ?? '')), 'from' => (string) ($_GET['from'] ?? ''), 'to' => (string) ($_GET['to'] ?? ''), 'seller' => (int) ($_GET['seller'] ?? 0)];
  $list = market_admin_orders($f, $page);
?>
  <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px"><?php foreach ($groups as $k => $label): ?><a class="btn <?= $g === $k ? 'btn-purple' : 'btn-line' ?>" style="padding:6px 12px; font-size:.8rem" href="/admin-market.php?tab=orders&amp;group=<?= $h($k) ?>"><?= $h($label) ?></a><?php endforeach; ?></div>
  <form method="get" class="admin-card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px">
    <input type="hidden" name="tab" value="orders"><input type="hidden" name="group" value="<?= $h($g) ?>"><?php if ($f['seller']): ?><input type="hidden" name="seller" value="<?= (int) $f['seller'] ?>"><?php endif; ?>
    <div class="admin-form-row" style="margin:0; flex:1 1 240px"><label>Search</label><input type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Order, customer, phone, email, store, reference"></div>
    <div class="admin-form-row" style="margin:0"><label>From</label><input type="date" name="from" value="<?= $h($f['from']) ?>"></div>
    <div class="admin-form-row" style="margin:0"><label>To</label><input type="date" name="to" value="<?= $h($f['to']) ?>"></div>
    <button class="btn btn-purple" type="submit">Filter</button>
  </form>
  <?php if (!$list['rows']): ?><div class="admin-card"><p class="muted" style="margin:0">No orders match.</p></div><?php else: ?>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Order</th><th>Store</th><th>Customer</th><th>Total</th><th>Commission</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($list['rows'] as $o): ?>
      <tr><td><a href="/admin-market-order.php?n=<?= urlencode((string) $o['order_number']) ?>"><b><?= $h($o['order_number']) ?></b></a><br><span class="muted" style="font-size:.78rem"><?= $h(date('d M Y, H:i', strtotime((string) ($o['paid_at'] ?? $o['created_at'])))) ?></span></td>
        <td><?= $h($o['store']) ?></td><td><?= $h($o['customer_name']) ?></td><td class="mono"><?= $ugx($o['total_ugx']) ?></td><td class="mono"><?= $ugx($o['commission_ugx']) ?></td>
        <td><?= $statusBadge((string) $o['fulfilment'], (string) $o['payment_status']) ?><?= $o['dispute_at'] !== null && $o['received_by'] === null ? ' <span class="admin-badge admin-badge-danger">Problem</span>' : '' ?></td>
        <td><a class="btn btn-line" href="/admin-market-order.php?n=<?= urlencode((string) $o['order_number']) ?>">Open</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $pager($list, ['tab' => 'orders', 'group' => $g, 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to'], 'seller' => $f['seller'] ?: null]) ?>
  <?php endif; ?>

<?php elseif ($tab === 'refunds'): $owed = market_refunds_owed(); ?>
  <div class="admin-card">
    <h3 style="margin:0 0 6px">Refunds to send</h3>
    <p class="muted" style="margin:0 0 14px; font-size:.84rem">Money obitickets owes customers: cancelled orders, refunds you approved, and payments that arrived after the items sold out. Send the money back from the ioTec portal (or MTN/Airtel), then record it here. The seller's earnings were already adjusted when the refund was approved.</p>
    <?php if (!$owed): ?><p class="muted" style="margin:0">Nothing to send.</p><?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Phone it was paid from</th><th>Owed</th><th></th></tr></thead>
      <tbody><?php foreach ($owed as $r): ?>
        <tr><td><a href="/admin-market-order.php?n=<?= urlencode((string) $r['order_number']) ?>"><b><?= $h($r['order_number']) ?></b></a><br><span class="muted" style="font-size:.78rem"><?= $r['fulfilment'] === 'REFUND_REQUESTED' ? 'Paid after the items were gone' : 'Refund approved' ?></span></td>
          <td><?= $h($r['customer']) ?></td><td class="mono"><?= $h(coin_phone_display((string) $r['phone'])) ?></td><td class="mono"><b><?= $ugx($r['owed_ugx']) ?></b></td>
          <td><?php if ($canRefund): ?><form method="post" data-confirm="Record that <?= $h($ugx($r['owed_ugx'])) ?> was sent back to the customer? Only do this after the money has really gone."><?= csrf_field() ?><input type="hidden" name="action" value="refund_sent"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="amount" value="<?= (int) $r['owed_ugx'] ?>"><button class="btn btn-purple" type="submit">Mark sent</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'products'):
  $f = ['status' => (string) ($_GET['status'] ?? ''), 'q' => trim((string) ($_GET['q'] ?? '')), 'seller' => (int) ($_GET['seller'] ?? 0)];
  $list = market_admin_products($f, $page);
?>
  <form method="get" class="admin-card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px">
    <input type="hidden" name="tab" value="products">
    <div class="admin-form-row" style="margin:0; flex:1 1 240px"><label>Search</label><input type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Product, store or SKU"></div>
    <div class="admin-form-row" style="margin:0"><label>Status</label><select name="status"><option value="">All</option><?php foreach (['ACTIVE' => 'Live', 'DRAFT' => 'Draft', 'INACTIVE' => 'Hidden', 'ARCHIVED' => 'Archived', 'REMOVED' => 'Taken down'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-purple" type="submit">Filter</button>
  </form>
  <?php if (!$list['rows']): ?><div class="admin-card"><p class="muted" style="margin:0">No products match.</p></div><?php else: ?>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Product</th><th>Store</th><th>Price</th><th>Left</th><th>Sold</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody><?php foreach ($list['rows'] as $p): ?>
      <tr><td><div style="display:flex; gap:10px; align-items:center"><?php if (market_img($p['primary_image']) !== ''): ?><img src="<?= $h(market_img($p['primary_image'])) ?>" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:8px"><?php endif; ?>
          <div><?php if ($p['status'] === 'ACTIVE'): ?><a href="/market-product.php?id=<?= (int) $p['id'] ?>" target="_blank" rel="noopener"><b><?= $h($p['name']) ?></b></a><?php else: ?><b><?= $h($p['name']) ?></b><?php endif; ?><br><span class="muted" style="font-size:.78rem"><?= $h($p['category']) ?> &middot; #<?= (int) $p['id'] ?></span></div></div></td>
        <td><a href="/admin-market.php?tab=sellers&amp;q=<?= urlencode((string) $p['store']) ?>"><?= $h($p['store']) ?></a></td><td class="mono"><?= $ugx($p['price']) ?></td><td><?= (int) $p['available'] ?></td><td><?= (int) $p['sold_count'] ?></td>
        <td><span class="admin-badge <?= ['ACTIVE' => 'admin-badge-success', 'REMOVED' => 'admin-badge-danger'][$p['status']] ?? 'admin-badge-muted' ?>"><?= $h(['ACTIVE' => 'Live', 'DRAFT' => 'Draft', 'INACTIVE' => 'Hidden', 'ARCHIVED' => 'Archived', 'REMOVED' => 'Taken down'][$p['status']] ?? $p['status']) ?></span><?= (int) $p['is_featured'] ? ' <span class="admin-badge admin-badge-purple">Featured</span>' : '' ?></td>
        <td><?php if ($canManage): ?><div style="display:flex; gap:6px; flex-wrap:wrap">
          <?php if ($p['status'] === 'ACTIVE'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= (int) $p['is_featured'] ? 'unfeature' : 'feature' ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-line" type="submit"><?= (int) $p['is_featured'] ? 'Unfeature' : 'Feature' ?></button></form><?php endif; ?>
          <?php if ($p['status'] !== 'REMOVED'): ?><form method="post" data-confirm="Take this product down? Customers will not see it and the seller cannot put it back." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="product_remove"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="q" value="<?= $h($f['q']) ?>"><input name="reason" required placeholder="Reason" style="width:120px"> <button class="btn btn-line" type="submit">Take down</button></form>
          <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product_restore"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="q" value="<?= $h($f['q']) ?>"><input name="reason" required placeholder="Reason" style="width:120px"> <button class="btn btn-line" type="submit">Give back</button></form><?php endif; ?>
        </div><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $pager($list, ['tab' => 'products', 'status' => $f['status'] ?: null, 'q' => $f['q'] ?: null]) ?>
  <?php endif; ?>

<?php elseif ($tab === 'sellers'): $q = trim((string) ($_GET['q'] ?? '')); $list = market_admin_sellers($q, $page); ?>
  <form method="get" class="admin-card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px">
    <input type="hidden" name="tab" value="sellers"><div class="admin-form-row" style="margin:0; flex:1 1 240px"><label>Search</label><input type="search" name="q" value="<?= $h($q) ?>" placeholder="Store, owner or email"></div><button class="btn btn-purple" type="submit">Search</button>
  </form>
  <?php if (!$list['rows']): ?><div class="admin-card"><p class="muted" style="margin:0">No stores yet. A store is created the first time an organizer opens OBI MARKET in their dashboard.</p></div><?php else: ?>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Store</th><th>Products</th><th>Orders</th><th>Waiting / released</th><th>Paid out</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($list['rows'] as $s): ?>
      <tr><td><a href="/market.php?store=<?= urlencode((string) $s['slug']) ?>" target="_blank" rel="noopener"><b><?= $h($s['name']) ?></b></a><br><span class="muted" style="font-size:.78rem"><?= $h($s['owner']) ?> &middot; <?= $h($s['email']) ?></span></td>
        <td><a href="/admin-market.php?tab=products&amp;q=<?= urlencode((string) $s['name']) ?>"><?= (int) $s['products'] ?></a></td>
        <td><a href="/admin-market.php?tab=orders&amp;seller=<?= (int) $s['seller_id'] ?>"><?= (int) $s['orders'] ?></a><?= (int) $s['disputes'] ? ' <span class="admin-badge admin-badge-danger">' . (int) $s['disputes'] . ' problem</span>' : '' ?></td>
        <td class="mono"><?= $ugx($s['pending']) ?> / <?= $ugx($s['available']) ?></td><td class="mono"><?= $ugx($s['withdrawn']) ?></td>
        <td><span class="admin-badge <?= $s['status'] === 'ACTIVE' ? 'admin-badge-success' : 'admin-badge-danger' ?>"><?= $s['status'] === 'ACTIVE' ? 'Active' : 'Suspended' ?></span><?= (int) $s['is_visible'] ? '' : ' <span class="admin-badge admin-badge-muted">Hidden by seller</span>' ?></td>
        <td><?php if ($canManage): ?><form method="post"<?= $s['status'] === 'ACTIVE' ? ' data-confirm="Suspend this store? Nothing of it will be shown or sold and its withdrawals are on hold." data-danger' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="<?= $s['status'] === 'ACTIVE' ? 'store_suspend' : 'store_restore' ?>"><input type="hidden" name="id" value="<?= (int) $s['seller_id'] ?>"><input name="reason" required placeholder="Reason" style="width:120px"> <button class="btn btn-line" type="submit"><?= $s['status'] === 'ACTIVE' ? 'Suspend' : 'Restore' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $pager($list, ['tab' => 'sellers', 'q' => $q ?: null]) ?>
  <?php endif; ?>

<?php elseif ($tab === 'finance'): $cfg = market_commission_current(); $hist = market_commission_history(); $months = market_admin_monthly(6); $wds = market_admin_withdrawals(40); ?>
  <div class="admin-grid-2" style="margin-bottom:18px">
    <div class="admin-card">
      <h3 style="margin:0 0 6px">Commission: <?= $h(rtrim(rtrim(number_format($cfg['bps'] / 100, 2), '0'), '.')) ?>%</h3>
      <p class="muted" style="margin:0 0 12px; font-size:.84rem">obitickets keeps this share of the merchandise price; the seller keeps the rest and all of the delivery fee. A change applies to NEW orders only: every order stores the rate it was bought at.</p>
      <?php if ($canMoney): ?>
      <form method="post" data-confirm="Change the OBI MARKET commission for new orders?">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_commission">
        <div class="admin-form-row"><label>New commission (%)</label><input type="text" name="percent" inputmode="decimal" required placeholder="10"></div>
        <div class="admin-form-row"><label>Reason (kept in the history)</label><input type="text" name="reason" required maxlength="250"></div>
        <div class="admin-form-row"><label>Starts</label><select name="when"><option value="now">Now</option><option value="later">At a date and time</option></select></div>
        <div class="admin-form-row"><label>Date and time (if later)</label><input type="datetime-local" name="effective_from"></div>
        <button class="btn btn-purple" type="submit">Change commission</button>
      </form>
      <?php else: ?><p class="muted" style="margin:0; font-size:.84rem">Only the top-level admin can change it.</p><?php endif; ?>
      <h3 style="margin:18px 0 8px; font-size:.95rem">History</h3>
      <?php foreach ($hist as $c): ?><div class="admin-detail-row"><span class="k">v<?= (int) $c['version'] ?> &middot; <?= $h(rtrim(rtrim(number_format((int) $c['commission_bps'] / 100, 2), '0'), '.')) ?>%</span><span class="v muted" style="font-size:.8rem">from <?= $h(date('d M Y, H:i', strtotime((string) $c['effective_from']))) ?><?= !empty($c['reason']) ? ' &middot; ' . $h($c['reason']) : '' ?></span></div><?php endforeach; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 4px">Seller withdrawals: <span class="admin-badge <?= market_withdraw_enabled() ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= market_withdraw_enabled() ? 'ON' : 'OFF' ?></span></h3>
      <p class="muted" style="margin:0 0 12px; font-size:.84rem">Sellers take out released money by mobile money through ioTec (smallest UGX <?= $num(MARKET_WITHDRAW_MIN_UGX) ?>, one at a time). obitickets pays the ioTec fee. Separate from ticket payouts and gift withdrawals.</p>
      <?php if ($canMoney): ?><form method="post" data-confirm="<?= market_withdraw_enabled() ? 'Switch seller withdrawals OFF?' : 'Switch seller withdrawals ON? Real money will be sent to sellers.' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_withdrawals"><?php if (!market_withdraw_enabled()): ?><input type="hidden" name="on" value="1"><?php endif; ?><button class="btn <?= market_withdraw_enabled() ? 'btn-line' : 'btn-purple' ?>" type="submit"><?= market_withdraw_enabled() ? 'Switch OFF' : 'Switch ON' ?></button></form><?php endif; ?>
      <h3 style="margin:18px 0 8px; font-size:.95rem">By month</h3>
      <?php if (!$months): ?><p class="muted" style="margin:0">No sales yet.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table"><thead><tr><th>Month</th><th>Orders</th><th>Sales</th><th>Commission</th></tr></thead><tbody>
        <?php foreach ($months as $m): ?><tr><td><?= $h(date('M Y', strtotime($m['month'] . '-01'))) ?></td><td><?= (int) $m['orders'] ?></td><td class="mono"><?= $ugx($m['sales']) ?></td><td class="mono"><?= $ugx($m['commission']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="admin-card">
    <h3 style="margin:0 0 12px">Withdrawals</h3>
    <?php if (!$wds): ?><p class="muted" style="margin:0">None yet.</p><?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table"><thead><tr><th>When</th><th>Store</th><th>Amount</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($wds as $w): $stuck = $w['status'] === 'PROCESSING' && strtotime((string) $w['requested_at']) < time() - 600; ?>
        <tr><td class="muted"><?= $h(date('d M, H:i', strtotime((string) $w['requested_at']))) ?><br><span style="font-size:.76rem"><?= $h($w['reference']) ?> &middot; OMW-<?= (int) $w['id'] ?></span></td><td><?= $h($w['store']) ?></td><td class="mono"><?= $ugx($w['amount_ugx']) ?></td><td class="mono"><?= $h($w['destination']) ?></td>
          <td><span class="admin-badge <?= ['PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger'][$w['status']] ?? 'admin-badge-warn' ?>"><?= $h(ucfirst(strtolower((string) $w['status']))) ?></span><?php if (!empty($w['reason'])): ?><br><span class="muted" style="font-size:.76rem"><?= $h($w['reason']) ?></span><?php endif; ?></td>
          <td><?php if ($canMoney && $stuck): ?><div style="display:flex; gap:6px; flex-wrap:wrap">
            <form method="post" data-confirm="Mark this withdrawal PAID? Only if the ioTec portal shows the money was sent."><?= csrf_field() ?><input type="hidden" name="action" value="wd_paid"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><button class="btn btn-line" type="submit">Mark paid</button></form>
            <form method="post" data-confirm="Mark this withdrawal FAILED? The money goes back to the seller's balance. Only if the ioTec portal shows nothing was sent." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="wd_failed"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><input name="reason" required placeholder="What ioTec shows" style="width:130px"> <button class="btn btn-line" type="submit">Mark failed</button></form>
          </div><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'emails'):
  $st = in_array($_GET['status'] ?? '', ['QUEUED', 'SENT', 'FAILED', 'SKIPPED'], true) ? (string) $_GET['status'] : 'ALL';
  $list = market_admin_emails($st, $page);
  $kinds = ['ORGANIZER' => 'Seller: new order', 'ORGANIZER_COPY' => 'Seller copy (store address)', 'CUSTOMER' => 'Customer: confirmed', 'SHIPPED' => 'Customer: sent / ready', 'CANCELLED' => 'Customer: cancelled'];
?>
  <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px"><?php foreach (['ALL' => 'All', 'QUEUED' => 'Waiting', 'SENT' => 'Sent', 'FAILED' => 'Failed', 'SKIPPED' => 'Skipped'] as $k => $label): ?><a class="btn <?= $st === $k ? 'btn-purple' : 'btn-line' ?>" style="padding:6px 12px; font-size:.8rem" href="/admin-market.php?tab=emails&amp;status=<?= $k ?>"><?= $h($label) ?></a><?php endforeach; ?>
    <a class="btn btn-line" style="padding:6px 12px; font-size:.8rem; margin-left:auto" href="/admin-emails.php">Full Email log</a></div>
  <p class="muted" style="font-size:.84rem; margin:0 0 12px">Every order email goes through this queue: sent right after payment, retried by the 5-minute job, and after 3 failed tries marked failed and the admins are emailed. Skipped = an address that can never receive mail (demo or reserved).</p>
  <?php if (!$list['rows']): ?><div class="admin-card"><p class="muted" style="margin:0">Nothing here.</p></div><?php else: ?>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Order</th><th>Email</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($list['rows'] as $e): ?>
      <tr><td class="muted"><?= $h(date('d M, H:i', strtotime((string) ($e['sent_at'] ?? $e['created_at'])))) ?></td><td><a href="/admin-market-order.php?n=<?= urlencode((string) $e['order_number']) ?>"><?= $h($e['order_number']) ?></a></td><td><?= $h($kinds[$e['kind']] ?? $e['kind']) ?></td><td><?= $h($e['to_email']) ?></td>
        <td><span class="admin-badge <?= ['SENT' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'SKIPPED' => 'admin-badge-muted'][$e['status']] ?? 'admin-badge-warn' ?>"><?= $h(ucfirst(strtolower((string) $e['status']))) ?></span><?= (int) $e['attempts'] > 1 ? ' <span class="muted" style="font-size:.76rem">' . (int) $e['attempts'] . ' tries</span>' : '' ?><?php if (!empty($e['last_error']) && $e['status'] !== 'SENT'): ?><br><span class="muted" style="font-size:.76rem"><?= $h($e['last_error']) ?></span><?php endif; ?></td>
        <td><?php if ($canManage && in_array($e['status'], ['FAILED', 'SKIPPED'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="email_requeue"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="<?= $h($st) ?>"><button class="btn btn-line" type="submit">Send again</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= $pager($list, ['tab' => 'emails', 'status' => $st]) ?>
  <?php endif; ?>

<?php else: $ver = market_admin_verification(); ?>
  <div class="admin-card" style="margin-bottom:18px">
    <h3 style="margin:0 0 4px">Payment check: <span class="admin-badge <?= $mark[$ver['overall']][1] ?>"><?= $h($ver['overall'] === 'pass' ? 'All good' : ($ver['overall'] === 'warn' ? 'Some things to look at' : 'Something needs fixing')) ?></span></h3>
    <p class="muted" style="margin:0; font-size:.86rem">Every promise OBI MARKET makes about money, stock and emails, checked against the data right now. <b>Fix</b> means do not open (or keep open) the shop until it is fixed; <b>Look</b> means worth a look, including the real-money tests only you can do.</p>
  </div>
  <?php foreach ($ver['groups'] as $group => $rows): ?>
    <div class="admin-card" style="margin-bottom:18px">
      <h3 style="margin:0 0 12px"><?= $h($group) ?></h3>
      <div class="admin-table-wrap" style="border:none; box-shadow:none"><table class="admin-table"><tbody>
        <?php foreach ($rows as $r): ?><tr><td style="white-space:nowrap; width:1%"><span class="admin-badge <?= $mark[$r['status']][1] ?>"><?= $h($mark[$r['status']][0]) ?></span></td><td><b><?= $h($r['label']) ?></b><br><span class="muted" style="font-size:.82rem"><?= $h($r['detail']) ?></span></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; /* ready */ ?>

<?php render_admin_foot(); ?>
