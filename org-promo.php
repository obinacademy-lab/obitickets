<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('promo.view');
$organizerId = $ctx['organizer_id'];
$actorId = $ctx['actor_id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!organizer_can($ctx, 'promo.manage')) {
        http_response_code(403);
        exit('You do not have permission to manage promo codes.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        [$ok, $err] = create_promo_code_for_organizer($organizerId, $actorId, $_POST);
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /org-promo.php?updated=1');
            exit;
        }
    } elseif ($action === 'toggle') {
        set_promo_code_active_for_organizer($organizerId, $actorId, (int) $_POST['id'], !empty($_POST['active']));
        header('Location: /org-promo.php?updated=1');
        exit;
    } elseif ($action === 'delete') {
        delete_promo_code_for_organizer($organizerId, $actorId, (int) $_POST['id']);
        header('Location: /org-promo.php?updated=1');
        exit;
    }
}

$allPromos = get_promo_codes_for_organizer($organizerId);
$events = array_values(array_filter(get_events_for_organizer($organizerId), static fn ($e) => in_array($e['status'], ['PUBLISHED', 'DRAFT'], true)));

$statusCounts = ['ACTIVE' => 0, 'INACTIVE' => 0, 'EXPIRED' => 0];
foreach ($allPromos as $p) {
    $expired = $p['ends_at'] && strtotime($p['ends_at']) < time();
    $statusCounts[$expired ? 'EXPIRED' : ($p['active'] ? 'ACTIVE' : 'INACTIVE')]++;
}
$statusFilter = $_GET['status'] ?? '';
$promos = array_values(array_filter($allPromos, static function ($p) use ($statusFilter) {
    if ($statusFilter === '') {
        return true;
    }
    $expired = $p['ends_at'] && strtotime($p['ends_at']) < time();
    return ($expired ? 'EXPIRED' : ($p['active'] ? 'ACTIVE' : 'INACTIVE')) === $statusFilter;
}));

$pageTitle = 'Promo Codes';
render_organizer_head('promo', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Promo codes</h1><p><?= count($allPromos) ?> total</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $statusFilter === '' ? ' active' : '' ?>" href="/org-promo.php"><span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span></a>
  <a class="admin-mini-stat<?= $statusFilter === 'ACTIVE' ? ' active' : '' ?>" href="/org-promo.php?status=ACTIVE"><span class="n"><?= $statusCounts['ACTIVE'] ?></span><span class="l">Active</span></a>
  <a class="admin-mini-stat<?= $statusFilter === 'INACTIVE' ? ' active' : '' ?>" href="/org-promo.php?status=INACTIVE"><span class="n"><?= $statusCounts['INACTIVE'] ?></span><span class="l">Inactive</span></a>
  <a class="admin-mini-stat<?= $statusFilter === 'EXPIRED' ? ' active' : '' ?>" href="/org-promo.php?status=EXPIRED"><span class="n"><?= $statusCounts['EXPIRED'] ?></span><span class="l">Expired</span></a>
</div>

<div class="admin-grid-2">
  <div>
    <?php if (!$promos): ?>
      <div class="admin-empty">
        <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-tag"/></svg></div>
        <h3>No promo codes<?= $statusFilter !== '' ? ' in this view' : ' yet' ?></h3>
        <p><?= $statusFilter !== '' ? 'Try a different filter, or clear it.' : 'Create one to offer a discount on one of your events.' ?></p>
        <?php if ($statusFilter !== ''): ?><a class="btn btn-line" href="/org-promo.php">Clear filter</a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Code</th><th>Discount</th><th>Event</th><th>Usage</th><th>Expires</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($promos as $p): $expired = $p['ends_at'] && strtotime($p['ends_at']) < time(); ?>
              <tr>
                <td class="mono" style="font-weight:700"><?= htmlspecialchars($p['code']) ?></td>
                <td class="mono"><?= $p['discount_type'] === 'PERCENT' ? number_format((float) $p['discount_value'], 0) . '%' : 'UGX ' . number_format((float) $p['discount_value'], 0) ?></td>
                <td class="muted"><?= htmlspecialchars($p['event_title']) ?></td>
                <td class="mono"><?= (int) $p['used_count'] ?><?= $p['max_uses'] ? ' / ' . (int) $p['max_uses'] : '' ?></td>
                <td class="muted mono"><?= $p['ends_at'] ? htmlspecialchars(date('d M Y', strtotime($p['ends_at']))) : '—' ?></td>
                <td>
                  <?php if ($expired): ?><span class="admin-badge admin-badge-muted">Expired</span>
                  <?php else: ?><span class="admin-badge <?= $p['active'] ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $p['active'] ? 'Active' : 'Inactive' ?></span><?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="active" value="<?= $p['active'] ? '' : '1' ?>">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--purple); cursor:pointer; padding:0; font-weight:700"><?= $p['active'] ? 'Deactivate' : 'Activate' ?></button></form>
                  &middot;
                  <form method="post" style="display:inline" data-confirm="Delete this promo code?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if (organizer_can($ctx, 'promo.manage')): ?>
    <div class="admin-card">
      <h3 style="margin-bottom:14px">New promo code</h3>
      <?php if (!$events): ?>
        <p class="muted" style="font-size:0.86rem">Create an event first — promo codes must apply to one of your own events.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="create">
          <div class="admin-form-row"><label>Code</label><input type="text" name="code" required placeholder="e.g. LAUNCH20" style="text-transform:uppercase"></div>
          <div class="admin-form-grid">
            <div class="admin-form-row"><label>Type</label><select name="discount_type"><option value="PERCENT">Percent off</option><option value="FIXED">Fixed amount (UGX)</option></select></div>
            <div class="admin-form-row"><label>Value</label><input type="number" name="discount_value" min="1" step="1" required></div>
          </div>
          <div class="admin-form-row"><label>Applies to</label>
            <select name="event_id" required><?php foreach ($events as $e): ?><option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['title']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="admin-form-grid">
            <div class="admin-form-row"><label>Max uses (optional)</label><input type="number" name="max_uses" min="1" step="1" placeholder="Unlimited"></div>
            <div class="admin-form-row"><label>Min order (optional)</label><input type="number" name="min_order_amount" min="0" step="1" placeholder="None"></div>
          </div>
          <div class="admin-form-grid">
            <div class="admin-form-row"><label>Starts</label><input type="date" name="starts_at"></div>
            <div class="admin-form-row"><label>Ends</label><input type="date" name="ends_at"></div>
          </div>
          <button class="btn btn-purple btn-block" type="submit">Create code</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php render_organizer_foot(); ?>
