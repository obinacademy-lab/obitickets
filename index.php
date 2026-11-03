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
  <?php
  // The four reasons, with a small icon each. They glide past as a ticker; the first copy is the real list for
  // screen readers and the copies are hidden from them.
  $orgPerks = [
      ['<rect x="3" y="6.5" width="18" height="11" rx="2.2"/><circle cx="12" cy="12" r="2.6"/>', 'No upfront cost, only a small commission on paid tickets'],
      ['<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><path d="M14 14h2v2h-2zM18 18h2M14 20v-2"/>', 'QR check-in for your gate team'],
      ['<path d="M4 19V5M4 19h16M8 15v-4M12 15V8M16 15v-6"/>', 'Live sales and attendee lists'],
      ['<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.6 3.6 5.4 3.6 8.5s-1.1 5.9-3.6 8.5c-2.5-2.6-3.6-5.4-3.6-8.5S9.5 6.1 12 3.5z"/>', 'Free tickets and online events built in'],
  ];
  ?>
  <div class="ld-org reveal">
    <div class="ld-org-in">
      <div>
        <div class="ld-org-k">For organizers</div>
        <h2>Selling tickets? Start in minutes.</h2>
        <p class="ld-org-lead">List a free or paid event, in person or online, and get paid straight to mobile money.</p>
      </div>
      <div class="ld-org-act">
        <a class="btn btn-lg ld-org-btn" href="/my-events.php">Create an event</a>
        <div class="ld-org-note">No upfront cost, only a small commission on paid tickets.</div>
      </div>
    </div>
    <div class="ld-org-rail">
      <ul class="ld-org-track">
        <?php for ($copy = 0; $copy < 4; $copy++): foreach ($orgPerks as $perk): ?>
          <li<?= $copy > 0 ? ' aria-hidden="true"' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $perk[0] ?></svg><?= htmlspecialchars($perk[1]) ?></li>
        <?php endforeach; endfor; ?>
      </ul>
    </div>
  </div>
</section>

<script src="/assets/js/landing.js?v=<?= @filemtime(__DIR__ . '/assets/js/landing.js') ?: time() ?>"></script>
<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
