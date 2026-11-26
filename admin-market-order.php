<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * One OBI MARKET order for an admin (ADR-003, phase 7): items, customer and delivery, the payment (ioTec reference, fee, late payment), the money split, the history,
 * the seller's ledger rows for it and its emails. Actions (market.refund): settle a reported problem (pay the seller or refund the customer), refund part or all of it,
 * and record a refund as sent. Every action needs a reason and is in the audit log.
 */
$admin = require_admin_permission('market.view');
$adminId = (int) $admin['id'];
$canRefund = admin_can('market.refund');
$number = trim((string) ($_GET['n'] ?? $_POST['n'] ?? ''));
$o = market_ready() ? market_admin_order($number) : null;
$flash = $_SESSION['market_admin_flash'] ?? null;
unset($_SESSION['market_admin_flash']);

if ($o && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canRefund) {
        http_response_code(403);
        exit('You do not have permission to change OBI MARKET orders.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $res = ['ok' => false, 'error' => 'Unknown action.'];
    if ($action === 'release' || $action === 'refund_all') {
        $res = market_order_admin_resolve($adminId, (int) $o['id'], $action === 'release' ? 'release' : 'refund', $note, !empty($_POST['restock']));
        $msg = $action === 'release' ? 'Settled: the seller is paid for this order.' : 'Refunded in full. Send the money back to the customer, then mark it sent in Refunds.';
    } elseif ($action === 'refund_part') {
        $amount = max(0, (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '')));
        $res = market_order_refund($adminId, (int) $o['id'], $amount, !empty($_POST['include_delivery']), $note, !empty($_POST['restock']));
        $msg = 'Refund recorded: UGX ' . number_format((int) ($res['refund'] ?? $amount)) . '. Send the money back, then mark it sent in Refunds.';
    } elseif ($action === 'refund_sent') {
        $res = market_refund_mark_paid($adminId, (int) $o['id'], max(0, (int) ($_POST['amount'] ?? 0)));
        $msg = 'Recorded: the refund was sent to the customer.';
    }
    $_SESSION['market_admin_flash'] = $res['ok'] ? $msg : ($res['error'] ?? 'That did not work.');
    header('Location: /admin-market-order.php?n=' . urlencode($number));
    exit;
}
if (!$o) {
    http_response_code(404);
}
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$ugx = static fn ($n): string => 'UGX ' . number_format((int) $n);
$disputed = $o && $o['dispute_at'] !== null && $o['received_by'] === null;
$paid = $o && in_array($o['payment_status'], ['PAID', 'PARTIALLY_REFUNDED'], true);
$leftMerch = $o ? (int) $o['merchandise_ugx'] - (int) $o['refunded_merch_ugx'] : 0;
$owed = $o ? (int) $o['refunded_ugx'] - (int) $o['refund_paid_ugx'] : 0;
if ($o && $o['fulfilment'] === 'REFUND_REQUESTED' && (int) $o['refunded_ugx'] === 0) {
    $owed = (int) $o['total_ugx'];
}

$pageTitle = $o ? 'Order ' . $o['order_number'] : 'OBI MARKET order';
render_admin_head('market');
?>
<div class="admin-page-head">
  <div><h1><?= $o ? 'Order ' . $h($o['order_number']) : 'OBI MARKET order' ?></h1><p><?= $o ? $h($o['store_name']) . ' &middot; placed ' . $h(date('d M Y, H:i', strtotime((string) $o['created_at']))) : '' ?></p></div>
  <a class="btn btn-line" href="/admin-market.php?tab=orders">&larr; All orders</a>
</div>
<?php if ($flash): ?><span data-flash="<?= $h($flash) ?>" hidden></span><?php endif; ?>

<?php if (!$o): ?>
  <div class="admin-card"><p class="muted" style="margin:0">No order with that number.</p></div>
<?php else: ?>
<?php if ($disputed): ?><div class="admin-card" style="border-left:4px solid var(--danger); margin-bottom:18px"><h3 style="margin:0 0 6px">The customer reported a problem</h3><p style="margin:0">&ldquo;<?= $h($o['dispute_reason']) ?>&rdquo; (<?= $h(date('d M Y, H:i', strtotime((string) $o['dispute_at']))) ?>). The seller is not paid until you decide below.</p></div><?php endif; ?>
<?php if ($owed > 0): ?><div class="admin-card" style="border-left:4px solid var(--warn); margin-bottom:18px"><h3 style="margin:0 0 6px">UGX <?= number_format($owed) ?> is owed to the customer</h3><p style="margin:0">Send it to <b><?= $h(coin_phone_display((string) $o['pay_phone'])) ?></b> (the number that paid), then record it below.</p></div><?php endif; ?>

