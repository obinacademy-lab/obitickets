<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('orders.refund');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'approve_request') {
        [$ok, $err] = approve_refund_request((int) $admin['id'], $requestId);
        header('Location: /admin-refunds.php?' . ($ok ? 'updated=1' : 'error=' . urlencode((string) $err)));
        exit;
    }
    if (($_POST['action'] ?? '') === 'reject_request') {
        reject_refund_request((int) $admin['id'], $requestId);
        header('Location: /admin-refunds.php?updated=1');
        exit;
    }
}

$pendingRequests = get_refund_requests_admin('REQUESTED');
$refunds = get_refunds_admin();
$totalRefunded = array_sum(array_column($refunds, 'refund_amount'));

$pageTitle = 'Refunds';
render_admin_head('refunds');
?>

<div class="admin-page-head">
  <div><h1>Refunds</h1><p><?= count($refunds) ?> processed &middot; UGX <?= number_format((float) $totalRefunded, 0) ?> refunded lifetime</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if (isset($_GET['error'])): ?><span data-flash="<?= htmlspecialchars($_GET['error'], ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if ($pendingRequests): ?>
  <div class="admin-card" style="margin-bottom:20px">
    <h3 style="margin-bottom:14px">Pending refund requests from organizers</h3>
    <div class="admin-table-wrap" style="border:none; box-shadow:none;">
      <table class="admin-table">
        <thead><tr><th>Order</th><th>Organizer</th><th>Customer</th><th>Event</th><th>Amount</th><th>Reason</th><th>Requested</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($pendingRequests as $r): ?>
            <tr class="admin-row-attention">
              <td><a class="link" href="/admin-order-detail.php?id=<?= (int) $r['order_id'] ?>">#<?= (int) $r['order_id'] ?></a></td>
              <td><?= htmlspecialchars($r['organizer_name']) ?></td>
              <td class="muted"><?= htmlspecialchars($r['buyer_name']) ?></td>
              <td class="muted"><?= htmlspecialchars($r['event_title']) ?></td>
              <td class="mono"><?= htmlspecialchars($r['currency']) ?> <?= number_format((float) $r['amount'], 0) ?></td>
              <td class="muted"><?= htmlspecialchars($r['reason']) ?></td>
              <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($r['requested_at']))) ?></td>
              <td style="white-space:nowrap">
                <form method="post" style="display:inline" data-confirm="Approve and process this refund?"><?= csrf_field() ?><input type="hidden" name="action" value="approve_request"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" class="btn" style="background:var(--success); color:#fff; padding:6px 14px; font-size:0.8rem">Approve</button></form>
                <form method="post" style="display:inline; margin-left:6px" data-confirm="Reject this refund request?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Reject</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

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
