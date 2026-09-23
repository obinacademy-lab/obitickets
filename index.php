<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda. Buy your ticket in under a minute.';
$bodyClass = 'classic-page spotlight-home';
include __DIR__ . '/includes/header.php';

$featured = get_hero_carousel_events(7);
$spotlight = $featured[0] ?? null;
$filmReel = array_slice($featured, 1, 6);
$publishedCount = get_platform_stats()['published_events'];
?>

<section class="spotlight-hero">
  <?php if ($spotlight && !empty($spotlight['banner_image'])): ?>
    <img class="spotlight-hero-bg" src="<?= htmlspecialchars($spotlight['banner_image']) ?>" alt="">
  <?php elseif ($spotlight): ?>
    <div class="spotlight-hero-fallback"><?= htmlspecialchars($spotlight['banner_emoji']) ?></div>
  <?php endif; ?>
  <div class="spotlight-scrim"></div>
  <div class="spotlight-card-wrap">
    <div class="spotlight-card">
      <div class="eyebrow"><?= $publishedCount ?> events live right now</div>
      <h1>One night. One ticket. Zero doubt.</h1>
      <p>Real seats, real QR codes, real Mobile Money checkout.</p>
      <form class="spotlight-search" action="/search.php" method="get">
        <span aria-hidden="true">&#128269;</span>
        <input type="text" name="q" placeholder="Search events, artists or venues">
        <button type="submit">Search</button>
      </form>
    </div>
  </div>
</section>

<?php if ($filmReel): ?>
<section class="filmstrip-sec">
  <div class="filmstrip">
    <?php foreach ($filmReel as $event): ?>
      <a class="film-frame" href="/event.php?slug=<?= urlencode($event['slug']) ?>">
        <div class="film-frame-art">
          <?php if (!empty($event['banner_image'])): ?>
            <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
          <?php else: ?>
            <div class="film-frame-art-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
          <?php endif; ?>
        </div>
        <div class="film-cap">
          <div class="film-cat"><?= htmlspecialchars($event['category']) ?> &middot; <?= htmlspecialchars(date('M j', strtotime($event['starts_at']))) ?></div>
          <div class="film-ttl"><?= htmlspecialchars($event['title']) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
  <p style="text-align:center; padding:40px 0 80px">No events published yet — check back soon.</p>
<?php endif; ?>

<div class="wrap">
  <div class="cat-index">
    <a class="active" href="/search.php">All</a>
    <?php foreach (EVENT_CATEGORIES as $cat): ?>
      <a href="/search.php?category=<?= urlencode($cat) ?>"><?= htmlspecialchars($cat) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="pullquote-band">
  <span class="eyebrow">For organizers</span>
  <h2>Keep 90% of every sale. Get paid the same day.</h2>
  <p>10% commission, only when you sell. No setup fees, no surprises.</p>
</div>

<div class="classic-cta-wrap wrap">
  <div class="classic-cta reveal">
    <span class="eyebrow">Get started</span>
    <h2>Every ticket, properly kept.</h2>
    <p>Whichever side of the door you're on, obitickets is built to make it simple.</p>
    <div class="classic-cta-btns">
      <a class="btn btn-purple btn-lg" href="/">Browse events</a>
      <a class="btn btn-line btn-lg" href="/signup.php">Start selling &mdash; it's free</a>
    </div>
  </div>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
