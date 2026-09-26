<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda. Buy your ticket in under a minute.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

$upcoming = upcoming_events_with_organizer(12);
$picks = array_slice($upcoming, 0, 4);
$grid = array_slice($upcoming, 4);
$categoryIcons = category_icon_map();

/** One poster-forward card for the Picks row / More events grid — image
 * does the talking, just title/date/venue beneath it, no price or button
 * (momoticketing.com-inspired: the grid card stays minimal, the sell
 * happens on the event page itself). */
function render_poster_card(array $event): void
{
    ?>
    <a class="poster-card reveal" href="/event.php?slug=<?= urlencode($event['slug']) ?>">
      <?php if (!empty($event['banner_image'])): ?>
        <img class="poster-card-img" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
      <?php else: ?>
        <div class="poster-card-img poster-card-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
      <?php endif; ?>
      <h3 class="poster-card-title"><?= htmlspecialchars($event['title']) ?></h3>
      <div class="poster-card-meta"><?= htmlspecialchars(date('D j M', strtotime($event['starts_at']))) ?></div>
      <div class="poster-card-meta poster-card-venue"><?= htmlspecialchars($event['venue_name']) ?></div>
    </a>
    <?php
}
?>

<section class="home-hero-v2">
  <div class="wrap home-hero-v2-inner">
    <div class="home-hero-v2-copy">
      <div class="home-hero-eyebrow reveal"><span class="hero-dot"></span>Uganda's events, all in one place</div>
      <h1 class="reveal">Never miss a moment worth showing up for.</h1>
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
    <?php if ($picks): ?>
    <div class="home-hero-v2-collage" aria-hidden="true">
      <?php foreach (array_slice($picks, 0, 3) as $i => $event): ?>
        <?php if (!empty($event['banner_image'])): ?>
          <img class="home-hero-v2-collage-img" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
        <?php else: ?>
          <div class="home-hero-v2-collage-img poster-card-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="cat-strip">
  <?php foreach (EVENT_CATEGORIES as $cat): ?>
    <a class="cat-chip" href="/search.php?category=<?= urlencode($cat) ?>">
      <span class="cat-chip-ic"><svg width="16" height="16"><use href="#<?= $categoryIcons[$cat] ?>"/></svg></span>
      <span class="cat-chip-label"><?= htmlspecialchars($cat) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($picks): ?>
<section class="picks-section">
  <div class="wrap">
    <div class="evt-section-head">
      <div><span class="eyebrow">Our picks</span><h2 style="margin-top:10px;">Don't miss these</h2></div>
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
  <h2 style="margin-bottom:22px;">More events</h2>
  <div class="poster-grid">
    <?php foreach ($grid as $event) render_poster_card($event); ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$upcoming): ?>
  <p style="text-align:center; padding:40px 0 80px">No events published yet — check back soon.</p>
<?php endif; ?>

<div class="pullquote-band">
  <div class="band-shape band-shape-a"></div>
  <div class="band-shape band-shape-b"></div>
  <span class="eyebrow">For organizers</span>
  <div class="band-stat" data-count="90" data-suffix="%">0%</div>
  <h2>Keep 90% of every sale. Get paid the same day.</h2>
  <p>10% commission, only when you sell. No setup fees, no surprises.</p>
</div>

<div class="classic-cta-wrap wrap">
  <div class="classic-cta reveal">
    <span class="eyebrow">Get started</span>
    <h2>Every ticket, properly kept.</h2>
    <p>Whichever side of the door you're on, obitickets is built to make it simple.</p>
    <div class="classic-cta-btns">
      <span class="magnetic"><a class="btn btn-purple btn-lg" href="/">Browse events</a></span>
      <span class="magnetic"><a class="btn btn-line btn-lg" href="/signup.php">Start selling &mdash; it's free</a></span>
    </div>
  </div>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
