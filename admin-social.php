<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Platform-wide moderation of the event communities: every open report, content
// that was hidden or deleted (restorable), and a log of who did what. Organizers
// moderate their own events; admins can overrule any decision here.
$admin = require_admin_permission('events.manage');

$messages = ['hidden' => 'Hidden.', 'deleted' => 'Deleted.', 'restored' => 'Restored.', 'dismissed' => 'Reports closed. The content stays up.'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $type = ($_POST['type'] ?? '') === 'COMMENT' ? 'COMMENT' : 'POST';
    $id = (int) ($_POST['id'] ?? 0);
    $done = null;
    if (in_array($action, ['hide', 'delete', 'restore'], true)) {
        $result = moderate_content($admin, $type, $id, strtoupper($action));
        if ($result['ok']) {
            log_admin_action((int) $admin['id'], 'social.' . $action, strtolower($type), $id);
            $done = $action === 'hide' ? 'hidden' : ($action === 'delete' ? 'deleted' : 'restored');
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'dismiss') {
        $result = dismiss_reports($admin, $type, $id);
        if ($result['ok']) {
            log_admin_action((int) $admin['id'], 'social.dismiss', strtolower($type), $id);
            $done = 'dismissed';
        } else {
            $error = $result['error'];
        }
    }
    if ($done) {
        header('Location: /admin-social.php?done=' . $done);
        exit;
    }
}

$ready = true;
try {
    $totals = get_community_admin_totals();
    $reports = get_all_open_reports();
    $removed = get_removed_content_all();
    $actions = get_recent_moderation_actions();
} catch (Throwable $e) {
    // migration 014 not run yet
    error_log('[community] admin page: ' . $e->getMessage());
    $ready = false;
}

$pageTitle = 'Social moderation';
render_admin_head('social');

/** A small POST button. */
function admin_social_btn(string $action, string $label, string $type, int $id, string $kind = ''): void
{
    $confirm = $action === 'delete' ? ' data-confirm="Delete this ' . strtolower($type) . ' for good?" data-danger' : '';
    ?>
    <form method="post" style="display:inline"<?= $confirm ?>>
      <?= csrf_field() ?><input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="social-btn <?= $kind ?>"><?= $label ?></button>
    </form>
    <?php
}
?>

<div class="admin-page-head">
  <div><h1>Social moderation</h1><p>Reported posts and comments across every event community.</p></div>
</div>

<?php if (isset($_GET['done'], $messages[$_GET['done']])): ?><span data-flash="<?= htmlspecialchars($messages[$_GET['done']], ENT_QUOTES) ?>" hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$ready): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-shield"/></svg></div>
    <h3>Event communities aren't set up yet</h3>
    <p>Run <code>migration/014_event_community.sql</code> in phpMyAdmin to switch them on.</p>
  </div>
<?php else: ?>

<div class="admin-mini-stat-row">
  <div class="admin-mini-stat"><span class="n"<?= $totals['open_reports'] ? ' style="color:var(--danger)"' : '' ?>><?= $totals['open_reports'] ?></span><span class="l">Reports waiting</span></div>
  <div class="admin-mini-stat"><span class="n"><?= number_format($totals['posts']) ?></span><span class="l">Posts</span></div>
  <div class="admin-mini-stat"><span class="n"><?= number_format($totals['comments']) ?></span><span class="l">Comments</span></div>
  <div class="admin-mini-stat"><span class="n"><?= number_format($totals['reactions']) ?></span><span class="l">Reactions</span></div>
  <div class="admin-mini-stat"><span class="n"><?= number_format($totals['event_follows'] + $totals['organizer_follows']) ?></span><span class="l">Follows</span></div>
</div>

