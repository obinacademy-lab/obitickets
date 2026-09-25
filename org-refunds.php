<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('refunds.view');
$organizerId = $ctx['organizer_id'];
$requests = get_refund_requests_for_organizer($organizerId);

$statusMeta = ['REQUESTED' => 'admin-badge-warn', 'APPROVED' => 'admin-badge-purple', 'REJECTED' => 'admin-badge-danger', 'COMPLETED' => 'admin-badge-success'];

$pageTitle = 'Refunds';
render_organizer_head('refunds', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Refunds</h1><p>Refund requests you've submitted for your orders</p></div>
</div>

<?php if (!$requests): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-x"/></svg></div>
    <h3>No refund requests yet</h3>
    <p>Open an order from the Orders page to request a refund for it.</p>
    <a class="btn btn-line" href="/org-orders.php">Go to Orders</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Event</th><th>Amount</th><th>Reason</th><th>Status</th><th>Requested</th></tr></thead>
      <tbody>
        <?php foreach ($requests as $r): $needsNothing = in_array($r['status'], ['APPROVED', 'COMPLETED', 'REJECTED'], true); ?>
          <tr class="<?= $r['status'] === 'REQUESTED' ? 'admin-row-attention' : '' ?>">
            <td><a class="link" href="/org-order-detail.php?id=<?= (int) $r['order_id'] ?>">#<?= (int) $r['order_id'] ?></a></td>
            <td><?= htmlspecialchars($r['buyer_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($r['event_title']) ?></td>
            <td class="mono">UGX <?= number_format((float) $r['amount'], 0) ?></td>
            <td class="muted"><?= htmlspecialchars($r['reason']) ?></td>
            <td><span class="admin-badge <?= $statusMeta[$r['status']] ?? 'admin-badge-muted' ?>"><?= $r['status'] === 'REQUESTED' ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars($r['status']) ?></span></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($r['requested_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_organizer_foot(); ?>
