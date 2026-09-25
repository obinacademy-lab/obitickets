<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('promo.view');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!admin_can('promo.manage')) {
        http_response_code(403);
        exit('You do not have permission to manage promo codes.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        [$ok, $err] = create_promo_code((int) $admin['id'], $_POST);
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /admin-promo.php?updated=1');
            exit;
        }
    } elseif ($action === 'toggle') {
        set_promo_code_active((int) $admin['id'], (int) $_POST['id'], !empty($_POST['active']));
        header('Location: /admin-promo.php?updated=1');
        exit;
    } elseif ($action === 'delete') {
        delete_promo_code((int) $admin['id'], (int) $_POST['id']);
        header('Location: /admin-promo.php?updated=1');
        exit;
    }
}

$promos = get_promo_codes_admin();
$events = db()->query("SELECT id, title FROM events WHERE status IN ('PUBLISHED','PENDING_REVIEW','DRAFT') ORDER BY title")->fetchAll();

$pageTitle = 'Promo Codes';
render_admin_head('promo');
?>

<div class="admin-page-head">
  <div><h1>Promo codes</h1><p><?= count($promos) ?> total</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><div class="alert alert-success" style="margin-bottom:18px">Saved.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error" style="margin-bottom:18px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="admin-grid-2">
  <div>
    <?php if (!$promos): ?>
      <div class="admin-empty"><svg width="40" height="40"><use href="#ic-tag"/></svg><h3>No promo codes yet</h3><p>Create one to offer a discount platform-wide or on a specific event.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Code</th><th>Discount</th><th>Scope</th><th>Usage</th><th>Expires</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($promos as $p): $expired = $p['ends_at'] && strtotime($p['ends_at']) < time(); ?>
              <tr>
                <td class="mono" style="font-weight:700"><?= htmlspecialchars($p['code']) ?></td>
                <td class="mono"><?= $p['discount_type'] === 'PERCENT' ? number_format((float) $p['discount_value'], 0) . '%' : 'UGX ' . number_format((float) $p['discount_value'], 0) ?></td>
                <td class="muted"><?= $p['event_title'] ? htmlspecialchars($p['event_title']) : 'Platform-wide' ?></td>
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
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this promo code?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if (admin_can('promo.manage')): ?>
    <div class="admin-card">
      <h3 style="margin-bottom:14px">New promo code</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="admin-form-row"><label>Code</label><input type="text" name="code" required placeholder="e.g. LAUNCH20" style="text-transform:uppercase"></div>
        <div class="admin-form-grid">
          <div class="admin-form-row"><label>Type</label>
            <select name="discount_type"><option value="PERCENT">Percent off</option><option value="FIXED">Fixed amount (UGX)</option></select>
          </div>
          <div class="admin-form-row"><label>Value</label><input type="number" name="discount_value" min="1" step="1" required></div>
        </div>
        <div class="admin-form-row"><label>Applies to</label>
          <select name="event_id"><option value="">Platform-wide (any event)</option><?php foreach ($events as $e): ?><option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['title']) ?></option><?php endforeach; ?></select>
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
    </div>
  <?php endif; ?>
</div>

<?php render_admin_foot(); ?>
