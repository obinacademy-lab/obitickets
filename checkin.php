<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('checkin.use');
$organizerId = $ctx['organizer_id'];

$eventId = (int) ($_GET['event'] ?? 0);
$event = $eventId ? get_event_by_id($eventId) : null;

if ($eventId && (!$event || (int) $event['organizer_id'] !== $organizerId)) {
    http_response_code(404);
    $pageTitle = 'Event not found';
    render_organizer_head('checkin', $ctx);
    ?>
    <div class="admin-empty">
      <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-x"/></svg></div>
      <h3>Event not found</h3>
      <p>This event doesn't exist, or isn't yours to check in.</p>
      <a class="btn btn-purple" href="/checkin.php">Back to check-in</a>
    </div>
    <?php
    render_organizer_foot();
    exit;
}

if (!$event) {
    // No event chosen yet — show a picker of this organizer's published events instead of 404ing.
    $pickerEvents = array_values(array_filter(get_events_for_organizer($organizerId), static fn ($e) => $e['status'] === 'PUBLISHED'));
    $pageTitle = 'Check-In';
    render_organizer_head('checkin', $ctx);
    ?>
    <div class="admin-page-head">
      <div><h1>Check-in desk</h1><p>Choose an event to start checking attendees in.</p></div>
    </div>
    <?php if (!$pickerEvents): ?>
      <div class="admin-empty">
        <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-check"/></svg></div>
        <h3>No published events yet</h3>
        <p>Publish an event to start checking attendees in at the door.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Event</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($pickerEvents as $e): ?>
              <tr>
                <td><?= htmlspecialchars($e['banner_emoji']) ?> <?= htmlspecialchars($e['title']) ?></td>
                <td class="muted mono"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?></td>
                <td><a class="btn btn-line" style="padding:7px 16px" href="/checkin.php?event=<?= (int) $e['id'] ?>">Open check-in &rarr;</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php
    render_organizer_foot();
    exit;
}

// POST → session flash → redirect to GET, so refreshing the page after a
// scan never re-submits the same code and never triggers a browser
// "resubmit form?" prompt at the door.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code = trim($_POST['code'] ?? '');
    $ticket = find_ticket_by_code($code);

    if ($code === '') {
        $result = ['type' => 'error', 'message' => 'Enter or scan a ticket code.'];
    } elseif (!$ticket) {
        $result = ['type' => 'error', 'message' => "No ticket found for code \"{$code}\"."];
        log_organizer_action($organizerId, $ctx['actor_id'], 'ticket.checkin_rejected', null, null, ['code' => $code, 'reason' => 'NOT_FOUND', 'event_id' => $eventId]);
    } elseif ((int) $ticket['event_id'] !== $eventId) {
        $result = ['type' => 'error', 'message' => "That ticket is for a different event (\"{$ticket['event_title']}\"), not this one.", 'ticket' => $ticket];
        log_organizer_action($organizerId, $ctx['actor_id'], 'ticket.checkin_rejected', 'ticket', (int) $ticket['id'], ['code' => $code, 'reason' => 'WRONG_EVENT', 'event_id' => $eventId]);
    } elseif ($ticket['status'] === 'CANCELLED') {
        $result = ['type' => 'error', 'message' => 'This ticket has been cancelled and cannot be used.', 'ticket' => $ticket];
        log_organizer_action($organizerId, $ctx['actor_id'], 'ticket.checkin_rejected', 'ticket', (int) $ticket['id'], ['code' => $code, 'reason' => 'CANCELLED', 'event_id' => $eventId]);
    } elseif ($ticket['status'] === 'USED') {
        $result = [
            'type' => 'warn',
            'message' => 'Already checked in at ' . date('g:ia \o\n D j M', strtotime($ticket['checked_in_at'])) . '.',
            'ticket' => $ticket,
        ];
        log_organizer_action($organizerId, $ctx['actor_id'], 'ticket.checkin_rejected', 'ticket', (int) $ticket['id'], ['code' => $code, 'reason' => 'ALREADY_USED', 'event_id' => $eventId]);
    } else {
        check_in_ticket((int) $ticket['id']);
        $result = ['type' => 'success', 'message' => 'Checked in.', 'ticket' => $ticket];
        log_organizer_action($organizerId, $ctx['actor_id'], 'ticket.checkin', 'ticket', (int) $ticket['id']);
    }

    $_SESSION['checkin_result'] = $result;
    header('Location: /checkin.php?event=' . $eventId);
    exit;
}

