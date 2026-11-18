<?php
require_once __DIR__ . '/includes/bootstrap.php';

// The inbox (11/18): Chats and Message requests, newest first, with an unread count. With ?event=ID it becomes
// "Send this event to...": pick a chat or someone you follow and the chat opens with the event ready to send.
$user = require_login();
$uid = (int) $user['id'];
if (!social_ready()) {
    header('Location: /me.php');
    exit;
}
$tab = ($_GET['tab'] ?? '') === 'requests' ? 'requests' : 'chats';
$shareEvent = null;
if (isset($_GET['event'])) {
    $st = db()->prepare("SELECT id, title, banner_image FROM events WHERE id = ? AND status = 'PUBLISHED'");
    $st->execute([(int) $_GET['event']]);
    $shareEvent = $st->fetch() ?: null;
}
$items = dm_inbox($uid, $shareEvent ? 'chats' : $tab);
$counts = dm_unread_counts($uid);
$people = [];
if ($shareEvent) {
    $inChat = array_map(static fn ($i) => $i['other']['id'], $items);
    foreach (social_follow_list($uid, 'following', $uid, 60) as $p) {
        if (!in_array($p['id'], $inChat, true)) { $people[] = $p; }
    }
}

$pageTitle = ($shareEvent ? 'Send to' : 'Messages') . ' — obitickets';
$bodyClass = 'classic-page app-dark sx-page';
$hideWhatsApp = true;
$rcTabForce = 'account';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/social-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/social-app.css') ?: time() ?>">
<div class="wrap sx">
  <div class="ad-top">
    <a class="ad-icon" href="<?= $shareEvent ? '/discover.php' : '/me.php' ?>" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1><?= $shareEvent ? 'Send to' : 'Messages' ?></h1>
    <span style="width:42px" aria-hidden="true"></span>
  </div>

  <?php if ($shareEvent): ?>
    <div class="cx-share" style="margin:4px 0 12px">
      <?php if (preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', (string) $shareEvent['banner_image'])): ?><img src="<?= htmlspecialchars($shareEvent['banner_image']) ?>" alt=""><?php endif; ?>
      <span><b><?= htmlspecialchars($shareEvent['title']) ?></b></span>
    </div>
    <?php if (!$items && !$people): ?>
      <div class="sx-empty"><b>No one to send to yet</b><span>Follow friends and organizers, then share events with them here.</span><a href="/discover.php">Find people and events</a></div>
    <?php endif; ?>
    <div class="sx-list">
      <?php foreach ($items as $c): ?>
        <div class="sx-row">
          <span class="sx-who"><?= social_avatar_html($c['other'], 48) ?><span><b><?= htmlspecialchars($c['other']['name']) ?></b><small><?= htmlspecialchars($c['other']['handle']) ?></small></span></span>
          <a class="sx-btn" style="display:grid;place-items:center" href="/chat.php?c=<?= $c['id'] ?>&amp;event=<?= (int) $shareEvent['id'] ?>">Send</a>
        </div>
      <?php endforeach; ?>
      <?php foreach ($people as $p): ?>
        <div class="sx-row">
          <span class="sx-who"><?= social_avatar_html($p, 48) ?><span><b><?= htmlspecialchars($p['name']) ?></b><small><?= htmlspecialchars($p['handle']) ?></small></span></span>
          <button type="button" class="sx-btn" data-message-user="<?= $p['id'] ?>" data-share-event="<?= (int) $shareEvent['id'] ?>">Send</button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <nav class="sx-tabs" aria-label="Messages">
      <a href="/inbox.php"<?= $tab === 'chats' ? ' class="on" aria-current="page"' : '' ?>>Chats<?php if ($counts['chats']): ?> <b><?= $counts['chats'] ?></b><?php endif; ?></a>
      <a href="/inbox.php?tab=requests"<?= $tab === 'requests' ? ' class="on" aria-current="page"' : '' ?>>Requests<?php if ($counts['requests']): ?> <b><?= $counts['requests'] ?></b><?php endif; ?></a>
    </nav>
    <?php if ($tab === 'requests'): ?><p class="sx-note">Messages from people you don't follow. They won't know you've seen them until you reply or accept.</p><?php endif; ?>

    <?php if (!$items): ?>
      <div class="sx-empty">
        <b><?= $tab === 'requests' ? 'No message requests' : 'No messages yet' ?></b>
        <span><?= $tab === 'requests' ? 'Messages from people you don\'t follow will wait here.' : 'Start a chat from someone\'s profile or your Followers and Following lists.' ?></span>
        <?php if ($tab === 'chats'): ?><a href="/follows.php?tab=following">See who you follow</a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="sx-list" id="sxInbox">
        <?php foreach ($items as $c): ?>
          <a class="sx-chat<?= $c['unread'] ? ' is-unread' : '' ?>" href="/chat.php?c=<?= $c['id'] ?>">
            <?= social_avatar_html($c['other'], 54) ?>
            <span class="sx-chat-tx"><b><?= htmlspecialchars($c['other']['name']) ?></b><small><?= $c['pending'] ? 'Request sent · ' : '' ?><?= htmlspecialchars($c['preview']) ?></small></span>
            <span class="sx-chat-end"><?= htmlspecialchars(social_ago($c['at'])) ?><?php if ($c['unread']): ?><i><?= $c['unread'] > 99 ? '99+' : $c['unread'] ?></i><?php endif; ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php if (!$shareEvent): ?>
<script>
// a light refresh while the inbox is open: new chats and unread counts show without reloading by hand
(function () {
  var t = setInterval(function () {
    if (document.hidden) { return; }
    fetch(location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) {
      var d = new DOMParser().parseFromString(h, 'text/html'), a = d.querySelector('.sx'), b = document.querySelector('.sx');
      if (a && b && a.innerHTML !== b.innerHTML) { b.innerHTML = a.innerHTML; }
    }).catch(function () {});
  }, 12000);
  window.addEventListener('pagehide', function () { clearInterval(t); });
})();
</script>
<?php endif; ?>
<script src="/assets/js/social.js?v=<?= @filemtime(__DIR__ . '/assets/js/social.js') ?: time() ?>" defer></script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
