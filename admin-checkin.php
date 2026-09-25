<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('tickets.checkin');

$result = null;
$resultType = null; // success | warning | error
$code = trim((string) ($_POST['code'] ?? $_GET['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ticket = find_ticket_by_code($code);
    if (!$ticket) {
        $resultType = 'error';
        $result = ['message' => 'No ticket found for that code.'];
    } elseif ($ticket['status'] === 'CANCELLED') {
        $resultType = 'error';
        $result = $ticket + ['message' => 'This ticket has been cancelled — do not admit.'];
    } elseif ($ticket['status'] === 'USED') {
        $resultType = 'warning';
        $result = $ticket + ['message' => 'Already checked in at ' . date('d M, H:i', strtotime($ticket['checked_in_at']))];
    } else {
        admin_check_in((int) $admin['id'], (int) $ticket['id']);
        $resultType = 'success';
        $result = $ticket + ['message' => 'Valid — checked in just now.'];
    }
}

$eventId = (int) ($_GET['event_id'] ?? 0);
$stmt = db()->query("SELECT id, title FROM events WHERE status = 'PUBLISHED' ORDER BY starts_at DESC LIMIT 100");
$eventOptions = $stmt->fetchAll();
$eventStats = $eventId ? get_checkin_stats($eventId) : null;

$pageTitle = 'Check-In';
render_admin_head('checkin');
?>

<div class="admin-page-head">
  <div><h1>Check-in desk</h1><p>Search a ticket code to validate and check an attendee in.</p></div>
</div>

<div class="admin-grid-2">
  <div>
    <div class="admin-card">
      <form method="post">
        <?= csrf_field() ?>
        <div class="admin-form-row">
          <label>Ticket code</label>
          <input type="text" name="code" value="" autofocus placeholder="Scan or type the ticket code" style="font-family:'IBM Plex Mono',monospace; font-size:1.1rem; text-transform:uppercase" autocomplete="off">
        </div>
        <button class="btn btn-purple btn-block btn-lg" type="submit">Validate &amp; check in</button>
      </form>
    </div>

    <?php if ($result): ?>
      <div class="admin-card" style="margin-top:20px; border-color:var(--<?= $resultType === 'success' ? 'success' : ($resultType === 'warning' ? 'warn' : 'danger') ?>-border); background:var(--<?= $resultType === 'success' ? 'success' : ($resultType === 'warning' ? 'warn' : 'danger') ?>-bg);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
          <svg width="22" height="22" style="color:var(--<?= $resultType === 'success' ? 'success' : ($resultType === 'warning' ? 'warn' : 'danger') ?>)"><use href="#<?= $resultType === 'success' ? 'ic-check' : ($resultType === 'warning' ? 'ic-info' : 'ic-x') ?>"/></svg>
          <strong style="color:var(--<?= $resultType === 'success' ? 'success' : ($resultType === 'warning' ? 'warn' : 'danger') ?>)"><?= htmlspecialchars($result['message']) ?></strong>
        </div>
        <?php if (isset($result['event_title'])): ?>
          <div class="admin-detail-row"><span class="k">Event</span><span class="v"><?= htmlspecialchars($result['event_title']) ?></span></div>
          <div class="admin-detail-row"><span class="k">Attendee</span><span class="v"><?= htmlspecialchars($result['attendee_name']) ?></span></div>
          <div class="admin-detail-row"><span class="k">Tier</span><span class="v"><?= htmlspecialchars($result['tier_name']) ?></span></div>
          <div class="admin-detail-row"><span class="k">Code</span><span class="v mono"><?= htmlspecialchars($result['ticket_code']) ?></span></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:14px">Live stats by event</h3>
    <form method="get" style="margin-bottom:16px">
      <select name="event_id" onchange="this.form.submit()">
        <option value="">Choose an event…</option>
        <?php foreach ($eventOptions as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $eventId === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($eventStats): ?>
      <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px;">
        <div class="admin-stat-mini"><span class="num"><?= $eventStats['total'] ?></span><span class="lbl">Sold</span></div>
        <div class="admin-stat-mini"><span class="num"><?= $eventStats['checked_in'] ?></span><span class="lbl">Checked in</span></div>
        <div class="admin-stat-mini"><span class="num"><?= $eventStats['total'] > 0 ? round($eventStats['checked_in'] / $eventStats['total'] * 100) : 0 ?>%</span><span class="lbl">Rate</span></div>
      </div>
    <?php else: ?>
      <p class="muted" style="font-size:0.86rem">Pick an event to see its check-in progress.</p>
    <?php endif; ?>
  </div>
</div>

<?php render_admin_foot(); ?>
