<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/past-events.php';

$admin = require_admin_permission('events.view');
$eventId = (int) ($_GET['id'] ?? 0);
$event = get_event_admin_detail($eventId);
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!admin_can('events.manage')) {
        http_response_code(403);
        exit('You do not have permission to change events.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'status') {
        update_event_status_admin((int) $admin['id'], $eventId, (string) ($_POST['status'] ?? ''), $_POST['reason'] ?? null);
    } elseif ($action === 'feature') {
        set_event_featured((int) $admin['id'], $eventId, !empty($_POST['featured']), $_POST['featured_from'] ?? null, $_POST['featured_until'] ?? null);
    } elseif ($action === 'past_settings') {
        $pastErrors = admin_save_past_settings((int) $admin['id'], $eventId, $_POST);
    } elseif ($action === 'past_complete') {
        admin_mark_event_completed((int) $admin['id'], $eventId);
    } elseif ($action === 'past_poster') {
        [$okUp, $newPoster, $upErr] = handle_event_banner_upload($_FILES['banner_image'] ?? []);
        if (!$okUp) {
            $pastErrors = [$upErr];
        } elseif ($newPoster) {
            db()->prepare('UPDATE events SET banner_image = ? WHERE id = ?')->execute([$newPoster, $eventId]);
            delete_event_banner($event['banner_image'] ?? null);
            log_admin_action((int) $admin['id'], 'event.past_poster', 'event', $eventId);
        }
    } elseif ($action === 'past_media_add') {
        [$items, $mediaErrors] = handle_event_media_uploads($_FILES['gallery'] ?? ['name' => []], count_event_media_by_type($eventId));
        add_event_media($eventId, $items);
        if ($items) {
            log_admin_action((int) $admin['id'], 'event.past_media_add', 'event', $eventId, ['files' => count($items)]);
        }
        $pastErrors = $mediaErrors;
    } elseif ($action === 'past_media_delete') {
        $deleted = delete_event_media((int) ($_POST['media_id'] ?? 0), $eventId);
        if ($deleted !== null) {
            delete_event_media_file($deleted);
            log_admin_action((int) $admin['id'], 'event.past_media_delete', 'event', $eventId);
        }
    }
    $back = '/admin-event-detail.php?id=' . $eventId . (empty($pastErrors) ? '&updated=1' : '&perr=' . rawurlencode(mb_substr(implode(' ', $pastErrors), 0, 240)));
    header('Location: ' . $back . '#past');
    exit;
}

$statusMeta = [
    'DRAFT' => ['Draft', 'admin-badge-muted'], 'PENDING_REVIEW' => ['Pending review', 'admin-badge-warn'],
    'PUBLISHED' => ['Published', 'admin-badge-success'], 'SUSPENDED' => ['Suspended', 'admin-badge-danger'],
    'REJECTED' => ['Rejected', 'admin-badge-danger'], 'CANCELLED' => ['Cancelled', 'admin-badge-muted'],
];
$meta = $statusMeta[$event['status']] ?? ['Unknown', 'admin-badge-muted'];
$fin = $event['financials'];
$netOrganizer = (float) $fin['gross'] - (float) $fin['commission'] - (float) $fin['refunded'];
$attTotal = (int) $event['attendance']['total'];
$attChecked = (int) $event['attendance']['checked_in'];

$actionLabels = [
    'event.status_change' => 'changed status', 'event.feature' => 'featured this event', 'event.unfeature' => 'unfeatured this event',
    'event.past_settings' => 'updated the Past Events settings', 'event.mark_completed' => 'marked the event completed', 'event.past_poster' => 'changed the poster',
    'event.past_media_add' => 'added photos or videos', 'event.past_media_delete' => 'removed a photo or video',
];

$pageTitle = $event['title'];
render_admin_head('events');
?>

<div class="admin-page-head">
  <div>
    <a class="link" href="/admin-events.php" style="font-size:0.82rem; font-weight:700">&larr; All events</a>
    <h1 style="margin-top:8px"><?= htmlspecialchars($event['banner_emoji']) ?> <?= htmlspecialchars($event['title']) ?></h1>
    <p>by <?= htmlspecialchars($event['organizer_name']) ?> (<?= htmlspecialchars($event['organizer_email']) ?>) &middot; <?= htmlspecialchars($event['category']) ?> &middot; <?= htmlspecialchars(date('d M Y, H:i', strtotime($event['starts_at']))) ?></p>
  </div>
  <span class="admin-badge <?= $meta[1] ?>" style="font-size:0.82rem"><?= htmlspecialchars($meta[0]) ?></span>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if (isset($_GET['perr'])): ?><span data-flash="<?= htmlspecialchars((string) $_GET['perr'], ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>
<?php if ($event['status'] === 'REJECTED' && $event['rejection_reason']): ?>
  <div class="alert alert-error" style="margin-bottom:18px">Rejected: <?= htmlspecialchars($event['rejection_reason']) ?></div>
<?php endif; ?>

