<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Admin "Socials plans": who is on the free month, who pays, who has lapsed, the money in, and the payments.
// An admin can add days to an organizer's plan (no money, recorded as GRANTED) or end it now.
// Posts themselves are moderated on the Social moderation page.
$admin = require_admin_permission('organizers.view');
// Adding free days or ending a plan touches money, so only super admins may do it (no other role has this permission).
$canManage = admin_can('socials.manage');

$messages = ['granted' => 'Days added to the plan.', 'ended' => 'Plan ended. Their posts stay; they cannot post new ones.'];
$error = null;
$ready = socials_ready();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to change Socials plans.');
    }
    $orgId = (int) ($_POST['organizer_id'] ?? 0);
    $isOrganizer = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'ORGANIZER'");
    $isOrganizer->execute([$orgId]);
    if (!$isOrganizer->fetchColumn()) {
        $error = 'That organizer was not found.';
    } elseif (($_POST['action'] ?? '') === 'grant') {
        $days = (int) ($_POST['days'] ?? 0);
        if ($days < 1 || $days > 366) {
            $error = 'Choose between 1 and 366 days.';
        } elseif (socials_admin_grant($orgId, $days, (int) $admin['id'])) {
            log_admin_action((int) $admin['id'], 'socials.grant', 'user', $orgId, ['days' => $days]);
            header('Location: /admin-socials.php?done=granted');
            exit;
        } else {
            $error = 'Could not add the days. Please try again.';
        }
    } elseif (($_POST['action'] ?? '') === 'end') {
        socials_admin_end($orgId);
        log_admin_action((int) $admin['id'], 'socials.end', 'user', $orgId);
        header('Location: /admin-socials.php?done=ended');
        exit;
    }
}

$totals = socials_admin_totals();
$plans = socials_admin_list();
$payments = socials_admin_payments(40);
$organizers = $ready && $canManage
    ? db()->query("SELECT u.id, COALESCE(NULLIF(op.org_name, ''), u.name) AS name FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE u.role = 'ORGANIZER' AND u.account_status = 'ACTIVE' ORDER BY name LIMIT 500")->fetchAll()
    : [];

$pageTitle = 'Socials plans';
render_admin_head('socials');
?>
<div class="admin-page-head">
  <div><h1>Socials plans</h1><p>Organizers pay <?= htmlspecialchars(socials_price_label()) ?> for every <?= (int) SOCIALS_PERIOD_DAYS ?> days to post on Socials. New organizers get <?= (int) SOCIALS_TRIAL_DAYS ?> days free.</p></div>
  <a class="btn btn-line" href="/socials.php">Open the Socials feed</a>
</div>
<?php if (isset($_GET['done'], $messages[$_GET['done']])): ?><span data-flash="<?= htmlspecialchars($messages[$_GET['done']], ENT_QUOTES) ?>" hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$ready): ?>
  <div class="admin-card"><h2 style="font-size:1.1rem">Socials is not switched on yet</h2><p style="margin-top:6px">Run <code>migration/029_socials.sql</code> in phpMyAdmin to create the two Socials tables. Until then posting works as before, with no plan needed.</p></div>
<?php else: ?>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card"><div class="admin-kpi-label">Paying now</div><div class="admin-kpi-value"><?= (int) $totals['active'] ?></div></div>
  <div class="admin-kpi-card"><div class="admin-kpi-label">On the free month</div><div class="admin-kpi-value"><?= (int) $totals['trial'] ?></div></div>
  <div class="admin-kpi-card"><div class="admin-kpi-label">Ended</div><div class="admin-kpi-value"><?= (int) $totals['expired'] ?></div></div>
  <div class="admin-kpi-card"><div class="admin-kpi-label">Paid in the last 30 days</div><div class="admin-kpi-value">UGX <?= number_format($totals['revenue_30']) ?></div></div>
  <div class="admin-kpi-card"><div class="admin-kpi-label">Paid in total</div><div class="admin-kpi-value">UGX <?= number_format($totals['revenue']) ?></div></div>
</div>

