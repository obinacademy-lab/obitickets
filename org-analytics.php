<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('analytics.view');
$organizerId = $ctx['organizer_id'];

$sales = get_organizer_sales_summary($organizerId, 30);
$customers = get_organizer_customer_stats($organizerId, 30);
$performance = get_organizer_event_performance($organizerId);
$attendeeCounts = get_organizer_attendee_counts($organizerId);
$totalPool = array_sum($attendeeCounts) - $attendeeCounts['CANCELLED'];
$checkinRate = $totalPool > 0 ? round($attendeeCounts['USED'] / $totalPool * 100) : 0;

$pageTitle = 'Analytics';
render_organizer_head('analytics', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Analytics</h1><p>Real performance data across your events — last 30 days where noted.</p></div>
</div>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top"><span class="lbl">Revenue (30d)</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span></div>
    <span class="num"><span data-count-to="<?= (int) $sales['gross'] ?>" data-count-prefix="UGX ">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top"><span class="lbl">Tickets sold (30d)</span><span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-ticket"/></svg></span></div>
    <span class="num"><span data-count-to="<?= (int) $sales['tickets_sold'] ?>">0</span></span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top"><span class="lbl">Check-in rate</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-check"/></svg></span></div>
    <span class="num"><span data-count-to="<?= $checkinRate ?>">0</span>%</span>
    <span class="sub"><?= (int) $attendeeCounts['USED'] ?> of <?= $totalPool ?> tickets</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top"><span class="lbl">Total customers</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-people"/></svg></span></div>
    <span class="num"><span data-count-to="<?= (int) $customers['total_customers'] ?>">0</span></span>
    <span class="sub"><?= (int) $customers['new_customers'] ?> new in 30 days</span>
  </div>
</div>

<div class="admin-grid-2" style="margin-top:20px">
  <div class="admin-card">
    <h3 style="margin-bottom:14px">Customers</h3>
    <div class="admin-detail-row"><span class="k">Total customers</span><span class="v mono"><?= (int) $customers['total_customers'] ?></span></div>
    <div class="admin-detail-row"><span class="k">New (30 days)</span><span class="v mono"><?= (int) $customers['new_customers'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Returning</span><span class="v mono"><?= (int) $customers['returning_customers'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Average spend</span><span class="v mono">UGX <?= number_format($customers['average_spend'], 0) ?></span></div>
  </div>
  <div class="admin-card">
    <h3 style="margin-bottom:14px">Attendance</h3>
    <div class="admin-detail-row"><span class="k">Tickets sold</span><span class="v mono"><?= $totalPool ?></span></div>
    <div class="admin-detail-row"><span class="k">Checked in</span><span class="v mono" style="color:var(--success)"><?= (int) $attendeeCounts['USED'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Not checked in</span><span class="v mono"><?= (int) $attendeeCounts['VALID'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Check-in rate</span><span class="v mono"><?= $checkinRate ?>%</span></div>
  </div>
</div>

<div class="admin-card" style="margin-top:20px">
  <h3 style="margin-bottom:14px">Event performance</h3>
  <?php if (!$performance): ?>
    <div class="admin-empty" style="padding:28px 24px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-cal"/></svg></div><p style="margin-top:0">No events yet.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none;">
      <table class="admin-table">
        <thead><tr><th>Event</th><th>Tickets sold</th><th>Revenue</th><th>Attendance</th><th>Refund rate</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($performance as $e):
              $attendancePool = (int) $e['attendance_pool'];
              $rate = $attendancePool > 0 ? round((int) $e['checked_in'] / $attendancePool * 100) : null;
              $refundRate = (int) $e['total_orders'] > 0 ? round((int) $e['refunded_orders'] / (int) $e['total_orders'] * 100) : 0;
          ?>
            <tr>
              <td><?= htmlspecialchars($e['title']) ?></td>
              <td class="mono"><?= (int) $e['tickets_sold'] ?> / <?= (int) $e['capacity'] ?></td>
              <td class="mono">UGX <?= number_format((float) $e['revenue'], 0) ?></td>
              <td class="mono"><?= $rate === null ? '—' : $rate . '%' ?></td>
              <td class="mono"><?= $refundRate ?>%</td>
              <td class="muted"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $e['status'])))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_organizer_foot(); ?>
