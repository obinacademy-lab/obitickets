<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('tickets.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($ticketId && $action === 'checkin' && admin_can('tickets.checkin')) {
        admin_check_in((int) $admin['id'], $ticketId);
    } elseif ($ticketId && $action === 'reverse' && admin_can('tickets.checkin')) {
        admin_reverse_checkin((int) $admin['id'], $ticketId);
    } elseif ($ticketId && $action === 'cancel' && admin_can('tickets.view')) {
        admin_cancel_ticket((int) $admin['id'], $ticketId);
    }
    header('Location: /admin-tickets.php?' . http_build_query(['q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? '', 'updated' => 1]));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$statusCounts = get_ticket_status_counts();
$tickets = search_tickets_admin($q, 100, $statusFilter);
$statusMeta = ['VALID' => 'admin-badge-success', 'USED' => 'admin-badge-purple', 'CANCELLED' => 'admin-badge-muted'];

$pageTitle = 'Tickets';
render_admin_head('tickets');
?>

<div class="admin-page-head">
  <div><h1>Tickets</h1><p><?= count($tickets) ?> shown<?= $q === '' && $statusFilter === '' ? ' (most recent 100)' : '' ?></p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Ticket updated." hidden></span><?php endif; ?>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $statusFilter === '' ? ' active' : '' ?>" href="/admin-tickets.php">
    <span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'VALID' ? ' active' : '' ?>" href="/admin-tickets.php?status=VALID">
    <span class="n"><?= $statusCounts['VALID'] ?></span><span class="l">Valid</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'USED' ? ' active' : '' ?>" href="/admin-tickets.php?status=USED">
    <span class="n"><?= $statusCounts['USED'] ?></span><span class="l">Checked in</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'CANCELLED' ? ' active' : '' ?>" href="/admin-tickets.php?status=CANCELLED">
    <span class="n"><?= $statusCounts['CANCELLED'] ?></span><span class="l">Cancelled</span>
  </a>
</div>

<form class="admin-filter-bar" method="get">
  <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Search ticket code, attendee or event…" value="<?= htmlspecialchars($q) ?>" style="min-width:320px">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$tickets): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-ticket"/></svg></div>
    <h3>No tickets found</h3>
    <p>Try a different search, or clear your filters to see everything.</p>
    <a class="btn btn-line" href="/admin-tickets.php">Clear filters</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Code</th><th>Event</th><th>Tier</th><th>Attendee</th><th>Order</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($t['ticket_code']) ?></td>
            <td><a class="link" href="/admin-event-detail.php?id=<?= (int) $t['event_id'] ?>"><?= htmlspecialchars($t['event_title']) ?></a></td>
            <td class="muted"><?= htmlspecialchars($t['tier_name']) ?></td>
            <td><?= htmlspecialchars($t['attendee_name']) ?><br><span class="muted" style="font-size:0.78rem"><?= htmlspecialchars($t['attendee_email']) ?></span></td>
            <td><a class="link" href="/admin-order-detail.php?id=<?= (int) $t['order_id'] ?>">#<?= (int) $t['order_id'] ?></a></td>
            <td><span class="admin-badge <?= $statusMeta[$t['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($t['status']) ?><?= $t['checked_in_at'] ? ' · ' . htmlspecialchars(date('d M H:i', strtotime($t['checked_in_at']))) : '' ?></span></td>
            <td style="white-space:nowrap">
              <?php if (admin_can('tickets.checkin')): ?>
                <?php if ($t['status'] === 'VALID'): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="action" value="checkin"><button type="submit" class="link" style="background:none; border:none; color:var(--purple); cursor:pointer; padding:0; font-weight:700">Check in</button></form>
                <?php elseif ($t['status'] === 'USED'): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="action" value="reverse"><button type="submit" class="link" style="background:none; border:none; color:var(--muted); cursor:pointer; padding:0; font-weight:700">Reverse</button></form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($t['status'] !== 'CANCELLED'): ?>
                &middot;
                <form method="post" style="display:inline" data-confirm="Cancel this ticket?" data-danger><?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="action" value="cancel"><button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Cancel</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
