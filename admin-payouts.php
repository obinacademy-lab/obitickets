<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('payouts.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_can('payouts.process')) {
    verify_csrf();
    update_payout_status((int) $admin['id'], (int) $_POST['payout_id'], (string) $_POST['status']);
    header('Location: /admin-payouts.php?updated=1');
    exit;
}

$payouts = get_payouts_admin();
$pending = array_sum(array_map(static fn ($p) => in_array($p['status'], ['PENDING', 'APPROVED', 'PROCESSING'], true) ? (float) $p['amount'] : 0, $payouts));
$paid = array_sum(array_map(static fn ($p) => $p['status'] === 'PAID' ? (float) $p['amount'] : 0, $payouts));
$statusMeta = ['PENDING' => 'admin-badge-warn', 'APPROVED' => 'admin-badge-purple', 'PROCESSING' => 'admin-badge-purple', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'REJECTED' => 'admin-badge-danger'];

$pageTitle = 'Payouts';
render_admin_head('payouts');
?>

<div class="admin-page-head">
  <div><h1>Organizer payouts</h1><p>Recorded here after you pay an organizer manually — there's no automated disbursement yet.</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Payout updated." hidden></span><?php endif; ?>

<div class="admin-kpi-grid" style="margin-bottom:24px">
  <div class="admin-kpi-card"><div class="lbl">Pending</div><span class="num">UGX <?= number_format($pending, 0) ?></span><span class="sub"><?= count(array_filter($payouts, static fn ($p) => in_array($p['status'], ['PENDING', 'APPROVED', 'PROCESSING'], true))) ?> payouts</span></div>
  <div class="admin-kpi-card"><div class="lbl">Paid out</div><span class="num">UGX <?= number_format($paid, 0) ?></span><span class="sub">Lifetime</span></div>
</div>

<?php if (!$payouts): ?>
  <div class="admin-empty">
    <svg width="40" height="40"><use href="#ic-cash"/></svg>
    <h3>No payouts recorded yet</h3>
    <p>Record a payout from an organizer's detail page once they have an available balance.</p>
    <a class="btn btn-line" href="/admin-organizers.php">Go to Organizers</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Organizer</th><th>Amount</th><th>Method</th><th>Destination</th><th>Requested</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($payouts as $p): ?>
          <tr>
            <td><a class="link" href="/admin-organizer-detail.php?id=<?= (int) $p['organizer_id'] ?>"><?= htmlspecialchars($p['organizer_name']) ?></a></td>
            <td class="mono"><?= htmlspecialchars($p['currency']) ?> <?= number_format((float) $p['amount'], 0) ?></td>
            <td class="muted"><?= htmlspecialchars(str_replace('_', ' ', $p['method'])) ?></td>
            <td class="muted mono"><?= htmlspecialchars($p['destination']) ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($p['requested_at']))) ?></td>
            <td><span class="admin-badge <?= $statusMeta[$p['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($p['status']) ?></span></td>
            <td style="white-space:nowrap">
              <?php if (admin_can('payouts.process') && in_array($p['status'], ['PENDING', 'APPROVED', 'PROCESSING'], true)): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">
                  <select name="status" class="auto-submit" onchange="var el=this, v=this.value; window.adminConfirm('Update payout status to '+v+'?').then(function(ok){ if(ok){ el.form.requestSubmit(); } else { el.selectedIndex=0; } });">
                    <option value="">Update status…</option>
                    <?php foreach (['APPROVED', 'PROCESSING', 'PAID', 'FAILED', 'REJECTED'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                  </select>
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
