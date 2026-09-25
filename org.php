<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('dashboard.view');
$organizerId = $ctx['organizer_id'];

$stats = get_organizer_dashboard_stats($organizerId);
$comparison = get_organizer_period_comparison($organizerId, 30);
$dailyRevenue = get_organizer_daily_revenue($organizerId, 14);
$maxDaily = max(1, ...array_column($dailyRevenue, 'total'));
$upcoming = get_upcoming_events_for_organizer($organizerId, 5);
$recentOrders = get_orders_for_organizer($organizerId, [], 6, 0);
$activity = get_organizer_activity($organizerId, 8);
$currency = $stats['currency'];

$actionLabels = [
    'event.status_change' => 'changed the status of event', 'ticket.pause_sales' => 'paused sales on', 'ticket.resume_sales' => 'resumed sales on',
    'payout.request' => 'requested a withdrawal', 'refund.request' => 'requested a refund for order', 'promo.create' => 'created promo code',
    'promo.activate' => 'activated promo code', 'promo.deactivate' => 'deactivated promo code', 'promo.delete' => 'deleted promo code',
    'team.invite' => 'invited', 'team.role_change' => 'changed role for', 'team.revoke' => 'removed', 'support.create' => 'submitted a support ticket',
];

/** Renders a trend pill, or nothing if there's no prior period to compare against. */
function render_org_trend(?float $percent): string
{
    if ($percent === null) {
        return '';
    }
    $dir = $percent >= 0 ? 'up' : 'down';
    $arrow = '<svg width="10" height="10"><use href="#ic-arrow" transform="rotate(-90 12 12)"/></svg>';
    return '<span class="admin-kpi-trend ' . $dir . '">' . $arrow . ' ' . htmlspecialchars(number_format(abs($percent), 1)) . '%</span>';
}

// Build an SVG line-chart path for the daily revenue trend (same technique as admin.php's Dashboard).
$chartDays = count($dailyRevenue);
$chartW = 680; $chartH = 180; $padTop = 22; $padBottom = 24; $padX = 6;
$plotW = $chartW - $padX * 2; $plotH = $chartH - $padTop - $padBottom;
$chartPoints = [];
foreach ($dailyRevenue as $i => $d) {
    $x = $chartDays > 1 ? $padX + ($i * ($plotW / ($chartDays - 1))) : $padX + $plotW / 2;
    $y = $padTop + (1 - ($d['total'] / $maxDaily)) * $plotH;
    $chartPoints[] = ['x' => round($x, 1), 'y' => round($y, 1), 'total' => $d['total'], 'day' => $d['day'], 'peak' => $d['total'] > 0 && $d['total'] == $maxDaily];
}
$chartLine = '';
foreach ($chartPoints as $i => $p) {
    $chartLine .= ($i === 0 ? 'M' : ' L') . $p['x'] . ',' . $p['y'];
}
$chartArea = $chartPoints ? $chartLine . ' L' . end($chartPoints)['x'] . ',' . ($padTop + $plotH) . ' L' . $chartPoints[0]['x'] . ',' . ($padTop + $plotH) . ' Z' : '';

$orderStatusMeta = ['PENDING' => 'admin-badge-warn', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'CANCELLED' => 'admin-badge-muted', 'REFUNDED' => 'admin-badge-purple'];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', current_user()['name'])[0];

$pageTitle = 'Dashboard';
render_organizer_head('dashboard', $ctx);
?>

<div class="admin-page-head">
  <div>
    <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?></h1>
    <p>Here's how your events are doing.</p>
  </div>
  <?php if (organizer_can($ctx, 'events.manage')): ?><a class="btn btn-purple" href="/event-create.php">+ Create event</a><?php endif; ?>
</div>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Total events</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cal"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['total_events'] ?>">0</span></span>
    <span class="sub"><?= (int) $stats['upcoming_events'] ?> upcoming</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Tickets sold</span>
      <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-ticket"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['tickets_sold'] ?>">0</span></span>
    <span class="sub"><?= render_org_trend($comparison['orders']['percent'] ?? null) ?> <span><?= (int) $stats['checked_in'] ?> checked in</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Total revenue</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) array_sum(array_column($stats['gross_by_currency'], 'gross')) ?>" data-count-prefix="<?= htmlspecialchars($currency) ?> ">0</span></span>
    <span class="sub"><?= render_org_trend($comparison['revenue']['percent'] ?? null) ?> <span>vs prior 30 days</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Net earnings</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-shield"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['net_earnings'] ?>" data-count-prefix="<?= htmlspecialchars($currency) ?> ">0</span></span>
    <span class="sub">After platform commission</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Available balance</span>
      <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['available_balance'] ?>" data-count-prefix="<?= htmlspecialchars($currency) ?> ">0</span></span>
    <span class="sub"><a class="link" href="/org-payouts.php">Withdraw &rarr;</a></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Pending payouts</span>
      <span class="admin-kpi-ic" style="background:<?= $stats['pending_payouts_count'] ? 'var(--warn-bg); color:var(--warn)' : 'var(--purple-tint); color:var(--purple)' ?>"><svg width="17" height="17"><use href="#ic-clock"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['pending_payouts_count'] ?>">0</span></span>
    <span class="sub"><?= htmlspecialchars($currency) ?> <?= number_format((float) $stats['pending_payouts_amount'], 0) ?> requested</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Total attendees</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-people"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['total_attendees'] ?>">0</span></span>
    <span class="sub">Unique buyers</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Checked in</span>
      <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-check"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) $stats['checked_in'] ?>">0</span> <span style="color:var(--muted-2); font-size:1rem">/ <?= (int) $stats['tickets_sold'] ?></span></span>
    <span class="sub">Of tickets sold</span>
  </div>
