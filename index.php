<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda, in person or online. Buy your ticket in under a minute.';
$bodyClass = 'classic-page landing-home home-grid';
include __DIR__ . '/includes/header.php';

// "Marketplace Grid" home (owner pick 11/16). Everything shown is real: a section with no events does not appear.
$featured = array_slice(get_popular_events(4), 0, 4);
$featuredIds = array_map(static fn ($e) => (int) $e['id'], $featured);
$upcoming = search_events(null, null, false, 24);
$gridEvents = array_values(array_filter($upcoming, static fn ($e) => !in_array((int) $e['id'], $featuredIds, true)));
if (count($gridEvents) < 2) {
    $gridEvents = $upcoming; // few events: show them all in the grid too rather than an empty section
}
$currentUser = current_user();
$nextList = $currentUser ? get_upcoming_tickets_for_user((int) $currentUser['id']) : [];
// Signed-in people see a slider: their own tickets first, then other upcoming events so there is always something to slide to.
$slides = [];
foreach ($nextList as $t) { $slides[] = ['kind' => 'ticket', 'e' => $t]; }
if ($currentUser) {
    $ownIds = array_map(static fn ($t) => (int) $t['id'], $nextList);
    foreach (upcoming_events_with_organizer(12) as $e) {
        if (count($slides) >= 8) { break; }
        if (!in_array((int) $e['id'], $ownIds, true)) { $slides[] = ['kind' => 'event', 'e' => $e]; }
    }
}
$nearPts = array_map(static fn ($n, $v) => [$n, $v[1], $v[2]], array_keys(uganda_districts()), uganda_districts());
?>

<link rel="stylesheet" href="/assets/css/home-grid.css?v=<?= @filemtime(__DIR__ . '/assets/css/home-grid.css') ?: time() ?>">
<section class="hg-top">
  <div class="wrap">
    <h1 class="hg-h1">Tickets to everything happening in Uganda.</h1>
    <form class="hg-search" action="/search.php" method="get" role="search">
      <label class="sr-only" for="ldQ">Search events</label>
      <svg width="19" height="19" aria-hidden="true"><use href="#ic-search"/></svg>
      <input id="ldQ" type="text" name="q" placeholder="Search events or venues" autocomplete="off">
      <button type="submit" class="hg-go">Search</button>
    </form>
    <nav class="hg-seg" aria-label="Show events">
      <a class="on" href="/" aria-current="page">For you</a>
      <button type="button" id="sxNear" data-url="/search.php?near=__D__" data-fallback="/search.php" data-pts="<?= htmlspecialchars(json_encode($nearPts)) ?>">Near me</button>
      <a href="/search.php?when=weekend">Weekend</a>
      <a href="/search.php?free=1">Free</a>
    </nav>
    <p class="hg-msg" id="sxMsg" role="status" hidden></p>
  </div>
</section>

