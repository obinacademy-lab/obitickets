<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$organizer = is_string($slug) && $slug !== '' ? get_organizer_profile_by_slug($slug) : null;

if (!$organizer) {
    http_response_code(404);
    $pageTitle = 'Organizer not found — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <section class="auth-section">
        <div class="auth-card">
          <h1>Organizer not found</h1>
          <p class="sub">This organizer page may have moved, or the link is incorrect.</p>
          <a class="btn btn-purple btn-block btn-lg" href="/" style="margin-top:24px">Back to homepage</a>
        </div>
      </section>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}


$uid = (int) $organizer['user_id'];
$orgName = $organizer['org_name'];
$events = get_public_events_for_organizer($uid);
$eventIds = array_merge(array_column($events['upcoming'], 'id'), array_column($events['past'], 'id'));
$going = get_events_going_counts($eventIds);
$reviewStats = get_events_review_stats(array_column($events['past'], 'id'));
$ratingSummary = get_organizer_rating_summary($uid);
$stats = get_organizer_public_stats($uid);

// The follow button and social links need the community tables; the profile must
// still work without them (e.g. before migration 014 has been run).
$communityReady = false;
$followers = 0;
$following = false;
$socialLinks = [];
$viewer = current_user();
try {
    $followers = count_organizer_followers($uid);
    $following = is_following_organizer($uid, $viewer ? (int) $viewer['id'] : null);
    $socialLinks = get_organizer_social_links($uid);
    $communityReady = true;
} catch (Throwable $e) {
    error_log('[community] organizer page: ' . $e->getMessage());
}
$isOwnProfile = $viewer && (int) $viewer['id'] === $uid;

// Socials: the organizer's posts (Videos tab) and the numbers at the top. The profile works without them.
$soPosts = [];
$soCounts = ['posts' => 0, 'following' => 0];
try {
    if (function_exists('socials_feed')) {
        $soPosts = socials_feed($viewer ? (int) $viewer['id'] : null, 'profile', 0, 24, null, $uid);
        $soCounts = socials_profile_counts($uid);
    }
} catch (Throwable $e) {
    error_log('[socials] organizer page: ' . $e->getMessage());
    $soPosts = [];
}
$profileUrl = rtrim(APP_URL, '/') . '/organizer.php?slug=' . urlencode($organizer['slug']);
// The profile from the owner's mockup (11/18): a cover photo (their own, or their newest flyer), the picture over
// it, name + tick, "what · where", Videos / Followers / Following, Follow + share, the bio, then Events, Videos and
// Liked. The cover photo and the line come from migration 035 (My profile); without it the flyer and no line.
$opCover = function_exists('user_cover_url') ? (string) (user_cover_url($uid) ?? '') : '';
if ($opCover === '') {
    foreach (array_merge($events['upcoming'], $events['past']) as $ce) {
        if (preg_match('#^/(uploads|assets)/[A-Za-z0-9/_.\-]+$#', (string) ($ce['banner_image'] ?? ''))) { $opCover = (string) $ce['banner_image']; break; }
    }
}
$opLine = function_exists('organizer_tagline') ? organizer_tagline($uid) : ['headline' => '', 'location' => ''];
$opLineText = implode(' · ', array_filter([$opLine['headline'] !== '' ? $opLine['headline'] : 'Event Organizer', $opLine['location']]));
$opCompact = static fn (int $n): string => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M' : ($n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K' : (string) $n);
// videos this organizer liked (the Liked tab)
$opLiked = [];
try {
    if (function_exists('moment_select_sql')) {
        $lq = db()->prepare('SELECT x.* FROM (' . moment_select_sql() . ') x JOIN post_reactions r ON r.post_id = x.id AND r.user_id = ?
                             WHERE x.status = "PUBLISHED" AND COALESCE(x.is_draft, 0) = 0 ORDER BY r.created_at DESC LIMIT 30');
        $lq->execute([$uid]);
        $opLiked = $lq->fetchAll();
    }
} catch (Throwable $e) {
    error_log('[organizer] liked videos: ' . $e->getMessage());
    $opLiked = [];
}

$pageTitle = $orgName . ' — obitickets';
$pageDescription = ($organizer['bio'] ?: ('Events by ' . $orgName . ' on obitickets.'));
$bodyClass = 'ev-page app-dark op-app';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';

/** One event row: picture, title, date · venue, arrow. */
function render_profile_event_row(array $e, bool $past): void
{
    $start = strtotime($e['starts_at']);
    ?>
    <a class="op-row" href="/event.php?slug=<?= urlencode($e['slug']) ?>">
      <span class="op-row-art"><?php if (!empty($e['banner_image'])): ?><img src="<?= htmlspecialchars($e['banner_image']) ?>" alt="" loading="lazy"><?php else: ?><i aria-hidden="true"><?= htmlspecialchars((string) ($e['banner_emoji'] ?? '')) ?></i><?php endif; ?></span>
      <span class="op-row-tx">
        <b><?= htmlspecialchars($e['title']) ?></b>
        <small><?= htmlspecialchars(date('j M Y', $start) . ' · ' . $e['venue_name']) ?></small>
      </span>
      <?php if ($past): ?><em class="op-row-pill">Ended</em><?php endif; ?>
      <svg class="op-row-go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
    </a>
    <?php
}
/** One video tile for the Videos and Liked grids. */
function render_profile_tile(array $p): void
{
    $thumb = (string) ($p['media_type'] === 'VIDEO' ? ($p['poster_path'] ?: '') : $p['media_path']);
    $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $thumb) ? $thumb : '';
    $cap = trim((string) ($p['caption'] ?? ''));
    ?>
    <a class="op-tile" href="/socials.php?v=<?= (int) $p['id'] ?>" aria-label="<?= htmlspecialchars(($p['media_type'] === 'VIDEO' ? 'Video' : 'Post') . ($cap !== '' ? ': ' . mb_substr($cap, 0, 80) : '')) ?>">
      <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars((string) $p['media_path']) ?>#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video><?php endif; ?>
      <span class="op-views"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><?= htmlspecialchars(moment_compact((int) $p['view_count'])) ?></span>
    </a>
    <?php
}
$opAvatar = function_exists('user_avatar_url') ? user_avatar_url($uid) : null;
$opLive = (bool) $events['upcoming'];
?>

