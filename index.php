<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda. Buy your ticket in under a minute.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

$upcoming = upcoming_events_with_organizer(6);
$categoryIcons = category_icon_map();
?>

<section class="home-hero">
  <div class="home-hero-slides" id="homeHeroSlides">
    <?php foreach (range(1, 8) as $slideNum): ?>
      <div class="home-hero-slide<?= $slideNum === 1 ? ' is-active' : '' ?>" style="background-image:url('/assets/images/home-hero/slide-<?= $slideNum ?>.jpg')"></div>
    <?php endforeach; ?>
  </div>
  <div class="home-hero-overlay"></div>
  <div class="home-hero-noise"></div>
  <div class="home-hero-glow home-hero-glow-a"></div>
  <div class="home-hero-glow home-hero-glow-b"></div>
  <div class="home-hero-content">
    <div class="home-hero-eyebrow"><span class="hero-dot"></span>Uganda's events, all in one place</div>
    <h1 class="split-heading" data-split>Connecting Uganda&rsquo;s events.</h1>
    <p>Easy to search &mdash; just enter a keyword, or pick a category.</p>
    <form class="home-search" action="/search.php" method="get">
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
      <button type="submit" class="btn btn-purple">Search</button>
    </form>
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

<?php if ($upcoming): ?>
<section class="evt-section">
  <div class="wrap">
    <div class="evt-section-head">
      <div><span class="eyebrow">What's on</span><h2 style="margin-top:10px;">Upcoming events</h2></div>
      <div class="evt-nav">
        <button type="button" class="evt-arrow" data-dir="-1" aria-label="Scroll left"><svg width="18" height="18"><use href="#ic-arrow" transform="rotate(180 12 12)"/></svg></button>
        <button type="button" class="evt-arrow" data-dir="1" aria-label="Scroll right"><svg width="18" height="18"><use href="#ic-arrow"/></svg></button>
      </div>
    </div>
    <div class="evt-row">
      <?php foreach ($upcoming as $event):
          $price = format_money($event['min_price'] ?? null, $event['min_price_currency'] ?? 'UGX');
          $priceLabel = $price === 'Free entry' ? $price : 'From ' . $price;
      ?>
        <div class="evt-card reveal">
          <a class="evt-art" href="/event.php?slug=<?= urlencode($event['slug']) ?>">
            <?php if (!empty($event['banner_image'])): ?>
              <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
            <?php else: ?>
              <div class="evt-art-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
            <?php endif; ?>
            <span class="evt-cat-pill"><?= htmlspecialchars($event['category']) ?></span>
            <span class="evt-avatar"><?= htmlspecialchars(initials_from_name($event['organizer_name'])) ?></span>
          </a>
          <div class="evt-body">
            <h3><a href="/event.php?slug=<?= urlencode($event['slug']) ?>"><?= htmlspecialchars($event['title']) ?></a></h3>
            <div class="evt-meta"><svg width="14" height="14"><use href="#ic-cal"/></svg> <?= htmlspecialchars(date('D, M j, g:ia', strtotime($event['starts_at']))) ?></div>
            <div class="evt-meta"><svg width="14" height="14"><use href="#ic-pin"/></svg> <?= htmlspecialchars($event['venue_name']) ?></div>
            <div class="evt-foot">
              <a class="btn btn-line" href="/event.php?slug=<?= urlencode($event['slug']) ?>">Get Ticket</a>
              <span class="evt-price"><?= htmlspecialchars($priceLabel) ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php else: ?>
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
