<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('dashboard.view');
$stats = get_platform_stats();
$activity = get_recent_activity(10);
$upcoming = get_upcoming_events_admin(5);
$dailyRevenue = get_daily_revenue(14);
$maxDaily = max(1, ...array_column($dailyRevenue, 'total'));

$actionLabels = [
    'event.status_change' => 'changed the status of event', 'event.feature' => 'featured event', 'event.unfeature' => 'unfeatured event',
    'organizer.verification' => 'updated verification for organizer', 'user.suspend' => 'suspended user', 'user.reactivate' => 'reactivated user',
    'ticket.checkin' => 'checked in ticket', 'ticket.reverse_checkin' => 'reversed check-in for ticket', 'ticket.cancel' => 'cancelled ticket',
    'order.refund' => 'refunded order', 'payout.create' => 'created a payout for', 'payout.request' => 'requested a withdrawal', 'payout.status_change' => 'updated payout status for',
    'promo.create' => 'created promo code', 'promo.activate' => 'activated promo code', 'promo.deactivate' => 'deactivated promo code', 'promo.delete' => 'deleted promo code',
    'user.role_change' => 'changed role for user', 'admin.role_assign' => 'assigned admin role to', 'contact.status_change' => 'updated contact message',
    'settings.update' => 'updated a setting',
];

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', $admin['name'])[0];
$grossRevenue = (float) ($stats['revenue_by_currency'][0]['total'] ?? 0);
$platformRevenue = (float) ($stats['commission_by_currency'][0]['total'] ?? 0) + (float) ($stats['service_fees_by_currency'][0]['total'] ?? 0);

$pageTitle = 'Dashboard';
render_admin_head('dashboard');
?>

<div class="admin-page-head">
  <div>
    <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?></h1>
    <p>Here's what's happening across obitickets today.</p>
  </div>
</div>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-cash"/></svg> Gross revenue</div>
    <span class="num"><span data-count-to="<?= (int) $grossRevenue ?>" data-count-prefix="<?= htmlspecialchars($stats['revenue_by_currency'][0]['currency'] ?? 'UGX') ?> ">0</span></span>
    <span class="sub">All paid orders, lifetime</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-shield"/></svg> Platform revenue</div>
    <span class="num"><span data-count-to="<?= (int) $platformRevenue ?>" data-count-prefix="<?= htmlspecialchars($stats['commission_by_currency'][0]['currency'] ?? 'UGX') ?> ">0</span></span>
    <span class="sub">Commission + service fees</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-ticket"/></svg> Tickets sold</div>
    <span class="num"><span data-count-to="<?= (int) $stats['total_tickets'] ?>">0</span></span>
    <span class="sub"><?= $stats['checked_in'] ?> checked in</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-bolt"/></svg> Paid orders</div>
    <span class="num"><span data-count-to="<?= (int) $stats['total_orders'] ?>">0</span></span>
    <span class="sub"><?= $stats['refunded_orders'] ?> refunded</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-cal"/></svg> Events</div>
    <span class="num"><span data-count-to="<?= (int) $stats['published_events'] ?>">0</span> <span style="color:var(--muted-2); font-size:1rem">/ <?= $stats['total_events'] ?></span></span>
    <span class="sub"><?= $stats['pending_events'] ?> awaiting review</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-briefcase"/></svg> Organizers</div>
    <span class="num"><span data-count-to="<?= (int) $stats['total_organizers'] ?>">0</span></span>
    <span class="sub">Active on the platform</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-user"/></svg> Customers</div>
    <span class="num"><span data-count-to="<?= (int) $stats['total_customers'] ?>">0</span></span>
    <span class="sub">Registered attendees</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-cash"/></svg> Pending payouts</div>
    <span class="num"><span data-count-to="<?= (int) $stats['pending_payouts'] ?>">0</span></span>
    <span class="sub">Awaiting processing</span>
  </div>
</div>

<div class="admin-grid-2" style="margin-top:24px">
  <div class="admin-card">
    <h3 style="margin-bottom:2px">Revenue — last 14 days</h3>
    <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:18px">Gross paid-order value per day</p>
    <div style="display:flex; align-items:flex-end; gap:6px; height:140px;">
      <?php foreach ($dailyRevenue as $d): ?>
        <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px;" title="<?= htmlspecialchars(date('d M', strtotime($d['day']))) ?>: UGX <?= number_format($d['total'], 0) ?>">
          <div style="width:100%; max-width:22px; border-radius:5px 5px 0 0; background:linear-gradient(180deg, var(--purple-light), var(--purple)); height:<?= max(2, round(($d['total'] / $maxDaily) * 110)) ?>px;"></div>
          <span style="font-size:0.62rem; color:var(--muted-2); font-family:'IBM Plex Mono',monospace;"><?= htmlspecialchars(date('d', strtotime($d['day']))) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:16px">Recent activity</h3>
    <?php if (!$activity): ?>
      <p style="color:var(--muted-2); font-size:0.86rem">Nothing logged yet — admin actions will show up here.</p>
    <?php else: ?>
      <div class="admin-activity">
        <?php foreach ($activity as $a): ?>
          <div class="admin-activity-row">
            <span class="admin-activity-ic"><svg width="15" height="15"><use href="#ic-clock"/></svg></span>
            <div class="admin-activity-body">
              <span class="who"><?= htmlspecialchars($a['admin_name'] ?? 'System') ?></span>
              <?= htmlspecialchars($actionLabels[$a['action']] ?? $a['action']) ?>
              <?php if ($a['entity_id']): ?><span class="muted">#<?= (int) $a['entity_id'] ?></span><?php endif; ?>
            </div>
            <span class="admin-activity-time"><?= htmlspecialchars(date('d M, H:i', strtotime($a['created_at']))) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (admin_can('audit.view')): ?><a class="link" style="display:inline-block; margin-top:14px; font-size:0.84rem; font-weight:700; color:var(--purple)" href="/admin-audit.php">View all activity &rarr;</a><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="admin-card" style="margin-top:24px">
  <h3 style="margin-bottom:16px">Upcoming events</h3>
  <?php if (!$upcoming): ?>
    <div class="admin-empty">
      <svg width="40" height="40"><use href="#ic-cal"/></svg>
      <h3>No upcoming published events</h3>
      <p>Once organizers publish events, they'll show up here.</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none;">
      <table class="admin-table">
        <thead><tr><th>Event</th><th>Organizer</th><th>Starts</th><th>Sold</th><th>Revenue</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($upcoming as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['banner_emoji']) ?> <?= htmlspecialchars($e['title']) ?></td>
              <td><?= htmlspecialchars($e['organizer_name']) ?></td>
              <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
              <td class="mono"><?= (int) $e['tickets_sold'] ?> / <?= (int) $e['tickets_total'] ?></td>
              <td class="mono">UGX <?= number_format((float) $e['revenue'], 0) ?></td>
              <td><a class="link" href="/admin-event-detail.php?id=<?= (int) $e['id'] ?>">View &rarr;</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_admin_foot(); ?>
