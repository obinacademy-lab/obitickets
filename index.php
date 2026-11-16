<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda, in person or online. Buy your ticket in under a minute.';
$bodyClass = 'classic-page landing-home home-stories';

// "Stories Feed" home, built exactly to the owner's pick (11/16, mockup 3): story circles, event posts with Follow
// and a buy bar, and the Trending top three. Everything shown is real; with no events the page says so.
$homePlace = is_string($_COOKIE['ot_place'] ?? null) ? $_COOKIE['ot_place'] : '';
$homePlace = (in_array($homePlace, UG_REGIONS, true) || is_uganda_district($homePlace)) ? $homePlace : '';

// The two round buttons in the phone top bar (search, saved events), in place of the menu button.
$navPlace = '<span class="st-topbtns">'
    . '<a class="st-topbtn" href="/search.php" aria-label="Search events"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></a>'
    . '<a class="st-topbtn" href="/saved.php" aria-label="Saved events"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg></a>'
    . '</span>';
include __DIR__ . '/includes/header.php';

$feed = st_feed_events(40);
[$firstPost, $trending, $morePosts, $trendLabel] = st_home_layout($feed, $homePlace);
$stories = st_stories($feed);
$viewer = current_user();
$nearPts = array_map(static fn ($n, $v) => [$n, $v[1], $v[2]], array_keys(uganda_districts()), uganda_districts());
?>
<link rel="stylesheet" href="/assets/css/home-stories.css?v=<?= @filemtime(__DIR__ . '/assets/css/home-stories.css') ?: time() ?>">

<div class="wrap st-page">
  <h1 class="st-h1">Tickets to everything happening in Uganda.</h1>

  <nav class="st-stories" aria-label="Quick picks">
    <button type="button" class="st-story" id="sxNear" data-url="/search.php?near=__D__" data-fallback="/search.php" data-pts="<?= htmlspecialchars(json_encode($nearPts)) ?>">
      <span class="st-ring red"><span class="st-in st-in-ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/></svg></span></span>
      <span class="st-story-l">Near me</span>
    </button>
    <?php foreach ($stories as $s): ?>
    <a class="st-story" href="<?= htmlspecialchars($s['url']) ?>">
      <span class="st-ring<?= $s['live'] ? '' : ' seen' ?>"><?php if ($s['img'] !== ''): ?><img class="st-in" src="<?= htmlspecialchars($s['img']) ?>" alt="" loading="lazy"><?php else: ?><span class="st-in st-in-ic"><svg width="22" height="22" aria-hidden="true"><use href="#<?= htmlspecialchars($s['icon']) ?>"/></svg></span><?php endif; ?></span>
      <span class="st-story-l"><?= htmlspecialchars($s['label']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <p class="st-msg" id="sxMsg" role="status" hidden></p>

  <?php if ($firstPost): ?>
  <div class="st-feed">
    <?php render_st_post($firstPost, 0, $viewer); ?>
    <?php render_st_trending($trending, $trendLabel); ?>
    <?php foreach ($morePosts as $i => $e) { render_st_post($e, $i + 1, $viewer); } ?>
    <a class="st-more" href="/search.php">See all events</a>
  </div>
  <?php else: ?>
    <p class="st-none">No events published yet — check back soon.</p>
  <?php endif; ?>
</div>

<script src="/assets/js/search-near.js" defer></script>
<script src="/assets/js/home-stories.js?v=<?= @filemtime(__DIR__ . '/assets/js/home-stories.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
