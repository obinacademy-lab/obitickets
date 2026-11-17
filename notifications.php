<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Notifications for every signed-in person (the bell on the home feed). Uses the same notifications table and
// helpers the organizer dashboard already had (includes/organizer.php); organizers keep their dashboard page too.
$user = require_login();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    verify_csrf();
    mark_all_notifications_read($uid);
    header('Location: /notifications.php');
    exit;
}
// Opening one marks it read, then goes where it points (only to pages on this site).
if (isset($_GET['open'])) {
    $openId = (int) $_GET['open'];
    $go = '/notifications.php';
    foreach (get_notifications($uid, 100) as $n) {
        if ((int) $n['id'] === $openId) {
            mark_notification_read($uid, $openId);
            $link = (string) ($n['link'] ?? '');
            if ($link !== '' && $link[0] === '/' && !str_starts_with($link, '//') && !str_contains($link, '\\')) {
                $go = $link;
            }
            break;
        }
    }
    header('Location: ' . $go);
    exit;
}

$notifications = get_notifications($uid, 60);
$unread = count_unread_notifications($uid);
$typeIcons = ['event' => 'ic-cal', 'payout' => 'ic-cash', 'refund' => 'ic-x', 'ticket' => 'ic-ticket', 'support' => 'ic-mail', 'team' => 'ic-people', 'follow' => 'ic-user', 'like' => 'ic-heart', 'comment' => 'ic-mail'];

/** "2m ago", "3h ago", "Yesterday", "12 Nov". */
$ago = static function (string $at): string {
    $d = time() - strtotime($at);
    if ($d < 60) { return 'Just now'; }
    if ($d < 3600) { return floor($d / 60) . 'm ago'; }
    if ($d < 86400) { return floor($d / 3600) . 'h ago'; }
    if ($d < 172800) { return 'Yesterday'; }
    return date('j M', strtotime($at));
};

$pageTitle = 'Notifications — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<section class="rc-head lift">
  <div class="wrap">
    <h1>Notifications</h1>
    <p><?= $unread === 0 ? 'You are all caught up' : $unread . ' unread' ?></p>
  </div>
</section>

<div class="wrap rc-tk">
  <?php if (!$notifications): ?>
    <div class="rc-empty">
      <i><svg width="28" height="28" aria-hidden="true"><use href="#ic-bell"/></svg></i>
      <h2>No notifications yet</h2>
      <p>Updates from organizers you follow, your tickets and event reminders will show up here.</p>
      <a class="btn btn-purple" href="/">Watch event videos</a>
    </div>
  <?php else: ?>
    <?php if ($unread > 0): ?>
      <form method="post" class="nt-all"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all_read"><button class="rc-mini" type="submit">Mark all as read</button></form>
    <?php endif; ?>
    <div class="rc-rows">
      <?php foreach ($notifications as $n): $isNew = empty($n['read_at']); ?>
      <a class="rc-row nt-row<?= $isNew ? ' is-new' : '' ?>" href="/notifications.php?open=<?= (int) $n['id'] ?>">
        <span class="rc-date nt-ic"><svg width="20" height="20" aria-hidden="true"><use href="#<?= htmlspecialchars($typeIcons[$n['type']] ?? 'ic-bell') ?>"/></svg></span>
        <span class="rc-row-tx">
          <b><?= htmlspecialchars((string) $n['title']) ?><?php if ($isNew): ?> <span class="sr-only">(unread)</span><?php endif; ?></b>
          <?php if (!empty($n['body'])): ?><span><?= htmlspecialchars((string) $n['body']) ?></span><?php endif; ?>
          <span class="nt-when"><?= htmlspecialchars($ago((string) $n['created_at'])) ?></span>
        </span>
        <?php if ($isNew): ?><i class="nt-dot" aria-hidden="true"></i><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<style>
  .nt-all{display:flex; justify-content:flex-end; margin:0 0 4px}
  .nt-row .nt-ic{display:grid; place-items:center; padding:0; height:58px}
  .nt-row .nt-when{font-size:.76rem; color:#8A7F7F}
  .nt-row.is-new{background:#FDECEC}
  .nt-dot{flex:none; width:10px; height:10px; border-radius:50%; background:#DC2626; margin-right:6px}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