<?php if ($canManage && $organizers): ?>
<section class="admin-card" style="margin-top:18px">
  <h2 style="font-size:1.05rem">Add days to a plan</h2>
  <p style="margin-top:4px; font-size:.88rem">For a courtesy, a partnership or to fix a payment problem. No money is collected; it shows as "Added by obitickets".</p>
  <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; align-items:flex-end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="grant">
    <label style="display:grid; gap:4px; flex:1 1 240px; font-weight:700; font-size:.82rem">Organizer
      <select name="organizer_id" required><?php foreach ($organizers as $o): ?><option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label style="display:grid; gap:4px; width:120px; font-weight:700; font-size:.82rem">Days
      <input type="number" name="days" min="1" max="366" value="<?= (int) SOCIALS_PERIOD_DAYS ?>" required>
    </label>
    <button class="btn btn-purple" type="submit">Add days</button>
  </form>
</section>
<?php endif; ?>

<section class="admin-card" style="margin-top:18px">
  <h2 style="font-size:1.05rem">Organizers</h2>
  <?php if (!$plans): ?>
    <p style="margin-top:6px">No organizer has started Socials yet.</p>
  <?php else: ?>
  <div class="admin-table-wrap" style="margin-top:12px">
    <table class="admin-table">
      <thead><tr><th>Organizer</th><th>Plan</th><th>Until</th><th>Posts</th><th>Paid in total</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($plans as $p):
          $state = (int) $p['is_paid'] === 1 ? 'Paying' : ((int) $p['in_trial'] === 1 ? 'Free month' : 'Ended');
          $until = (int) $p['is_paid'] === 1 ? $p['paid_until'] : ((int) $p['in_trial'] === 1 ? $p['trial_ends_at'] : max((string) $p['paid_until'], (string) $p['trial_ends_at']));
      ?>
        <tr>
          <td><strong><?= htmlspecialchars((string) ($p['org_name'] ?: $p['name'])) ?></strong><div class="muted" style="font-size:.8rem"><?= htmlspecialchars((string) $p['email']) ?></div></td>
          <td><span class="tag-pill"<?= $state === 'Ended' ? ' style="background:var(--line); color:var(--muted)"' : '' ?>><?= $state ?></span></td>
          <td><?= $until ? htmlspecialchars(date('j M Y', strtotime((string) $until))) : '&ndash;' ?></td>
          <td><?= (int) $p['post_count'] ?></td>
          <td class="mono">UGX <?= number_format((float) $p['total_paid']) ?></td>
          <?php if ($canManage): ?>
          <td><?php if ($state !== 'Ended'): ?>
            <form method="post" data-confirm="End this organizer's Socials plan now? Their posts stay up, but they cannot post new ones until they pay.">
              <?= csrf_field() ?><input type="hidden" name="action" value="end"><input type="hidden" name="organizer_id" value="<?= (int) $p['user_id'] ?>">
              <button class="btn btn-line" type="submit">End plan</button>
            </form>
          <?php endif; ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="admin-card" style="margin-top:18px">
  <h2 style="font-size:1.05rem">Recent payments</h2>
  <?php if (!$payments): ?>
    <p style="margin-top:6px">No payments yet.</p>
  <?php else: ?>
  <div class="admin-table-wrap" style="margin-top:12px">
    <table class="admin-table">
      <thead><tr><th>#</th><th>Organizer</th><th>Amount</th><th>Status</th><th>Phone</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $pay): ?>
        <tr>
          <td class="mono">S<?= (int) $pay['id'] ?></td>
          <td><?= htmlspecialchars((string) ($pay['org_name'] ?: $pay['name'])) ?></td>
          <td class="mono"><?= $pay['status'] === 'GRANTED' ? 'Added, no charge' : 'UGX ' . number_format((float) $pay['amount']) ?></td>
          <td><?= htmlspecialchars(['PAID' => 'Paid', 'GRANTED' => 'Added by admin', 'FAILED' => 'Failed', 'PENDING' => 'Waiting'][$pay['status']] ?? $pay['status']) ?><?php if ($pay['status'] === 'FAILED' && $pay['status_message']): ?><div class="muted" style="font-size:.78rem"><?= htmlspecialchars((string) $pay['status_message']) ?></div><?php endif; ?></td>
          <td class="mono"><?= htmlspecialchars((string) ($pay['phone'] ?? '')) ?></td>
          <td><?= htmlspecialchars(date('j M Y, g:ia', strtotime((string) $pay['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php render_admin_foot(); ?>
