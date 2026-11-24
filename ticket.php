<?php
// One ticket a friend sent you (includes/transfers.php): the QR to show at the gate, add to calendar, and send it on.
// Tickets you bought yourself live on order.php; this page is only for tickets you hold but did not buy.
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$id = (int) ($_GET['id'] ?? 0);
$tk = ($id && transfers_ready()) ? transfer_ticket_row($id) : null;
if ($tk && (int) $tk['buyer_id'] === (int) $user['id'] && transfer_holder_id($tk) === (int) $user['id']) {
    header('Location: /order.php?id=' . (int) $tk['order_id'] . '#ticket-' . $id);   // their own purchase
    exit;
}
if (!$tk || transfer_holder_id($tk) !== (int) $user['id'] || $tk['order_status'] !== 'PAID') {
    http_response_code(404);
    $pageTitle = 'Ticket not found — obitickets';
    $bodyClass = 'classic-page app-dark tk-page';
    include __DIR__ . '/includes/header.php';
    echo '<link rel="stylesheet" href="/assets/css/app-dark.css"><link rel="stylesheet" href="/assets/css/ticket-app.css">'
        . '<div class="wrap tk"><div class="ad-top"><h1>Ticket not found</h1></div><div class="tk-note tk-error">This ticket is not in your account. If someone sent it to you, open the link they shared and accept it.</div><a class="tk-btn" href="/my-tickets.php">My tickets</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['send_ticket', 'cancel_send'], true)) {
    verify_csrf();
    if ($_POST['action'] === 'send_ticket') {
        $res = transfer_create($id, $user, (string) ($_POST['to'] ?? ''));
        $_SESSION['send_notice'] = isset($res['error']) ? ['error', $res['error']]
            : ['ok', $res['via'] === 'email' ? 'Sent to ' . $res['contact'] . '. They will get an email to accept it.' : ($res['via'] === 'sms' ? 'Sent by SMS to ' . $res['contact'] . '.' : 'Ready. Share the link below with your friend.')];
    } else {
        $_SESSION['send_notice'] = transfer_cancel($id, (int) $user['id']) ? ['ok', 'Taken back. The ticket is yours again.'] : ['error', 'That send was already accepted or taken back.'];
    }
    header('Location: /ticket.php?id=' . $id);
    exit;
}
if (isset($_SESSION['send_notice'])) {
    $notice = $_SESSION['send_notice'];
    unset($_SESSION['send_notice']);
}

$event = get_event_by_id((int) $tk['event_id']);
$onlineOnly = $event && function_exists('event_is_online_only') && event_is_online_only($event);
$pend = transfer_pending_for_ticket($id);
$canSend = !$pend && transfer_block_reason($tk, (int) $user['id']) === null;
$upcoming = strtotime((string) ($tk['event_ends_at'] ?: $tk['event_starts_at'])) > time();
$img = (string) ($tk['banner_image'] ?? '');
$img = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $img) ? $img : '';
$fromStmt = db()->prepare("SELECT u.name FROM ticket_transfers tr JOIN users u ON u.id = tr.from_user_id WHERE tr.ticket_id = ? AND tr.status = 'CLAIMED' ORDER BY tr.id DESC LIMIT 1");
$fromStmt->execute([$id]);
$fromName = (string) ($fromStmt->fetchColumn() ?: 'a friend');

$pageTitle = 'Your ticket — obitickets';
$bodyClass = 'classic-page app-dark tk-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/ticket-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/ticket-app.css') ?: time() ?>">