<div class="admin-grid-2">
  <div style="display:flex; flex-direction:column; gap:20px;">

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Ticket sales</h3>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>Tier</th><th>Price</th><th>Sold</th><th>Remaining</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($event['ticket_types'] as $tt): $remaining = (int) $tt['quantity_total'] - (int) $tt['quantity_sold']; ?>
              <tr>
                <td><?= htmlspecialchars($tt['name']) ?></td>
                <td class="mono"><?= htmlspecialchars($tt['currency']) ?> <?= number_format((float) $tt['price'], 0) ?></td>
                <td class="mono"><?= (int) $tt['quantity_sold'] ?> / <?= (int) $tt['quantity_total'] ?></td>
                <td class="mono"><?= $remaining ?></td>
                <td><?php if ($remaining <= 0): ?><span class="admin-badge admin-badge-muted">Sold out</span><?php else: ?><span class="admin-badge admin-badge-success">On sale</span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$event['ticket_types']): ?><tr><td colspan="5" class="muted" style="text-align:center">No ticket types configured yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:4px">Financials</h3>
      <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:12px">From <?= (int) $fin['paid_orders'] ?> paid order<?= (int) $fin['paid_orders'] === 1 ? '' : 's' ?></p>
      <div class="admin-detail-row"><span class="k">Gross ticket sales</span><span class="v mono">UGX <?= number_format((float) $fin['gross'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Platform commission</span><span class="v mono">&minus; UGX <?= number_format((float) $fin['commission'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Refunded</span><span class="v mono">&minus; UGX <?= number_format((float) $fin['refunded'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Organizer earnings (net)</span><span class="v mono" style="color:var(--purple)">UGX <?= number_format($netOrganizer, 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Buyer service fees collected</span><span class="v mono">UGX <?= number_format((float) $fin['fees'], 0) ?></span></div>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Attendance</h3>
      <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px;">
        <div class="admin-stat-mini"><span class="num"><?= $attTotal ?></span><span class="lbl">Tickets sold</span></div>
        <div class="admin-stat-mini"><span class="num"><?= $attChecked ?></span><span class="lbl">Checked in</span></div>
        <div class="admin-stat-mini"><span class="num"><?= $attTotal > 0 ? round($attChecked / $attTotal * 100) : 0 ?>%</span><span class="lbl">Attendance rate</span></div>
      </div>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Activity</h3>
      <?php if (!$event['activity']): ?>
        <p class="muted" style="font-size:0.86rem">No admin actions logged for this event yet.</p>
      <?php else: ?>
        <div class="admin-activity">
          <?php foreach ($event['activity'] as $a): ?>
            <div class="admin-activity-row">
              <span class="admin-activity-ic"><svg width="15" height="15"><use href="#ic-clock"/></svg></span>
              <div class="admin-activity-body"><?= htmlspecialchars($actionLabels[$a['action']] ?? $a['action']) ?></div>
              <span class="admin-activity-time"><?= htmlspecialchars(date('d M, H:i', strtotime($a['created_at']))) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex; flex-direction:column; gap:20px;">
    <?php if (admin_can('events.manage')): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:14px">Change status</h3>
        <form method="post" data-confirm="Change this event's status?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <div class="admin-form-row">
            <select name="status">
              <?php foreach ($statusMeta as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $event['status'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl[0]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-row">
            <label>Rejection reason (if rejecting)</label>
            <input type="text" name="reason" value="<?= htmlspecialchars($event['rejection_reason'] ?? '') ?>" placeholder="Shown to the organizer">
          </div>
          <button class="btn btn-purple btn-block" type="submit">Save status</button>
        </form>
      </div>

      <div class="admin-card">
        <h3 style="margin-bottom:4px">Homepage feature</h3>
        <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:14px">Featured, published events appear in the homepage's featured section.</p>
        <?php if ($event['featured']): ?>
          <form method="post" data-confirm="Remove this event from featured?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="feature">
            <input type="hidden" name="featured" value="">
            <button class="btn btn-line btn-block" type="submit">Unfeature</button>
          </form>
        <?php else: ?>
          <form method="post" data-confirm="Feature this event on the homepage?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="feature">
            <input type="hidden" name="featured" value="1">
            <div class="admin-form-grid" style="margin-bottom:14px">
              <div class="admin-form-row" style="margin-bottom:0"><label>From (optional)</label><input type="date" name="featured_from"></div>
              <div class="admin-form-row" style="margin-bottom:0"><label>Until (optional)</label><input type="date" name="featured_until"></div>
            </div>
            <button class="btn btn-purple btn-block" type="submit">Feature this event</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="admin-card" id="past">
        <h3 style="margin-bottom:4px">Past Events page</h3>
        <?php
        $pastReady = past_events_ready();
        $finished = strtotime($event['ends_at']) < time() || ($pastReady && !empty($event['completed_manually_at']));
        $pastMedia = get_event_media($eventId);
        ?>
        <?php if (!$pastReady): ?>
          <p style="color:var(--muted-2); font-size:0.86rem">Run <b>migration/024_past_events.sql</b> in phpMyAdmin to turn on these controls.</p>
        <?php else: ?>
          <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:14px">
            <?php if ($event['status'] !== 'PUBLISHED'): ?>Only published events can appear on the Past Events page.
            <?php elseif (!$finished): ?>This event has not finished yet. It appears on the Past Events page by itself after <?= htmlspecialchars(date('d M Y, H:i', strtotime($event['ends_at']))) ?>.
            <?php elseif ((int) $event['past_hidden'] === 1): ?>This event has finished but is <b>hidden</b> from the Past Events page.
            <?php else: ?>This event has finished and is <b>shown</b> on the Past Events page<?= (int) $event['past_featured'] === 1 ? ' (featured)' : '' ?>. <a class="link" href="/past-event.php?slug=<?= urlencode($event['slug']) ?>" target="_blank" rel="noopener">View it</a>.
            <?php endif; ?>
          </p>

          <?php if ($event['status'] === 'PUBLISHED' && !$finished): ?>
            <form method="post" data-confirm="Mark this event as completed? It ends now and ticket sales stop.">
              <?= csrf_field() ?><input type="hidden" name="action" value="past_complete">
              <button class="btn btn-line btn-block" type="submit" style="margin-bottom:16px">Mark as completed now</button>
            </form>
          <?php endif; ?>

          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="past_settings">
            <label style="display:flex; gap:10px; align-items:center; margin-bottom:10px; font-size:0.9rem"><input type="checkbox" name="public" value="1" <?= (int) $event['past_hidden'] === 0 ? 'checked' : '' ?>> Show on the public Past Events page</label>
            <label style="display:flex; gap:10px; align-items:center; margin-bottom:16px; font-size:0.9rem"><input type="checkbox" name="featured" value="1" <?= (int) $event['past_featured'] === 1 ? 'checked' : '' ?>> Feature it at the top of the page</label>
            <div class="admin-form-row"><label for="pastDesc">Event description</label><textarea id="pastDesc" name="description" rows="6"><?= htmlspecialchars((string) $event['description']) ?></textarea></div>
            <div class="admin-form-row"><label for="pastHl">Event highlights</label><textarea id="pastHl" name="highlights" rows="5" placeholder="One highlight per line"><?= htmlspecialchars((string) ($event['highlights'] ?? '')) ?></textarea><div class="hint">One per line. Only real highlights, they appear on the event's page.</div></div>
            <button class="btn btn-purple btn-block" type="submit">Save Past Events settings</button>
          </form>

          <hr style="border:0; border-top:1px solid var(--line); margin:20px 0">
          <h4 style="margin-bottom:8px">Poster</h4>
          <?php if (!empty($event['banner_image'])): ?><img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="" style="max-width:100%; max-height:150px; border-radius:10px; display:block; margin-bottom:10px"><?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="past_poster">
            <div class="admin-form-row"><input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required><div class="hint">JPG, PNG or WebP, up to 5MB. Replaces the current poster.</div></div>
            <button class="btn btn-line btn-block" type="submit">Upload poster</button>
          </form>

          <hr style="border:0; border-top:1px solid var(--line); margin:20px 0">
          <h4 style="margin-bottom:8px">Photos and videos <span class="muted" style="font-weight:500">(<?= count($pastMedia) ?>)</span></h4>
          <?php if ($pastMedia): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(92px,1fr)); gap:8px; margin-bottom:14px">
              <?php foreach ($pastMedia as $m): ?>
                <form method="post" data-confirm="Remove this file from the event?" style="position:relative; margin:0">
                  <?= csrf_field() ?><input type="hidden" name="action" value="past_media_delete"><input type="hidden" name="media_id" value="<?= (int) $m['id'] ?>">
                  <?php if ($m['media_type'] === 'VIDEO'): ?><video src="<?= htmlspecialchars($m['file_path']) ?>" muted preload="metadata" style="width:100%; aspect-ratio:1; object-fit:cover; border-radius:8px; display:block"></video>
                  <?php else: ?><img src="<?= htmlspecialchars($m['file_path']) ?>" alt="" style="width:100%; aspect-ratio:1; object-fit:cover; border-radius:8px; display:block"><?php endif; ?>
                  <button type="submit" aria-label="Remove" style="position:absolute; top:4px; right:4px; width:24px; height:24px; border:0; border-radius:50%; background:rgba(0,0,0,.7); color:#fff; cursor:pointer; line-height:1">&times;</button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="past_media_add">
            <div class="admin-form-row"><input type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" multiple required><div class="hint">Add as many as you like at once. Photos up to 5MB each.</div></div>
            <button class="btn btn-line btn-block" type="submit">Add photos or videos</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Links</h3>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <a class="btn btn-line btn-block" href="/event.php?slug=<?= urlencode($event['slug']) ?>" target="_blank" rel="noopener">View public page</a>
        <a class="btn btn-line btn-block" href="/event-edit.php?id=<?= (int) $event['id'] ?>">Edit event details</a>
        <a class="btn btn-line btn-block" href="/admin-organizer-detail.php?id=<?= (int) $event['organizer_user_id'] ?>">View organizer</a>
      </div>
    </div>
  </div>
</div>

<?php render_admin_foot(); ?>