<div class="admin-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:6px">Reports waiting</h3>
  <?php if (!$reports): ?>
    <div class="muted" style="padding:18px 0; text-align:center">No open reports. All quiet.</div>
  <?php endif; ?>
  <?php foreach ($reports as $r): $isPost = $r['content_type'] === 'POST'; ?>
    <div class="social-report">
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
        <b><?= $isPost ? 'Post' : 'Comment' ?> by <?= $r['author_id'] ? '<a class="link" href="/admin-customer-detail.php?id=' . (int) $r['author_id'] . '">' . htmlspecialchars($r['author_name']) . '</a>' : 'a removed user' ?></b>
        <?php foreach ($r['reasons'] as $reason): ?><span class="admin-badge admin-badge-muted"><?= htmlspecialchars(REPORT_REASONS[$reason] ?? $reason) ?></span><?php endforeach; ?>
        <?php if ($r['content_status'] !== 'PUBLISHED'): ?><span class="admin-badge admin-badge-danger"><?= htmlspecialchars(ucfirst(strtolower($r['content_status']))) ?></span><?php endif; ?>
        <span class="muted" style="font-size:0.82rem"><?= (int) $r['report_count'] ?> report<?= (int) $r['report_count'] === 1 ? '' : 's' ?> &middot; <?= htmlspecialchars(date('j M, H:i', strtotime($r['last_reported']))) ?> &middot; <a class="link" href="/event.php?slug=<?= urlencode($r['event_slug']) ?>#community" target="_blank" rel="noopener"><?= htmlspecialchars($r['event_title']) ?></a></span>
      </div>
      <div class="social-quote"><?= $r['snippet'] !== '' ? nl2br(htmlspecialchars($r['snippet'])) : '<span class="muted">(photo only)</span>' ?></div>
      <div class="social-actions">
        <?php admin_social_btn('hide', 'Hide', $r['content_type'], (int) $r['content_id']); ?>
        <?php admin_social_btn('delete', 'Delete', $r['content_type'], (int) $r['content_id'], 'danger'); ?>
        <?php admin_social_btn('dismiss', 'Keep it', $r['content_type'], (int) $r['content_id'], 'plain'); ?>
        <?php if ($r['author_id']): ?><a class="social-btn plain" style="text-decoration:none" href="/admin-customer-detail.php?id=<?= (int) $r['author_id'] ?>">View account</a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="admin-grid-2">
  <div class="admin-card">
    <h3 style="margin-bottom:6px">Hidden or deleted</h3>
    <p class="muted" style="font-size:0.86rem; margin:0 0 10px">Restore anything that was removed by mistake.</p>
    <?php if (!$removed): ?><div class="muted" style="padding:14px 0; text-align:center">Nothing has been removed.</div><?php endif; ?>
    <?php foreach ($removed as $x): ?>
      <div class="social-hidden">
        <div style="flex:1; min-width:0">
          <b><?= $x['content_type'] === 'POST' ? 'Post' : 'Comment' ?> by <?= htmlspecialchars(community_display_name($x['author_name'])) ?></b>
          <span class="admin-badge <?= $x['status'] === 'DELETED' ? 'admin-badge-danger' : 'admin-badge-muted' ?>"><?= htmlspecialchars(ucfirst(strtolower($x['status']))) ?></span>
          <div class="muted" style="font-size:0.84rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= htmlspecialchars($x['body'] !== null && $x['body'] !== '' ? mb_substr($x['body'], 0, 140) : '(photo only)') ?> &middot; <?= htmlspecialchars($x['event_title']) ?></div>
        </div>
        <?php admin_social_btn('restore', 'Restore', $x['content_type'], (int) $x['content_id'], 'plain'); ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:10px">Recent moderation</h3>
    <?php if (!$actions): ?><div class="muted" style="padding:14px 0; text-align:center">No actions yet.</div><?php endif; ?>
    <?php foreach ($actions as $a): ?>
      <div style="padding:9px 0; border-top:1px solid var(--line); font-size:0.88rem">
        <b><?= htmlspecialchars(ucfirst(strtolower($a['action']))) ?></b> a <?= strtolower($a['content_type']) ?>
        <span class="muted">by <?= htmlspecialchars($a['actor_name'] ? community_display_name($a['actor_name']) : 'a removed user') ?> (<?= htmlspecialchars(strtolower($a['actor_role'])) ?>) &middot; <?= htmlspecialchars($a['event_title']) ?> &middot; <?= htmlspecialchars(date('j M, H:i', strtotime($a['created_at']))) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php endif; ?>

<?php render_admin_foot(); ?>