</div>

<div class="admin-grid-2" style="margin-top:20px">
  <div class="admin-card">
    <h3 style="margin-bottom:2px">Revenue — last 14 days</h3>
    <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:20px">Gross paid-order value per day</p>
    <?php if ($maxDaily <= 1): ?>
      <div class="admin-empty" style="padding:28px 24px">
        <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-cash"/></svg></div>
        <p style="margin-top:0">No paid orders in the last 14 days yet.</p>
      </div>
    <?php else: ?>
      <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" width="100%" height="170" preserveAspectRatio="none" class="admin-line-chart">
        <defs>
          <linearGradient id="orgRevChartGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--purple)" stop-opacity="0.30"/>
            <stop offset="100%" stop-color="var(--purple)" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <path d="<?= htmlspecialchars($chartArea) ?>" fill="url(#orgRevChartGrad)" stroke="none"></path>
        <path d="<?= htmlspecialchars($chartLine) ?>" fill="none" stroke="var(--purple)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="admin-line-path"></path>
        <?php foreach ($chartPoints as $p): ?>
          <?php if ($p['peak']): ?>
            <text x="<?= $p['x'] ?>" y="<?= max(11, $p['y'] - 9) ?>" text-anchor="middle" font-size="10" font-weight="700" style="fill:var(--success); font-family:'IBM Plex Mono',monospace;"><?= htmlspecialchars($currency) ?> <?= number_format($p['total'], 0) ?></text>
          <?php endif; ?>
          <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="<?= $p['peak'] ? 4.5 : 3 ?>" style="fill:<?= $p['peak'] ? 'var(--success)' : 'var(--purple)' ?>; stroke:var(--surface); stroke-width:1.5;">
            <title><?= htmlspecialchars(date('d M Y', strtotime($p['day']))) ?>: <?= htmlspecialchars($currency) ?> <?= number_format($p['total'], 0) ?></title>
          </circle>
          <text x="<?= $p['x'] ?>" y="<?= $chartH - 6 ?>" text-anchor="middle" font-size="9" style="fill:var(--muted-2); font-family:'IBM Plex Mono',monospace;"><?= htmlspecialchars(date('d', strtotime($p['day']))) ?></text>
        <?php endforeach; ?>
      </svg>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:16px">Recent activity</h3>
    <?php if (!$activity): ?>
      <div class="admin-empty" style="padding:28px 24px">
        <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-clock"/></svg></div>
        <p style="margin-top:0">Nothing logged yet — your actions will show up here.</p>
      </div>
    <?php else: ?>
      <div class="admin-activity">
        <?php foreach ($activity as $a): ?>
          <div class="admin-activity-row">
            <span class="admin-activity-ic"><svg width="15" height="15"><use href="#ic-clock"/></svg></span>
            <div class="admin-activity-body">
              <span class="who"><?= htmlspecialchars($a['actor_name']) ?></span>
              <?= htmlspecialchars($actionLabels[$a['action']] ?? $a['action']) ?>
              <?php if ($a['entity_id']): ?><span class="muted">#<?= (int) $a['entity_id'] ?></span><?php endif; ?>
            </div>
            <span class="admin-activity-time"><?= htmlspecialchars(date('d M, H:i', strtotime($a['created_at']))) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="admin-card" style="margin-top:20px">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
    <h3 style="margin:0">Upcoming events</h3>
    <a class="link" style="font-size:0.84rem; font-weight:700; color:var(--purple)" href="/my-events.php">View all &rarr;</a>
  </div>
  <?php if (!$upcoming): ?>
    <div class="admin-empty">
      <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-cal"/></svg></div>
      <h3>No upcoming events</h3>
      <p>Once you publish an event, it'll show up here.</p>
      <?php if (organizer_can($ctx, 'events.manage')): ?><a class="btn btn-line" href="/event-create.php">Create your first event</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none;">
      <table class="admin-table">
        <thead><tr><th>Event</th><th>Starts</th><th>Sold</th><th>Revenue</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($upcoming as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['banner_emoji']) ?> <?= htmlspecialchars($e['title']) ?></td>
              <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
              <td class="mono"><?= (int) $e['tickets_sold'] ?> / <?= (int) $e['tickets_total'] ?></td>
              <td class="mono"><?= htmlspecialchars($currency) ?> <?= number_format((float) $e['revenue'], 0) ?></td>
              <td><a class="link" href="/event-edit.php?id=<?= (int) $e['id'] ?>">Manage &rarr;</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="admin-card" style="margin-top:20px">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
    <h3 style="margin:0">Recent orders</h3>
    <a class="link" style="font-size:0.84rem; font-weight:700; color:var(--purple)" href="/org-orders.php">View all &rarr;</a>
  </div>
  <?php if (!$recentOrders): ?>
    <div class="admin-empty" style="padding:28px 24px">
      <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-bolt"/></svg></div>
      <p style="margin-top:0">No ticket sales yet.</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none;">
      <table class="admin-table">
        <thead><tr><th>Order</th><th>Buyer</th><th>Event</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td class="mono">#<?= (int) $o['id'] ?></td>
              <td><?= htmlspecialchars($o['buyer_name']) ?></td>
              <td class="muted"><?= htmlspecialchars($o['event_title']) ?></td>
              <td class="mono"><?= htmlspecialchars($o['currency']) ?> <?= number_format((float) $o['total_amount'], 0) ?></td>
              <td><span class="admin-badge <?= $orderStatusMeta[$o['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($o['status']) ?></span></td>
              <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($o['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_organizer_foot(); ?>
