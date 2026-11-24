<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('payouts.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_can('payouts.process') && in_array($_POST['action'] ?? '', ['toggle_auto', 'send'], true)) {
    verify_csrf();
    if ($_POST['action'] === 'toggle_auto') {
        $turnOn = !empty($_POST['on']);
        if ($turnOn && !payout_auto_enabled() && !(payouts_auto_ready() && payouts_iotec_configured())) {
            header('Location: /admin-payouts.php?auto=unavailable');
            exit;
        }
        payout_set_auto((int) $admin['id'], $turnOn);
        header('Location: /admin-payouts.php?auto=' . ($turnOn ? 'on' : 'off'));
        exit;
    }
    $r = payout_send((int) $_POST['payout_id'], (int) $admin['id']);
    $_SESSION['payout_admin_msg'] = $r['message'];
    header('Location: /admin-payouts.php?sent=' . ($r['ok'] ? '1' : '0'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_can('payouts.process')) {
    verify_csrf();
    update_payout_status((int) $admin['id'], (int) $_POST['payout_id'], (string) $_POST['status']);
    header('Location: /admin-payouts.php?updated=1&status=' . htmlspecialchars((string) $_POST['status'], ENT_QUOTES));
    exit;
}

if (payouts_auto_ready()) {
    payout_poll_open(null, 10);   // refresh payouts ioTec is still processing
}
$payouts = get_payouts_admin();
$autoReady = payouts_auto_ready();
$autoOn = payout_auto_enabled();
$autoStats = payout_auto_stats();
$adminPayoutMsg = $_SESSION['payout_admin_msg'] ?? null;
unset($_SESSION['payout_admin_msg']);
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

<?php if (isset($_GET['sent'])): ?><span data-flash="<?= htmlspecialchars((string) $adminPayoutMsg, ENT_QUOTES) ?>"<?= $_GET['sent'] === '1' ? '' : ' data-flash-type="error"' ?> hidden></span><?php endif; ?>
<?php if (isset($_GET['auto'])): ?><span data-flash="<?= htmlspecialchars(['on' => 'Automatic payouts are ON: organizers are paid the moment they tap Withdraw.', 'off' => 'Automatic payouts are OFF: requests wait here for Send now.', 'unavailable' => 'Cannot switch on yet: run database update 044 and add the ioTec keys to the server config first.'][$_GET['auto']] ?? 'Updated.', ENT_QUOTES) ?>"<?= $_GET['auto'] === 'unavailable' ? ' data-flash-type="error"' : '' ?> hidden></span><?php endif; ?>
<?php if ($autoReady): ?>
<div class="admin-card" style="margin-bottom:24px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap">
  <div style="min-width:240px; flex:1">
    <h3 style="margin:0 0 4px">Automatic payouts: <span class="admin-badge <?= $autoOn ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $autoOn ? 'ON' : 'OFF' ?></span></h3>
    <p class="muted" style="margin:0; font-size:0.86rem">
      <?php if (!payouts_iotec_configured()): ?>ioTec keys are not in the server config yet, so money cannot be sent from here.
      <?php elseif ($autoOn): ?>When an organizer taps Withdraw, ioTec sends the money to their saved MTN or Airtel number at once. <?= (int) $autoStats['auto_paid'] ?> paid this way so far.
      <?php else: ?>Requests wait here. Use <b>Send now</b> to pay one through ioTec, or switch automatic payouts on.<?php endif; ?>
      <?php if ($autoStats['stuck'] > 0): ?><br><b style="color:var(--danger)"><?= (int) $autoStats['stuck'] ?> payout(s) need a check in the ioTec portal (see Status below).</b><?php endif; ?>
    </p>
  </div>
  <?php if (admin_can('payouts.process') && payouts_iotec_configured()): ?>
    <form method="post" data-confirm="<?= $autoOn ? 'Switch automatic payouts OFF? Requests will wait here for you.' : 'Switch automatic payouts ON? Organizers will be paid instantly from the ioTec wallet when they tap Withdraw.' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="toggle_auto"><?php if (!$autoOn): ?><input type="hidden" name="on" value="1"><?php endif; ?>
      <button type="submit" class="btn <?= $autoOn ? 'btn-line' : 'btn-purple' ?>"><?= $autoOn ? 'Switch OFF' : 'Switch ON' ?></button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
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
            <td><span class="admin-badge <?= $statusMeta[$p['status']] ?? 'admin-badge-muted' ?>"><?= $p['status'] === 'PENDING' ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars($p['status']) ?></span><?php if (!empty($p['failure_reason'])): ?><div class="muted" style="font-size:0.74rem; max-width:260px; margin-top:4px"><?= htmlspecialchars((string) $p['failure_reason']) ?></div><?php elseif (!empty($p['is_auto']) && $p['status'] === 'PAID'): ?><div class="muted" style="font-size:0.74rem">sent automatically</div><?php endif; ?></td>
            <td style="white-space:nowrap">
              <?php if (admin_can('payouts.process') && $isOpen): ?>
                <?php if ($autoReady && $p['status'] !== 'PROCESSING' && in_array($p['method'], ['MTN_MOMO', 'AIRTEL_MONEY'], true) && payouts_iotec_configured()): ?>
                  <form method="post" style="display:inline; margin-right:6px" data-confirm="Send UGX <?= number_format((float) $p['amount'], 0) ?> to <?= htmlspecialchars($p['destination'], ENT_QUOTES) ?> through ioTec now? This moves real money and cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="btn btn-purple" style="padding:7px 16px; font-size:0.82rem">Send now</button>
                  </form>
                <?php endif; ?>
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
