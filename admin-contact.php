<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('contact.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_can('contact.manage')) {
    verify_csrf();
    set_contact_message_status((int) $admin['id'], (int) $_POST['id'], (string) $_POST['status']);
    header('Location: /admin-contact.php?' . http_build_query(['status' => $_GET['status'] ?? '', 'updated' => 1]));
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$messages = get_contact_messages_admin($statusFilter);
$statusMeta = ['NEW' => 'admin-badge-warn', 'READ' => 'admin-badge-muted', 'IN_PROGRESS' => 'admin-badge-purple', 'RESOLVED' => 'admin-badge-success'];

$pageTitle = 'Contact Messages';
render_admin_head('contact');
?>

<div class="admin-page-head">
  <div><h1>Contact messages</h1><p><?= count($messages) ?> shown</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>

<form class="admin-filter-bar" method="get">
  <select name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['NEW', 'READ', 'IN_PROGRESS', 'RESOLVED'] as $s): ?><option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
  </select>
</form>

<?php if (!$messages): ?>
  <div class="admin-empty"><svg width="40" height="40"><use href="#ic-mail"/></svg><h3>No messages</h3><p>Nothing submitted through the Contact Us page yet.</p></div>
<?php else: ?>
  <div style="display:flex; flex-direction:column; gap:14px;">
    <?php foreach ($messages as $m): ?>
      <div class="admin-card">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:10px;">
          <div>
            <strong><?= htmlspecialchars($m['name']) ?></strong> &middot; <span class="muted"><?= htmlspecialchars($m['email']) ?></span>
            <div class="muted" style="font-size:0.8rem; margin-top:2px;"><?= htmlspecialchars($m['topic']) ?> &middot; <?= htmlspecialchars(date('d M Y, H:i', strtotime($m['created_at']))) ?></div>
          </div>
          <span class="admin-badge <?= $statusMeta[$m['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars(str_replace('_', ' ', $m['status'])) ?></span>
        </div>
        <p style="font-size:0.9rem; color:var(--ink); margin-bottom:14px;"><?= nl2br(htmlspecialchars($m['message'])) ?></p>
        <?php if (admin_can('contact.manage')): ?>
          <form method="post" style="display:flex; gap:8px; align-items:center;">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <select name="status" onchange="this.form.submit()">
              <?php foreach (['NEW', 'READ', 'IN_PROGRESS', 'RESOLVED'] as $s): ?><option value="<?= $s ?>" <?= $m['status'] === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option><?php endforeach; ?>
            </select>
            <a class="btn btn-line" style="padding:8px 16px; font-size:0.82rem" href="mailto:<?= htmlspecialchars($m['email']) ?>">Reply by email</a>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
