<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('ORGANIZER');

$withdrawError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    verify_csrf();
    [$ok, $err] = request_withdrawal(
        (int) $user['id'],
        (float) ($_POST['amount'] ?? 0),
        in_array($_POST['method'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY', 'BANK'], true) ? $_POST['method'] : 'MTN_MOMO',
        (string) ($_POST['destination'] ?? '')
    );
    if ($ok) {
        header('Location: /my-events.php?withdrawn=1');
        exit;
    }
    $withdrawError = $err;
}

$events = get_events_for_organizer((int) $user['id']);
$balance = get_organizer_balance((int) $user['id']);
$pendingPayouts = get_pending_payouts_for_organizer((int) $user['id']);

$stmt = db()->prepare('SELECT payout_provider, payout_phone FROM organizer_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: ['payout_provider' => 'MTN_MOMO', 'payout_phone' => ''];

$statusLabels = ['PUBLISHED' => 'Published', 'DRAFT' => 'Draft', 'CANCELLED' => 'Cancelled'];
$payoutStatusLabels = ['PENDING' => 'Pending review', 'APPROVED' => 'Approved', 'PROCESSING' => 'Processing'];

$pageTitle = 'My events — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <div class="sec-top">
      <h2>My events</h2>
      <a class="btn btn-purple" href="/event-create.php">+ Create event</a>
    </div>

    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">Event created.</div><?php endif; ?>
    <?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Event updated.</div><?php endif; ?>
    <?php if (isset($_GET['withdrawn'])): ?><div class="alert alert-success">Withdrawal requested &mdash; we'll process it shortly.</div><?php endif; ?>
    <?php if ($withdrawError): ?><div class="alert alert-error"><?= htmlspecialchars($withdrawError) ?></div><?php endif; ?>

    <?php if ($balance): ?>
      <div class="payout-card" style="margin-bottom:28px">
        <?php foreach ($balance as $cur => $b): ?>
          <div class="payout-card-row">
            <div class="payout-balance">
              <span class="lbl">Available to withdraw</span>
              <span class="num"><?= htmlspecialchars($cur) ?> <?= number_format($b['available'], 0) ?></span>
              <?php if ($b['pending'] > 0): ?><span class="sub"><?= htmlspecialchars($cur) ?> <?= number_format($b['pending'], 0) ?> pending withdrawal</span><?php endif; ?>
            </div>
            <?php if ($b['available'] > 0 && !$pendingPayouts): ?>
              <button type="button" class="btn btn-purple" id="withdrawToggle">Request withdrawal</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($pendingPayouts): ?>
          <div class="payout-pending-list">
            <?php foreach ($pendingPayouts as $p): ?>
              <div class="payout-pending-row">
                <span><?= htmlspecialchars($p['currency']) ?> <?= number_format((float) $p['amount'], 0) ?> &middot; <?= htmlspecialchars(str_replace('_', ' ', $p['method'])) ?></span>
                <span class="tag-pill" style="background:var(--purple-tint); color:var(--purple-deep)"><?= htmlspecialchars($payoutStatusLabels[$p['status']] ?? $p['status']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="payout-withdraw-form" id="withdrawForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="withdraw">
          <div class="field-grid-2">
            <div class="field">
              <label>Amount (UGX)</label>
              <input type="number" name="amount" min="1" step="1" max="<?= (float) array_sum(array_column($balance, 'available')) ?>" required>
            </div>
            <div class="field">
              <label>Method</label>
              <select name="method">
                <option value="MTN_MOMO" <?= $profile['payout_provider'] === 'MTN_MOMO' ? 'selected' : '' ?>>MTN MoMo</option>
                <option value="AIRTEL_MONEY" <?= $profile['payout_provider'] === 'AIRTEL_MONEY' ? 'selected' : '' ?>>Airtel Money</option>
                <option value="BANK" <?= $profile['payout_provider'] === 'BANK' ? 'selected' : '' ?>>Bank transfer</option>
              </select>
            </div>
          </div>
          <div class="field">
            <label>Phone number or account</label>
            <input type="text" name="destination" value="<?= htmlspecialchars($profile['payout_phone'] ?? '') ?>" required placeholder="e.g. 0772 123 456">
          </div>
          <button class="btn btn-purple btn-block" type="submit">Submit withdrawal request</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (!$events): ?>
      <p style="text-align:center; padding:40px 0">You haven't created any events yet &mdash; <a href="/event-create.php">create your first one</a>.</p>
    <?php else: ?>
      <div class="my-events-list">
        <?php foreach ($events as $e): ?>
          <div class="my-event-row">
            <?php if (!empty($e['banner_image'])): ?>
              <div class="my-event-icon" style="background-image:url('<?= htmlspecialchars($e['banner_image']) ?>'); background-size:cover; background-position:center;"></div>
            <?php else: ?>
              <div class="my-event-icon"><?= htmlspecialchars($e['banner_emoji']) ?></div>
            <?php endif; ?>
            <div class="my-event-info">
              <h3><?= htmlspecialchars($e['title']) ?></h3>
              <p><?= htmlspecialchars(format_event_date_range($e['starts_at'], $e['ends_at'])) ?> &middot; <?= htmlspecialchars($e['venue_name']) ?></p>
              <div class="tag-list" style="margin-top:8px">
                <span class="tag-pill" style="<?= $e['status'] === 'PUBLISHED' ? '' : 'background:var(--line); color:var(--muted)' ?>"><?= $statusLabels[$e['status']] ?? $e['status'] ?></span>
                <span class="tag-pill"><?= (int) $e['tickets_sold'] ?> sold</span>
              </div>
              <?php if ((float) $e['gross_revenue'] > 0): ?>
                <p style="margin-top:8px; font-size:0.82rem">
                  <span class="mono"><?= htmlspecialchars($e['currency'] . ' ' . number_format((float) $e['gross_revenue'], 0)) ?></span> gross &middot;
                  payout <span class="mono"><?= htmlspecialchars($e['currency'] . ' ' . number_format((float) $e['gross_revenue'] - (float) $e['commission_owed'], 0)) ?></span>
                  <span style="color:var(--muted-2)">after 10% commission</span>
                </p>
              <?php endif; ?>
            </div>
            <div style="display:flex; gap:8px; flex:none">
              <a class="btn btn-line" href="/checkin.php?event=<?= (int) $e['id'] ?>">Check in</a>
              <a class="btn btn-line" href="/event-edit.php?id=<?= (int) $e['id'] ?>">Edit</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
