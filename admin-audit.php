<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('audit.view');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$total = count_audit_logs();
$logs = get_audit_logs($perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));

$pageTitle = 'Audit Logs';
render_admin_head('audit');
?>

<div class="admin-page-head">
  <div><h1>Audit logs</h1><p><?= $total ?> recorded actions &middot; permanent, never edited or deleted</p></div>
</div>

<?php if (!$logs): ?>
  <div class="admin-empty"><svg width="40" height="40"><use href="#ic-clock"/></svg><h3>Nothing logged yet</h3><p>Every sensitive admin action will appear here.</p></div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Admin</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): $details = $l['details'] ? json_decode($l['details'], true) : null; ?>
          <tr>
            <td><?= htmlspecialchars($l['admin_name'] ?? 'System') ?></td>
            <td class="mono" style="font-size:0.78rem"><?= htmlspecialchars($l['action']) ?></td>
            <td class="muted"><?= htmlspecialchars($l['entity_type']) ?><?= $l['entity_id'] ? ' #' . (int) $l['entity_id'] : '' ?></td>
            <td class="muted" style="max-width:280px; white-space:normal; font-size:0.78rem"><?= $details ? htmlspecialchars(json_encode($details)) : '—' ?></td>
            <td class="muted mono" style="font-size:0.78rem"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y, H:i', strtotime($l['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?page=<?= $p ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
