<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda, in person or online. Buy your ticket in under a minute.';
$bodyClass = 'classic-page landing-home';
include __DIR__ . '/includes/header.php';

// Everything below is real: nothing is invented, and a section with no events simply does not appear.
$wallEvents = get_hero_carousel_events(8);            // prefers events that have a poster
$featured = array_slice(get_popular_events(3), 0, 3);
$featuredIds = array_map(static fn ($e) => (int) $e['id'], $featured);
$more = array_values(array_filter(
    upcoming_events_with_organizer(12),
    static fn ($e) => !in_array((int) $e['id'], $featuredIds, true)
));
$currentUser = current_user();
$next = $currentUser ? get_next_ticket_for_user((int) $currentUser['id']) : null;

// Two columns of posters that drift in opposite directions. Each column needs enough tiles to fill the height
// before it is doubled for a seamless loop.
$colA = $colB = [];
foreach ($wallEvents as $i => $e) {
    if ($i % 2 === 0) { $colA[] = $e; } else { $colB[] = $e; }
}
if (!$colB) { $colB = array_reverse($colA); }
$fill = static function (array $col): array {
    $out = $col;
    while ($out && count($out) < 4) { $out = array_merge($out, $col); }
    return $out;
};
$colA = $fill($colA);
$colB = $fill($colB);
?>

<section class="ld-hero">
  <div class="wrap ld-hero-grid">
    <div class="ld-copy">
      <h1>Tickets to everything happening in <i>Uganda.</i></h1>
      <p>Discover concerts, conferences, comedy and festivals, in person or online. Pay with mobile money and get your QR ticket instantly.</p>
      <form class="ld-search" action="/search.php" method="get" role="search">
        <label class="sr-only" for="ldQ">Search events</label>
        <input id="ldQ" type="text" name="q" placeholder="Search events, artists or venues" autocomplete="off">
        <button type="submit" class="btn btn-purple">Search</button>
      </form>
      <ul class="ld-trust">
        <li>MTN MoMo and Airtel Money</li>
        <li>Instant QR tickets</li>
        <li>Free and online events</li>
      </ul>
    </div>
    <?php if ($colA): ?>
    <div class="ld-wall" aria-hidden="true">
      <div class="ld-col"><?php foreach (array_merge($colA, $colA) as $e) { render_landing_poster($e); } ?></div>
      <div class="ld-col r"><?php foreach (array_merge($colB, $colB) as $e) { render_landing_poster($e); } ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($next):
    $nxTs = strtotime((string) $next['starts_at']);
    $nxEvent = events_online_ready() ? get_event_by_id((int) $next['id']) : null;
    $nxJoin = ($nxEvent && event_is_online($nxEvent) && online_join_window($nxEvent)['state'] === 'open');
?>
<div class="wrap">
  <div class="ld-next" id="ldNext" data-start="<?= $nxTs ?>" data-end="<?= (int) strtotime((string) $next['ends_at']) ?>">
    <div class="ld-nx-tx"><small>Your next event</small><b><?= htmlspecialchars($next['title']) ?></b><span><?= htmlspecialchars(date('D j M, g:ia', $nxTs)) ?> &middot; <?= htmlspecialchars($next['venue_name']) ?></span></div>
    <div class="ld-nx-cd" id="ldCd" aria-live="off">&nbsp;</div>
    <?php if ($nxJoin): ?><a class="btn btn-purple" href="/join.php?event=<?= (int) $next['id'] ?>">Join now</a><?php endif; ?>
    <a class="btn <?= $nxJoin ? 'btn-line' : 'btn-purple' ?>" href="/order.php?id=<?= (int) $next['order_id'] ?>">View ticket</a>
  </div>
</div>
<?php endif; ?>

<section class="wrap ld-sec">
  <h2 class="reveal">How it works</h2>
  <p class="ld-lead reveal">Three steps from search to the gate.</p>
  <div class="ld-steps">
    <div class="ld-step reveal"><b>1</b><h3>Find an event</h3><p>Search or browse by category, date, free or online.</p></div>
    <div class="ld-step reveal"><b>2</b><h3>Pay in seconds</h3><p>Approve a prompt on your phone with MTN MoMo or Airtel Money. Free events skip payment.</p></div>
    <div class="ld-step reveal"><b>3</b><h3>Show your QR code</h3><p>Your ticket arrives instantly by email and on your account. Online events add a join link.</p></div>
  </div>
</section>

<?php if ($featured): ?>
<section class="wrap ld-sec">
  <div class="ld-head">
    <div><h2 class="reveal">Featured events</h2><p class="ld-lead reveal">Hand-picked by the obitickets team.</p></div>
    <a class="ld-all" href="/search.php">See all events &rarr;</a>
  </div>
  <div class="ld-feat">
    <?php foreach ($featured as $e) { render_landing_card($e); } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($more): ?>
<section class="wrap ld-sec">
  <h2 class="reveal">More events</h2>
  <div class="poster-grid" style="margin-top:22px">
    <?php foreach ($more as $e) { render_poster_card($e); } ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$wallEvents && !$featured && !$more): ?>
  <p style="text-align:center; padding:40px 0 80px">No events published yet — check back soon.</p>
<?php endif; ?>

<section class="wrap">
  <div class="ld-org reveal">
    <div>
      <h2>Selling tickets? Start in minutes.</h2>
      <p>List a free or paid event, in person or online, and get paid straight to mobile money.</p>
      <a class="btn btn-purple btn-lg" href="/my-events.php">Create an event</a>
    </div>
    <ul>
      <li>No upfront cost, only a small commission on paid tickets</li>
      <li>QR check-in for your gate team</li>
      <li>Live sales and attendee lists</li>
      <li>Free tickets and online events built in</li>
    </ul>
  </div>
</section>

<script src="/assets/js/landing.js?v=<?= @filemtime(__DIR__ . '/assets/js/landing.js') ?: time() ?>"></script>
<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
