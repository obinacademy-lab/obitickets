<?php
require_once __DIR__ . '/includes/bootstrap.php';

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
    }
    header('Location: /admin-event-detail.php?id=' . $eventId . '&updated=1');
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

<?php if (isset($_GET['updated'])): ?><div class="alert alert-success" style="margin-bottom:18px">Saved.</div><?php endif; ?>
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
        <form method="post" onsubmit="return confirm('Change this event\'s status?');">
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
          <form method="post" onsubmit="return confirm('Remove this event from featured?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="feature">
            <input type="hidden" name="featured" value="">
            <button class="btn btn-line btn-block" type="submit">Unfeature</button>
          </form>
        <?php else: ?>
          <form method="post" onsubmit="return confirm('Feature this event on the homepage?');">
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
