<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    // Never let an admin change their own role here — a lone admin
    // accidentally demoting themselves would lock the whole admin area.
    if ($targetId && $targetId !== (int) $admin['id']) {
        update_user_role($targetId, $role);
    }
    header('Location: /admin-users.php?updated=1');
    exit;
}

$users = get_all_users_admin();

$pageTitle = 'Manage users — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <?php render_admin_tabs('users'); ?>

    <div class="admin-head sec-top">
      <div><span class="kicker">Admin</span><h1>Users</h1></div>
      <span class="mono" style="color:var(--muted-2)"><?= count($users) ?> total</span>
    </div>

    <?php if (isset($_GET['updated'])): ?><div class="alert alert-success">User updated.</div><?php endif; ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Events</th><th>Orders</th><th>Joined</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div class="row-user">
                  <span class="avatar"><?= htmlspecialchars(initials_from_name($u['name'])) ?></span>
                  <?= htmlspecialchars($u['name']) ?>
                </div>
              </td>
              <td style="color:var(--muted)"><?= htmlspecialchars($u['email']) ?></td>
              <td>
                <?php if ((int) $u['id'] === (int) $admin['id']): ?>
                  <span class="tag-pill tag-pill-dark">You &middot; <?= htmlspecialchars($u['role']) ?></span>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <select name="role" class="auto-submit">
                      <?php foreach (['ATTENDEE', 'ORGANIZER', 'ADMIN'] as $r): ?>
                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php endif; ?>
              </td>
              <td class="mono"><?= (int) $u['event_count'] ?></td>
              <td class="mono"><?= (int) $u['order_count'] ?></td>
              <td class="mono" style="color:var(--muted-2)"><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
