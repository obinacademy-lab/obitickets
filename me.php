<?php
require_once __DIR__ . '/includes/bootstrap.php';

// "My Profile" (the Profile tab), to the owner's mockup (11/18): "My Profile" with the bell and menu, the round
// picture, @username, Videos / Followers / Following, Edit Profile + account settings, the bio, then Videos /
// Saved / Liked grids. A cover photo, when set, sits softly behind the top. Only ever shows the signed-in person;
// editing is on /profile.php, account things on /settings.php.
$user = require_login();
$uid = (int) $user['id'];

$st = db()->prepare('SELECT name, role FROM users WHERE id = ?');
$st->execute([$uid]);
$me = $st->fetch();
$name = (string) $me['name'];
$isOrganizer = $me['role'] === 'ORGANIZER';
$avatar = user_avatar_url($uid);
$cover = function_exists('user_cover_url') ? (string) (user_cover_url($uid) ?? '') : '';
$about = function_exists('user_about') ? user_about($uid) : ['username' => '', 'bio' => ''];
$unread = function_exists('count_unread_notifications') ? count_unread_notifications($uid) : 0;

// numbers: your videos (organizers post them), people following you (organizers), organizers you follow
$counts = function_exists('socials_profile_counts') ? socials_profile_counts($uid) : ['posts' => 0, 'following' => 0];
$followers = 0;
if ($isOrganizer) {
    try { $followers = count_organizer_followers($uid); } catch (Throwable $e) { $followers = 0; }
}

// your videos, saved videos and liked videos
$mine = [];
if ($isOrganizer && function_exists('socials_feed')) {
    try { $mine = socials_feed($uid, 'profile', 0, 30, null, $uid); } catch (Throwable $e) { $mine = []; }
}
$videos = static function (string $join) use ($uid): array {
    if (!function_exists('moment_select_sql')) { return []; }
    try {
        $q = db()->prepare('SELECT x.* FROM (' . moment_select_sql() . ') x ' . $join . ' WHERE x.status = "PUBLISHED" AND COALESCE(x.is_draft, 0) = 0 ORDER BY t.created_at DESC LIMIT 30');
        $q->execute([$uid]);
        return $q->fetchAll();
    } catch (Throwable $e) {
        error_log('[me] videos: ' . $e->getMessage());
        return [];
    }
};
$saved = function_exists('post_saves_ready') && post_saves_ready() ? $videos('JOIN post_saves t ON t.post_id = x.id AND t.user_id = ?') : [];
$liked = $videos('JOIN post_reactions t ON t.post_id = x.id AND t.user_id = ?');

$compact = static fn (int $n): string => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M' : ($n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K' : (string) $n);
$tile = static function (array $p) use ($compact): void {
    $thumb = (string) ($p['media_type'] === 'VIDEO' ? ($p['poster_path'] ?: '') : $p['media_path']);
    $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $thumb) ? $thumb : '';
    ?>
    <a class="mp-tile" href="/socials.php?v=<?= (int) $p['id'] ?>" aria-label="<?= $p['media_type'] === 'VIDEO' ? 'Video' : 'Post' ?>, <?= (int) $p['view_count'] ?> views">
      <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars((string) $p['media_path']) ?>#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video><?php endif; ?>
      <span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round" aria-hidden="true"><path d="M7 4.5v15l12-7.5z"/></svg><?= htmlspecialchars($compact((int) $p['view_count'])) ?></span>
    </a>
    <?php
};
$handle = $about['username'] !== '' ? '@' . $about['username'] : $name;

$pageTitle = 'My Profile — obitickets';
$bodyClass = 'classic-page app-dark mp-page';
$hideWhatsApp = true;
$rcTabForce = 'account';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/me-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/me-app.css') ?: time() ?>">