<div class="wrap tk">
  <div class="ad-top">
    <a class="ad-icon" href="/my-tickets.php" aria-label="Back to my tickets"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Your Ticket</h1>
  </div>

  <a class="tk-event" href="/event.php?slug=<?= urlencode((string) $tk['event_slug']) ?>">
    <span class="tk-event-art"><?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt=""><?php endif; ?></span>
    <span class="tk-event-tx">
      <b><?= htmlspecialchars((string) $tk['event_title']) ?></b>
      <small><?= htmlspecialchars(date('D j M Y · g:i A', strtotime((string) $tk['event_starts_at']))) ?></small>
      <small><?= htmlspecialchars((string) $tk['venue_name']) ?></small>
    </span>
  </a>
  <?php if ($upcoming): ?>
    <?= calendar_buttons_html(['id' => (int) $tk['event_id'], 'title' => $tk['event_title'], 'slug' => $tk['event_slug'], 'starts_at' => $tk['event_starts_at'], 'ends_at' => $tk['event_ends_at'], 'venue_name' => $onlineOnly ? '' : $tk['venue_name'], 'venue_address' => $onlineOnly ? '' : ($tk['venue_address'] ?? ''), 'attend_mode' => $onlineOnly ? 'ONLINE' : ''], 'cal-btns tk-cal') ?>
  <?php endif; ?>

  <?php if (isset($_GET['new'])): ?><div class="tk-note tk-ok">🎉 The ticket is yours. Show this QR code at the gate.</div><?php endif; ?>

  <div class="tk-list">
    <article class="tk-ticket" id="ticket-<?= $id ?>">
      <div class="tk-ticket-top">
        <div><span class="tk-tier"><?= htmlspecialchars((string) $tk['tier_name']) ?></span><small>From <?= htmlspecialchars($fromName) ?></small></div>
        <span class="tk-pill tk-<?= $tk['status'] === 'VALID' ? 'g' : 'n' ?>"><?= htmlspecialchars((string) $tk['status']) ?></span>
      </div>
      <div class="tk-cut" aria-hidden="true"></div>
      <?php if (!$onlineOnly): ?><div class="tk-qr"><div class="ticket-issued-qr" data-code="<?= htmlspecialchars((string) $tk['ticket_code']) ?>" role="img" aria-label="QR code for ticket <?= htmlspecialchars((string) $tk['ticket_code']) ?>"></div></div><?php endif; ?>
      <div class="ticket-issued-code tk-code mono"><?= htmlspecialchars((string) $tk['ticket_code']) ?></div>
      <div class="tk-hint"><?= $onlineOnly ? 'Online ticket. The join link opens on the event page when it starts.' : 'Show this QR code at the gate. Turn your screen brightness up.' ?></div>
      <?php if ($notice): ?><div class="tk-note tk-<?= $notice[0] === 'ok' ? 'ok' : 'error' ?> tk-send-msg" role="status"><?= htmlspecialchars($notice[1]) ?></div><?php endif; ?>
      <?php if ($pend): $claim = transfer_claim_url((string) $pend['token']); ?>
        <div class="tk-pend">
          <p>Waiting for <b><?= htmlspecialchars((string) $pend['to_contact']) ?></b> to accept. Until they do, this ticket is still yours.</p>
          <div class="tk-pend-acts">
            <a class="tk-mini tk-wa" href="https://wa.me/?text=<?= rawurlencode('I sent you a ticket to ' . $tk['event_title'] . '. Accept it here: ' . $claim) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
            <button class="tk-mini" type="button" data-copy="<?= htmlspecialchars($claim) ?>">Copy link</button>
            <form method="post" action="/ticket.php?id=<?= $id ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_send"><button class="tk-mini tk-ghost" type="submit">Take back</button></form>
          </div>
        </div>
      <?php elseif ($canSend): ?>
        <details class="tk-send"<?= $notice && $notice[0] === 'error' ? ' open' : '' ?>>
          <summary><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>Send to a friend</summary>
          <form method="post" action="/ticket.php?id=<?= $id ?>" class="tk-send-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="send_ticket">
            <label for="to-<?= $id ?>">Friend's email or phone</label>
            <input id="to-<?= $id ?>" name="to" type="text" inputmode="email" autocomplete="off" placeholder="friend@email.com or 07XX XXX XXX" required maxlength="191">
            <small>They accept it with a free obitickets account. Then this QR stops working and they get a new one.</small>
            <button class="tk-mini tk-red" type="submit">Send ticket</button>
          </form>
        </details>
      <?php endif; ?>
    </article>
  </div>
  <a class="tk-btn" href="/my-tickets.php">Back to my tickets</a>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.ticket-issued-qr').forEach(function (el) {
  new QRCode(el, { text: el.getAttribute('data-code'), width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
});
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]');
  if (!b) { return; }
  var url = b.getAttribute('data-copy'), done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1600); };
  if (navigator.clipboard) { navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link', url); }); } else { window.prompt('Copy this link', url); }
});
</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
