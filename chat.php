<?php
require_once __DIR__ . '/includes/bootstrap.php';

// One chat (11/18). ?c=ID opens a conversation you are in; ?to=USERID opens (or starts) one with that person.
// ?event=ID attaches an event card ready to send. Messages load and refresh through /api/social.php (chat.js);
// message text is always inserted as text, never as HTML.
$user = require_login();
$uid = (int) $user['id'];
if (!social_ready()) {
    header('Location: /me.php');
    exit;
}
if (isset($_GET['to'])) {
    $r = dm_open($uid, (int) $_GET['to']);
    header('Location: ' . ($r['ok'] ? '/chat.php?c=' . $r['id'] . (isset($_GET['event']) ? '&event=' . (int) $_GET['event'] : '') : '/inbox.php'));
    exit;
}
$cid = (int) ($_GET['c'] ?? 0);
$c = $cid ? dm_conversation($cid, $uid) : null;
$other = $c ? social_person($c['other_id']) : null;
if (!$c || !$other) {
    http_response_code(404);
    $pageTitle = 'Chat not found — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap"><section class="auth-section"><div class="auth-card"><h1>Chat not found</h1><p class="sub">This chat does not exist, or is not yours.</p><a class="btn btn-purple btn-block" href="/inbox.php" style="margin-top:20px">Back to messages</a></div></section></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$blocked = social_blocked_between($uid, $other['id']);
$iBlocked = social_i_blocked($uid, $other['id']);
$msgs = dm_messages($uid, $cid, 0, 60);
if ($msgs) {
    dm_mark_read($uid, $cid, (int) end($msgs)['id']);
}
$pendingMine = $c['accepted_at'] === null && (int) $c['started_by'] === $uid;
$sentCount = 0;
foreach ($msgs as $m) { if ($m['mine']) { $sentCount++; } }

$attach = null;
if (isset($_GET['event']) && !$blocked) {
    $st = db()->prepare("SELECT id, title, banner_image, starts_at FROM events WHERE id = ? AND status = 'PUBLISHED'");
    $st->execute([(int) $_GET['event']]);
    $attach = $st->fetch() ?: null;
}
$data = [
    'id' => $cid,
    'messages' => $msgs,
    'seen' => dm_other_read_id($c),
    'request' => $c['is_request_for_me'],
    'event' => $attach ? (int) $attach['id'] : null,
];

$pageTitle = $other['name'] . ' — Messages — obitickets';
$bodyClass = 'classic-page app-dark sx-page sx-chat-page';
$hideWhatsApp = true;
$rcTabForce = 'account';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/social-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/social-app.css') ?: time() ?>">
<div class="cx" id="cx">
  <div class="cx-top">
    <a class="ad-icon" href="/inbox.php<?= $c['is_request_for_me'] ? '?tab=requests' : '' ?>" aria-label="Back to messages"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <a class="cx-who" href="<?= htmlspecialchars($other['url']) ?>">
      <?= social_avatar_html($other, 40) ?>
      <span><b><?= htmlspecialchars($other['name']) ?></b><small><?= htmlspecialchars($other['handle'] !== '' ? $other['handle'] : ($other['organizer'] ? 'Event Organizer' : 'View profile')) ?></small></span>
    </a>
    <div class="cx-menu">
      <button type="button" class="ad-icon" data-menu-btn aria-haspopup="true" aria-expanded="false" aria-label="Chat options"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></button>
      <div class="cx-menu-list" hidden>
        <a href="<?= htmlspecialchars($other['url']) ?>">View profile</a>
        <button type="button" data-cx="clear">Delete chat</button>
        <button type="button" data-cx="report">Report</button>
        <button type="button" class="danger" data-block-user="<?= $other['id'] ?>" data-blocked="<?= $iBlocked ? '1' : '0' ?>"><?= $iBlocked ? 'Unblock' : 'Block' ?></button>
      </div>
    </div>
  </div>

  <div class="cx-body" id="cxBody" aria-live="polite">
    <div class="cx-intro">
      <?= social_avatar_html($other, 72) ?>
      <b><?= htmlspecialchars($other['name']) ?></b>
      <span><?= htmlspecialchars($other['handle']) ?></span>
      <span>Messages are private between you two. Never share your PIN or mobile money codes.</span>
    </div>
  </div>

  <?php if ($blocked): ?>
    <div class="cx-closed"><?= $iBlocked ? 'You blocked this person. Unblock them from the menu to message again.' : "You can't reply to this chat." ?></div>
  <?php else: ?>
    <?php if ($c['is_request_for_me']): ?>
      <div class="cx-req" id="cxReq">
        <?= htmlspecialchars($other['name']) ?> wants to message you. Reply or accept to move this chat to your inbox.
        <div><button type="button" class="sx-btn" data-cx="accept">Accept</button><button type="button" class="sx-btn sx-soft" data-cx="clear">Delete</button><button type="button" class="sx-btn sx-soft" data-block-user="<?= $other['id'] ?>" data-blocked="0">Block</button></div>
      </div>
    <?php elseif ($pendingMine): ?>
      <p class="sx-note" id="cxPending"><?= $sentCount >= DM_REQUEST_MAX ? 'Message request sent. You can send more once they accept.' : 'They don\'t follow you yet, so this goes to their message requests.' ?></p>
    <?php endif; ?>
    <?php if ($attach): ?>
      <div class="cx-share" id="cxAttach">
        <?php if (preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', (string) $attach['banner_image'])): ?><img src="<?= htmlspecialchars($attach['banner_image']) ?>" alt=""><?php endif; ?>
        <span>Sharing: <b><?= htmlspecialchars($attach['title']) ?></b></span>
        <button type="button" class="sx-icon-btn" data-cx="unattach" aria-label="Don't share this event"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>
    <?php endif; ?>
    <form class="cx-form" id="cxForm" autocomplete="off">
      <label class="sr-only" for="cxText">Message</label>
      <textarea id="cxText" rows="1" maxlength="<?= DM_MAX_CHARS ?>" placeholder="Message..." enterkeyhint="send"></textarea>
      <button class="cx-send" type="submit" aria-label="Send" disabled><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
    </form>
  <?php endif; ?>
</div>

<div class="cx-sheet" id="cxReport" hidden>
  <form>
    <h2>Report this chat</h2>
    <?php foreach (DM_REPORT_REASONS as $k => $label): ?>
      <label><input type="radio" name="reason" value="<?= htmlspecialchars($k) ?>"> <?= htmlspecialchars($label) ?></label>
    <?php endforeach; ?>
    <label><input type="checkbox" name="block" checked> Also block <?= htmlspecialchars($other['name']) ?></label>
    <div class="cx-sheet-btns"><button type="button" class="sx-btn sx-soft" data-cx="report-cancel">Cancel</button><button type="submit" class="sx-btn">Send report</button></div>
  </form>
</div>

<script type="application/json" id="cxData"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/social.js?v=<?= @filemtime(__DIR__ . '/assets/js/social.js') ?: time() ?>"></script>
<script src="/assets/js/chat-menu.js?v=<?= @filemtime(__DIR__ . '/assets/js/chat-menu.js') ?: time() ?>"></script>
<script src="/assets/js/chat.js?v=<?= @filemtime(__DIR__ . '/assets/js/chat.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
