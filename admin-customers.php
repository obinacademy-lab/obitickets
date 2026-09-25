<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('customers.view');

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$customers = get_customers_admin();

$statusCounts = ['ACTIVE' => 0, 'SUSPENDED' => 0];
foreach ($customers as $c) {
    if (isset($statusCounts[$c['account_status']])) {
        $statusCounts[$c['account_status']]++;
    }
}

if ($statusFilter !== '') {
    $customers = array_values(array_filter($customers, static fn ($c) => $c['account_status'] === $statusFilter));
}
if ($q !== '') {
    $customers = array_values(array_filter($customers, static fn ($c) => stripos($c['name'], $q) !== false || stripos($c['email'], $q) !== false));
}

$pageTitle = 'Customers';
render_admin_head('customers');
?>

<div class="admin-page-head">
  <div><h1>Customers</h1><p><?= count($customers) ?> shown</p></div>
</div>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $statusFilter === '' ? ' active' : '' ?>" href="/admin-customers.php">
    <span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'ACTIVE' ? ' active' : '' ?>" href="/admin-customers.php?status=ACTIVE">
    <span class="n"><?= $statusCounts['ACTIVE'] ?></span><span class="l">Active</span>
  </a>
  <a class="admin-mini-stat<?= $statusFilter === 'SUSPENDED' ? ' active' : '' ?>" href="/admin-customers.php?status=SUSPENDED">
    <span class="n"><?= $statusCounts['SUSPENDED'] ?></span><span class="l">Suspended</span>
  </a>
</div>

<form class="admin-filter-bar" method="get">
  <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($q) ?>">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$customers): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-user"/></svg></div>
    <h3>No customers found</h3>
    <p>Try a different search, or clear your filters to see everyone.</p>
    <a class="btn btn-line" href="/admin-customers.php">Clear filters</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Customer</th><th>Orders</th><th>Total spent</th><th>Status</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td><div class="row-user"><span class="avatar"><?= htmlspecialchars(initials_from_name($c['name'])) ?></span><div><?= htmlspecialchars($c['name']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($c['email']) ?></span></div></div></td>
            <td class="mono"><?= (int) $c['order_count'] ?></td>
            <td class="mono">UGX <?= number_format((float) $c['total_spent'], 0) ?></td>
            <td><span class="admin-badge <?= $c['account_status'] === 'ACTIVE' ? 'admin-badge-success' : 'admin-badge-danger' ?>"><?= htmlspecialchars($c['account_status']) ?></span></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($c['created_at']))) ?></td>
            <td><a class="link" href="/admin-customer-detail.php?id=<?= (int) $c['id'] ?>">View &rarr;</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
