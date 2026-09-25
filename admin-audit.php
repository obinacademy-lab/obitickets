<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('audit.view');

$period = $_GET['period'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$periodCounts = get_audit_log_period_counts();
$total = count_audit_logs($period);
$logs = get_audit_logs($perPage, ($page - 1) * $perPage, $period);
$totalPages = max(1, (int) ceil($total / $perPage));

$pageTitle = 'Audit Logs';
render_admin_head('audit');
?>

<div class="admin-page-head">
  <div><h1>Audit logs</h1><p><?= $periodCounts[''] ?> recorded actions &middot; permanent, never edited or deleted</p></div>
</div>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $period === '' ? ' active' : '' ?>" href="/admin-audit.php">
    <span class="n"><?= $periodCounts[''] ?></span><span class="l">All time</span>
  </a>
  <a class="admin-mini-stat<?= $period === 'today' ? ' active' : '' ?>" href="/admin-audit.php?period=today">
    <span class="n"><?= $periodCounts['today'] ?></span><span class="l">Today</span>
  </a>
  <a class="admin-mini-stat<?= $period === 'week' ? ' active' : '' ?>" href="/admin-audit.php?period=week">
    <span class="n"><?= $periodCounts['week'] ?></span><span class="l">Last 7 days</span>
  </a>
  <a class="admin-mini-stat<?= $period === 'month' ? ' active' : '' ?>" href="/admin-audit.php?period=month">
    <span class="n"><?= $periodCounts['month'] ?></span><span class="l">Last 30 days</span>
  </a>
</div>

<?php if (!$logs): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-clock"/></svg></div>
    <h3>Nothing logged<?= $period !== '' ? ' in this period' : ' yet' ?></h3>
    <p><?= $period !== '' ? 'Try a wider time range, or clear it to see everything.' : 'Every sensitive admin action will appear here.' ?></p>
    <?php if ($period !== ''): ?><a class="btn btn-line" href="/admin-audit.php">Clear filter</a><?php endif; ?>
  </div>
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
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= http_build_query(['period' => $period, 'page' => $p]) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
