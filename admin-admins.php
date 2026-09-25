<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('admins.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($targetId && $targetId === (int) $admin['id']) {
        // Never let an admin change their own role — a lone admin accidentally
        // demoting themselves would lock the whole admin area.
        header('Location: /admin-admins.php?error=self');
        exit;
    }
    if ($action === 'set_role' && $targetId) {
        set_admin_role((int) $admin['id'], $targetId, (string) ($_POST['admin_role'] ?? ''));
    } elseif ($action === 'promote') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            update_user_role((int) $admin['id'], (int) $userId, 'ADMIN');
        }
    }
    header('Location: /admin-admins.php?updated=1');
    exit;
}

$admins = get_admin_users();

$pageTitle = 'Admin Users';
render_admin_head('admins');
?>

<div class="admin-page-head">
  <div><h1>Admin users</h1><p><?= count($admins) ?> total</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'self'): ?><span data-flash="You can't change your own admin role." data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Admin</th><th>Role</th><th>Last login</th><th>Since</th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td><div class="row-user"><span class="avatar"><?= htmlspecialchars(initials_from_name($a['name'])) ?></span><div><?= htmlspecialchars($a['name']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($a['email']) ?></span></div></div></td>
            <td>
              <?php if ((int) $a['id'] === (int) $admin['id']): ?>
                <span class="admin-badge admin-badge-purple">You &middot; <?= htmlspecialchars(ADMIN_ROLES[$a['admin_role']] ?? 'Super Admin') ?></span>
              <?php else: ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                  <select name="admin_role" class="auto-submit" onfocus="this.dataset.prev=this.selectedIndex" onchange="var el=this; window.adminConfirm('Change this admin\'s role?').then(function(ok){ if(ok){ el.form.requestSubmit(); } else { el.selectedIndex=el.dataset.prev; } });">
                    <option value="" <?= empty($a['admin_role']) ? 'selected' : '' ?>>Super Admin</option>
                    <?php foreach (ADMIN_ROLES as $val => $label): ?>
                      <option value="<?= $val ?>" <?= $a['admin_role'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </td>
            <td class="muted mono"><?= $a['last_login_at'] ? htmlspecialchars(date('d M Y, H:i', strtotime($a['last_login_at']))) : '—' ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($a['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:4px">Make someone an admin</h3>
    <p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:14px">They must already have an obitickets account.</p>
    <form method="post" data-confirm="Grant admin access to this account?">
      <?= csrf_field() ?><input type="hidden" name="action" value="promote">
      <div class="admin-form-row"><label>Email</label><input type="email" name="email" required placeholder="person@example.com"></div>
      <button class="btn btn-purple btn-block" type="submit">Grant admin access</button>
    </form>
  </div>
</div>

<?php render_admin_foot(); ?>
