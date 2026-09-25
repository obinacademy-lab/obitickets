<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('orders.refund');
$refunds = get_refunds_admin();
$totalRefunded = array_sum(array_column($refunds, 'refund_amount'));

$pageTitle = 'Refunds';
render_admin_head('refunds');
?>

<div class="admin-page-head">
  <div><h1>Refunds</h1><p><?= count($refunds) ?> processed &middot; UGX <?= number_format((float) $totalRefunded, 0) ?> refunded lifetime</p></div>
</div>

<?php if (!$refunds): ?>
  <div class="admin-empty">
    <svg width="40" height="40"><use href="#ic-x"/></svg>
    <h3>No refunds yet</h3>
    <p>Refunds are processed from an order's detail page.</p>
    <a class="btn btn-line" href="/admin-orders.php">Go to Orders</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Order</th><th>Buyer</th><th>Event</th><th>Amount</th><th>Reason</th><th>Processed by</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($refunds as $r): ?>
          <tr>
            <td><a class="link" href="/admin-order-detail.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
            <td><?= htmlspecialchars($r['buyer_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($r['event_title']) ?></td>
            <td class="mono">UGX <?= number_format((float) $r['refund_amount'], 0) ?></td>
            <td class="muted"><?= htmlspecialchars($r['refund_reason'] ?? '—') ?></td>
            <td class="muted"><?= htmlspecialchars($r['refunded_by_name'] ?? '—') ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($r['refunded_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
