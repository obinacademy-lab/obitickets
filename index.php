<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda. Buy your ticket in under a minute.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

$upcoming = upcoming_events_with_organizer(12);
$picks = array_slice($upcoming, 0, 4);
$grid = array_slice($upcoming, 4);
try {
    $stats = home_stats();
} catch (Throwable $e) {
    $stats = ['events' => 0, 'organizers' => 0, 'free' => 0];
}

?>

<section class="home-hero-v2">
  <div class="wrap home-hero-v2-inner">
    <div class="home-hero-v2-copy">
      <span class="mo-eyebrow"><i aria-hidden="true"></i>Tickets on sale across Uganda</span>
      <h1 data-rise>Never miss a moment worth showing up for.</h1>
      <p class="reveal">Discover concerts, conferences, comedy and festivals across Uganda &mdash; and pay securely with MTN MoMo or Airtel Money.</p>
      <form class="home-search reveal" action="/search.php" method="get">
        <div class="home-search-field home-search-field-q">
          <svg width="16" height="16"><use href="#ic-search"/></svg>
          <input type="text" name="q" placeholder="Search events, artists or venues">
        </div>
        <div class="home-search-div"></div>
        <div class="home-search-field home-search-field-cat">
          <select name="category">
            <option value="">All categories</option>
            <?php foreach (EVENT_CATEGORIES as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
          </select>
          <svg class="field-chevron" width="13" height="13"><use href="#ic-chev" transform="rotate(90 12 12)"/></svg>
        </div>
        <button type="submit" class="btn btn-purple">Discover</button>
      </form>
    </div>
    <?php if ($upcoming): ?>
    <div class="home-hero-v2-collage" id="heroCollage">
      <?php foreach ($upcoming as $i => $event): ?>
        <a class="home-hero-v2-collage-item" href="/event.php?slug=<?= urlencode($event['slug']) ?>" aria-label="View <?= htmlspecialchars($event['title']) ?>">
          <?php if (!empty($event['banner_image'])): ?>
            <img class="home-hero-v2-collage-img" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
          <?php else: ?>
            <div class="home-hero-v2-collage-img poster-card-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <script>
    (function () {
      var collage = document.getElementById('heroCollage');
      var items = collage ? collage.querySelectorAll('.home-hero-v2-collage-item') : [];
      if (!items.length) return;

      // pos[i] = which slot (0 = front, 1 = mid, 2 = back) item i currently sits in.
      var pos = Array.prototype.map.call(items, function (_, i) { return i; });
      function render() {
        items.forEach(function (el, i) { el.setAttribute('data-pos', pos[i]); });
      }
      items.forEach(function (el, i) {
        setTimeout(function () { el.setAttribute('data-pos', pos[i]); }, i * 150);
      });

      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || items.length < 2) return;

      var timer = null;
      function advance() {
        pos = pos.map(function (p) { return (p + 1) % items.length; });
        render();
      }
      function start() { if (!timer) timer = setInterval(advance, 3000); }
      function stop() { clearInterval(timer); timer = null; }

      start();
      collage.addEventListener('mouseenter', stop);
      collage.addEventListener('mouseleave', start);
      collage.addEventListener('focusin', stop);
      collage.addEventListener('focusout', start);
    })();
    </script>
    <?php endif; ?>
  </div>
</section>

<div class="mo-ticker" aria-hidden="true"><div class="t">
  <?php for ($i = 0; $i < 4; $i++): ?><span class="row"><span class="i">Now selling <b>&#10022;</b></span><span class="i">MTN MoMo &amp; Airtel Money <b>&#10022;</b></span><span class="i">Free events <b>&#10022;</b></span><span class="i">Online events <b>&#10022;</b></span><span class="i">Instant QR tickets <b>&#10022;</b></span><span class="i">Kampala &middot; Jinja &middot; Entebbe <b>&#10022;</b></span></span><?php endfor; ?>
</div></div>

<?php if ($picks): ?>
<section class="picks-section">
  <div class="wrap">
    <div class="evt-section-head reveal">
      <div><h2>Our Picks</h2><p class="section-sub">Hand-picked by the obitickets team</p></div>
      <a style="font-size:0.86rem; font-weight:700; color:var(--purple-deep)" href="/search.php">See more &rarr;</a>
    </div>
    <div class="picks-row">
      <?php foreach ($picks as $event) render_poster_card($event); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($grid): ?>
<section class="wrap" style="padding:8px 0 64px;">
  <h2 class="reveal" style="margin-bottom:22px;">More events</h2>
  <div class="poster-grid">
    <?php foreach ($grid as $event) render_poster_card($event); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($stats['events'] > 0): ?>
<section class="wrap" style="padding:8px 0 56px">
  <div class="mo-stats reveal">
    <div><b data-count="<?= (int) $stats['events'] ?>"><?= (int) $stats['events'] ?></b><span>event<?= $stats['events'] === 1 ? '' : 's' ?> on sale right now</span></div>
    <div><b data-count="<?= (int) $stats['organizers'] ?>"><?= (int) $stats['organizers'] ?></b><span>organizer<?= $stats['organizers'] === 1 ? '' : 's' ?> hosting them</span></div>
    <?php if ($stats['free'] > 0): ?>
    <div><b data-count="<?= (int) $stats['free'] ?>"><?= (int) $stats['free'] ?></b><span>event<?= $stats['free'] === 1 ? '' : 's' ?> you can get into free</span></div>
    <?php else: ?>
    <div><b data-count="2">2</b><span>mobile money networks: MTN and Airtel</span></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$upcoming): ?>
  <p style="text-align:center; padding:40px 0 80px">No events published yet — check back soon.</p>
<?php endif; ?>

<section class="wrap">
  <div class="mo-band reveal">
    <div>
      <h2>Selling tickets? Start in minutes.</h2>
      <p>Create a free or paid event, in person or online, and get paid straight to mobile money.</p>
    </div>
    <a class="btn btn-purple btn-lg" href="/my-events.php">Create an event</a>
  </div>
</section>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