<div class="mp<?= $cover !== '' ? ' has-cover' : '' ?>">
  <?php if ($cover !== ''): ?><div class="mp-cover" style="background-image:url('<?= htmlspecialchars($cover, ENT_QUOTES) ?>')" aria-hidden="true"></div><?php endif; ?>

  <div class="wrap mp-in">
    <div class="mp-top">
      <span class="mp-gap" aria-hidden="true"></span>
      <h1>My Profile</h1>
      <a class="mp-ic" href="/notifications.php" aria-label="Notifications<?= $unread ? ', ' . (int) $unread . ' unread' : '' ?>"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><?php if ($unread): ?><i></i><?php endif; ?></a>
      <button type="button" class="mp-ic" data-open-menu aria-label="Open menu"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    </div>

    <div class="mp-av"><?php if ($avatar): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><b aria-hidden="true"><?= htmlspecialchars(initials_from_name($name)) ?></b><?php endif; ?></div>
    <h2 class="mp-handle"><?= htmlspecialchars($handle) ?></h2>

    <div class="mp-stats">
      <div><b><?= htmlspecialchars($compact((int) $counts['posts'])) ?></b><span><?= (int) $counts['posts'] === 1 ? 'Video' : 'Videos' ?></span></div>
      <div><b><?= htmlspecialchars($compact($followers)) ?></b><span><?= $followers === 1 ? 'Follower' : 'Followers' ?></span></div>
      <div><b><?= htmlspecialchars($compact((int) $counts['following'])) ?></b><span>Following</span></div>
    </div>

    <div class="mp-actions">
      <a class="mp-edit" href="/profile.php">Edit Profile</a>
      <a class="mp-sq" href="/settings.php" aria-label="Account settings"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="8" r="4"/><path d="M3 21c0-3.9 3.1-6.5 7-6.5 1.2 0 2.3.2 3.3.7"/><circle cx="18" cy="17" r="2.3"/><path d="M18 13.2v1.5M18 19.3v1.5M21.3 15.1l-1.3.8M16 18.1l-1.3.8M21.3 18.9l-1.3-.8M16 15.9l-1.3-.8"/></svg></a>
    </div>

    <?php if ($about['bio'] !== ''): ?>
      <p class="mp-bio"><?= nl2br(htmlspecialchars($about['bio'])) ?></p>
    <?php elseif (function_exists('profile_about_ready') && profile_about_ready()): ?>
      <p class="mp-bio"><a href="/profile.php#about">+ Add a username and bio</a></p>
    <?php endif; ?>
  </div>

  <div class="mp-tabs" role="tablist" aria-label="My Profile">
    <button type="button" role="tab" id="mpTabVideos" aria-controls="mpVideos" aria-selected="true">Videos</button>
    <button type="button" role="tab" id="mpTabSaved" aria-controls="mpSaved" aria-selected="false" tabindex="-1">Saved</button>
    <button type="button" role="tab" id="mpTabLiked" aria-controls="mpLiked" aria-selected="false" tabindex="-1">Liked</button>
  </div>

  <section class="mp-panel" id="mpVideos" role="tabpanel" aria-labelledby="mpTabVideos">
    <?php if ($mine): ?><div class="mp-grid"><?php foreach ($mine as $p) { $tile($p); } ?></div>
    <?php elseif ($isOrganizer): ?><div class="mp-empty"><b>No videos yet</b><span>Post a video of your event and it shows here and on the home feed.</span><a href="/org-socials.php">Post a video</a></div>
    <?php else: ?><div class="mp-empty"><b>No videos yet</b><span>Organizers post event videos. Want to host your own events?</span><a href="/become-organizer.php">Become an organizer</a></div><?php endif; ?>
  </section>
  <section class="mp-panel" id="mpSaved" role="tabpanel" aria-labelledby="mpTabSaved" hidden>
    <?php if ($saved): ?><div class="mp-grid"><?php foreach ($saved as $p) { $tile($p); } ?></div>
    <?php else: ?><div class="mp-empty"><b>Nothing saved yet</b><span>Tap Save on a video and it waits for you here.</span></div><?php endif; ?>
  </section>
  <section class="mp-panel" id="mpLiked" role="tabpanel" aria-labelledby="mpTabLiked" hidden>
    <?php if ($liked): ?><div class="mp-grid"><?php foreach ($liked as $p) { $tile($p); } ?></div>
    <?php else: ?><div class="mp-empty"><b>No liked videos yet</b><span>Videos you like show up here.</span></div><?php endif; ?>
  </section>
</div>
<script>
(function () {
  var tabs = document.querySelectorAll('.mp-tabs [role="tab"]');
  tabs.forEach(function (t, i) {
    function pick(x, focus) {
      tabs.forEach(function (y) { var on = y === x; y.setAttribute('aria-selected', on ? 'true' : 'false'); y.tabIndex = on ? 0 : -1; document.getElementById(y.getAttribute('aria-controls')).hidden = !on; });
      if (focus) { x.focus(); }
    }
    t.addEventListener('click', function () { pick(t, false); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
      e.preventDefault();
      pick(tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length], true);
    });
  });
})();
</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