<div class="admin-grid-2">
  <div style="display:grid; gap:18px; align-content:start">
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Items</h3>
      <?php foreach ($o['items'] as $i): ?><div class="admin-detail-row"><span class="k"><b><?= $h($i['product_name']) ?></b><?= $i['variant_label'] !== '' ? ' &middot; ' . $h($i['variant_label']) : '' ?> &times; <?= (int) $i['quantity'] ?></span><span class="v mono"><?= $ugx($i['line_ugx']) ?></span></div><?php endforeach; ?>
      <div class="admin-detail-row"><span class="k">Merchandise</span><span class="v mono"><?= $ugx($o['merchandise_ugx']) ?></span></div>
      <div class="admin-detail-row"><span class="k">Delivery fee (seller's)</span><span class="v mono"><?= $ugx($o['delivery_ugx']) ?></span></div>
      <div class="admin-detail-row"><span class="k"><b>Customer paid</b></span><span class="v mono"><b><?= $ugx($o['total_ugx']) ?></b></span></div>
      <div class="admin-detail-row"><span class="k">obitickets commission (<?= $h(rtrim(rtrim(number_format((int) $o['commission_bps'] / 100, 2), '0'), '.')) ?>%, version <?= (int) $o['config_version'] ?>)</span><span class="v mono"><?= $ugx($o['commission_ugx']) ?></span></div>
      <div class="admin-detail-row"><span class="k">Seller keeps (90% + delivery)</span><span class="v mono"><?= $ugx((int) $o['seller_ugx'] + (int) $o['delivery_ugx']) ?></span></div>
      <?php if ((int) $o['refunded_ugx'] > 0): ?><div class="admin-detail-row"><span class="k">Refunded</span><span class="v mono"><?= $ugx($o['refunded_ugx']) ?> (sent: <?= $ugx($o['refund_paid_ugx']) ?>)</span></div><?php endif; ?>
      <div class="admin-detail-row"><span class="k">Status</span><span class="v"><?= $h(market_status_label((string) $o['fulfilment'], (string) $o['payment_status'])[0]) ?> &middot; payment <?= $h(strtolower((string) $o['payment_status'])) ?> &middot; seller money <?= $h(strtolower((string) $o['earnings_state'])) ?></span></div>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Payment</h3>
      <div class="admin-detail-row"><span class="k">Checkout</span><span class="v mono"><?= $h($o['checkout_reference']) ?> (<?= $h(strtolower((string) $o['checkout_status'])) ?>)</span></div>
      <div class="admin-detail-row"><span class="k">ioTec reference</span><span class="v mono"><?= $h($o['provider_reference'] ?? '—') ?></span></div>
      <div class="admin-detail-row"><span class="k">Paid with</span><span class="v"><?= $o['payment_method'] === 'AIRTEL_MONEY' ? 'Airtel Money' : 'MTN MoMo' ?> &middot; <?= $h(coin_phone_display((string) $o['pay_phone'])) ?></span></div>
      <div class="admin-detail-row"><span class="k">ioTec fee (paid by obitickets)</span><span class="v mono"><?= $o['gateway_fee_ugx'] !== null ? $ugx($o['gateway_fee_ugx']) : '—' ?></span></div>
      <?php if ((int) $o['paid_late']): ?><div class="admin-detail-row"><span class="k">Note</span><span class="v">The money arrived after the checkout expired.</span></div><?php endif; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Customer and delivery</h3>
      <div class="admin-detail-row"><span class="k">Customer</span><span class="v"><?= $h($o['customer_name']) ?> &middot; <?= $h($o['customer_phone']) ?> &middot; <?= $h($o['customer_email']) ?></span></div>
      <div class="admin-detail-row"><span class="k"><?= $o['delivery_method'] === 'COLLECTION' ? 'Collection' : 'Deliver to' ?></span><span class="v"><?= $o['delivery_method'] === 'COLLECTION' ? 'The customer collects' : $h($o['delivery_name'] . ', ' . $o['delivery_phone'] . ', ' . $o['delivery_address'] . ', ' . $o['delivery_district']) ?></span></div>
      <div class="admin-detail-row"><span class="k">Seller</span><span class="v"><?= $h($o['store_name']) ?> &middot; <?= $h($o['seller_email']) ?></span></div>
    </div>
  </div>

  <div style="display:grid; gap:18px; align-content:start">
    <?php if ($canRefund): ?>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Actions</h3>
      <?php if ($paid && in_array($o['fulfilment'], ['NEW', 'PROCESSING', 'SHIPPED', 'READY_FOR_COLLECTION'], true)): ?>
        <?php if (in_array($o['fulfilment'], ['SHIPPED', 'READY_FOR_COLLECTION'], true) && $o['earnings_state'] === 'PENDING'): ?>
        <form method="post" data-confirm="Pay the seller for this order (as if the customer confirmed delivery)?" style="margin-bottom:14px">
          <?= csrf_field() ?><input type="hidden" name="n" value="<?= $h($o['order_number']) ?>"><input type="hidden" name="action" value="release">
          <div class="admin-form-row"><label>Pay the seller &mdash; reason</label><input name="note" required maxlength="240" placeholder="e.g. Tracking shows it was delivered"></div>
          <button class="btn btn-purple" type="submit">Pay the seller</button>
        </form>
        <?php endif; ?>
        <form method="post" data-confirm="Refund this whole order? The seller loses this sale (taken off their earnings), and you send the customer their money back." data-danger style="margin-bottom:14px">
          <?= csrf_field() ?><input type="hidden" name="n" value="<?= $h($o['order_number']) ?>"><input type="hidden" name="action" value="refund_all">
          <div class="admin-form-row"><label>Refund everything &mdash; reason</label><input name="note" required maxlength="240" placeholder="e.g. Never delivered"></div>
          <label style="display:flex; gap:8px; align-items:center; margin:0 0 10px"><input type="checkbox" name="restock" value="1"> Put the items back in stock</label>
          <button class="btn btn-line" type="submit">Refund in full (<?= $ugx((int) $o['total_ugx'] - (int) $o['refunded_ugx']) ?>)</button>
        </form>
        <form method="post" data-confirm="Refund part of this order?">
          <?= csrf_field() ?><input type="hidden" name="n" value="<?= $h($o['order_number']) ?>"><input type="hidden" name="action" value="refund_part">
          <div class="admin-form-row"><label>Refund part of the merchandise (UGX, up to <?= number_format($leftMerch) ?>)</label><input name="amount" inputmode="numeric" required></div>
          <div class="admin-form-row"><label>Reason</label><input name="note" required maxlength="240" placeholder="e.g. One shirt was damaged"></div>
          <label style="display:flex; gap:8px; align-items:center; margin:0 0 10px"><input type="checkbox" name="include_delivery" value="1"> Also refund the delivery fee</label>
          <button class="btn btn-line" type="submit">Refund part</button>
        </form>
      <?php elseif ($owed <= 0): ?>
        <p class="muted" style="margin:0">Nothing to do on this order.</p>
      <?php endif; ?>
      <?php if ($owed > 0): ?>
        <form method="post" data-confirm="Record that <?= $h($ugx($owed)) ?> was sent back to the customer? Only after the money has really gone." style="margin-top:14px">
          <?= csrf_field() ?><input type="hidden" name="n" value="<?= $h($o['order_number']) ?>"><input type="hidden" name="action" value="refund_sent"><input type="hidden" name="amount" value="<?= (int) $owed ?>">
          <button class="btn btn-purple" type="submit">Mark <?= $ugx($owed) ?> sent to the customer</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">History</h3>
      <?php foreach ($o['history'] as $e): ?><div class="admin-detail-row"><span class="k"><b><?= $h(ucfirst(strtolower(str_replace('_', ' ', (string) $e['to_status'])))) ?></b><?php if (!empty($e['note'])): ?><br><span class="muted" style="font-size:.8rem"><?= $h($e['note']) ?></span><?php endif; ?></span><span class="v muted" style="font-size:.8rem"><?= $h(date('d M, H:i', strtotime((string) $e['created_at']))) ?> &middot; <?= $h(strtolower((string) $e['actor_role'])) ?><?= $e['actor'] ? ' (' . $h($e['actor']) . ')' : '' ?></span></div><?php endforeach; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Seller's money for this order</h3>
      <?php if (!$o['ledger']): ?><p class="muted" style="margin:0">No money movement (not earned).</p><?php else: foreach ($o['ledger'] as $l): ?>
        <div class="admin-detail-row"><span class="k"><?= $h(ucfirst(strtolower((string) $l['entry_type']))) ?><br><span class="muted" style="font-size:.78rem"><?= $h(date('d M, H:i', strtotime((string) $l['created_at']))) ?></span></span><span class="v mono" style="font-size:.82rem">pending <?= number_format((int) $l['pending_delta']) ?> &middot; released <?= number_format((int) $l['available_delta']) ?> &middot; obitickets <?= number_format((int) $l['platform_delta']) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin:0 0 12px">Emails</h3>
      <?php if (!$o['emails']): ?><p class="muted" style="margin:0">None (emails go out once the order is paid).</p><?php else: foreach ($o['emails'] as $e): ?>
        <div class="admin-detail-row"><span class="k"><?= $h(strtolower(str_replace('_', ' ', (string) $e['kind']))) ?> &rarr; <?= $h($e['to_email']) ?><?php if (!empty($e['last_error']) && $e['status'] !== 'SENT'): ?><br><span class="muted" style="font-size:.78rem"><?= $h($e['last_error']) ?></span><?php endif; ?></span><span class="v"><span class="admin-badge <?= ['SENT' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'SKIPPED' => 'admin-badge-muted'][$e['status']] ?? 'admin-badge-warn' ?>"><?= $h(ucfirst(strtolower((string) $e['status']))) ?></span></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php render_admin_foot(); ?>
