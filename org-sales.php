<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('sales.view');
$organizerId = $ctx['organizer_id'];

$period = (int) ($_GET['days'] ?? 30);
$period = in_array($period, [7, 30, 90, 365], true) ? $period : 30;
$summary = get_organizer_sales_summary($organizerId, $period);
$currency = 'UGX';

$pageTitle = 'Sales';
render_organizer_head('sales', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Sales</h1><p>Gross sales performance across your events</p></div>
</div>

<div class="admin-filter-bar" style="margin-bottom:20px">
  <?php foreach (['7' => '7 Days', '30' => '30 Days', '90' => '90 Days', '365' => '12 Months'] as $val => $label): ?>
    <a class="btn <?= $period === (int) $val ? 'btn-purple' : 'btn-line' ?>" style="padding:8px 16px; font-size:0.84rem" href="?days=<?= $val ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Gross sales</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $summary['gross'] ?>" data-count-prefix="<?= $currency ?> ">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Tickets sold</span>
      <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-ticket"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $summary['tickets_sold'] ?>">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Orders</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-bolt"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $summary['orders'] ?>">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Average order value</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-shield"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $summary['aov'] ?>" data-count-prefix="<?= $currency ?> ">0</span></span>
  </div>
</div>

<div class="admin-grid-2" style="margin-top:20px">
  <div class="admin-card">
    <h3 style="margin-bottom:14px">Revenue by event</h3>
    <?php if (!$summary['by_event']): ?>
      <div class="admin-empty" style="padding:28px 24px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-cal"/></svg></div><p style="margin-top:0">No sales in this period.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>Event</th><th>Orders</th><th>Revenue</th></tr></thead>
          <tbody>
            <?php foreach ($summary['by_event'] as $e): ?>
              <tr><td><?= htmlspecialchars($e['title']) ?></td><td class="mono"><?= (int) $e['orders'] ?></td><td class="mono"><?= $currency ?> <?= number_format((float) $e['revenue'], 0) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:14px">Revenue by ticket type</h3>
    <?php if (!$summary['by_tier']): ?>
      <div class="admin-empty" style="padding:28px 24px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-ticket"/></svg></div><p style="margin-top:0">No sales in this period.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>Ticket type</th><th>Event</th><th>Sold</th><th>Revenue</th></tr></thead>
          <tbody>
            <?php foreach ($summary['by_tier'] as $t): ?>
              <tr><td style="font-weight:700"><?= htmlspecialchars($t['name']) ?></td><td class="muted"><?= htmlspecialchars($t['event_title']) ?></td><td class="mono"><?= (int) $t['sold'] ?></td><td class="mono"><?= $currency ?> <?= number_format((float) $t['revenue'], 0) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_organizer_foot(); ?>
