<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Who viewed one of your videos (11/27). Only the video's owner can open it (vv_list checks); each person has a Message button
// that opens the normal chat (someone who does not follow you gets it as a message request).
$user = require_login();
$uid = (int) $user['id'];
$postId = max(0, (int) ($_GET['post'] ?? 0));
$cursor = isset($_GET['after']) ? (string) $_GET['after'] : null;
$res = function_exists('vv_list') ? vv_list($postId, $uid, $cursor, 40) : ['ok' => false, 'error' => 'Viewer lists are not available yet.'];
if (!$res['ok']) {
    http_response_code(403);
}
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$caption = '';
if ($res['ok']) {
    $st = db()->prepare('SELECT p.body, e.title FROM event_posts p JOIN events e ON e.id = p.event_id WHERE p.id = ?');
    $st->execute([$postId]);
    $row = $st->fetch() ?: [];
    $caption = trim((string) ($row['body'] ?? '')) !== '' ? mb_substr((string) $row['body'], 0, 80) : (string) ($row['title'] ?? 'Your video');
}

$pageTitle = 'Who viewed your video — obitickets';
$bodyClass = 'classic-page app-dark vv-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<div class="wrap vv">
  <div class="ad-top">
    <a class="ad-icon" href="/org-social.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Viewers</h1>
  </div>

  <?php if (!$res['ok']): ?>
    <p class="vv-empty"><?= $h($res['error']) ?></p>
  <?php elseif (!empty($res['off'])): ?>
    <p class="vv-empty">You turned off &ldquo;Show me in video viewer lists&rdquo;, so you cannot see who viewed your videos either. <a href="/settings.php#viewers">Turn it on in Settings</a>.</p>
  <?php else: ?>
    <p class="vv-sub"><b><?= number_format((int) $res['total']) ?></b> signed-in <?= (int) $res['total'] === 1 ? 'person' : 'people' ?> viewed &ldquo;<?= $h($caption) ?>&rdquo;. Only you can see this list.</p>
    <?php if (!$res['rows']): ?>
      <p class="vv-empty"><?= $cursor ? 'No more viewers.' : 'No signed-in viewers yet. When people watch this video, they show up here.' ?></p>
    <?php else: ?>
      <div class="vv-list">
        <?php foreach ($res['rows'] as $v): ?>
          <div class="vv-row">
            <a class="vv-who" href="<?= $h($v['url']) ?>">
              <span class="vv-av"><?php if ($v['avatar']): ?><img src="<?= $h($v['avatar']) ?>" alt=""><?php else: ?><?= $h($v['initials']) ?><?php endif; ?></span>
              <span class="vv-tx"><b><?= $h($v['name']) ?></b><small>Viewed <?= $h($v['ago']) ?><?= $v['times'] > 1 ? ' &middot; ' . (int) $v['times'] . ' times' : '' ?><?= $v['followsYou'] ? ' &middot; Follows you' : '' ?></small></span>
            </a>
            <a class="vv-msg" href="<?= $h($v['chat']) ?>" aria-label="Message <?= $h($v['name']) ?>">Message</a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($res['next']): ?><a class="vv-more" href="/video-viewers.php?post=<?= $postId ?>&amp;after=<?= urlencode((string) $res['next']) ?>">Show more</a><?php endif; ?>
    <?php endif; ?>
    <p class="vv-note">People who are not signed in, or who turned off &ldquo;Show me in video viewer lists&rdquo; in Settings, are not shown. Someone who does not follow you gets your message as a request.</p>
  <?php endif; ?>
</div>
<style>
  .vv{max-width:620px; padding-bottom:28px}
  .vv .ad-top h1{font-size:22px}
  .vv-sub{margin:4px 0 14px; color:var(--ad-mute); font-size:14px}
  .vv-sub b{color:#fff}
  .vv-list{border-radius:16px; background:var(--ad-card); box-shadow:inset 0 0 0 1px var(--ad-line); overflow:hidden}
  .vv-row{display:flex; align-items:center; gap:12px; padding:10px 14px}
  .vv-row + .vv-row{border-top:1px solid var(--ad-line)}
  body.vv-page a.vv-who{flex:1; min-width:0; display:flex; align-items:center; gap:12px; color:#fff}
  .vv-av{flex:none; width:44px; height:44px; border-radius:50%; background:#2A1D20; color:#FFB4B4; display:grid; place-items:center; font:700 13px 'Outfit',sans-serif; overflow:hidden}
  .vv-av img{width:100%; height:100%; object-fit:cover}
  .vv-tx{min-width:0; display:grid; gap:2px}
  .vv-tx b{font-size:15px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
  .vv-tx small{font-size:12.5px; color:var(--ad-mute); overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
  body.vv-page a.vv-msg{flex:none; padding:9px 16px; border-radius:99px; background:#F0313F; color:#fff; font-weight:800; font-size:13px}
  body.vv-page a.vv-msg:hover{background:#D72634; color:#fff}
  body.vv-page a.vv-more{display:block; width:max-content; margin:14px auto 0; padding:9px 20px; border-radius:99px; border:1px solid var(--ad-line); color:#fff; font-weight:700; font-size:13px}
  .vv-empty{margin:18px 0; color:var(--ad-mute)}
  .vv-empty a{color:#FF6B76; font-weight:700}
  .vv-note{margin:16px 2px 0; color:var(--ad-mute); font-size:12.5px; line-height:1.5}
</style>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
