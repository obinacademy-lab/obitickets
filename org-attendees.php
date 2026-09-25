<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('attendees.view');
$organizerId = $ctx['organizer_id'];

$filters = ['status' => $_GET['status'] ?? '', 'q' => trim((string) ($_GET['q'] ?? ''))];

if (($_GET['export'] ?? '') === 'csv') {
    $rows = get_attendees_for_organizer($organizerId, $filters, 10000, 0);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendees.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Phone', 'Event', 'Tier', 'Ticket code', 'Order #', 'Payment status', 'Check-in status', 'Purchased at']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['attendee_name'], $r['attendee_email'], $r['attendee_phone'], $r['event_title'], $r['tier_name'], $r['ticket_code'], $r['order_id'], $r['payment_status'], $r['status'], $r['purchased_at']]);
    }
    fclose($out);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$total = count_attendees_for_organizer($organizerId, $filters);
$attendees = get_attendees_for_organizer($organizerId, $filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));
$statusCounts = get_organizer_attendee_counts($organizerId);

$statusMeta = ['VALID' => 'admin-badge-success', 'USED' => 'admin-badge-purple', 'CANCELLED' => 'admin-badge-muted'];

$pageTitle = 'Attendees';
render_organizer_head('attendees', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Attendees</h1><p><?= $total ?> total</p></div>
  <a class="btn btn-line" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"><svg width="15" height="15" style="vertical-align:-2px; margin-right:4px"><use href="#ic-download"/></svg> Export CSV</a>
</div>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $filters['status'] === '' ? ' active' : '' ?>" href="/org-attendees.php">
    <span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span>
  </a>
  <a class="admin-mini-stat<?= $filters['status'] === 'VALID' ? ' active' : '' ?>" href="/org-attendees.php?status=VALID">
    <span class="n"><?= $statusCounts['VALID'] ?></span><span class="l">Valid</span>
  </a>
  <a class="admin-mini-stat<?= $filters['status'] === 'USED' ? ' active' : '' ?>" href="/org-attendees.php?status=USED">
    <span class="n"><?= $statusCounts['USED'] ?></span><span class="l">Checked in</span>
  </a>
  <a class="admin-mini-stat<?= $filters['status'] === 'CANCELLED' ? ' active' : '' ?>" href="/org-attendees.php?status=CANCELLED">
    <span class="n"><?= $statusCounts['CANCELLED'] ?></span><span class="l">Cancelled</span>
  </a>
</div>

<form class="admin-filter-bar" method="get">
  <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filters['status']) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Search attendee, email or ticket code…" value="<?= htmlspecialchars($filters['q']) ?>" style="min-width:280px">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$attendees): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-people"/></svg></div>
    <h3>No attendees<?= $filters['status'] !== '' || $filters['q'] !== '' ? ' in this view' : ' yet' ?></h3>
    <p><?= $filters['status'] !== '' || $filters['q'] !== '' ? 'Try a different search, or clear your filters to see everyone.' : 'Once someone buys a ticket, they\'ll show up here.' ?></p>
    <?php if ($filters['status'] !== '' || $filters['q'] !== ''): ?><a class="btn btn-line" href="/org-attendees.php">Clear filters</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Attendee</th><th>Event</th><th>Tier</th><th>Code</th><th>Order</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($attendees as $a): ?>
          <tr>
            <td><div class="row-user"><span class="avatar"><?= htmlspecialchars(initials_from_name($a['attendee_name'])) ?></span><div><?= htmlspecialchars($a['attendee_name']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($a['attendee_email']) ?></span></div></div></td>
            <td class="muted"><?= htmlspecialchars($a['event_title']) ?></td>
            <td class="muted"><?= htmlspecialchars($a['tier_name']) ?></td>
            <td class="mono"><?= htmlspecialchars($a['ticket_code']) ?></td>
            <td><a class="link" href="/org-order-detail.php?id=<?= (int) $a['order_id'] ?>">#<?= (int) $a['order_id'] ?></a></td>
            <td><span class="admin-badge <?= $statusMeta[$a['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($a['status']) ?><?= $a['checked_in_at'] ? ' · ' . htmlspecialchars(date('d M H:i', strtotime($a['checked_in_at']))) : '' ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_organizer_foot(); ?>
