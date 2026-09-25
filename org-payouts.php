<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('payouts.view');
$organizerId = $ctx['organizer_id'];
$withdrawError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    verify_csrf();
    if (!organizer_can($ctx, 'payouts.request')) {
        http_response_code(403);
        exit('You do not have permission to request payouts.');
    }
    [$ok, $err] = request_withdrawal(
        $organizerId,
        (float) ($_POST['amount'] ?? 0),
        in_array($_POST['method'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY', 'BANK'], true) ? $_POST['method'] : 'MTN_MOMO',
        (string) ($_POST['destination'] ?? '')
    );
    if ($ok) {
        header('Location: /org-payouts.php?withdrawn=1');
        exit;
    }
    $withdrawError = $err;
}

$balance = get_organizer_balance($organizerId);
$pendingPayouts = get_pending_payouts_for_organizer($organizerId);
$history = get_payouts_for_organizer($organizerId);

$stmt = db()->prepare('SELECT payout_provider, payout_phone FROM organizer_profiles WHERE user_id = ?');
$stmt->execute([$organizerId]);
$profile = $stmt->fetch() ?: ['payout_provider' => 'MTN_MOMO', 'payout_phone' => ''];

$statusMeta = ['PENDING' => 'admin-badge-warn', 'APPROVED' => 'admin-badge-purple', 'PROCESSING' => 'admin-badge-purple', 'PAID' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'REJECTED' => 'admin-badge-danger'];

$pageTitle = 'Payouts';
render_organizer_head('payouts', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Payouts</h1><p>Request a withdrawal from your available balance and track its status.</p></div>
</div>

<?php if (isset($_GET['withdrawn'])): ?><span data-flash="Withdrawal requested — we'll process it shortly." hidden></span><?php endif; ?>
<?php if ($withdrawError): ?><span data-flash="<?= htmlspecialchars($withdrawError, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-kpi-grid" style="margin-bottom:24px">
  <?php foreach ($balance as $cur => $b): ?>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top">
        <span class="lbl">Available balance</span>
        <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span>
      </div>
      <span class="num"><span data-count-to="<?= (int) $b['available'] ?>" data-count-prefix="<?= htmlspecialchars($cur) ?> ">0</span></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top">
        <span class="lbl">Pending withdrawal</span>
        <span class="admin-kpi-ic" style="background:var(--warn-bg); color:var(--warn)"><svg width="17" height="17"><use href="#ic-clock"/></svg></span>
      </div>
      <span class="num"><span data-count-to="<?= (int) $b['pending'] ?>" data-count-prefix="<?= htmlspecialchars($cur) ?> ">0</span></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top">
        <span class="lbl">Total paid out</span>
        <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-shield"/></svg></span>
      </div>
      <span class="num"><span data-count-to="<?= (int) $b['paid_out'] ?>" data-count-prefix="<?= htmlspecialchars($cur) ?> ">0</span></span>
    </div>
  <?php endforeach; ?>
</div>

<div class="admin-grid-2">
  <div class="admin-card">
    <h3 style="margin-bottom:14px">Payout history</h3>
    <?php if (!$history): ?>
      <div class="admin-empty" style="padding:28px 24px">
        <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-cash"/></svg></div>
        <p style="margin-top:0">No withdrawal requests yet.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>Amount</th><th>Method</th><th>Requested</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($history as $p): ?>
              <tr class="<?= $p['status'] === 'PENDING' ? 'admin-row-attention' : '' ?>">
                <td class="mono" style="font-weight:700"><?= htmlspecialchars($p['currency']) ?> <?= number_format((float) $p['amount'], 0) ?></td>
                <td class="muted"><?= htmlspecialchars(str_replace('_', ' ', $p['method'])) ?></td>
                <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($p['requested_at']))) ?></td>
                <td><span class="admin-badge <?= $statusMeta[$p['status']] ?? 'admin-badge-muted' ?>"><?= $p['status'] === 'PENDING' ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars($p['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if (organizer_can($ctx, 'payouts.request')): ?>
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Request a withdrawal</h3>
      <?php if ($pendingPayouts): ?>
        <div class="admin-empty" style="padding:24px">
          <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-clock"/></svg></div>
          <p style="margin-top:0">You already have a withdrawal request being processed. You can request another once it's resolved.</p>
        </div>
      <?php elseif (array_sum(array_column($balance, 'available')) <= 0): ?>
        <p class="muted" style="font-size:0.86rem">You don't have any available balance to withdraw yet.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
          <div class="admin-form-row"><label>Amount (UGX)</label><input type="number" name="amount" min="1" step="1" max="<?= (float) array_sum(array_column($balance, 'available')) ?>" required></div>
          <div class="admin-form-row"><label>Method</label>
            <select name="method">
              <option value="MTN_MOMO" <?= $profile['payout_provider'] === 'MTN_MOMO' ? 'selected' : '' ?>>MTN MoMo</option>
              <option value="AIRTEL_MONEY" <?= $profile['payout_provider'] === 'AIRTEL_MONEY' ? 'selected' : '' ?>>Airtel Money</option>
              <option value="BANK" <?= $profile['payout_provider'] === 'BANK' ? 'selected' : '' ?>>Bank transfer</option>
            </select>
          </div>
          <div class="admin-form-row"><label>Phone number or account</label><input type="text" name="destination" value="<?= htmlspecialchars($profile['payout_phone'] ?? '') ?>" required placeholder="e.g. 0772 123 456"></div>
          <button class="btn btn-purple btn-block" type="submit">Submit withdrawal request</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php render_organizer_foot(); ?>
