<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('orders.view');

$flash = null;
$flashOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'test') {
        $to = (string) $admin['email'];
        $ok = resend_send($to, 'obitickets test email', '<div style="font-family:Arial,sans-serif;padding:20px"><h2>It works</h2><p>This is a test email from the obitickets admin. If you can read it, ticket emails can reach people too.</p></div>', 'TEST', null);
        $flashOk = $ok;
        $flash = $ok ? "Test email sent to $to. Check that inbox (and its spam folder)." : 'The test email was not sent. The reason is the top row in the table below.';
    } elseif ($action === 'resend_missing') {
        $r = tickets_resend_undelivered(50);
        $flashOk = $r['failed'] === 0 && !$r['stopped'];
        $flash = "Ticket emails sent: {$r['sent']}. Refused: {$r['failed']}."
            . ($r['stopped'] ? ' Stopped early because three in a row were refused. Fix the cause in the Set-up check above (the reason is in the log table below), then press the button again.' : '')
            . ($r['remaining'] > 0 && !$r['stopped'] ? " {$r['remaining']} still waiting." : '');
    } elseif ($action === 'resend') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $ok = $orderId > 0 && send_order_tickets_email($orderId);
        $flashOk = $ok;
        $flash = $ok ? "Ticket email for order #$orderId sent again." : "The ticket email for order #$orderId was not accepted. The reason is the top row in the table below.";
    }
}

