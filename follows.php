<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Followers / Following of anyone (tap the numbers on a profile; 11/18). Lists are public, like TikTok; Follow and
// Message need you to be signed in. People blocked either way are left out.
$viewer = current_user();
$vid = $viewer ? (int) $viewer['id'] : null;
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 && $vid) {
    $id = $vid;
}
$person = $id > 0 ? social_person($id) : null;
if (!$person || !social_ready() || ($vid && $vid !== $id && social_blocked_between($vid, $id))) {
    http_response_code(404);
    $pageTitle = 'Not found — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap"><section class="auth-section"><div class="auth-card"><h1>Not found</h1><p class="sub">This list is not available.</p><a class="btn btn-purple btn-block" href="/" style="margin-top:20px">Home</a></div></section></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$tab = ($_GET['tab'] ?? '') === 'following' ? 'following' : 'followers';
$counts = social_counts($id);
$list = social_follow_list($id, $tab, $vid, 100);
$isMe = $vid === $id;
$back = $isMe ? '/me.php' : $person['url'];

$pageTitle = ($tab === 'following' ? 'Following' : 'Followers') . ' — ' . $person['name'] . ' — obitickets';
$bodyClass = 'classic-page app-dark sx-page';
$hideWhatsApp = true;
if ($isMe) { $rcTabForce = 'account'; }
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/social-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/social-app.css') ?: time() ?>">
<div class="wrap sx">
  <div class="ad-top">
    <a class="ad-icon" href="<?= htmlspecialchars($back) ?>" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1><?= htmlspecialchars($person['handle'] !== '' ? $person['handle'] : $person['name']) ?></h1>
    <span style="width:42px" aria-hidden="true"></span>
  </div>
  <nav class="sx-tabs" aria-label="Lists">
    <a href="/follows.php?id=<?= $id ?>&amp;tab=followers"<?= $tab === 'followers' ? ' class="on" aria-current="page"' : '' ?>><?= number_format($counts['followers']) ?> Followers</a>
    <a href="/follows.php?id=<?= $id ?>&amp;tab=following"<?= $tab === 'following' ? ' class="on" aria-current="page"' : '' ?>><?= number_format($counts['following']) ?> Following</a>
  </nav>

  <?php if (!$list): ?>
    <div class="sx-empty">
      <b><?= $tab === 'following' ? ($isMe ? "You don't follow anyone yet" : 'Not following anyone yet') : ($isMe ? 'No followers yet' : 'No followers yet') ?></b>
      <span><?= $isMe ? ($tab === 'following' ? 'Follow organizers and friends to see them here.' : 'When people follow you, they show up here.') : '' ?></span>
      <?php if ($isMe && $tab === 'following'): ?><a href="/discover.php">Find people and events</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="sx-list">
      <?php foreach ($list as $p): ?>
        <div class="sx-row">
          <a class="sx-who" href="<?= htmlspecialchars($p['url']) ?>">
            <?= social_avatar_html($p, 52) ?>
            <span><b><?= htmlspecialchars($p['name']) ?><?php if ($p['verified']): ?><i class="sx-tick" title="Verified"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></i><?php endif; ?></b><small><?= htmlspecialchars($p['handle'] !== '' ? $p['handle'] : ($p['organizer'] ? 'Event Organizer' : '')) ?></small></span>
          </a>
          <?php if (!$p['is_me']): ?>
            <button type="button" class="sx-btn" data-follow-user="<?= $p['id'] ?>" aria-pressed="<?= $p['i_follow'] ? 'true' : 'false' ?>"><?= $p['i_follow'] ? 'Following' : 'Follow' ?></button>
            <button type="button" class="sx-icon-btn" data-message-user="<?= $p['id'] ?>" aria-label="Message <?= htmlspecialchars($p['name']) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script src="/assets/js/social.js?v=<?= @filemtime(__DIR__ . '/assets/js/social.js') ?: time() ?>" defer></script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
