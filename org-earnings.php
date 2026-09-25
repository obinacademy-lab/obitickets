<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('earnings.view');
$organizerId = $ctx['organizer_id'];
$earnings = get_organizer_earnings_breakdown($organizerId);
$currency = $earnings['currency'];

$pageTitle = 'Earnings';
render_organizer_head('earnings', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Earnings</h1><p>How your net earnings are calculated — authoritative figures from paid orders, not estimates.</p></div>
</div>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Gross ticket sales</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $earnings['gross'] ?>" data-count-prefix="<?= $currency ?> ">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Platform fees</span>
      <span class="admin-kpi-ic" style="background:var(--warn-bg); color:var(--warn)"><svg width="17" height="17"><use href="#ic-shield"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $earnings['platform_fees'] ?>" data-count-prefix="<?= $currency ?> ">0</span></span>
    <span class="sub"><?= number_format(PLATFORM_COMMISSION_RATE * 100, 0) ?>% commission</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Refunds</span>
      <span class="admin-kpi-ic" style="background:var(--danger-bg); color:var(--danger)"><svg width="17" height="17"><use href="#ic-x"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $earnings['refunds'] ?>" data-count-prefix="<?= $currency ?> ">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Net earnings</span>
      <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-check"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $earnings['net_earnings'] ?>" data-count-prefix="<?= $currency ?> ">0</span></span>
    <span class="sub">Gross minus platform fees</span>
  </div>
</div>

<div class="admin-grid-2" style="margin-top:20px">
  <div class="admin-card">
    <h3 style="margin-bottom:14px">Balance</h3>
    <div class="admin-detail-row"><span class="k">Available now</span><span class="v mono" style="color:var(--success)"><?= $currency ?> <?= number_format($earnings['available_balance'], 0) ?></span></div>
    <div class="admin-detail-row"><span class="k">Pending withdrawal</span><span class="v mono"><?= $currency ?> <?= number_format($earnings['pending_balance'], 0) ?></span></div>
    <div class="admin-detail-row"><span class="k">Total paid out</span><span class="v mono"><?= $currency ?> <?= number_format($earnings['total_paid_out'], 0) ?></span></div>
    <a class="btn btn-purple btn-block" style="margin-top:16px" href="/org-payouts.php">Manage payouts</a>
  </div>
  <div class="admin-card">
    <h3 style="margin-bottom:10px">How this is calculated</h3>
    <p style="color:var(--muted-2); font-size:0.85rem; line-height:1.6">
      Your <strong>net earnings</strong> are the total your buyers paid for tickets (gross), minus obitickets' <?= number_format(PLATFORM_COMMISSION_RATE * 100, 0) ?>% platform commission, minus any refunds processed on your events. These figures come straight from your paid orders — not an estimate — the same numbers admin sees when approving your payouts.
    </p>
  </div>
</div>

<?php render_organizer_foot(); ?>
