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

    <?php if ($result): $rc = $resultType === 'success' ? 'success' : ($resultType === 'warning' ? 'warn' : 'danger'); ?>
      <div class="admin-card admin-checkin-result" style="margin-top:20px; border-color:var(--<?= $rc ?>-border); background:var(--<?= $rc ?>-bg);">
        <div style="display:flex; align-items:center; gap:14px; margin-bottom:<?= isset($result['event_title']) ? '16px' : '0' ?>;">
          <span class="admin-checkin-result-ic" style="background:var(--surface); color:var(--<?= $rc ?>)"><svg width="24" height="24"><use href="#<?= $resultType === 'success' ? 'ic-check' : ($resultType === 'warning' ? 'ic-info' : 'ic-x') ?>"/></svg></span>
          <strong style="color:var(--<?= $rc ?>); font-size:1rem;"><?= htmlspecialchars($result['message']) ?></strong>
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
    <?php if ($eventStats): $rate = $eventStats['total'] > 0 ? round($eventStats['checked_in'] / $eventStats['total'] * 100) : 0; ?>
      <div class="admin-kpi-grid">
        <div class="admin-kpi-card">
          <div class="admin-kpi-card-top">
            <span class="lbl">Sold</span>
            <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-ticket"/></svg></span>
          </div>
          <span class="num"><span data-count-to="<?= (int) $eventStats['total'] ?>">0</span></span>
        </div>
        <div class="admin-kpi-card">
          <div class="admin-kpi-card-top">
            <span class="lbl">Checked in</span>
            <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-check"/></svg></span>
          </div>
          <span class="num"><span data-count-to="<?= (int) $eventStats['checked_in'] ?>">0</span></span>
        </div>
        <div class="admin-kpi-card">
          <div class="admin-kpi-card-top">
            <span class="lbl">Rate</span>
            <span class="admin-kpi-ic" style="background:<?= $rate >= 75 ? 'var(--success-bg); color:var(--success)' : 'var(--warn-bg); color:var(--warn)' ?>"><svg width="17" height="17"><use href="#ic-bolt"/></svg></span>
          </div>
          <span class="num"><span data-count-to="<?= $rate ?>">0</span>%</span>
        </div>
      </div>
    <?php else: ?>
      <div class="admin-empty" style="padding:28px 24px">
        <div class="admin-empty-ic"><svg width="24" height="24"><use href="#ic-cal"/></svg></div>
        <h3>No event selected</h3>
        <p>Pick an event above to see its live check-in progress.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_admin_foot(); ?>
