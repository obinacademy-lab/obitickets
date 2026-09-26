<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('orders.view');
$orderId = (int) ($_GET['id'] ?? 0);
$order = get_order_admin_detail($orderId);
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'refund') {
        if (!admin_can('orders.refund')) {
            http_response_code(403);
            exit('You do not have permission to process refunds.');
        }
        [$ok, $err] = process_refund((int) $admin['id'], $orderId, (float) $_POST['amount'], (string) $_POST['reason']);
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /admin-order-detail.php?id=' . $orderId . '&updated=1');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'resend_ticket_email') {
        if ($order['status'] !== 'PAID') {
            $error = 'Only a paid order has tickets to resend.';
        } else {
            try {
                send_order_tickets_email($orderId);
                header('Location: /admin-order-detail.php?id=' . $orderId . '&resent=1');
                exit;
            } catch (Throwable $e) {
                error_log('[admin] resend_ticket_email failed for order ' . $orderId . ': ' . $e->getMessage());
                $error = "Couldn't resend the ticket email. Check the error log for details.";
            }
        }
    }
}

$statusMeta = [
    'PENDING' => 'admin-badge-warn', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger',
    'CANCELLED' => 'admin-badge-muted', 'REFUNDED' => 'admin-badge-purple',
];
$ticketMeta = ['VALID' => 'admin-badge-success', 'USED' => 'admin-badge-purple', 'CANCELLED' => 'admin-badge-muted'];

$pageTitle = 'Order #' . $orderId;
render_admin_head('orders');
?>

<div class="admin-page-head">
  <div>
    <a class="link" href="/admin-orders.php" style="font-size:0.82rem; font-weight:700">&larr; All orders</a>
    <h1 style="margin-top:8px">Order #<?= $orderId ?></h1>
    <p><?= htmlspecialchars($order['buyer_name']) ?> (<?= htmlspecialchars($order['buyer_email']) ?>) &middot; <?= htmlspecialchars(date('d M Y, H:i', strtotime($order['created_at']))) ?></p>
  </div>
  <span class="admin-badge <?= $statusMeta[$order['status']] ?? 'admin-badge-muted' ?>" style="font-size:0.82rem"><?= htmlspecialchars($order['status']) ?></span>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Refund recorded." hidden></span><?php endif; ?>
<?php if (isset($_GET['resent'])): ?><span data-flash="Ticket email resent to <?= htmlspecialchars($order['buyer_email'], ENT_QUOTES) ?>." hidden></span><?php endif; ?>
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
      <div class="admin-detail-row"><span class="k">Service fee (buyer)</span><span class="v mono"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['service_fee_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Total charged</span><span class="v mono" style="color:var(--purple)"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['total_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Platform commission</span><span class="v mono"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['commission_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Organizer earnings</span><span class="v mono"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['subtotal_amount'] - (float) $order['commission_amount'], 0) ?></span></div>
      <div class="admin-detail-row"><span class="k">Payment method</span><span class="v"><?= htmlspecialchars(str_replace('_', ' ', $order['payment_method'] ?? '—')) ?></span></div>
      <div class="admin-detail-row"><span class="k">Gateway reference</span><span class="v mono"><?= htmlspecialchars($order['payment_reference'] ?? '—') ?></span></div>
      <?php if ($order['status_message']): ?><div class="admin-detail-row"><span class="k">Note</span><span class="v"><?= htmlspecialchars($order['status_message']) ?></span></div><?php endif; ?>
      <?php if ($order['refunded_at']): ?>
        <div class="admin-detail-row"><span class="k">Refunded</span><span class="v mono" style="color:var(--danger)"><?= htmlspecialchars($order['currency']) ?> <?= number_format((float) $order['refund_amount'], 0) ?> on <?= htmlspecialchars(date('d M Y', strtotime($order['refunded_at']))) ?></span></div>
        <div class="admin-detail-row"><span class="k">Refund reason</span><span class="v"><?= htmlspecialchars($order['refund_reason'] ?? '') ?></span></div>
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
      <h3 style="margin-bottom:14px">Links</h3>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <a class="btn btn-line btn-block" href="/admin-customer-detail.php?id=<?= (int) $order['buyer_id'] ?>">View customer</a>
        <a class="btn btn-line btn-block" href="/admin-event-detail.php?id=<?= (int) $order['event_id'] ?>">View event</a>
        <?php if ($order['status'] === 'PAID'): ?>
          <form method="post" onsubmit="if(this.dataset.confirmed) return true; event.preventDefault(); var f=this; window.adminConfirm('Resend the ticket email to <?= htmlspecialchars($order['buyer_email'], ENT_QUOTES) ?>?').then(function(ok){ if(ok){ f.dataset.confirmed='1'; f.requestSubmit(); } });">
            <?= csrf_field() ?><input type="hidden" name="action" value="resend_ticket_email">
            <button class="btn btn-line btn-block" type="submit">Resend ticket email</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (admin_can('orders.refund') && $order['status'] === 'PAID' && !$order['refunded_at']): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:4px">Refund this order</h3>
        <p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:14px">Records the refund here — you still need to send the money back to the buyer yourself (mobile money), since obitickets' payment integration has no automated refund API.</p>
        <form method="post" onsubmit="if(this.dataset.confirmed) return true; event.preventDefault(); var f=this; window.adminConfirm('Refund UGX ' + this.amount.value + '? This cannot be undone.', true).then(function(ok){ if(ok){ f.dataset.confirmed='1'; f.requestSubmit(); } });">
          <?= csrf_field() ?><input type="hidden" name="action" value="refund">
          <div class="admin-form-row"><label>Amount (<?= htmlspecialchars($order['currency']) ?>)</label><input type="number" name="amount" min="1" max="<?= (float) $order['total_amount'] ?>" value="<?= (float) $order['total_amount'] ?>" step="1" required></div>
          <div class="admin-form-row"><label>Reason</label><input type="text" name="reason" required placeholder="e.g. Event cancelled"></div>
          <button class="btn btn-block" type="submit" style="background:var(--danger); color:#fff">Process refund</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_admin_foot(); ?>