$result = $_SESSION['checkin_result'] ?? null;
unset($_SESSION['checkin_result']);

$stats = get_checkin_stats($eventId);
$rejectionCount = count_checkin_rejections_for_event($eventId);
$rejections = $rejectionCount ? get_checkin_rejections_for_event($eventId, 10) : [];
$rejectionReasonLabels = [
    'ALREADY_USED' => 'Already used',
    'CANCELLED' => 'Cancelled ticket',
    'WRONG_EVENT' => 'Different event',
    'NOT_FOUND' => 'Code not found',
];

$pageTitle = 'Check-in — ' . $event['title'];
render_organizer_head('checkin', $ctx);
?>

<div class="admin-card" style="max-width:520px; margin:0 auto">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px">
    <h2 style="font-size:1.3rem; margin:0"><?= htmlspecialchars($event['title']) ?></h2>
    <a class="btn btn-line" style="padding:7px 14px" href="/checkin.php">Switch event</a>
  </div>
  <p class="muted" style="font-size:0.9rem">Check-in desk</p>

  <div class="checkin-stat">
    <span class="num mono"><?= $stats['checked_in'] ?> / <?= $stats['total'] ?></span>
    <span class="lbl">tickets checked in</span>
  </div>
  <?php if ($rejectionCount > 0): ?>
    <div class="checkin-stat" style="margin-top:10px">
      <span class="num mono" style="color:var(--danger)"><?= $rejectionCount ?></span>
      <span class="lbl">flagged attempts &mdash; reused, cancelled or invalid codes</span>
    </div>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="alert alert-<?= $result['type'] === 'success' ? 'success' : ($result['type'] === 'warn' ? 'warn' : 'error') ?>" style="margin-top:20px">
      <?= htmlspecialchars($result['message']) ?>
    </div>
    <?php if (!empty($result['ticket'])): $t = $result['ticket']; ?>
      <div class="checkin-ticket-card">
        <div class="ticket-issued-tier"><?= htmlspecialchars($t['tier_name']) ?></div>
        <div class="ticket-issued-event"><?= htmlspecialchars($t['attendee_name']) ?> &middot; <?= htmlspecialchars($t['attendee_email']) ?></div>
        <div class="ticket-issued-divider"></div>
        <div class="ticket-issued-code mono"><?= htmlspecialchars($t['ticket_code']) ?></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="checkin-scan-area" style="margin-top:20px">
    <button type="button" class="btn btn-purple btn-lg btn-block" id="scanBtn">
      <svg width="18" height="18" style="margin-right:8px; vertical-align:-3px"><use href="#ic-grid"/></svg>
      Scan QR code
    </button>
    <div class="checkin-scan-view" id="scanView" hidden>
      <video id="scanVideo" playsinline muted></video>
      <button type="button" class="btn btn-line btn-block" id="scanCancelBtn" style="margin-top:12px">Cancel</button>
    </div>
    <p class="muted" id="scanStatus" style="margin-top:10px; font-size:0.82rem; text-align:center; min-height:1.2em"></p>
  </div>

  <button type="button" id="manualToggleBtn" style="display:block; margin:4px auto 0; padding:6px; background:none; border:none; cursor:pointer; font-size:0.85rem; color:var(--muted-2); text-decoration:underline;">Or enter the code manually</button>

  <form method="post" style="margin-top:16px" id="checkin-form" hidden>
    <?= csrf_field() ?>
    <div class="field">
      <label for="code">Ticket code</label>
      <input id="code" name="code" type="text" autocomplete="off" placeholder="Scan or type a code, e.g. OT-A1B2C3D4E5">
      <div class="field-hint">Also works with a USB QR/barcode scanner — it just types the code and presses Enter for you.</div>
    </div>
    <button class="btn btn-purple btn-lg btn-block" type="submit" style="margin-top:18px">Check in</button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
