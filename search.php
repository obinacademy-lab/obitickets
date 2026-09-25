<?php
require_once __DIR__ . '/includes/bootstrap.php';

$q = trim((string) ($_GET['q'] ?? ''));
$category = (string) ($_GET['category'] ?? '');
$category = in_array($category, EVENT_CATEGORIES, true) ? $category : '';
$freeOnly = isset($_GET['free']);

$results = search_events($q, $category ?: null, $freeOnly);

$titleBits = [];
if ($q !== '') {
    $titleBits[] = '"' . $q . '"';
}
if ($category !== '') {
    $titleBits[] = $category;
}
$pageTitle = ($titleBits ? implode(' · ', $titleBits) . ' — ' : '') . 'Search — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

/** Builds a search.php URL that keeps the current q while changing category/free. */
function search_url(string $q, string $category = '', bool $freeOnly = false): string
{
    $params = array_filter([
        'q' => $q !== '' ? $q : null,
        'category' => $category !== '' ? $category : null,
        'free' => $freeOnly ? '1' : null,
    ], static fn ($v) => $v !== null);
    return '/search.php' . ($params ? '?' . http_build_query($params) : '');
}

$catStripIcons = [
    'Music' => 'ic-music', 'Conference' => 'ic-briefcase', 'Comedy' => 'ic-mic',
    'Sports' => 'ic-ball', 'Faith' => 'ic-cross', 'Fashion' => 'ic-hanger', 'Community' => 'ic-people',
];

if ($category !== '') {
    $heroTitle = $category;
} elseif ($q !== '') {
    $heroTitle = 'Search results';
} else {
    $heroTitle = 'Browse events';
}
?>

<section class="page-hero">
  <img class="page-hero-bg" src="/assets/images/about/crowd.jpg" alt="">
  <div class="wrap page-hero-content">
    <h1><?= htmlspecialchars($heroTitle) ?></h1>
    <div class="page-hero-crumb">
      <a href="/">obitickets</a>
      <span>&rsaquo;</span>
      <span>Browse events</span>
    </div>
  </div>
</section>

<div class="wrap">
  <section class="sec">

    <form class="search-bar" action="/search.php" method="get" style="max-width:560px; margin:0 auto">
      <svg width="17" height="17"><use href="#ic-search"/></svg>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search events, artists or venues">
      <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
      <?php if ($freeOnly): ?><input type="hidden" name="free" value="1"><?php endif; ?>
      <button type="submit">Search</button>
    </form>

    <div class="search-cats">
      <a class="cat-chip <?= $category === '' && !$freeOnly ? 'active' : '' ?>" href="<?= htmlspecialchars(search_url($q)) ?>">
        <span class="cat-chip-ic"><svg width="16" height="16"><use href="#ic-grid"/></svg></span>
        <span class="cat-chip-label">All</span>
      </a>
      <?php foreach (EVENT_CATEGORIES as $cat): ?>
        <a class="cat-chip <?= $category === $cat ? 'active' : '' ?>" href="<?= htmlspecialchars(search_url($q, $cat)) ?>">
          <span class="cat-chip-ic"><svg width="16" height="16"><use href="#<?= $catStripIcons[$cat] ?>"/></svg></span>
          <span class="cat-chip-label"><?= htmlspecialchars($cat) ?></span>
        </a>
      <?php endforeach; ?>
      <a class="cat-chip <?= $freeOnly ? 'active' : '' ?>" href="<?= htmlspecialchars(search_url($q, '', true)) ?>">
        <span class="cat-chip-ic"><svg width="16" height="16"><use href="#ic-ticket"/></svg></span>
        <span class="cat-chip-label">Free</span>
      </a>
    </div>

    <div class="sec-head" style="margin-top:32px">
      <h2><?= count($results) ?> event<?= count($results) === 1 ? '' : 's' ?> found<?= $q !== '' ? ' for &ldquo;' . htmlspecialchars($q) . '&rdquo;' : '' ?></h2>
    </div>

    <?php if (!$results): ?>
      <p style="text-align:center; padding:40px 0">No events match your search &mdash; try a different keyword or category.</p>
    <?php else: ?>
      <div class="event-tile-grid" style="margin-top:32px">
        <?php foreach ($results as $event):
            $price = format_money($event['min_price'] ?? null, $event['min_price_currency'] ?? 'UGX');
            $priceLabel = $price === 'Free entry' ? $price : 'From ' . $price;
            $hasImage = !empty($event['banner_image']);
            $eventUrl = '/event.php?slug=' . urlencode($event['slug']);
        ?>
          <div class="event-tile reveal">
            <a class="event-tile-art" href="<?= htmlspecialchars($eventUrl) ?>">
              <span class="event-tile-date">
                <span class="d"><?= date('d', strtotime($event['starts_at'])) ?></span>
                <span class="m"><?= strtoupper(date('M', strtotime($event['starts_at']))) ?></span>
              </span>
              <?php if ($hasImage): ?>
                <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
              <?php else: ?>
                <div class="event-tile-art-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
              <?php endif; ?>
            </a>
            <div class="event-tile-body">
              <h3><a href="<?= htmlspecialchars($eventUrl) ?>"><?= htmlspecialchars($event['title']) ?></a></h3>
              <div class="event-tile-meta"><svg width="14" height="14"><use href="#ic-pin"/></svg> <?= htmlspecialchars($event['venue_name']) ?></div>
              <div class="event-tile-price"><?= htmlspecialchars($priceLabel) ?></div>
            </div>
            <a class="btn btn-purple" href="<?= htmlspecialchars($eventUrl) ?>">Get tickets &rarr;</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="trust-badges" style="margin-top:64px">
      <div><svg width="20" height="20"><use href="#ic-cal"/></svg> Real, scannable QR tickets</div>
      <div><svg width="20" height="20"><use href="#ic-shield"/></svg> Secure MTN &amp; Airtel checkout</div>
      <div><svg width="20" height="20"><use href="#ic-bolt"/></svg> Instant delivery, no waiting</div>
    </div>

  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
