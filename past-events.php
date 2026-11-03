<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Past events: nothing is "moved" here — an event shows up on this page by
// itself the moment its end time passes (see past_events() in
// includes/events.php), and drops off the homepage/search at the same instant.

const PAST_EVENTS_PER_PAGE = 24;

$q = trim((string) ($_GET['q'] ?? ''));
$category = (string) ($_GET['category'] ?? '');
$category = in_array($category, EVENT_CATEGORIES, true) ? $category : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = past_events($q !== '' ? $q : null, $category ?: null, PAST_EVENTS_PER_PAGE, ($page - 1) * PAST_EVENTS_PER_PAGE);
$events = $result['events'];
$total = $result['total'];
$pageCount = max(1, (int) ceil($total / PAST_EVENTS_PER_PAGE));

/** Builds a past-events.php URL that keeps the current filters. */
function past_events_url(string $q = '', string $category = '', int $page = 1): string
{
    $params = array_filter([
        'q' => $q !== '' ? $q : null,
        'category' => $category !== '' ? $category : null,
        'page' => $page > 1 ? $page : null,
    ], static fn ($v) => $v !== null);
    return '/past-events.php' . ($params ? '?' . http_build_query($params) : '');
}

$catStripIcons = category_icon_map();
$hasFilter = $q !== '' || $category !== '';

$pageTitle = 'Past events — obitickets';
$pageDescription = 'Look back at events that have happened on obitickets — photos, ratings and reviews from the people who were there.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <img class="page-hero-bg" src="/assets/images/about/crowd.jpg" alt="">
  <div class="page-hero-glow"></div>
  <div class="wrap page-hero-content">
    <h1 class="hero-in" style="animation-delay:.05s">Past events</h1>
    <div class="page-hero-crumb hero-in" style="animation-delay:.15s">
      <a href="/">obitickets</a>
      <span>&rsaquo;</span>
      <span>Past events</span>
    </div>
  </div>
</section>

<div class="wrap">
  <section class="sec">

    <form class="search-bar" action="/past-events.php" method="get" style="max-width:560px; margin:0 auto">
      <svg width="17" height="17"><use href="#ic-search"/></svg>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search past events or venues">
      <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
      <button type="submit">Search</button>
    </form>

    <div class="search-cats">
      <a class="cat-chip <?= $category === '' ? 'active' : '' ?>" href="<?= htmlspecialchars(past_events_url($q)) ?>">
        <span class="cat-chip-ic"><svg width="16" height="16"><use href="#ic-grid"/></svg></span>
        <span class="cat-chip-label">All</span>
      </a>
      <?php foreach (EVENT_CATEGORIES as $cat): ?>
        <a class="cat-chip <?= $category === $cat ? 'active' : '' ?>" href="<?= htmlspecialchars(past_events_url($q, $cat)) ?>">
          <span class="cat-chip-ic"><svg width="16" height="16"><use href="#<?= $catStripIcons[$cat] ?>"/></svg></span>
          <span class="cat-chip-label"><?= htmlspecialchars($cat) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="sec-head" style="margin-top:32px">
      <h2><?= $total ?> past event<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' for &ldquo;' . htmlspecialchars($q) . '&rdquo;' : '' ?></h2>
      <a class="more" href="/search.php">Upcoming events &rarr;</a>
    </div>

    <?php if (!$events): ?>
      <div class="empty-state hero-in" style="margin-top:8px">
        <div class="empty-badge"><svg width="32" height="32"><use href="#ic-cal"/></svg></div>
        <h3><?= $hasFilter ? 'No past events match that' : 'No past events yet' ?></h3>
        <p><?= $hasFilter ? 'Try another category or a different search.' : 'Events appear here automatically once they finish.' ?></p>
        <div class="empty-actions">
          <?php if ($hasFilter): ?>
            <a class="btn btn-purple" href="/past-events.php">Show all past events</a>
          <?php else: ?>
            <a class="btn btn-purple" href="/search.php">Browse upcoming events <svg width="15" height="15" class="btn-arrow"><use href="#ic-arrow"/></svg></a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="past-grid" style="margin-top:8px">
        <?php foreach ($events as $event): render_past_event_card($event); endforeach; ?>
      </div>

      <?php if ($pageCount > 1): ?>
        <nav class="past-pager" aria-label="Pages">
          <?php if ($page > 1): ?>
            <a class="past-pager-btn" href="<?= htmlspecialchars(past_events_url($q, $category, $page - 1)) ?>">&larr; Newer</a>
          <?php endif; ?>
          <span class="past-pager-count">Page <?= $page ?> of <?= $pageCount ?></span>
          <?php if ($page < $pageCount): ?>
            <a class="past-pager-btn" href="<?= htmlspecialchars(past_events_url($q, $category, $page + 1)) ?>">Older &rarr;</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>

  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
