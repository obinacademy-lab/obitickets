<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('organizers.view');
$userId = (int) ($_GET['id'] ?? 0);
$org = get_organizer_admin_detail($userId);
if (!$org) {
    http_response_code(404);
    exit('Organizer not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'verify' && admin_can('organizers.verify')) {
        set_organizer_verification((int) $admin['id'], $userId, (string) $_POST['status'], $_POST['notes'] ?? null);
    } elseif ($action === 'account_status' && admin_can('customers.manage')) {
        set_user_account_status((int) $admin['id'], $userId, (string) $_POST['status']);
    } elseif ($action === 'payout' && admin_can('payouts.process')) {
        create_payout((int) $admin['id'], $userId, (float) $_POST['amount'], (string) $_POST['method'], (string) $_POST['destination'], $_POST['notes'] ?? null);
    }
    header('Location: /admin-organizer-detail.php?id=' . $userId . '&updated=1');
    exit;
}

$verificationMeta = [
    'UNVERIFIED' => ['Unverified', 'admin-badge-muted'], 'PENDING' => ['Pending', 'admin-badge-warn'],
    'VERIFIED' => ['Verified', 'admin-badge-success'], 'REJECTED' => ['Rejected', 'admin-badge-danger'],
];
$vm = $verificationMeta[$org['verification_status']] ?? $verificationMeta['UNVERIFIED'];
$payoutMeta = ['PENDING' => 'admin-badge-warn', 'APPROVED' => 'admin-badge-purple', 'PROCESSING' => 'admin-badge-purple', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'REJECTED' => 'admin-badge-danger'];

$pageTitle = $org['org_name'] ?: $org['name'];
render_admin_head('organizers');
?>