<?php if ($slides): $multi = count($slides) > 1; $ticketsSeen = 0; ?>
<div class="wrap">
  <div class="ld-nx" id="ldNext" data-multi="<?= $multi ? '1' : '0' ?>">
    <?php if ($multi): ?>
    <div class="ld-nx-bar">
      <span><?= $nextList ? 'Your tickets and what&rsquo;s coming up' : 'Coming up' ?></span>
      <div class="ld-nx-ctl">
        <button type="button" class="ld-nx-btn ld-nx-pause" data-nx="pause" aria-label="Pause auto-slide" aria-pressed="false"><span aria-hidden="true">&#10073;&#10073;</span></button>
        <button type="button" class="ld-nx-btn" data-nx="prev" aria-label="Previous event">&lsaquo;</button>
        <button type="button" class="ld-nx-btn" data-nx="next" aria-label="Next event">&rsaquo;</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="ld-nx-track"<?= $multi ? ' tabindex="0" role="group" aria-roledescription="carousel" aria-label="Your tickets and upcoming events"' : '' ?>>
      <?php foreach ($slides as $nxi => $sl):
          $nx = $sl['e'];
          $isTicket = $sl['kind'] === 'ticket';
          $nxTs = strtotime((string) $nx['starts_at']);
          $nxEnd = (int) strtotime((string) ($nx['ends_at'] ?? $nx['starts_at']));
          $nxJoin = false;
          if ($isTicket) {
              $nxEvent = events_online_ready() ? get_event_by_id((int) $nx['id']) : null;
              $nxJoin = ($nxEvent && event_is_online($nxEvent) && online_join_window($nxEvent)['state'] === 'open');
              $label = $ticketsSeen === 0 ? 'Your next event' : 'Your ticket';
              $ticketsSeen++;
          } else {
              $label = 'Coming up';
          }
      ?>
      <div class="ld-next ld-nx-slide<?= $isTicket ? '' : ' is-event' ?>" data-start="<?= $nxTs ?>" data-end="<?= $nxEnd ?>"<?= $multi ? ' role="group" aria-roledescription="slide" aria-label="' . ($nxi + 1) . ' of ' . count($slides) . '"' : '' ?>>
        <div class="ld-nx-tx"><small><?= $label ?></small><b><?= htmlspecialchars($nx['title']) ?></b><span><?= htmlspecialchars(date('D j M, g:ia', $nxTs)) ?> &middot; <?= htmlspecialchars($nx['venue_name']) ?><?php if (!$isTicket && ($p = landing_price_label($nx)) !== ''): ?> &middot; <?= htmlspecialchars($p) ?><?php endif; ?></span></div>
        <div class="ld-nx-cd ld-cd" aria-live="off">&nbsp;</div>
        <?php if ($isTicket): ?>
          <?php if ($nxJoin): ?><a class="btn btn-purple" href="/join.php?event=<?= (int) $nx['id'] ?>">Join now</a><?php endif; ?>
          <a class="btn btn-purple" href="/order.php?id=<?= (int) $nx['order_id'] ?>">View ticket</a>
        <?php endif; ?>
        <a class="btn btn-purple ld-nx-ev" href="/event.php?slug=<?= urlencode($nx['slug']) ?>">View event</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($multi): ?>
    <div class="ld-nx-dots" role="tablist" aria-label="Choose an event">
      <?php foreach ($slides as $nxi => $sl): ?><button type="button" role="tab" class="ld-nx-dot" data-i="<?= $nxi ?>" aria-label="Event <?= $nxi + 1 ?>: <?= htmlspecialchars($sl['e']['title']) ?>" aria-selected="<?= $nxi === 0 ? 'true' : 'false' ?>"></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($featured): ?>
<section class="wrap hg-sec" aria-labelledby="hgFeat">
  <div class="hg-head"><h2 id="hgFeat">Featured</h2><a href="/search.php">See all</a></div>
  <div class="hg-feat">
    <?php foreach ($featured as $e) { render_hg_feature($e); } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($gridEvents): ?>
<section class="wrap hg-sec" aria-labelledby="hgSoon">
  <div class="hg-head"><h2 id="hgSoon">Happening soon</h2><a href="/search.php">See all</a></div>
  <div class="hg-grid">
    <?php foreach ($gridEvents as $e) { render_hg_card($e); } ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$featured && !$gridEvents): ?>
  <p class="hg-none">No events published yet — check back soon.</p>
<?php endif; ?>

<?php $rcCatIcons = category_icon_map(); ?>
<nav class="wrap rc-home-cats hg-sec" aria-label="Browse by category">
  <div class="hg-head"><h2>Browse by category</h2></div>
  <div class="rc-cats">
    <?php foreach (EVENT_CATEGORIES as $rcCat): ?>
      <a class="rc-cat" href="/search.php?category=<?= urlencode((string) $rcCat) ?>"><i><svg width="24" height="24" aria-hidden="true"><use href="#<?= htmlspecialchars($rcCatIcons[$rcCat] ?? 'ic-ticket') ?>"/></svg></i><?= htmlspecialchars((string) $rcCat) ?></a>
    <?php endforeach; ?>
    <a class="rc-cat" href="/search.php?free=1"><i><svg width="24" height="24" aria-hidden="true"><use href="#ic-ticket"/></svg></i>Free</a>
    <?php if (events_online_ready()): ?><a class="rc-cat" href="/search.php?online=1"><i><svg width="24" height="24" aria-hidden="true"><use href="#ic-monitor"/></svg></i>Online</a><?php endif; ?>
  </div>
</nav>

<section class="wrap hg-sec">
  <div class="hg-org">
    <div class="hg-org-tx">
      <b>Selling tickets?</b>
      <span>Create your event with no upfront cost and get paid on mobile money.</span>
    </div>
    <a class="hg-org-btn" href="/my-events.php">Start</a>
  </div>
  <ul class="hg-trust">
    <li>MTN MoMo and Airtel Money</li>
    <li>Instant QR tickets</li>
    <li>Free and online events</li>
  </ul>
</section>

<script src="/assets/js/search-near.js" defer></script>
<script src="/assets/js/landing.js?v=<?= @filemtime(__DIR__ . '/assets/js/landing.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
