<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle ='obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda, in person or online. Buy your ticket in under a minute.';
$bodyClass = 'classic-page landing-home home-grid';

// "Marketplace Grid" home, built exactly to the owner's pick (11/16): white header with the place picker, search,
// For you / Near me / Weekend / Free, one big banner, the "Popular in ..." grid and the Selling tickets band.
// Everything shown is real; with no events the page says so.
$homePlace = is_string($_COOKIE['ot_place'] ?? null) ? $_COOKIE['ot_place'] : '';
$homePlace = (in_array($homePlace, UG_REGIONS, true) || is_uganda_district($homePlace)) ? $homePlace : '';
$districtsByRegion = [];
foreach (uganda_districts() as $dName => [$dRegion]) {
    $districtsByRegion[$dRegion][] = $dName;
}
$navPlace = mg_place_picker($homePlace, $districtsByRegion);
include __DIR__ . '/includes/header.php';

[$banner, $popular, $placeLabel] = mg_home_events($homePlace);
$nearPts = array_map(static fn ($n, $v) => [$n, $v[1], $v[2]], array_keys(uganda_districts()), uganda_districts());
?>
<link rel="stylesheet" href="/assets/css/home-grid.css?v=<?= @filemtime(__DIR__ . '/assets/css/home-grid.css') ?: time() ?>">

<section class="mg-top">
  <div class="wrap">
    <h1 class="mg-h1">Tickets to everything happening in Uganda.</h1>
    <form class="mg-search" action="/search.php" method="get" role="search">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <label class="sr-only" for="ldQ">Search events</label>
      <input id="ldQ" type="text" name="q" placeholder="Search events, artists, venues" autocomplete="off" enterkeyhint="search">
      <button type="submit" class="sr-only">Search</button>
    </form>
    <nav class="mg-seg" aria-label="Show events">
      <a class="on" href="/" aria-current="page">For you</a>
      <button type="button" id="sxNear" data-url="/search.php?near=__D__" data-fallback="/search.php" data-pts="<?= htmlspecialchars(json_encode($nearPts)) ?>">Near me</button>
      <a href="/search.php?when=weekend">Weekend</a>
      <a href="/search.php?free=1">Free</a>
    </nav>
    <p class="mg-msg" id="sxMsg" role="status" hidden></p>
  </div>
</section>

<div class="wrap mg-body">
  <?php if ($banner): render_mg_banner($banner); endif; ?>

  <?php if ($popular): ?>
  <section class="mg-sec" aria-labelledby="mgPop">
    <div class="mg-head"><h2 id="mgPop">Popular in <?= htmlspecialchars($placeLabel) ?></h2><a href="<?= htmlspecialchars(mg_see_all_url($homePlace)) ?>">See all</a></div>
    <div class="mg-grid">
      <?php foreach ($popular as $e) { render_mg_card($e); } ?>
    </div>
  </section>
  <?php elseif (!$banner): ?>
    <p class="mg-none">No events published yet — check back soon.</p>
  <?php endif; ?>

  <section class="mg-org">
    <span class="mg-org-tx"><b>Selling tickets?</b><span>Create your event free. Get paid on mobile money.</span></span>
    <a class="mg-org-btn" href="/my-events.php">Start</a>
  </section>
</div>

<script src="/assets/js/search-near.js" defer></script>
<script src="/assets/js/home-grid.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
