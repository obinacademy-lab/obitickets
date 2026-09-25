<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('tickets.view');
$organizerId = $ctx['organizer_id'];

$filters = ['status' => $_GET['status'] ?? '', 'q' => trim((string) ($_GET['q'] ?? ''))];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$total = count_orders_for_organizer($organizerId, $filters);
$orders = get_orders_for_organizer($organizerId, $filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));
$statusCounts = get_organizer_order_status_counts($organizerId);

$statusMeta = [
    'PENDING' => 'admin-badge-warn', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger',
    'CANCELLED' => 'admin-badge-muted', 'REFUNDED' => 'admin-badge-purple',
];

$pageTitle = 'Orders';
render_organizer_head('orders', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Orders</h1><p><?= $total ?> total</p></div>
</div>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $filters['status'] === '' ? ' active' : '' ?>" href="/org-orders.php">
    <span class="n"><?= array_sum($statusCounts) ?></span><span class="l">All</span>
  </a>
  <?php foreach ($statusMeta as $val => $badgeClass): ?>
    <a class="admin-mini-stat<?= $filters['status'] === $val ? ' active' : '' ?>" href="/org-orders.php?status=<?= $val ?>">
      <span class="n"><?= $statusCounts[$val] ?></span><span class="l"><?= ucfirst(strtolower($val)) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form class="admin-filter-bar" method="get">
  <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filters['status']) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Search buyer or order #…" value="<?= htmlspecialchars($filters['q']) ?>" style="min-width:260px">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$orders): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-bolt"/></svg></div>
    <h3>No orders<?= $filters['status'] !== '' || $filters['q'] !== '' ? ' in this view' : ' yet' ?></h3>
    <p><?= $filters['status'] !== '' || $filters['q'] !== '' ? 'Try a different search, or clear your filters to see everything.' : 'Ticket sales for your events will show up here.' ?></p>
    <?php if ($filters['status'] !== '' || $filters['q'] !== ''): ?><a class="btn btn-line" href="/org-orders.php">Clear filters</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Order</th><th>Buyer</th><th>Event</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><a class="link" href="/org-order-detail.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td>
            <td><?= htmlspecialchars($o['buyer_name']) ?><br><span class="muted" style="font-size:0.78rem"><?= htmlspecialchars($o['buyer_email']) ?></span></td>
            <td class="muted"><?= htmlspecialchars($o['event_title']) ?></td>
            <td class="mono"><?= htmlspecialchars($o['currency']) ?> <?= number_format((float) $o['total_amount'], 0) ?></td>
            <td><span class="admin-badge <?= $statusMeta[$o['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($o['status']) ?></span></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($o['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_organizer_foot(); ?>
