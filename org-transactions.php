<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('transactions.view');
$organizerId = $ctx['organizer_id'];

$filters = ['status' => $_GET['status'] ?? '', 'q' => trim((string) ($_GET['q'] ?? ''))];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$total = count_orders_for_organizer($organizerId, $filters);
$transactions = get_orders_for_organizer($organizerId, $filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));

$statusMeta = ['PENDING' => 'admin-badge-warn', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'CANCELLED' => 'admin-badge-muted', 'REFUNDED' => 'admin-badge-purple'];

$pageTitle = 'Transactions';
render_organizer_head('transactions', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Transactions</h1><p><?= $total ?> payment attempts across your events</p></div>
</div>

<form class="admin-filter-bar" method="get">
  <select name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['PENDING', 'PAID', 'FAILED', 'CANCELLED', 'REFUNDED'] as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst(strtolower($s)) ?></option><?php endforeach; ?>
  </select>
  <input type="text" name="q" placeholder="Search buyer or order #…" value="<?= htmlspecialchars($filters['q']) ?>" style="min-width:240px">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$transactions): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-cash"/></svg></div>
    <h3>No transactions<?= $filters['status'] !== '' || $filters['q'] !== '' ? ' in this view' : ' yet' ?></h3>
    <p><?= $filters['status'] !== '' || $filters['q'] !== '' ? 'Try a different search, or clear your filters.' : 'Payment attempts for your ticket sales will show up here.' ?></p>
    <?php if ($filters['status'] !== '' || $filters['q'] !== ''): ?><a class="btn btn-line" href="/org-transactions.php">Clear filters</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Transaction</th><th>Customer</th><th>Event</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($transactions as $o): ?>
          <tr>
            <td><a class="link" href="/org-order-detail.php?id=<?= (int) $o['id'] ?>">TXN-<?= (int) $o['id'] ?></a></td>
            <td><?= htmlspecialchars($o['buyer_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($o['event_title']) ?></td>
            <td class="mono"><?= htmlspecialchars($o['currency']) ?> <?= number_format((float) $o['total_amount'], 0) ?></td>
            <td class="muted"><?= htmlspecialchars(str_replace('_', ' ', $o['payment_method'] ?? '—')) ?></td>
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
