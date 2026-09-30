<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Reviews are event content, so this reuses the existing events.manage
// permission rather than introducing a new one.
$admin = require_admin_permission('events.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        delete_event_review((int) $admin['id'], (int) ($_POST['id'] ?? 0));
    }
    header('Location: /admin-reviews.php?updated=1');
    exit;
}

$reviews = get_all_reviews_admin();

$pageTitle = 'Reviews';
render_admin_head('reviews');
?>

<div class="admin-page-head">
  <div><h1>Reviews</h1><p><?= count($reviews) ?> shown &middot; newest first. Remove anything abusive or off-topic.</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Review removed." hidden></span><?php endif; ?>

<?php if (!$reviews): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-heart"/></svg></div>
    <h3>No reviews yet</h3>
    <p>Reviews appear here once checked-in attendees start leaving them after an event.</p>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Event</th><th>Reviewer</th><th>Rating</th><th>Comment</th><th>Posted</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($reviews as $r): ?>
          <tr>
            <td><a class="link" href="/event.php?slug=<?= urlencode($r['event_slug']) ?>#reviews" target="_blank" rel="noopener"><?= htmlspecialchars($r['event_title']) ?></a></td>
            <td><?= htmlspecialchars($r['reviewer_name']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($r['reviewer_email']) ?></span></td>
            <td class="mono"><?= (int) $r['rating'] ?>/5</td>
            <td style="max-width:320px"><?= $r['comment'] ? nl2br(htmlspecialchars($r['comment'])) : '<span class="muted">&mdash;</span>' ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($r['created_at']))) ?></td>
            <td>
              <form method="post" style="display:inline" data-confirm="Remove this review? This can't be undone." data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
