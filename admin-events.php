<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('events.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!admin_can('events.manage')) {
        http_response_code(403);
        exit('You do not have permission to change events.');
    }
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($eventId && $action === 'status') {
        update_event_status_admin((int) $admin['id'], $eventId, (string) ($_POST['status'] ?? ''), $_POST['reason'] ?? null);
    } elseif ($eventId && $action === 'feature') {
        set_event_featured((int) $admin['id'], $eventId, !empty($_POST['featured']));
    }
    header('Location: /admin-events.php?' . http_build_query(array_filter(['updated' => 1, 'status' => $_GET['status'] ?? '', 'category' => $_GET['category'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? ''])));
    exit;
}

$filters = [
    'status' => $_GET['status'] ?? '',
    'category' => $_GET['category'] ?? '',
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total = count_events_admin($filters);
$events = get_events_admin($filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));

$statusMeta = [
    'DRAFT' => ['Draft', 'admin-badge-muted'],
    'PENDING_REVIEW' => ['Pending review', 'admin-badge-warn'],
    'PUBLISHED' => ['Published', 'admin-badge-success'],
    'SUSPENDED' => ['Suspended', 'admin-badge-danger'],
    'REJECTED' => ['Rejected', 'admin-badge-danger'],
    'CANCELLED' => ['Cancelled', 'admin-badge-muted'],
];

$pageTitle = 'Events';
render_admin_head('events');
?>

<div class="admin-page-head">
  <div><h1>Events</h1><p><?= $total ?> total</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Event updated." hidden></span><?php endif; ?>

<form class="admin-filter-bar" method="get">
  <input type="text" name="q" placeholder="Search title or organizer…" value="<?= htmlspecialchars($filters['q']) ?>">
  <select name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (EVENT_ADMIN_STATUSES as $s): ?>
      <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($statusMeta[$s][0]) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="category" onchange="this.form.submit()">
    <option value="">All categories</option>
    <?php foreach (EVENT_CATEGORIES as $c): ?>
      <option value="<?= htmlspecialchars($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
  <div class="spacer"></div>
</form>

<?php if (!$events): ?>
  <div class="admin-empty">
    <svg width="40" height="40"><use href="#ic-cal"/></svg>
    <h3>No events found</h3>
    <p>Try a different search or clear your filters.</p>
    <a class="btn btn-line" href="/admin-events.php">Clear filters</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Event</th><th>Organizer</th><th>Category</th><th>Starts</th><th>Sold</th><th>Revenue</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($events as $e): $meta = $statusMeta[$e['status']] ?? ['Unknown', 'admin-badge-muted']; ?>
          <tr>
            <td><a class="link" href="/admin-event-detail.php?id=<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['banner_emoji']) ?> <?= htmlspecialchars($e['title']) ?></a><?php if ($e['featured']): ?> <span class="admin-badge admin-badge-purple">Featured</span><?php endif; ?></td>
            <td><?= htmlspecialchars($e['organizer_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($e['category']) ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
            <td class="mono"><?= (int) $e['tickets_sold'] ?></td>
            <td class="mono">UGX <?= number_format((float) $e['revenue'], 0) ?></td>
            <td><span class="admin-badge <?= $meta[1] ?>"><?= htmlspecialchars($meta[0]) ?></span></td>
            <td><a class="link" href="/admin-event-detail.php?id=<?= (int) $e['id'] ?>">Manage &rarr;</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span>
        <?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
