<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('customers.view');
$userId = (int) ($_GET['id'] ?? 0);
$customer = get_customer_admin_detail($userId);
if (!$customer) {
    http_response_code(404);
    exit('Customer not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_can('customers.manage')) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'account_status') {
        set_user_account_status((int) $admin['id'], $userId, (string) $_POST['status']);
    } elseif ($action === 'promote') {
        update_user_role((int) $admin['id'], $userId, 'ORGANIZER');
        header('Location: /admin-organizer-detail.php?id=' . $userId . '&updated=1');
        exit;
    }
    header('Location: /admin-customer-detail.php?id=' . $userId . '&updated=1');
    exit;
}

$ticketMeta = ['VALID' => 'admin-badge-success', 'USED' => 'admin-badge-purple', 'CANCELLED' => 'admin-badge-muted'];

$pageTitle = $customer['name'];
render_admin_head('customers');
?>

<div class="admin-page-head">
  <div>
    <a class="link" href="/admin-customers.php" style="font-size:0.82rem; font-weight:700">&larr; All customers</a>
    <h1 style="margin-top:8px"><?= htmlspecialchars($customer['name']) ?></h1>
    <p><?= htmlspecialchars($customer['email']) ?><?= $customer['phone'] ? ' · ' . htmlspecialchars($customer['phone']) : '' ?> &middot; Joined <?= htmlspecialchars(date('d M Y', strtotime($customer['created_at']))) ?></p>
  </div>
  <span class="admin-badge <?= $customer['account_status'] === 'ACTIVE' ? 'admin-badge-success' : 'admin-badge-danger' ?>"><?= htmlspecialchars($customer['account_status']) ?></span>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div style="display:flex; flex-direction:column; gap:20px;">
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Orders (<?= count($customer['orders']) ?>)</h3>
      <?php if (!$customer['orders']): ?>
        <p class="muted" style="font-size:0.86rem">No orders yet.</p>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>Order</th><th>Event</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($customer['orders'] as $o): ?>
                <tr>
                  <td><a class="link" href="/admin-order-detail.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td>
                  <td class="muted"><?= htmlspecialchars($o['event_title']) ?></td>
                  <td class="mono"><?= htmlspecialchars($o['currency']) ?> <?= number_format((float) $o['total_amount'], 0) ?></td>
                  <td class="muted"><?= htmlspecialchars($o['status']) ?></td>
                  <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($o['created_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Tickets (<?= count($customer['tickets']) ?>)</h3>
      <?php if (!$customer['tickets']): ?>
        <p class="muted" style="font-size:0.86rem">No tickets yet.</p>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>Code</th><th>Event</th><th>Tier</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($customer['tickets'] as $t): ?>
                <tr>
                  <td class="mono"><?= htmlspecialchars($t['ticket_code']) ?></td>
                  <td class="muted"><?= htmlspecialchars($t['event_title']) ?></td>
                  <td class="muted"><?= htmlspecialchars($t['tier_name']) ?></td>
                  <td><span class="admin-badge <?= $ticketMeta[$t['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <?php if (admin_can('customers.manage')): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:14px">Account</h3>
        <form method="post" data-confirm="<?= $customer['account_status'] === 'ACTIVE' ? 'Suspend' : 'Reactivate' ?> this customer account?" <?= $customer['account_status'] === 'ACTIVE' ? 'data-danger' : '' ?> style="margin-bottom:10px">
          <?= csrf_field() ?><input type="hidden" name="action" value="account_status">
          <input type="hidden" name="status" value="<?= $customer['account_status'] === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE' ?>">
          <button class="btn btn-block <?= $customer['account_status'] === 'ACTIVE' ? 'btn-line' : 'btn-purple' ?>" type="submit" style="<?= $customer['account_status'] === 'ACTIVE' ? 'color:var(--danger); border-color:var(--danger-border)' : '' ?>">
            <?= $customer['account_status'] === 'ACTIVE' ? 'Suspend customer' : 'Reactivate customer' ?>
          </button>
        </form>
        <form method="post" data-confirm="Make this customer an organizer? They'll be able to create and sell events.">
          <?= csrf_field() ?><input type="hidden" name="action" value="promote">
          <button class="btn btn-line btn-block" type="submit">Promote to organizer</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_admin_foot(); ?>
