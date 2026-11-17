<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Discover (the app's second tab), built to the owner's dark app mockup (Phase 2, 11/18): search, category circles,
// quick picks, Trending events, Trending videos, Popular organizers and Events around you.
// Everything is real data; a section with nothing to show is left out. index.php also shows this page while there
// are no videos yet.
$pageTitle = 'Discover events — obitickets';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda, in person or online. Buy your ticket in under a minute.';
$bodyClass = 'classic-page app-dark discover-page';
$rcTabForce = 'discover';
$hideWhatsApp = true;

$place = is_string($_COOKIE['ot_place'] ?? null) ? $_COOKIE['ot_place'] : '';
$place = (in_array($place, UG_REGIONS, true) || is_uganda_district($place)) ? $place : '';
include __DIR__ . '/includes/header.php';

$viewer = current_user();
$unread = $viewer ? count_unread_notifications((int) $viewer['id']) : 0;
$trending = dsc_trending_events(10);
$videos = dsc_trending_videos(10);
$organizers = dsc_organizers(10);
[$around, $aroundLabel] = dsc_around($place, 8);
$catIcons = category_icon_map();
$nearPts = array_map(static fn ($n, $v) => [$n, $v[1], $v[2]], array_keys(uganda_districts()), uganda_districts());
$compact = static fn (int $n): string => $n >= 1000000 ? round($n / 1000000, 1) . 'M' : ($n >= 1000 ? round($n / 1000, 1) . 'K' : (string) $n);
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/discover.css?v=<?= @filemtime(__DIR__ . '/assets/css/discover.css') ?: time() ?>">

<div class="wrap dv">
  <div class="ad-top">
    <h1>Discover</h1>
    <a class="ad-icon" href="<?= $viewer ? '/notifications.php' : '/login.php?next=' . rawurlencode('/notifications.php') ?>" aria-label="Notifications<?= $unread ? ', ' . (int) $unread . ' unread' : '' ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><?php if ($unread): ?><i></i><?php endif; ?></a>
    <button type="button" class="ad-icon ad-menu" data-open-menu aria-label="Open menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
  </div>

  <form class="dv-search" action="/search.php" method="get" role="search">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <label class="sr-only" for="dvQ">Search events, organizers, venues</label>
    <input id="dvQ" type="text" name="q" placeholder="Search events, organizers, venues..." autocomplete="off" enterkeyhint="search">
    <button type="submit" class="sr-only">Search</button>
  </form>

  <nav class="dv-cats" aria-label="Categories">
    <?php foreach (EVENT_CATEGORIES as $cat): ?>
      <a href="/search.php?category=<?= urlencode((string) $cat) ?>"><i><svg width="22" height="22" aria-hidden="true"><use href="#<?= htmlspecialchars($catIcons[$cat] ?? 'ic-ticket') ?>"/></svg></i><span><?= htmlspecialchars((string) $cat) ?></span></a>
    <?php endforeach; ?>
    <a href="/search.php?free=1"><i><svg width="22" height="22" aria-hidden="true"><use href="#ic-ticket"/></svg></i><span>Free</span></a>
    <?php if (events_online_ready()): ?><a href="/search.php?online=1"><i><svg width="22" height="22" aria-hidden="true"><use href="#ic-monitor"/></svg></i><span>Online</span></a><?php endif; ?>
  </nav>

  <nav class="dv-when" aria-label="When and where">
    <button type="button" class="dv-chip dv-near" id="sxNear" data-url="/search.php?near=__D__" data-fallback="/search.php" data-pts="<?= htmlspecialchars(json_encode($nearPts)) ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/></svg>Near me</button>
    <a class="dv-chip" href="/search.php?when=today">Today</a>
    <a class="dv-chip" href="/search.php?when=weekend">This weekend</a>
    <a class="dv-chip" href="/search.php?when=week">This week</a>
    <a class="dv-chip" href="/search.php?when=month">This month</a>
  </nav>
  <p class="dv-msg" id="sxMsg" role="status" hidden></p>

  <?php if ($trending): ?>
  <section class="ad-sec" aria-labelledby="dvTrend">
    <div class="ad-sec-h"><h2 id="dvTrend"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2c1 3.5-1.500 5-1.500 7.500A2.500 2.500 0 0 0 13 12c1.700 0 2.500-1.400 2.300-3.200C17.600 10.500 19 13 19 15.500 19 19.600 15.900 22 12 22s-7-2.400-7-6.300C5 10.800 9.500 8.300 12 2z"/></svg>Trending events</h2><a href="/search.php">See all</a></div>
    <div class="dv-rail">
      <?php foreach ($trending as $e):
          $url = '/event.php?slug=' . urlencode((string) $e['slug']);
          $img = (string) ($e['banner_image'] ?? ''); ?>
      <a class="dv-card" href="<?= htmlspecialchars($url) ?>">
        <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><span class="dv-noimg" style="background:<?= htmlspecialchars(landing_gradient($e)) ?>"><?= htmlspecialchars((string) ($e['banner_emoji'] ?? '')) ?></span><?php endif; ?>
        <span class="dv-card-tx">
          <b><?= htmlspecialchars((string) $e['title']) ?></b>
          <small><?= htmlspecialchars(dsc_when($e)) ?></small>
          <?php if ((int) $e['views'] > 0): ?><em><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><?= htmlspecialchars($compact((int) $e['views'])) ?> <span class="sr-only">views</span></em><?php endif; ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($videos): ?>
  <section class="ad-sec" aria-labelledby="dvVids">
    <div class="ad-sec-h"><h2 id="dvVids"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>Trending videos</h2><a href="/">Watch all</a></div>
    <div class="dv-rail dv-rail-v">
      <?php foreach ($videos as $v):
          $thumb = $v['type'] === 'video' ? (string) ($v['poster'] ?: ($v['event']['banner'] ?? '')) : ($v['type'] === 'slideshow' ? (string) ($v['slides'][0] ?? '') : (string) $v['src']); ?>
      <a class="dv-vid" href="/?v=<?= (int) $v['id'] ?>" aria-label="<?= htmlspecialchars($v['event']['title'] . ', by ' . $v['org']['name']) ?>">
        <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php endif; ?>
        <span class="dv-vid-tx"><b><?= htmlspecialchars($v['org']['handle']) ?></b><small><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><?= htmlspecialchars($compact((int) $v['views'])) ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($organizers): ?>
  <section class="ad-sec" aria-labelledby="dvOrgs">
    <div class="ad-sec-h"><h2 id="dvOrgs"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9c0-4 3.600-6.500 8-6.500s8 2.500 8 6.500z"/></svg>Popular organizers</h2></div>
    <div class="dv-rail dv-orgs">
      <?php foreach ($organizers as $o): $av = function_exists('user_avatar_url') ? user_avatar_url((int) $o['user_id']) : null; ?>
      <a class="dv-org" href="/organizer.php?slug=<?= urlencode((string) $o['slug']) ?>">
        <span class="dv-org-av"><?php if ($av): ?><img src="<?= htmlspecialchars($av) ?>" alt="" loading="lazy"><?php else: ?><?= htmlspecialchars(community_initials((string) $o['org_name'])) ?><?php endif; ?></span>
        <b><?= htmlspecialchars((string) $o['org_name']) ?><?php if ((int) $o['verified'] === 1): ?> <span class="dv-ver" title="Verified organizer"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><?php endif; ?></b>
        <small><?= (int) $o['upcoming'] ?> <?= (int) $o['upcoming'] === 1 ? 'event' : 'events' ?><?= (int) $o['followers'] > 0 ? ' · ' . htmlspecialchars($compact((int) $o['followers'])) . ' ' . ((int) $o['followers'] === 1 ? 'follower' : 'followers') : '' ?></small>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="ad-sec" aria-labelledby="dvAround">
    <div class="ad-sec-h"><h2 id="dvAround"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 22s7-6.200 7-12a7 7 0 1 0-14 0c0 5.800 7 12 7 12zm0-9.500a2.500 2.500 0 1 1 0-5 2.500 2.500 0 0 1 0 5z"/></svg><?= htmlspecialchars($aroundLabel) ?></h2><a href="<?= htmlspecialchars('/search.php' . ($place !== '' ? '?where=' . urlencode($place) : '')) ?>">See all</a></div>
    <?php if ($around): ?>
    <div class="dv-rows">
      <?php foreach ($around as $e):
          $url = '/event.php?slug=' . urlencode((string) $e['slug']);
          $img = (string) ($e['banner_image'] ?? '');
          $min = $e['min_price'] ?? null;
          $price = $min === null ? '' : ((float) $min <= 0.0 ? 'Free' : 'UGX ' . number_format((float) $min)); ?>
      <a class="dv-row" href="<?= htmlspecialchars($url) ?>">
        <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><span class="dv-noimg" style="background:<?= htmlspecialchars(landing_gradient($e)) ?>"><?= htmlspecialchars((string) ($e['banner_emoji'] ?? '')) ?></span><?php endif; ?>
        <span class="dv-row-tx">
          <b><?= htmlspecialchars((string) $e['title']) ?></b>
          <small><?= htmlspecialchars(dsc_when($e) . ' · ' . st_town($e)) ?></small>
          <?php if ($price !== ''): ?><em class="<?= $price === 'Free' ? 'free' : '' ?>"><?= htmlspecialchars($price === 'Free' ? 'Free' : 'From ' . $price) ?></em><?php endif; ?>
        </span>
        <svg class="dv-go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="ad-empty">No events published yet. Check back soon.</p>
    <?php endif; ?>
  </section>
</div>

<script src="/assets/js/search-near.js" defer></script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
