<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('dashboard.view');
$actor = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    verify_csrf();
    mark_all_notifications_read((int) $actor['id']);
    header('Location: /org-notifications.php');
    exit;
}
if (isset($_GET['read'])) {
    mark_notification_read((int) $actor['id'], (int) $_GET['read']);
    header('Location: /org-notifications.php');
    exit;
}

$notifications = get_notifications((int) $actor['id'], 50);
$unread = count_unread_notifications((int) $actor['id']);

$typeIcons = ['event' => 'ic-cal', 'payout' => 'ic-cash', 'refund' => 'ic-x', 'ticket' => 'ic-ticket', 'support' => 'ic-mail', 'team' => 'ic-people'];

$pageTitle = 'Notifications';
render_organizer_head('dashboard', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Notifications</h1><p><?= $unread ?> unread</p></div>
  <?php if ($unread > 0): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all_read"><button class="btn btn-line" type="submit">Mark all as read</button></form>
  <?php endif; ?>
</div>

<?php if (!$notifications): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-bell"/></svg></div>
    <h3>No notifications yet</h3>
    <p>Updates about your events, orders and payouts will show up here.</p>
  </div>
<?php else: ?>
  <div style="display:flex; flex-direction:column; gap:2px;">
    <?php foreach ($notifications as $n): $icon = $typeIcons[$n['type']] ?? 'ic-info'; ?>
      <a href="<?= $n['read_at'] ? '#' : '/org-notifications.php?read=' . (int) $n['id'] ?>" class="admin-activity-row" style="text-decoration:none; padding:14px; border-radius:10px; <?= $n['read_at'] ? '' : 'background:var(--purple-tint)' ?>">
        <span class="admin-activity-ic"><svg width="15" height="15"><use href="#<?= $icon ?>"/></svg></span>
        <div class="admin-activity-body">
          <span class="who"><?= htmlspecialchars($n['title']) ?></span>
          <?php if ($n['body']): ?><br><span class="muted" style="font-size:0.82rem"><?= htmlspecialchars($n['body']) ?></span><?php endif; ?>
        </div>
        <span class="admin-activity-time"><?= htmlspecialchars(date('d M, H:i', strtotime($n['created_at']))) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_organizer_foot(); ?>
