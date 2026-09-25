<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('tickets.view');
$organizerId = $ctx['organizer_id'];
$orderId = (int) ($_GET['id'] ?? 0);
$order = get_order_detail_for_organizer($organizerId, $orderId);
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_refund') {
    verify_csrf();
    if (!organizer_can($ctx, 'refunds.request')) {
        http_response_code(403);
        exit('You do not have permission to request refunds.');
    }
    [$ok, $err] = request_refund($organizerId, $orderId, (float) $_POST['amount'], (string) $_POST['reason']);
    if (!$ok) {
        $error = $err;
    } else {
        header('Location: /org-order-detail.php?id=' . $orderId . '&requested=1');
        exit;
    }
}

$existingRequest = null;
foreach (get_refund_requests_for_organizer($organizerId) as $rr) {
    if ((int) $rr['order_id'] === $orderId && in_array($rr['status'], ['REQUESTED', 'APPROVED'], true)) {
        $existingRequest = $rr;
        break;
    }
}

$statusMeta = ['PENDING' => 'admin-badge-warn', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'CANCELLED' => 'admin-badge-muted', 'REFUNDED' => 'admin-badge-purple'];
$ticketMeta = ['VALID' => 'admin-badge-success', 'USED' => 'admin-badge-purple', 'CANCELLED' => 'admin-badge-muted'];

$pageTitle = 'Order #' . $orderId;
render_organizer_head('orders', $ctx);
?>

<div class="admin-page-head">
  <div>
    <a class="link" href="/org-orders.php" style="font-size:0.82rem; font-weight:700">&larr; All orders</a>
    <h1 style="margin-top:8px">Order #<?= $orderId ?></h1>
    <p><?= htmlspecialchars($order['buyer_name']) ?> (<?= htmlspecialchars($order['buyer_email']) ?>) &middot; <?= htmlspecialchars(date('d M Y, H:i', strtotime($order['created_at']))) ?></p>
  </div>
  <span class="admin-badge <?= $statusMeta[$order['status']] ?? 'admin-badge-muted' ?>" style="font-size:0.82rem"><?= htmlspecialchars($order['status']) ?></span>
</div>

<?php if (isset($_GET['requested'])): ?><span data-flash="Refund request sent to obitickets for approval." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div style="display:flex; flex-direction:column; gap:20px;">
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Items</h3>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>Tier</th><th>Qty</th><th>Unit price</th><th>Line total</th></tr></thead>
          <tbody>
            <?php foreach ($order['items'] as $it): ?>
              <tr><td><?= htmlspecialchars($it['tier_name']) ?></td><td class="mono"><?= (int) $it['quantity'] ?></td><td class="mono"><?= number_format((float) $it['unit_price'], 0) ?></td><td class="mono"><?= number_format((float) $it['unit_price'] * (int) $it['quantity'], 0) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Payment</h3>
      <div class="admin-detail-row"><span class="k">Subtotal</span><span class="v mono"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['subtotal_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Total charged (buyer)</span><span class="v mono" style="color:var(--purple)"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['total_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Platform commission</span><span class="v mono"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['commission_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Your earnings</span><span class="v mono" style="color:var(--success)"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['subtotal_amount'] - (float) $order['commission_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Payment method</span><span class="v"><?= htmlspecialchars(str_replace('_', ' ', $order['payment_method'] ?? '—')) ?></span></div>
      <?php if ($order['refunded_at']): ?>
        <div class="admin-detail-row"><span class="k">Refunded</span><span class="v mono" style="color:var(--danger)"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['refund_amount'], 0) ?> on <?= htmlspecialchars(date('d M Y', strtotime($order['refunded_at']))) ?></span></div>
      <?php endif; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Tickets issued</h3>
      <?php if (!$order['tickets']): ?>
        <p class="muted" style="font-size:0.86rem">No tickets issued for this order.</p>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>Code</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($order['tickets'] as $t): ?>
                <tr><td class="mono"><?= htmlspecialchars($t['ticket_code']) ?></td><td><span class="admin-badge <?= $ticketMeta[$t['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($t['status']) ?></span></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex; flex-direction:column; gap:20px;">
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Buyer</h3>
      <div class="admin-detail-row"><span class="k">Name</span><span class="v"><?= htmlspecialchars($order['buyer_name']) ?></span></div>
      <div class="admin-detail-row"><span class="k">Email</span><span class="v"><?= htmlspecialchars($order['buyer_email']) ?></span></div>
      <?php if ($order['buyer_phone']): ?><div class="admin-detail-row"><span class="k">Phone</span><span class="v"><?= htmlspecialchars($order['buyer_phone']) ?></span></div><?php endif; ?>
    </div>

    <?php if ($existingRequest): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:4px">Refund request</h3>
        <p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:10px">You've already requested a refund for this order.</p>
        <div class="admin-detail-row"><span class="k">Amount</span><span class="v mono"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $existingRequest['amount'], 0) ?></span></div>
        <div class="admin-detail-row"><span class="k">Status</span><span class="v"><span class="admin-badge admin-badge-warn"><span class="admin-badge-dot"></span><?= htmlspecialchars($existingRequest['status']) ?></span></span></div>
      </div>
    <?php elseif (organizer_can($ctx, 'refunds.request') && $order['status'] === 'PAID' && !$order['refunded_at']): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:4px">Request a refund</h3>
        <p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:14px">obitickets reviews and processes every refund — you're requesting one, not issuing it directly.</p>
        <form method="post" data-confirm="Send this refund request to obitickets for approval?">
          <?= csrf_field() ?><input type="hidden" name="action" value="request_refund">
          <div class="admin-form-row"><label>Amount (<?= htmlspecialchars($order['currency']) ?>)</label><input type="number" name="amount" min="1" max="<?= (float) $order['total_amount'] ?>" value="<?= (float) $order['total_amount'] ?>" step="1" required></div>
          <div class="admin-form-row"><label>Reason</label><input type="text" name="reason" required placeholder="e.g. Event cancelled"></div>
          <button class="btn btn-block" type="submit" style="background:var(--danger); color:#fff">Request refund</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_organizer_foot(); ?>