(function () {
  var scanBtn = document.getElementById('scanBtn');
  var scanView = document.getElementById('scanView');
  var scanVideo = document.getElementById('scanVideo');
  var scanCancelBtn = document.getElementById('scanCancelBtn');
  var scanStatus = document.getElementById('scanStatus');
  var manualToggleBtn = document.getElementById('manualToggleBtn');
  var checkinForm = document.getElementById('checkin-form');
  var codeInput = document.getElementById('code');
  if (!scanBtn) return;

  var stream = null;
  var rafId = null;
  var canvas = document.createElement('canvas');
  var canvasCtx = canvas.getContext('2d', { willReadFrequently: true });

  function showManualForm() {
    checkinForm.hidden = false;
    codeInput.focus();
  }

  function stopScan() {
    if (rafId) cancelAnimationFrame(rafId);
    rafId = null;
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    scanView.hidden = true;
    scanBtn.hidden = false;
  }

  function tick() {
    if (scanVideo.readyState === scanVideo.HAVE_ENOUGH_DATA) {
      canvas.width = scanVideo.videoWidth;
      canvas.height = scanVideo.videoHeight;
      canvasCtx.drawImage(scanVideo, 0, 0, canvas.width, canvas.height);
      var imageData = canvasCtx.getImageData(0, 0, canvas.width, canvas.height);
      var qr = (typeof jsQR === 'function') ? jsQR(imageData.data, imageData.width, imageData.height) : null;
      if (qr && qr.data) {
        stopScan();
        codeInput.value = qr.data.trim();
        checkinForm.hidden = false;
        checkinForm.requestSubmit();
        return;
      }
    }
    rafId = requestAnimationFrame(tick);
  }

  function startScan() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      scanStatus.textContent = 'Camera scanning isn\'t supported on this browser — enter the code manually below.';
      showManualForm();
      return;
    }
    if (typeof jsQR !== 'function') {
      scanStatus.textContent = 'Couldn\'t load the QR scanner — enter the code manually below.';
      showManualForm();
      return;
    }
    scanStatus.textContent = '';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s;
      scanVideo.srcObject = stream;
      return scanVideo.play();
    }).then(function () {
      scanBtn.hidden = true;
      scanView.hidden = false;
      scanStatus.textContent = 'Point the camera at the ticket\'s QR code.';
      rafId = requestAnimationFrame(tick);
    }).catch(function () {
      scanStatus.textContent = 'Camera access was denied or unavailable — enter the code manually below.';
      showManualForm();
    });
  }

  scanBtn.addEventListener('click', startScan);
  scanCancelBtn.addEventListener('click', stopScan);
  manualToggleBtn.addEventListener('click', function () {
    stopScan();
    showManualForm();
  });
})();
</script>

<?php if ($rejections): ?>
<div class="admin-card" style="max-width:520px; margin:20px auto 0">
  <h3 style="font-size:0.98rem; margin-bottom:4px">Flagged attempts</h3>
  <p class="muted" style="font-size:0.82rem; margin-bottom:14px">Rejected scans at this door — a repeated "Already used" for the same code is the clearest sign a ticket's been shared.</p>
  <div class="admin-table-wrap" style="border:none; box-shadow:none;">
    <table class="admin-table">
      <thead><tr><th>Code</th><th>Reason</th><th>Time</th></tr></thead>
      <tbody>
        <?php foreach ($rejections as $r): $d = json_decode($r['details'] ?? '', true) ?: []; ?>
          <tr>
            <td class="mono" style="font-size:0.82rem"><?= htmlspecialchars($d['code'] ?? '—') ?></td>
            <td><span class="admin-badge admin-badge-warn"><?= htmlspecialchars($rejectionReasonLabels[$d['reason'] ?? ''] ?? ($d['reason'] ?? 'Unknown')) ?></span></td>
            <td class="muted mono" style="font-size:0.78rem"><?= htmlspecialchars(date('g:ia D j M', strtotime($r['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php render_organizer_foot(); ?>
