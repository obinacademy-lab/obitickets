<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($eventId) {
        update_event_status_admin($eventId, $status);
    }
    header('Location: /admin-events.php?updated=1');
    exit;
}

$events = get_all_events_admin();
$statusLabels = ['PUBLISHED' => 'Published', 'DRAFT' => 'Draft', 'CANCELLED' => 'Cancelled'];

$pageTitle = 'Manage events — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <?php render_admin_tabs('events'); ?>

    <div class="admin-head sec-top">
      <div><span class="kicker">Admin</span><h1>Events</h1></div>
      <span class="mono" style="color:var(--muted-2)"><?= count($events) ?> total</span>
    </div>

    <?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Event updated.</div><?php endif; ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Event</th><th>Organizer</th><th>Category</th><th>Starts</th><th>Sold</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($events as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['banner_emoji']) ?> <?= htmlspecialchars($e['title']) ?></td>
              <td><?= htmlspecialchars($e['organizer_name']) ?></td>
              <td><span class="tag-pill tag-pill-muted"><?= htmlspecialchars($e['category']) ?></span></td>
              <td class="mono" style="color:var(--muted-2)"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
              <td class="mono"><?= (int) $e['tickets_sold'] ?></td>
              <td>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
                  <select name="status" class="auto-submit">
                    <?php foreach ($statusLabels as $val => $label): ?>
                      <option value="<?= $val ?>" <?= $e['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td style="white-space:nowrap">
                <a href="/event.php?slug=<?= urlencode($e['slug']) ?>">View</a>
                &middot;
                <a href="/event-edit.php?id=<?= (int) $e['id'] ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