<div class="admin-page-head">
  <div>
    <a class="link" href="/admin-organizers.php" style="font-size:0.82rem; font-weight:700">&larr; All organizers</a>
    <h1 style="margin-top:8px"><?= htmlspecialchars($org['org_name'] ?: $org['name']) ?></h1>
    <p><?= htmlspecialchars($org['name']) ?> &middot; <?= htmlspecialchars($org['email']) ?><?= $org['phone'] ? ' · ' . htmlspecialchars($org['phone']) : '' ?></p>
  </div>
  <div style="display:flex; gap:8px">
    <span class="admin-badge <?= $vm[1] ?>"><?= htmlspecialchars($vm[0]) ?></span>
    <span class="admin-badge <?= $org['account_status'] === 'ACTIVE' ? 'admin-badge-success' : 'admin-badge-danger' ?>"><?= htmlspecialchars($org['account_status']) ?></span>
  </div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div style="display:flex; flex-direction:column; gap:20px;">

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Revenue</h3>
      <?php if (!$org['balance']): ?>
        <p class="muted" style="font-size:0.86rem">No paid orders yet.</p>
      <?php endif; ?>
      <?php foreach ($org['balance'] as $cur => $b): ?>
        <div class="admin-detail-row"><span class="k">Lifetime net earnings</span><span class="v mono"><?= $cur ?> <?= number_format($b['lifetime'], 0) ?></span></div>
        <div class="admin-detail-row"><span class="k">Paid out</span><span class="v mono">&minus; <?= $cur ?> <?= number_format($b['paid_out'], 0) ?></span></div>
        <div class="admin-detail-row"><span class="k">Available balance</span><span class="v mono" style="color:var(--purple)"><?= $cur ?> <?= number_format($b['available'], 0) ?></span></div>
      <?php endforeach; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Events (<?= count($org['events']) ?>)</h3>
      <?php if (!$org['events']): ?>
        <p class="muted" style="font-size:0.86rem">No events created yet.</p>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>Event</th><th>Starts</th><th>Sold</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($org['events'] as $e): ?>
                <tr>
                  <td><a class="link" href="/admin-event-detail.php?id=<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['title']) ?></a></td>
                  <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
                  <td class="mono"><?= (int) $e['tickets_sold'] ?></td>
                  <td class="muted"><?= htmlspecialchars($e['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Orders through this organizer's events</h3>
      <?php if (!$org['orders']): ?>
        <p class="muted" style="font-size:0.86rem">No orders yet.</p>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>Order</th><th>Event</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($org['orders'], 0, 20) as $o): ?>
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
      <h3 style="margin-bottom:14px">Payouts</h3>
      <?php if (!$org['payouts']): ?>
        <p class="muted" style="font-size:0.86rem">No payouts recorded yet.</p>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>Amount</th><th>Method</th><th>Requested</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($org['payouts'] as $p): ?>
                <tr>
                  <td class="mono"><?= htmlspecialchars($p['currency']) ?> <?= number_format((float) $p['amount'], 0) ?></td>
                  <td class="muted"><?= htmlspecialchars(str_replace('_', ' ', $p['method'])) ?></td>
                  <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($p['requested_at']))) ?></td>
                  <td><span class="admin-badge <?= $payoutMeta[$p['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex; flex-direction:column; gap:20px;">
    <?php if (admin_can('organizers.verify')): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:14px">Verification</h3>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="verify">
          <div class="admin-form-row">
            <select name="status">
              <?php foreach ($verificationMeta as $val => $lbl): ?><option value="<?= $val ?>" <?= $org['verification_status'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl[0]) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-row"><label>Notes</label><textarea name="notes" rows="3" placeholder="Internal note or rejection reason"><?= htmlspecialchars($org['verification_notes'] ?? '') ?></textarea></div>
          <button class="btn btn-purple btn-block" type="submit">Save verification</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (admin_can('customers.manage')): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:14px">Account</h3>
        <form method="post" data-confirm="<?= $org['account_status'] === 'ACTIVE' ? 'Suspend' : 'Reactivate' ?> this organizer account?" <?= $org['account_status'] === 'ACTIVE' ? 'data-danger' : '' ?>>
          <?= csrf_field() ?><input type="hidden" name="action" value="account_status">
          <input type="hidden" name="status" value="<?= $org['account_status'] === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE' ?>">
          <button class="btn btn-block <?= $org['account_status'] === 'ACTIVE' ? 'btn-line' : 'btn-purple' ?>" type="submit" style="<?= $org['account_status'] === 'ACTIVE' ? 'color:var(--danger); border-color:var(--danger-border)' : '' ?>">
            <?= $org['account_status'] === 'ACTIVE' ? 'Suspend organizer' : 'Reactivate organizer' ?>
          </button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (admin_can('payouts.process') && $org['balance']): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:4px">Record a payout</h3>
        <p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:14px">Records that you paid this organizer outside the platform — it does not move money itself.</p>
        <form method="post" data-confirm="Record this payout?">
          <?= csrf_field() ?><input type="hidden" name="action" value="payout">
          <div class="admin-form-row"><label>Amount (UGX)</label><input type="number" name="amount" min="1" step="1" required></div>
          <div class="admin-form-row"><label>Method</label>
            <select name="method">
              <option value="MTN_MOMO" <?= $org['payout_provider'] === 'MTN_MOMO' ? 'selected' : '' ?>>MTN MoMo</option>
              <option value="AIRTEL_MONEY" <?= $org['payout_provider'] === 'AIRTEL_MONEY' ? 'selected' : '' ?>>Airtel Money</option>
              <option value="BANK" <?= $org['payout_provider'] === 'BANK' ? 'selected' : '' ?>>Bank transfer</option>
            </select>
          </div>
          <div class="admin-form-row"><label>Destination</label><input type="text" name="destination" value="<?= htmlspecialchars($org['payout_phone'] ?? '') ?>" placeholder="Phone or account number" required></div>
          <div class="admin-form-row"><label>Notes</label><input type="text" name="notes" placeholder="Optional"></div>
          <button class="btn btn-purple btn-block" type="submit">Record payout</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_admin_foot(); ?>
