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

$pageTitle = $orgName . ' — obitickets';
$pageDescription = ($organizer['bio'] ?: ('Events by ' . $orgName . ' on obitickets.'));
$bodyClass = 'ev-page';
include __DIR__ . '/includes/header.php';

/** One event card for the profile grids. */
function render_profile_event_card(array $e, bool $past, array $going, array $reviewStats): void
{
    $id = (int) $e['id'];
    $start = strtotime($e['starts_at']);
    ?>
    <a class="ev-ecard" href="/event.php?slug=<?= urlencode($e['slug']) ?>">
      <span class="ev-ecard-art">
        <?php if (!empty($e['banner_image'])): ?>
          <img src="<?= htmlspecialchars($e['banner_image']) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="ev-ecard-fallback" aria-hidden="true"><?= htmlspecialchars($e['banner_emoji']) ?></span>
        <?php endif; ?>
        <?php if ($past): ?>
          <span class="ev-ecard-pill">Ended</span>
        <?php else: ?>
          <span class="ev-ecard-date"><b><?= date('d', $start) ?></b><i><?= strtoupper(date('M', $start)) ?></i></span>
        <?php endif; ?>
      </span>
      <span class="ev-ecard-title"><?= htmlspecialchars($e['title']) ?></span>
      <span class="ev-ecard-sub"><?= htmlspecialchars($past ? date('D j M Y', $start) . ' · ' . $e['venue_name'] : $e['venue_name']) ?></span>
      <span class="ev-ecard-foot">
        <?php if ($past && isset($reviewStats[$id])): ?>
          <?= render_star_rating($reviewStats[$id]['average']) ?> <b><?= number_format($reviewStats[$id]['average'], 1) ?></b> <span>(<?= $reviewStats[$id]['count'] ?>)</span>
        <?php elseif (!$past && ($going[$id] ?? 0) > 0): ?>
          <b><?= number_format($going[$id]) ?></b> <span>going</span>
        <?php elseif ($past): ?>
          <span>No reviews yet</span>
        <?php endif; ?>
      </span>
    </a>
    <?php
}
?>

