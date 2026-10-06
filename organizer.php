<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$organizer = is_string($slug) && $slug !== '' ? get_organizer_profile_by_slug($slug) : null;

if (!$organizer) {
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

<div class="ev ev-profile" id="evRoot"<?= $communityReady && !$viewer ? ' data-login="' . htmlspecialchars('/login.php?next=' . urlencode('/organizer.php?slug=' . $organizer['slug']), ENT_QUOTES) . '"' : '' ?> data-event-url="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>">

  <section class="ev-wrap ev-org-hero">
    <span class="ev-avatar ev-avatar-xl" style="background:#DC2626; color:#fff" aria-hidden="true"><?= htmlspecialchars(initials_from_name($orgName)) ?></span>
    <div class="ev-org-hero-main">
      <div class="ev-org-hero-name">
        <h1><?= htmlspecialchars($orgName) ?></h1>
        <?php if ((int) $organizer['verified'] === 1): ?>
          <span class="ev-chip ev-chip-official ev-chip-solid-soft"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Verified organizer</span>
        <?php endif; ?>
      </div>
      <?php if ($organizer['bio']): ?><p class="ev-org-hero-bio"><?= nl2br(htmlspecialchars($organizer['bio'])) ?></p><?php endif; ?>

      <div class="ev-org-hero-actions">
        <?php if ($communityReady && !$isOwnProfile): ?>
          <button type="button" class="ev-btn ev-btn-dark" data-ev-follow="organizer" data-id="<?= $uid ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Following' : 'Follow' ?></button>
        <?php endif; ?>
        <button type="button" class="ev-icon-btn" data-ev-copy="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>" aria-label="Share this profile"><svg width="18" height="18"><use href="#ic-share"/></svg></button>
        <?php foreach ($socialLinks as $link): ?>
          <a class="ev-pill" href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><b><?= htmlspecialchars($link['short']) ?></b> <?= htmlspecialchars($link['label']) ?><span class="sr-only"> (opens in a new tab)</span></a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="ev-wrap">
    <div class="ev-statbar">
      <?php if ($communityReady): ?>
        <div class="ev-stat"><b id="orgFollowers"><?= number_format($followers) ?></b><span><?= $followers === 1 ? 'follower' : 'followers' ?></span></div>
      <?php endif; ?>
      <div class="ev-stat"><b><?= number_format($stats['events']) ?></b><span>event<?= $stats['events'] === 1 ? '' : 's' ?> hosted</span></div>
      <?php if ($ratingSummary['count'] > 0): ?>
        <div class="ev-stat"><b><?= number_format($ratingSummary['average'], 1) ?> <small class="ev-star">&#9733;</small></b><span>from <?= $ratingSummary['count'] ?> verified review<?= $ratingSummary['count'] === 1 ? '' : 's' ?></span></div>
      <?php endif; ?>
      <?php if ($stats['tickets_sold'] > 0): ?>
        <div class="ev-stat"><b><?= number_format($stats['tickets_sold']) ?></b><span>tickets sold</span></div>
      <?php endif; ?>
    </div>
  </section>

  <div class="ev-wrap ev-profile-body">
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
  </div>

  <div class="ev-toast" id="evToast" role="status" aria-live="polite" hidden></div>
</div>

<script src="/assets/js/community.js?v=<?= @filemtime(__DIR__ . '/assets/js/community.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
