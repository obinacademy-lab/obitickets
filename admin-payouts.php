<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('payouts.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_can('payouts.process')) {
    verify_csrf();
    update_payout_status((int) $admin['id'], (int) $_POST['payout_id'], (string) $_POST['status']);
    header('Location: /admin-payouts.php?updated=1&status=' . htmlspecialchars((string) $_POST['status'], ENT_QUOTES));
    exit;
}

$payouts = get_payouts_admin();
$requested = array_filter($payouts, static fn ($p) => in_array($p['status'], ['PENDING', 'APPROVED', 'PROCESSING'], true));
$pendingTotal = array_sum(array_column($requested, 'amount'));
$paidTotal = array_sum(array_map(static fn ($p) => $p['status'] === 'PAID' ? (float) $p['amount'] : 0, $payouts));
$statusMeta = ['PENDING' => 'admin-badge-warn', 'APPROVED' => 'admin-badge-purple', 'PROCESSING' => 'admin-badge-purple', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'REJECTED' => 'admin-badge-danger'];

$flashMap = ['PAID' => 'Withdrawal paid — organizer notified.', 'REJECTED' => 'Withdrawal rejected.', 'PROCESSING' => 'Marked as processing.', 'FAILED' => 'Marked as failed.', 'APPROVED' => 'Withdrawal approved.'];

$pageTitle = 'Payouts';
render_admin_head('payouts');
?>

<div class="admin-page-head">
  <div><h1>Organizer withdrawals</h1><p>Organizers request a withdrawal from their available balance; approve and record payment here once you've sent it.</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="<?= htmlspecialchars($flashMap[$_GET['status'] ?? ''] ?? 'Updated.', ENT_QUOTES) ?>" hidden></span><?php endif; ?>

<div class="admin-kpi-grid" style="margin-bottom:24px">
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-clock"/></svg> Awaiting action</div>
    <span class="num"><span data-count-to="<?= count($requested) ?>">0</span></span>
    <span class="sub">UGX <span data-count-to="<?= (int) $pendingTotal ?>">0</span> requested</span>
  </div>
  <div class="admin-kpi-card">
    <div class="lbl"><svg width="15" height="15"><use href="#ic-cash"/></svg> Paid out</div>
    <span class="num">UGX <span data-count-to="<?= (int) $paidTotal ?>">0</span></span>
    <span class="sub">Lifetime</span>
  </div>
</div>

<?php if (!$payouts): ?>
  <div class="admin-empty">
    <svg width="40" height="40"><use href="#ic-cash"/></svg>
    <h3>No withdrawal requests yet</h3>
    <p>Once an organizer requests a withdrawal from their dashboard, it'll show up here.</p>
    <a class="btn btn-line" href="/admin-organizers.php">Go to Organizers</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Organizer</th><th>Amount</th><th>Method</th><th>Destination</th><th>Requested</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($payouts as $p): $isOpen = in_array($p['status'], ['PENDING', 'APPROVED', 'PROCESSING'], true); ?>
          <tr class="<?= $p['status'] === 'PENDING' ? 'admin-row-attention' : '' ?>">
            <td><a class="link" href="/admin-organizer-detail.php?id=<?= (int) $p['organizer_id'] ?>"><?= htmlspecialchars($p['organizer_name']) ?></a></td>
            <td class="mono" style="font-weight:700"><?= htmlspecialchars($p['currency']) ?> <?= number_format((float) $p['amount'], 0) ?></td>
            <td class="muted"><?= htmlspecialchars(str_replace('_', ' ', $p['method'])) ?></td>
            <td class="muted mono"><?= htmlspecialchars($p['destination']) ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($p['requested_at']))) ?></td>
            <td><span class="admin-badge <?= $statusMeta[$p['status']] ?? 'admin-badge-muted' ?>"><?= $p['status'] === 'PENDING' ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars($p['status']) ?></span></td>
            <td style="white-space:nowrap">
              <?php if (admin_can('payouts.process') && $isOpen): ?>
                <form method="post" style="display:inline" data-confirm="Mark UGX <?= number_format((float) $p['amount'], 0) ?> as paid to <?= htmlspecialchars($p['organizer_name'], ENT_QUOTES) ?>? Send the money yourself first — this just records it.">
                  <?= csrf_field() ?><input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="status" value="PAID">
                  <button type="submit" class="btn" style="background:var(--success); color:#fff; padding:7px 16px; font-size:0.82rem">Approve &amp; pay</button>
                </form>
                <form method="post" style="display:inline; margin-left:6px" data-confirm="Reject this withdrawal request?" data-danger>
                  <?= csrf_field() ?><input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="status" value="REJECTED">
                  <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700; font-size:0.82rem">Reject</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
