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
    } elseif ((int) $ticket['event_id'] !== $eventId) {
        $result = ['type' => 'error', 'message' => "That ticket is for a different event (\"{$ticket['event_title']}\"), not this one.", 'ticket' => $ticket];
    } elseif ($ticket['status'] === 'CANCELLED') {
        $result = ['type' => 'error', 'message' => 'This ticket has been cancelled and cannot be used.', 'ticket' => $ticket];
    } elseif ($ticket['status'] === 'USED') {
        $result = [
            'type' => 'warn',
            'message' => 'Already checked in at ' . date('g:ia \o\n D j M', strtotime($ticket['checked_in_at'])) . '.',
            'ticket' => $ticket,
        ];
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

  <form method="post" style="margin-top:24px" id="checkin-form">
    <?= csrf_field() ?>
    <div class="field">
      <label for="code">Ticket code</label>
      <input id="code" name="code" type="text" autocomplete="off" autofocus placeholder="Scan or type a code, e.g. OT-A1B2C3D4E5">
      <div class="field-hint">Works with a USB QR/barcode scanner — it just types the code and presses Enter for you.</div>
    </div>
    <button class="btn btn-purple btn-lg btn-block" type="submit" style="margin-top:18px">Check in</button>
  </form>
</div>

<?php render_organizer_foot(); ?>
