<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('events.view');
$organizerId = $ctx['organizer_id'];
$actorId = $ctx['actor_id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!organizer_can($ctx, 'events.manage')) {
        http_response_code(403);
        exit('You do not have permission to manage events.');
    }
    $action = $_POST['action'] ?? '';
    $eventId = (int) ($_POST['id'] ?? 0);
    if ($action === 'set_status') {
        [$ok, $err] = set_event_status_by_organizer($organizerId, $actorId, $eventId, (string) ($_POST['status'] ?? ''));
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /my-events.php?updated=1');
            exit;
        }
    } elseif ($action === 'delete') {
        [$ok, $err] = delete_event_for_organizer($organizerId, $actorId, $eventId);
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /my-events.php?updated=1');
            exit;
        }
    } elseif ($action === 'duplicate') {
        [$ok, $result] = duplicate_event_for_organizer($organizerId, $actorId, $eventId);
        if ($ok) {
            header('Location: /event-edit.php?id=' . $result . '&duplicated=1');
            exit;
        }
        $error = (string) $result;
    }
}

$allEvents = get_events_for_organizer($organizerId);

$statusCounts = array_fill_keys(['DRAFT', 'PENDING_REVIEW', 'PUBLISHED', 'SUSPENDED', 'REJECTED', 'CANCELLED'], 0);
foreach ($allEvents as $e) {
    if (isset($statusCounts[$e['status']])) {
        $statusCounts[$e['status']]++;
    }
}

$statusFilter = $_GET['status'] ?? '';
$q = trim((string) ($_GET['q'] ?? ''));
$events = array_values(array_filter($allEvents, static function ($e) use ($statusFilter, $q) {
    if ($statusFilter !== '' && $e['status'] !== $statusFilter) {
        return false;
    }
    if ($q !== '' && stripos($e['title'], $q) === false) {
        return false;
    }
    return true;
}));

$statusMeta = [
    'DRAFT' => 'admin-badge-muted', 'PENDING_REVIEW' => 'admin-badge-warn', 'PUBLISHED' => 'admin-badge-success',
    'SUSPENDED' => 'admin-badge-danger', 'REJECTED' => 'admin-badge-danger', 'CANCELLED' => 'admin-badge-muted',
];

$pageTitle = 'My Events';
render_organizer_head('my-events', $ctx);
?>

<div class="admin-page-head">
  <div><h1>My events</h1><p><?= count($allEvents) ?> total</p></div>
  <?php if (organizer_can($ctx, 'events.manage')): ?><a class="btn btn-purple" href="/event-create.php">+ Create event</a><?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?><span data-flash="Event created." hidden></span><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $statusFilter === '' ? ' active' : '' ?>" href="/my-events.php">
    <span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span>
  </a>
  <?php foreach ($statusMeta as $val => $badgeClass): ?>
    <a class="admin-mini-stat<?= $statusFilter === $val ? ' active' : '' ?>" href="/my-events.php?status=<?= $val ?>">
      <span class="n"><?= $statusCounts[$val] ?></span><span class="l"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $val)))) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form class="admin-filter-bar" method="get">
  <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Search your events…" value="<?= htmlspecialchars($q) ?>" style="min-width:260px">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$events): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-cal"/></svg></div>
    <h3>No events<?= $statusFilter !== '' || $q !== '' ? ' in this view' : ' yet' ?></h3>
    <p><?= $statusFilter !== '' || $q !== '' ? 'Try a different filter, or clear it to see everything.' : "You haven't created any events yet." ?></p>
    <?php if ($statusFilter !== '' || $q !== ''): ?>
      <a class="btn btn-line" href="/my-events.php">Clear filters</a>
    <?php elseif (organizer_can($ctx, 'events.manage')): ?>
      <a class="btn btn-purple" href="/event-create.php">Create your first event</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Event</th><th>Date</th><th>Status</th><th>Sold</th><th>Revenue</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td>
              <div class="row-user">
                <?php if (!empty($e['banner_image'])): ?>
                  <span class="avatar" style="background-image:url('<?= htmlspecialchars($e['banner_image']) ?>'); background-size:cover; background-position:center;"></span>
                <?php else: ?>
                  <span class="avatar"><?= htmlspecialchars($e['banner_emoji']) ?></span>
                <?php endif; ?>
                <div><?= htmlspecialchars($e['title']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($e['category']) ?> &middot; <?= htmlspecialchars($e['venue_name']) ?></span></div>
              </div>
            </td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
            <td><span class="admin-badge <?= $statusMeta[$e['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $e['status'])))) ?></span></td>
            <td class="mono"><?= (int) $e['tickets_sold'] ?></td>
            <td class="mono"><?= htmlspecialchars($e['currency'] ?? 'UGX') ?> <?= number_format((float) $e['gross_revenue'], 0) ?></td>
            <td style="white-space:nowrap">
              <a class="link" href="/event-edit.php?id=<?= (int) $e['id'] ?>">Edit</a>
              &middot;
              <a class="link" href="/event.php?slug=<?= urlencode($e['slug']) ?>" target="_blank" rel="noopener">View</a>
              <?php if ($e['status'] === 'PUBLISHED' && organizer_can($ctx, 'checkin.use')): ?>
                &middot;
                <a class="link" href="/checkin.php?event=<?= (int) $e['id'] ?>">Check in</a>
              <?php endif; ?>
              <?php if (organizer_can($ctx, 'events.manage')): ?>
                &middot;
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                  <button type="submit" class="link" style="background:none; border:none; color:var(--purple); cursor:pointer; padding:0; font-weight:700">Duplicate</button></form>
                <?php if ($e['status'] === 'DRAFT'): ?>
                  &middot;
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="PUBLISHED">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--success); cursor:pointer; padding:0; font-weight:700">Publish</button></form>
                  &middot;
                  <form method="post" style="display:inline" data-confirm="Delete this draft event? This can't be undone." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Delete</button></form>
                <?php elseif ($e['status'] === 'PUBLISHED'): ?>
                  &middot;
                  <form method="post" style="display:inline" data-confirm="Unpublish this event and move it back to draft?"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="DRAFT">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--muted); cursor:pointer; padding:0; font-weight:700">Unpublish</button></form>
                  &middot;
                  <form method="post" style="display:inline" data-confirm="Cancel this event? Ticket buyers won't be automatically refunded." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="CANCELLED">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Cancel</button></form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_organizer_foot(); ?>