<div class="ev ev-profile op-page" id="evRoot"<?= $communityReady && !$viewer ? ' data-login="' . htmlspecialchars('/login.php?next=' . urlencode('/organizer.php?slug=' . $organizer['slug']), ENT_QUOTES) . '"' : '' ?> data-event-url="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>">

  <section class="ev-wrap op-hero">
    <?php $opAvatar = function_exists('user_avatar_url') ? user_avatar_url($uid) : null; ?>
    <span class="op-avatar" aria-hidden="true"><?php if ($opAvatar): ?><img src="<?= htmlspecialchars($opAvatar) ?>" alt=""><?php else: ?><?= htmlspecialchars(initials_from_name($orgName)) ?><?php endif; ?></span>
    <div class="op-name">
      <h1><?= htmlspecialchars($orgName) ?></h1>
      <?php if ((int) $organizer['verified'] === 1): ?>
        <span class="op-ver" title="Verified organizer"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><span class="sr-only">Verified organizer</span></span>
      <?php endif; ?>
    </div>
    <p class="op-handle">@<?= htmlspecialchars((string) $organizer['slug']) ?></p>

    <div class="op-stats">
      <div><b><?= number_format($soCounts['posts']) ?></b><span><?= $soCounts['posts'] === 1 ? 'Video' : 'Videos' ?></span></div>
      <?php if ($communityReady): ?>
        <div><b id="orgFollowers"><?= number_format($followers) ?></b><span><?= $followers === 1 ? 'Follower' : 'Followers' ?></span></div>
        <div><b><?= number_format($soCounts['following']) ?></b><span>Following</span></div>
      <?php endif; ?>
      <div><b><?= number_format($stats['events']) ?></b><span>Events hosted</span></div>
    </div>

    <div class="op-actions">
      <?php if ($communityReady && !$isOwnProfile): ?>
        <button type="button" class="op-follow" data-ev-follow="organizer" data-id="<?= $uid ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Following' : 'Follow' ?></button>
      <?php endif; ?>
      <button type="button" class="op-icon" data-ev-copy="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>" aria-label="Share this profile"><svg width="18" height="18"><use href="#ic-share"/></svg></button>
    </div>

    <?php if ($organizer['bio']): ?><p class="op-bio"><?= nl2br(htmlspecialchars($organizer['bio'])) ?></p><?php endif; ?>
    <?php if ($ratingSummary['count'] > 0 || $stats['tickets_sold'] > 0): ?>
      <p class="op-sub">
        <?php if ($ratingSummary['count'] > 0): ?><span>&#9733; <?= number_format($ratingSummary['average'], 1) ?> from <?= $ratingSummary['count'] ?> verified review<?= $ratingSummary['count'] === 1 ? '' : 's' ?></span><?php endif; ?>
        <?php if ($stats['tickets_sold'] > 0): ?><span><?= number_format($stats['tickets_sold']) ?> ticket<?= (int) $stats['tickets_sold'] === 1 ? '' : 's' ?> sold</span><?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($socialLinks): ?>
      <div class="op-links">
        <?php foreach ($socialLinks as $link): ?>
          <a class="ev-pill" href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><b><?= htmlspecialchars($link['short']) ?></b> <?= htmlspecialchars($link['label']) ?><span class="sr-only"> (opens in a new tab)</span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php $opStart = $soPosts ? 'videos' : 'events'; ?>
  <div class="ev-wrap op-tabs" role="tablist" aria-label="Profile">
    <button type="button" role="tab" id="opTabVideos" aria-controls="opVideos" aria-selected="<?= $opStart === 'videos' ? 'true' : 'false' ?>"<?= $opStart === 'videos' ? '' : ' tabindex="-1"' ?>>Videos</button>
    <button type="button" role="tab" id="opTabEvents" aria-controls="opEvents" aria-selected="<?= $opStart === 'events' ? 'true' : 'false' ?>"<?= $opStart === 'events' ? '' : ' tabindex="-1"' ?>>Events</button>
  </div>

  <div class="ev-wrap ev-profile-body">
    <section class="op-panel" id="opVideos" role="tabpanel" aria-labelledby="opTabVideos"<?= $opStart === 'videos' ? '' : ' hidden' ?>>
      <?php if ($soPosts): ?>
        <div class="op-grid">
          <?php foreach ($soPosts as $p):
              $thumb = (string) ($p['media_type'] === 'VIDEO' ? ($p['poster_path'] ?: '') : $p['media_path']);
              $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $thumb) ? $thumb : '';
              $cap = trim((string) ($p['caption'] ?? ''));
          ?>
            <a class="op-tile" href="/socials.php?v=<?= (int) $p['id'] ?>" aria-label="<?= htmlspecialchars(($p['media_type'] === 'VIDEO' ? 'Video' : 'Post') . ': ' . ($cap !== '' ? mb_substr($cap, 0, 80) : $p['event_title'])) ?>">
              <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars((string) $p['media_path']) ?>#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video><?php endif; ?>
              <span class="op-views"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><?= htmlspecialchars(moment_compact((int) $p['view_count'])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="ev-empty"><b>No videos yet</b><span>Videos from <?= htmlspecialchars($orgName) ?> will show up here.</span></div>
      <?php endif; ?>
    </section>

    <section class="op-panel" id="opEvents" role="tabpanel" aria-labelledby="opTabEvents"<?= $opStart === 'events' ? '' : ' hidden' ?>>

    <?php if ($events['upcoming']): ?>
      <section class="ev-section">
        <h2 class="ev-h2">Upcoming events <span class="ev-h2-count"><?= count($events['upcoming']) ?></span></h2>
        <div class="ev-egrid">
          <?php foreach ($events['upcoming'] as $e) render_profile_event_card($e, false, $going, $reviewStats); ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($events['past']): ?>
      <section class="ev-section">
        <h2 class="ev-h2">Past events <span class="ev-h2-count"><?= count($events['past']) ?></span></h2>
        <p class="ev-section-note">Photos and reviews from people who were there.</p>
        <div class="ev-egrid">
          <?php foreach ($events['past'] as $e) render_profile_event_card($e, true, $going, $reviewStats); ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$events['upcoming'] && !$events['past']): ?>
      <div class="ev-empty">
        <b>No published events yet</b>
        <span>Events from <?= htmlspecialchars($orgName) ?> will show up here.</span>
      </div>
    <?php endif; ?>
    </section>
  </div>

  <div class="ev-toast" id="evToast" role="status" aria-live="polite" hidden></div>
</div>

<link rel="stylesheet" href="/assets/css/socials.css?v=<?= @filemtime(__DIR__ . '/assets/css/socials.css') ?: time() ?>">
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
