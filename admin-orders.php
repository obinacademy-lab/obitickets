<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role('ADMIN');
$orders = get_all_orders_admin();

$pageTitle = 'Orders — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <?php render_admin_tabs('orders'); ?>

    <div class="admin-head sec-top">
      <div><span class="kicker">Admin</span><h1>Orders</h1></div>
      <span class="mono" style="color:var(--muted-2)"><?= count($orders) ?> total</span>
    </div>

    <?php if (!$orders): ?>
      <p style="text-align:center; padding:40px 0">No orders yet.</p>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr><th>Order</th><th>Buyer</th><th>Event</th><th>Total</th><th>Status</th><th>Paid via</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td class="mono">#<?= (int) $o['id'] ?></td>
                <td><?= htmlspecialchars($o['buyer_name']) ?><br><span style="color:var(--muted-2); font-size:0.82rem"><?= htmlspecialchars($o['buyer_email']) ?></span></td>
                <td><?= htmlspecialchars($o['event_title']) ?></td>
                <td class="mono"><?= htmlspecialchars($o['currency'] . ' ' . number_format((float) $o['total_amount'], 0)) ?></td>
                <td><span class="tag-pill <?= $o['status'] === 'PAID' ? '' : 'tag-pill-muted' ?>"><?= htmlspecialchars($o['status']) ?></span></td>
                <td style="color:var(--muted)"><?= htmlspecialchars(str_replace('_', ' ', $o['payment_method'] ?? '—')) ?></td>
                <td class="mono" style="color:var(--muted-2)"><?= htmlspecialchars(date('d M Y', strtotime($o['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