$ready = email_log_ready();
$status = in_array($_GET['status'] ?? '', ['SENT', 'FAILED', 'SKIPPED'], true) ? $_GET['status'] : '';
$rows = [];
$stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
if ($ready) {
    $sql = 'SELECT * FROM email_log' . ($status !== '' ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT 100';
    $stmt = db()->prepare($sql);
    $stmt->execute($status !== '' ? [$status] : []);
    $rows = $stmt->fetchAll();
    foreach (db()->query("SELECT status, COUNT(*) n FROM email_log WHERE created_at >= NOW() - INTERVAL 24 HOUR GROUP BY status")->fetchAll() as $r) {
        $stats[strtolower($r['status'])] = (int) $r['n'];
    }
}

$checks = [
    ['Sending key (Resend)', (bool) RESEND_API_KEY, RESEND_API_KEY ? 'Set' : 'Missing: no email can be sent until it is added to the server config'],
    ['Sender address', (bool) EMAIL_FROM, (string) EMAIL_FROM . ' (this domain must be verified in your Resend account)'],
    ['Site address in emails', !preg_match('#localhost|127\.0\.0\.1#', (string) APP_URL), (string) APP_URL . (preg_match('#localhost|127\.0\.0\.1#', (string) APP_URL) ? ' (links in emails would point to a local address)' : '')],
    ['Email log table', $ready, $ready ? 'Ready' : 'Missing: run migration/022_email_log.sql'],
];

$badge = ['SENT' => 'admin-badge-success', 'FAILED' => 'admin-badge-danger', 'SKIPPED' => 'admin-badge-muted'];
$pageTitle = 'Email log';
render_admin_head('emails');
?>

<div class="admin-page-head">
  <div><h1>Email log</h1><p>Every email the platform tries to send, and what happened to it.</p></div>
  <form method="post" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test">
    <button class="btn btn-purple" type="submit">Send me a test email</button>
  </form>
</div>

<?php if ($flash): ?>
  <div class="alert <?= $flashOk ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:18px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:12px">Set-up check</h3>
  <div style="display:grid; gap:10px">
    <?php foreach ($checks as [$label, $good, $note]): ?>
      <div style="display:flex; gap:12px; align-items:flex-start">
        <span class="admin-badge <?= $good ? 'admin-badge-success' : 'admin-badge-danger' ?>" style="flex:none"><?= $good ? 'OK' : 'Fix' ?></span>
        <div><b><?= htmlspecialchars($label) ?></b><div class="muted" style="font-size:0.84rem"><?= htmlspecialchars($note) ?></div></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php $undelivered = $ready ? tickets_undelivered_orders() : []; $undeliveredPast = $ready ? tickets_undelivered_past_count() : 0; ?>
<?php if ($ready && ($undelivered || $undeliveredPast)): ?>
<div class="admin-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:6px">Buyers who never got their tickets</h3>
  <p class="muted" style="font-size:0.88rem; margin:0 0 14px">Paid orders for events that have not finished, where no ticket email was ever accepted by the email service. Online events include the meeting link in the email.</p>
  <?php if ($undelivered): ?>
    <div class="admin-table-wrap" style="margin-bottom:14px">
      <table class="admin-table">
        <thead><tr><th>Order</th><th>Buyer</th><th>Event</th><th>Paid</th><th>Last attempt</th></tr></thead>
        <tbody>
          <?php foreach ($undelivered as $u): ?>
            <tr>
              <td><a href="/admin-order-detail.php?id=<?= (int) $u['id'] ?>">#<?= (int) $u['id'] ?></a></td>
              <td><?= htmlspecialchars($u['buyer_name']) ?><div class="muted" style="font-size:0.8rem"><?= htmlspecialchars($u['buyer_email']) ?></div></td>
              <td><?= htmlspecialchars(mb_strimwidth($u['event_title'], 0, 48, '…')) ?><div class="muted" style="font-size:0.8rem"><?= $u['attend_mode'] === 'IN_PERSON' ? 'In person' : ($u['attend_mode'] === 'ONLINE' ? 'Online' : 'Hybrid') ?> &middot; <?= htmlspecialchars(date('j M Y, g:i A', strtotime($u['starts_at']))) ?></div></td>
              <td class="muted mono" style="white-space:nowrap"><?= $u['paid_at'] ? htmlspecialchars(date('d M, g:i A', strtotime($u['paid_at']))) : '' ?></td>
              <td class="muted" style="font-size:0.82rem"><?= $u['last_status'] ? htmlspecialchars(ucfirst(strtolower($u['last_status']))) : 'Never tried (before the log)' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin:0" onsubmit="return confirm('Email tickets to <?= count($undelivered) ?> buyer(s) now?');">
      <?= csrf_field() ?><input type="hidden" name="action" value="resend_missing">
      <button class="btn btn-purple" type="submit">Send tickets to all <?= count($undelivered) ?> buyer(s)</button>
    </form>
  <?php else: ?>
    <p style="margin:0; color:#1B7A3D">Everyone with a ticket for an upcoming event has been emailed.</p>
  <?php endif; ?>
  <?php if ($undeliveredPast): ?>
    <p class="muted" style="font-size:0.82rem; margin:12px 0 0"><?= $undeliveredPast ?> more paid order(s) are for events that already took place and were never emailed. They are not included; tickets are still on each buyer's My tickets page.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="admin-mini-stat-row">
  <div class="admin-mini-stat"><span class="n"><?= $stats['sent'] ?></span><span class="l">Sent, last 24 hours</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $stats['failed'] ?></span><span class="l">Failed, last 24 hours</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $stats['skipped'] ?></span><span class="l">Skipped, last 24 hours</span></div>
</div>

<?php if (!$ready): ?>
  <div class="admin-empty"><h3>The email log is not set up yet</h3><p>Run <b>migration/022_email_log.sql</b> in phpMyAdmin. Emails keep sending in the meantime, they just are not recorded.</p></div>
<?php else: ?>
  <form class="admin-filter-bar" method="get">
    <select name="status">
      <option value="">All emails</option>
      <?php foreach (['SENT' => 'Sent', 'FAILED' => 'Failed', 'SKIPPED' => 'Skipped'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-line" type="submit" style="padding:9px 18px">Filter</button>
    <span class="muted" style="font-size:0.82rem; margin-left:auto">Latest 100</span>
  </form>

  <?php if (!$rows): ?>
    <div class="admin-empty"><h3>Nothing here yet</h3><p>Emails will appear the moment the site sends one: tickets to buyers, and sale notices to organizers and admins.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>When</th><th>Kind</th><th>To</th><th>Subject</th><th>Result</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="muted mono" style="white-space:nowrap"><?= htmlspecialchars(date('d M, g:i A', strtotime($r['created_at']))) ?></td>
              <td><span class="admin-badge admin-badge-muted"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $r['kind'])))) ?></span></td>
              <td><?= htmlspecialchars($r['to_email']) ?></td>
              <td class="muted" style="max-width:280px"><?= htmlspecialchars(mb_strimwidth($r['subject'], 0, 70, '…')) ?></td>
              <td>
                <span class="admin-badge <?= $badge[$r['status']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars(ucfirst(strtolower($r['status']))) ?></span>
                <?php if ($r['error']): ?><div class="muted" style="font-size:0.78rem; margin-top:4px; max-width:340px; overflow-wrap:anywhere" title="<?= htmlspecialchars($r['error']) ?>"><?= htmlspecialchars(mb_strimwidth($r['error'], 0, 160, '…')) ?></div><?php endif; ?>
              </td>
              <td style="white-space:nowrap">
                <?php if ($r['order_id']): ?>
                  <a class="btn btn-line" style="padding:6px 12px; font-size:0.8rem" href="/admin-order-detail.php?id=<?= (int) $r['order_id'] ?>">Order #<?= (int) $r['order_id'] ?></a>
                <?php endif; ?>
                <?php if ($r['kind'] === 'TICKET' && $r['order_id'] && $r['status'] !== 'SENT'): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="order_id" value="<?= (int) $r['order_id'] ?>"><button class="btn btn-purple" style="padding:6px 12px; font-size:0.8rem" type="submit">Send again</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
