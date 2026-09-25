<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('categories.manage');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        [$ok, $err] = create_category((string) ($_POST['name'] ?? ''), (string) ($_POST['icon_key'] ?? 'ic-ticket'));
        if (!$ok) {
            $error = $err;
        } else {
            log_admin_action((int) $admin['id'], 'category.create', 'category', null, ['name' => $_POST['name']]);
            header('Location: /admin-categories.php?updated=1');
            exit;
        }
    } elseif ($action === 'update') {
        update_category((int) $_POST['id'], (string) $_POST['name'], (string) $_POST['icon_key']);
        log_admin_action((int) $admin['id'], 'category.update', 'category', (int) $_POST['id']);
        header('Location: /admin-categories.php?updated=1');
        exit;
    } elseif ($action === 'toggle') {
        set_category_active((int) $_POST['id'], !empty($_POST['active']));
        log_admin_action((int) $admin['id'], 'category.toggle', 'category', (int) $_POST['id'], ['active' => !empty($_POST['active'])]);
        header('Location: /admin-categories.php?updated=1');
        exit;
    } elseif ($action === 'reorder') {
        reorder_category((int) $_POST['id'], (int) $_POST['direction']);
        header('Location: /admin-categories.php?updated=1');
        exit;
    } elseif ($action === 'delete') {
        [$ok, $err] = delete_category((int) $_POST['id']);
        if (!$ok) {
            $error = $err;
        } else {
            log_admin_action((int) $admin['id'], 'category.delete', 'category', (int) $_POST['id']);
            header('Location: /admin-categories.php?updated=1');
            exit;
        }
    }
}

$categories = get_all_categories_admin();
$iconOptions = ['ic-music', 'ic-briefcase', 'ic-mic', 'ic-ball', 'ic-cross', 'ic-hanger', 'ic-people', 'ic-ticket', 'ic-cal', 'ic-bolt', 'ic-tag', 'ic-cash'];

$pageTitle = 'Categories';
render_admin_head('categories');
?>

<div class="admin-page-head">
  <div><h1>Event categories</h1><p>Shown on the homepage strip and the browse-events filter row, in this order.</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Order</th><th>Icon</th><th>Name</th><th>Events</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($categories as $i => $c): ?>
          <tr>
            <td style="white-space:nowrap">
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="reorder"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="direction" value="-1">
                <button type="submit" class="btn btn-line" style="padding:4px 9px" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button></form>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="reorder"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="direction" value="1">
                <button type="submit" class="btn btn-line" style="padding:4px 9px" <?= $i === count($categories) - 1 ? 'disabled' : '' ?>>&darr;</button></form>
            </td>
            <td><svg width="18" height="18"><use href="#<?= htmlspecialchars($c['icon_key']) ?>"/></svg></td>
            <td style="font-weight:700"><?= htmlspecialchars($c['name']) ?></td>
            <td class="mono"><?= (int) $c['event_count'] ?></td>
            <td>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="active" value="<?= $c['active'] ? '' : '1' ?>">
                <button type="submit" class="admin-badge <?= $c['active'] ? 'admin-badge-success' : 'admin-badge-muted' ?>" style="border:none; cursor:pointer"><?= $c['active'] ? 'Active' : 'Disabled' ?></button>
              </form>
            </td>
            <td style="white-space:nowrap">
              <a class="link" href="#" onclick="document.getElementById('edit-<?= (int) $c['id'] ?>').style.display='block'; return false;">Edit</a>
              <?php if ((int) $c['event_count'] === 0): ?>
                &middot;
                <form method="post" style="display:inline" data-confirm="Delete this category? This can't be undone." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Delete</button>
                </form>
              <?php endif; ?>
              <div id="edit-<?= (int) $c['id'] ?>" style="display:none; margin-top:10px; padding:14px; border:1px solid var(--line); border-radius:12px; background:var(--paper)">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <div class="admin-form-row"><label>Name</label><input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>"></div>
                  <div class="admin-form-row"><label>Icon</label>
                    <select name="icon_key">
                      <?php foreach ($iconOptions as $ic): ?><option value="<?= $ic ?>" <?= $c['icon_key'] === $ic ? 'selected' : '' ?>><?= $ic ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <button class="btn btn-purple" type="submit" style="padding:8px 18px">Save</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:14px">New category</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="admin-form-row"><label>Name</label><input type="text" name="name" required placeholder="e.g. Food &amp; Drink"></div>
      <div class="admin-form-row">
        <label>Icon</label>
        <select name="icon_key">
          <?php foreach ($iconOptions as $ic): ?><option value="<?= $ic ?>"><?= $ic ?></option><?php endforeach; ?>
        </select>
        <p class="hint">Icons come from the site's existing icon set.</p>
      </div>
      <button class="btn btn-purple btn-block" type="submit">Add category</button>
    </form>
  </div>
</div>

<?php render_admin_foot(); ?>
