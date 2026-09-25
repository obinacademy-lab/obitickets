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
$statusCounts = get_contact_message_counts();
$statusMeta = ['NEW' => 'admin-badge-warn', 'READ' => 'admin-badge-muted', 'IN_PROGRESS' => 'admin-badge-purple', 'RESOLVED' => 'admin-badge-success'];

$pageTitle = 'Contact Messages';
render_admin_head('contact');
?>

<div class="admin-page-head">
  <div><h1>Contact messages</h1><p><?= count($messages) ?> shown</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $statusFilter === '' ? ' active' : '' ?>" href="/admin-contact.php">
    <span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'NEW' ? ' active' : '' ?>" href="/admin-contact.php?status=NEW">
    <span class="n"><?= $statusCounts['NEW'] ?></span><span class="l">New</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'READ' ? ' active' : '' ?>" href="/admin-contact.php?status=READ">
    <span class="n"><?= $statusCounts['READ'] ?></span><span class="l">Read</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'IN_PROGRESS' ? ' active' : '' ?>" href="/admin-contact.php?status=IN_PROGRESS">
    <span class="n"><?= $statusCounts['IN_PROGRESS'] ?></span><span class="l">In progress</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'RESOLVED' ? ' active' : '' ?>" href="/admin-contact.php?status=RESOLVED">
    <span class="n"><?= $statusCounts['RESOLVED'] ?></span><span class="l">Resolved</span>
  </a>
</div>

<?php if (!$messages): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-mail"/></svg></div>
    <h3>No messages<?= $statusFilter !== '' ? ' in this view' : '' ?></h3>
    <p><?= $statusFilter !== '' ? 'Try a different filter, or clear it to see everything.' : 'Nothing submitted through the Contact Us page yet.' ?></p>
    <?php if ($statusFilter !== ''): ?><a class="btn btn-line" href="/admin-contact.php">Clear filter</a><?php endif; ?>
  </div>
<?php else: ?>
  <div style="display:flex; flex-direction:column; gap:14px;">
    <?php foreach ($messages as $m): $isNew = $m['status'] === 'NEW'; ?>
      <div class="admin-card<?= $isNew ? ' admin-row-attention' : '' ?>">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:10px;">
          <div>
            <strong><?= htmlspecialchars($m['name']) ?></strong> &middot; <span class="muted"><?= htmlspecialchars($m['email']) ?></span>
            <div class="muted" style="font-size:0.8rem; margin-top:2px;"><?= htmlspecialchars($m['topic']) ?> &middot; <?= htmlspecialchars(date('d M Y, H:i', strtotime($m['created_at']))) ?></div>
          </div>
          <span class="admin-badge <?= $statusMeta[$m['status']] ?? 'admin-badge-muted' ?>"><?= $isNew ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars(str_replace('_', ' ', $m['status'])) ?></span>
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
