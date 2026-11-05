<?php
// The only way to reach an online event's meeting link from the website. It re-checks that the person holds a ticket
// (or hosts the event) and that the join window is open, counts the join, then sends them to the meeting.
require_once __DIR__ . '/includes/bootstrap.php';

$eventId = (int) ($_GET['event'] ?? 0);
$user = current_user();
if (!$user) {
    header('Location: /login.php?next=' . urlencode('/join.php?event=' . $eventId));
    exit;
}

$event = $eventId ? get_event_by_id($eventId) : null;
$access = $event ? get_online_access($event, $user) : null;
$isHost = $event && (int) $user['id'] === (int) $event['organizer_id'];

function join_page(string $title, string $message, ?string $detail = null, ?array $event = null, ?array $access = null, int $status = 200): void
{
    http_response_code($status);
    $pageTitle = $title . ' — obitickets';
    $hideWhatsApp = false;
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <section class="auth-section">
        <div class="auth-card" style="max-width:520px">
          <h1><?= htmlspecialchars($title) ?></h1>
          <p class="sub"><?= htmlspecialchars($message) ?></p>
          <?php if ($detail): ?><p class="sub" style="margin-top:6px"><?= htmlspecialchars($detail) ?></p><?php endif; ?>
          <?php if ($event && $access): ?><div style="margin-top:18px"><?= render_join_card_html($event, $access) ?></div><?php endif; ?>
          <a class="btn btn-purple btn-block btn-lg" href="/my-tickets.php" style="margin-top:22px">Back to my tickets</a>
        </div>
      </section>
    </div>
    <script src="/assets/js/join-card.js?v=<?= @filemtime(__DIR__ . '/assets/js/join-card.js') ?: time() ?>"></script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if (!$event || $event['status'] !== 'PUBLISHED' && !$isHost || !event_is_online($event)) {
    join_page('Event not found', 'This event does not exist, or it is not an online event.', null, null, null, 404);
}
if (!$access) {
    join_page('You need a ticket', 'The meeting link is only for people who registered for this event.', 'Get your ticket on the event page, then come back here.', null, null, 403);
}

$state = $access['window']['state'];
if ($state === 'before' && !$isHost) {
    join_page('Not open yet', 'The room opens ' . ONLINE_JOIN_OPENS_MINUTES . ' minutes before the start.', 'Opens ' . date('D j M, g:ia', $access['window']['opens']) . ' (EAT).', $event, $access);
}
if ($state === 'ended' && !$isHost) {
    join_page('This event has ended', 'The online room is closed now.');
}

if (!$isHost) {
    try {
        record_online_join($eventId, (int) $user['id']);
    } catch (Throwable $e) {
        error_log('[online] join count failed: ' . $e->getMessage()); // never stop someone joining over a counter
    }
}
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Location: ' . $access['url']);
exit;