<div class="ev ev-profile op-page" id="evRoot"<?= $communityReady && !$viewer ? ' data-login="' . htmlspecialchars('/login.php?next=' . urlencode('/organizer.php?slug=' . $organizer['slug']), ENT_QUOTES) . '"' : '' ?> data-event-url="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>">

  <header class="op-head">
    <div class="op-cover"<?= $opCover !== '' ? ' style="background-image:url(\'' . htmlspecialchars($opCover, ENT_QUOTES) . '\')"' : '' ?> aria-hidden="true"></div>
    <div class="ev-wrap op-top">
      <a class="op-tbtn" href="/discover.php" data-back aria-label="Back"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
      <button type="button" class="op-tbtn" data-open-menu aria-label="Open menu"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></button>
    </div>
    <span class="op-avatar<?= $opLive ? ' is-live' : '' ?>"><?php if ($opAvatar): ?><img src="<?= htmlspecialchars($opAvatar) ?>" alt=""><?php else: ?><b aria-hidden="true"><?= htmlspecialchars(initials_from_name($orgName)) ?></b><?php endif; ?><?php if ($opLive): ?><i title="Has upcoming events"></i><?php endif; ?></span>
  </header>

  <section class="ev-wrap op-hero">
    <div class="op-name">
      <h1><?= htmlspecialchars($orgName) ?></h1>
      <?php if ((int) $organizer['verified'] === 1): ?>
        <span class="op-ver" title="Verified organizer"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><span class="sr-only">Verified organizer</span></span>
      <?php endif; ?>
    </div>
    <p class="op-line"><?= htmlspecialchars($opLineText) ?></p>

    <div class="op-stats">
      <div><b><?= htmlspecialchars($opCompact((int) $soCounts['posts'])) ?></b><span><?= (int) $soCounts['posts'] === 1 ? 'Video' : 'Videos' ?></span></div>
      <?php if ($communityReady): ?>
        <div><b id="orgFollowers"><?= htmlspecialchars($opCompact($followers)) ?></b><span><?= $followers === 1 ? 'Follower' : 'Followers' ?></span></div>
        <div><b><?= htmlspecialchars($opCompact((int) $soCounts['following'])) ?></b><span>Following</span></div>
      <?php endif; ?>
    </div>

    <div class="op-actions">
      <?php if ($isOwnProfile): ?>
        <a class="op-follow op-edit" href="/profile.php#cover">Edit profile</a>
      <?php elseif ($communityReady): ?>
        <button type="button" class="op-follow" data-ev-follow="organizer" data-id="<?= $uid ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Following' : 'Follow' ?></button>
      <?php endif; ?>
      <button type="button" class="op-send" data-ev-copy="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>" aria-label="Share this profile"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
    </div>

    <?php if ($organizer['bio']): ?><p class="op-bio"><?= nl2br(htmlspecialchars($organizer['bio'])) ?></p><?php endif; ?>
    <?php if ($socialLinks): ?>
      <div class="op-links">
        <?php foreach ($socialLinks as $link): ?>
          <a class="ev-pill" href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><b><?= htmlspecialchars($link['short']) ?></b> <?= htmlspecialchars($link['label']) ?><span class="sr-only"> (opens in a new tab)</span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="ev-wrap op-tabs" role="tablist" aria-label="Profile">
    <button type="button" role="tab" id="opTabEvents" aria-controls="opEvents" aria-selected="true">Events</button>
    <button type="button" role="tab" id="opTabVideos" aria-controls="opVideos" aria-selected="false" tabindex="-1">Videos</button>
    <button type="button" role="tab" id="opTabLiked" aria-controls="opLiked" aria-selected="false" tabindex="-1">Liked</button>
  </div>

  <div class="ev-wrap ev-profile-body">
    <section class="op-panel" id="opEvents" role="tabpanel" aria-labelledby="opTabEvents">
      <?php if ($events['upcoming']): ?>
        <h2 class="op-h">Upcoming Events (<?= count($events['upcoming']) ?>)</h2>
        <div class="op-rows"><?php foreach ($events['upcoming'] as $e) render_profile_event_row($e, false); ?></div>
      <?php endif; ?>
      <?php if ($events['past']): ?>
        <h2 class="op-h">Past Events (<?= count($events['past']) ?>)</h2>
        <div class="op-rows"><?php foreach ($events['past'] as $e) render_profile_event_row($e, true); ?></div>
      <?php endif; ?>
      <?php if (!$events['upcoming'] && !$events['past']): ?>
        <div class="ev-empty"><b>No published events yet</b><span>Events from <?= htmlspecialchars($orgName) ?> will show up here.</span></div>
      <?php endif; ?>
    </section>

    <section class="op-panel" id="opVideos" role="tabpanel" aria-labelledby="opTabVideos" hidden>
      <?php if ($soPosts): ?>
        <div class="op-grid"><?php foreach ($soPosts as $p) render_profile_tile($p); ?></div>
      <?php else: ?>
        <div class="ev-empty"><b>No videos yet</b><span>Videos from <?= htmlspecialchars($orgName) ?> will show up here.</span></div>
      <?php endif; ?>
    </section>

    <section class="op-panel" id="opLiked" role="tabpanel" aria-labelledby="opTabLiked" hidden>
      <?php if ($opLiked): ?>
        <div class="op-grid"><?php foreach ($opLiked as $p) render_profile_tile($p); ?></div>
      <?php else: ?>
        <div class="ev-empty"><b>No liked videos yet</b><span>Videos <?= htmlspecialchars($orgName) ?> likes will show up here.</span></div>
      <?php endif; ?>
    </section>
  </div>

  <div class="ev-toast" id="evToast" role="status" aria-live="polite" hidden></div>
</div>

<link rel="stylesheet" href="/assets/css/socials.css?v=<?= @filemtime(__DIR__ . '/assets/css/socials.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/organizer-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/organizer-app.css') ?: time() ?>">
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<script>
(function () {
  var tabs = document.querySelectorAll('.op-tabs [role="tab"]');
  function pick(t, focus) {
    tabs.forEach(function (x) {
      var on = x === t;
      x.setAttribute('aria-selected', on ? 'true' : 'false');
      x.tabIndex = on ? 0 : -1;
      document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) { t.focus(); }
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { pick(t, false); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
      e.preventDefault();
      pick(tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length], true);
    });
  });
})();
</script>
<script src="/assets/js/community.js?v=<?= @filemtime(__DIR__ . '/assets/js/community.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
