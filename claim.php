<?php
// Accept a ticket a friend sent (includes/transfers.php). /claim.php?t=<secret>. Logging in / signing up comes back here.
require_once __DIR__ . '/includes/bootstrap.php';

$token = (string) ($_GET['t'] ?? '');
$tr = transfer_by_token($token);
$user = current_user();
$error = null;

if ($tr && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$user) {
        header('Location: /login.php?next=' . rawurlencode('/claim.php?t=' . $token));
        exit;
    }
    $res = transfer_claim($token, $user);
    if (!empty($res['ok'])) {
        header('Location: /ticket.php?id=' . (int) $res['ticket_id'] . '&new=1');
        exit;
    }
    $error = $res['error'];
    $tr = transfer_by_token($token);
}

$tk = $tr['ticket'] ?? null;
$open = $tr && $tr['status'] === 'PENDING';
$img = (string) ($tk['banner_image'] ?? '');
$img = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $img) ? $img : '';
$next = rawurlencode('/claim.php?t=' . $token);

$pageTitle = $tk ? 'A ticket for you: ' . $tk['event_title'] . ' — obitickets' : 'Ticket link — obitickets';
$bodyClass = 'classic-page app-dark tk-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/ticket-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/ticket-app.css') ?: time() ?>">

<div class="wrap tk">
  <div class="ad-top"><h1>A ticket for you</h1></div>

  <?php if (!$tk): ?>
    <div class="tk-note tk-error">This ticket link is not valid. Ask your friend to send it again.</div>
    <a class="tk-btn" href="/discover.php">Discover events</a>
  <?php else: ?>
    <a class="tk-event" href="/event.php?slug=<?= urlencode((string) $tk['event_slug']) ?>">
      <span class="tk-event-art"><?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt=""><?php endif; ?></span>
      <span class="tk-event-tx">
        <b><?= htmlspecialchars((string) $tk['event_title']) ?></b>
        <small><?= htmlspecialchars(date('D j M Y · g:i A', strtotime((string) $tk['event_starts_at']))) ?></small>
        <small><?= htmlspecialchars((string) $tk['venue_name']) ?></small>
      </span>
    </a>

    <?php if ($error): ?><div class="tk-note tk-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($open): ?>
      <div class="tk-note tk-ok"><strong><?= htmlspecialchars((string) $tr['from_name']) ?></strong> sent you a <strong><?= htmlspecialchars((string) $tk['tier_name']) ?></strong> ticket.</div>
      <?php if ($user): ?>
        <form method="post" action="/claim.php?t=<?= htmlspecialchars($token) ?>">
          <?= csrf_field() ?>
          <button class="tk-btn tk-red" type="submit">Accept ticket</button>
        </form>
        <p class="tk-hint" style="text-align:center">It goes to your My Tickets as <?= htmlspecialchars((string) $user['name']) ?>, with its own QR code.</p>
      <?php else: ?>
        <a class="tk-btn tk-red" href="/signup.php?next=<?= $next ?>">Create a free account to accept</a>
        <a class="tk-btn" href="/login.php?next=<?= $next ?>">I have an account: log in</a>
      <?php endif; ?>
    <?php elseif ($tr['status'] === 'CLAIMED' && $user && (int) $tr['to_user_id'] === (int) $user['id']): ?>
      <div class="tk-note tk-ok">You already accepted this ticket.</div>
      <a class="tk-btn tk-red" href="/ticket.php?id=<?= (int) $tk['id'] ?>">Show my ticket</a>
    <?php else: ?>
      <div class="tk-note tk-error">This ticket link has already been used or was taken back by the sender.</div>
      <a class="tk-btn" href="/event.php?slug=<?= urlencode((string) $tk['event_slug']) ?>">See the event</a>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
