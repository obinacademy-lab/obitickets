<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('ORGANIZER');

$eventId = (int) ($_GET['event'] ?? 0);
$event = $eventId ? get_event_by_id($eventId) : null;

if (!$event || ((int) $event['organizer_id'] !== (int) $user['id'] && $user['role'] !== 'ADMIN')) {
    http_response_code(404);
    $pageTitle = 'Event not found — obitickets';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <section class="auth-section">
        <div class="auth-card">
          <h1>Event not found</h1>
          <p class="sub">This event doesn't exist, or isn't yours to check in.</p>
          <a class="btn btn-purple btn-block btn-lg" href="/my-events.php" style="margin-top:24px">Back to my events</a>
        </div>
      </section>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
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
    }

    $_SESSION['checkin_result'] = $result;
    header('Location: /checkin.php?event=' . $eventId);
    exit;
}

$result = $_SESSION['checkin_result'] ?? null;
unset($_SESSION['checkin_result']);

$stats = get_checkin_stats($eventId);

$pageTitle = 'Check-in — ' . $event['title'] . ' — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec" style="max-width:520px; margin:0 auto">
    <div class="sec-top" style="margin-bottom:8px">
      <h2 style="font-size:1.3rem"><?= htmlspecialchars($event['title']) ?></h2>
      <a class="btn btn-line" href="/my-events.php">Back</a>
    </div>
    <p class="sub" style="text-align:left; font-size:0.9rem">Check-in desk</p>

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
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
