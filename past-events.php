<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/past-events.php';

// Past events: nothing is "moved" here. An event appears by itself once its end time has passed (or an admin
// marks it completed), and an admin can hide it, feature it, and add its story and photos. See includes/past-events.php.

const PAST_PER_PAGE = 12;

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$group = is_string($_GET['group'] ?? null) && isset(PAST_GROUPS[$_GET['group']]) ? $_GET['group'] : '';
$page = max(1, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1));

$counts = past_group_counts();
$unfiltered = $q === '' && $group === '';
$featured = $unfiltered ? past_featured_events(3) : [];
$featuredIds = array_map(static fn ($e) => (int) $e['id'], $featured);

$result = past_events_list($q, $group, PAST_PER_PAGE, ($page - 1) * PAST_PER_PAGE, $unfiltered ? $featuredIds : []);
$events = $result['events'];
$total = $result['total'];
$pageCount = max(1, (int) ceil($total / PAST_PER_PAGE));
$nothingYet = $counts['all'] === 0;

/** A past-events.php address that keeps the current filter and search. */
function past_list_url(string $q = '', string $group = '', int $page = 1): string
{
    $params = array_filter([
        'q' => $q !== '' ? $q : null,
        'group' => $group !== '' ? $group : null,
        'page' => $page > 1 ? $page : null,
    ], static fn ($v) => $v !== null);
    return '/past-events.php' . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = 'Past Events | Discover Event Highlights on Obitickets';
$pageDescription = "Explore past concerts, conferences, workshops and memorable experiences on Obitickets, Uganda's event discovery and ticketing platform.";
$bodyClass = 'classic-page past-events-page';
include __DIR__ . '/includes/header.php';

$ld = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Past Events',
    'description' => $pageDescription,
    'url' => APP_URL . '/past-events.php',
    'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => []],
];
foreach (array_merge($featured, $events) as $i => $e) {
    $ld['mainEntity']['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => APP_URL . past_event_url($e['slug']), 'name' => $e['title']];
}
?>

<section class="pe-hero">
  <img class="pe-hero-bg" src="/assets/images/about/about-club.jpg" alt="" fetchpriority="high">
  <div class="wrap pe-hero-in">
    <nav class="pe-crumb" aria-label="Breadcrumb"><a href="/">obitickets</a><span aria-hidden="true">&rsaquo;</span><span>Past events</span></nav>
    <h1 class="hero-in d1">PAST EVENTS</h1>
    <p class="pe-headline hero-in d2">Celebrating Experiences. Creating Memories.</p>
    <p class="pe-lead hero-in d3">Explore memorable events that have taken place through Obitickets. From concerts and entertainment to business conferences, workshops, masterclasses and special gatherings, discover the experiences that bring people together.</p>
  </div>
</section>

<div class="wrap pe-main">

  <?php if (!$nothingYet):
      $tileIcons = [
          '' => 'M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z',
          'concerts' => 'M9 18V6l10-2v12M9 18a3 3 0 1 1-3-3M19 16a3 3 0 1 1-3-3',
          'business' => 'M3 8h18v12H3zM9 8V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M3 13h18',
          'workshops' => 'M3 9l9-5 9 5-9 5zM7 11.5V16c0 1.5 2.2 3 5 3s5-1.5 5-3v-4.5',
          'festivals' => 'M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.5 6.7 19.4l1.2-6L3.4 9.3l6-.7z',
          'lifestyle' => 'M9 8a3 3 0 1 0 0 .01M3 20c0-3.2 2.7-5.5 6-5.5s6 2.3 6 5.5M17 9a2.5 2.5 0 1 0 0 .01M16.5 14.7c2.6 0 4.5 1.7 4.5 4.3',
      ];
      $tiles = ['' => 'All Past Events'] + PAST_GROUPS;
  ?>
  <section class="pe-find" aria-label="Find past events">
    <form class="pe-field<?= $q !== '' ? ' has-text' : '' ?>" action="/past-events.php" method="get" role="search">
      <?php if ($group !== ''): ?><input type="hidden" name="group" value="<?= htmlspecialchars($group) ?>"><?php endif; ?>
      <label class="pe-sr" for="peq">Search past events</label>
      <svg width="17" height="17" aria-hidden="true"><use href="#ic-search"/></svg>
      <input id="peq" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by event, venue or organizer">
      <?php if ($q !== ''): ?><a class="pe-clr" href="<?= htmlspecialchars(past_list_url('', $group)) ?>" aria-label="Clear search">&times;</a><?php endif; ?>
      <button type="submit">Search</button>
    </form>
    <nav class="pe-tiles" aria-label="Filter past events">
      <?php foreach ($tiles as $key => $label):
          $n = (int) $counts[$key === '' ? 'all' : $key];
          $on = $group === $key;
      ?>
        <a class="pe-tile<?= $on ? ' is-on' : '' ?><?= $n === 0 ? ' is-zero' : '' ?>" href="<?= htmlspecialchars(past_list_url($q, $key)) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
          <span class="pe-tile-ic"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="<?= $tileIcons[$key] ?>"/></svg></span>
          <b><?= htmlspecialchars($label) ?></b>
          <small><?= $n === 0 ? 'None yet' : $n . ($n === 1 ? ' event' : ' events') ?></small>
        </a>
      <?php endforeach; ?>
    </nav>
  </section>
  <?php endif; ?>

  <?php if ($nothingYet): ?>
    <section class="pe-empty" aria-labelledby="peEmptyH">
      <svg class="pe-empty-art" viewBox="0 0 220 180" role="img" aria-label="A calendar waiting for its first event">
        <rect x="26" y="34" width="168" height="126" rx="16" fill="#fff" stroke="#E7DFDA" stroke-width="2"/>
        <path d="M26 66h168V50a16 16 0 0 0-16-16H42a16 16 0 0 0-16 16z" fill="#B91C1C"/>
        <rect x="64" y="20" width="10" height="28" rx="5" fill="#1B1417"/><rect x="146" y="20" width="10" height="28" rx="5" fill="#1B1417"/>
        <g fill="#EADFDA"><rect x="48" y="82" width="22" height="18" rx="4"/><rect x="78" y="82" width="22" height="18" rx="4"/><rect x="108" y="82" width="22" height="18" rx="4"/><rect x="138" y="82" width="22" height="18" rx="4"/><rect x="48" y="108" width="22" height="18" rx="4"/><rect x="78" y="108" width="22" height="18" rx="4"/><rect x="48" y="134" width="22" height="14" rx="4"/></g>
        <rect x="108" y="108" width="22" height="18" rx="4" fill="#FDEDED" stroke="#B91C1C" stroke-width="2" stroke-dasharray="4 3"/>
        <path d="M168 128l5 11 12 2-9 8 3 12-11-6-11 6 3-12-9-8 12-2z" fill="#DC2626"/>
      </svg>
      <h2 id="peEmptyH">Our Event Journey Is Just Beginning!</h2>
      <p>Exciting experiences are ahead. Check back as we continue bringing more events and unforgettable moments to Obitickets.</p>
      <a class="pe-btn pe-btn-solid" href="/search.php">Browse upcoming events <svg width="15" height="15" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
    </section>
  <?php else: ?>

    <?php if ($featured && $page === 1): ?>
    <section class="pe-section" aria-labelledby="peFeatH">
      <div class="pe-head"><h2 id="peFeatH">Featured past events</h2></div>
      <div class="pe-featured">
        <?php foreach ($featured as $e): render_past_card($e, true); endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="pe-section" aria-labelledby="peAllH">
      <div class="pe-head">
        <h2 id="peAllH"><?= $group !== '' ? htmlspecialchars(PAST_GROUPS[$group]) : ($featured ? 'More past events' : 'All past events') ?></h2>
        <p class="pe-count"><?= (int) $total ?> event<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' for &ldquo;' . htmlspecialchars($q) . '&rdquo;' : '' ?></p>
      </div>

      <?php if (!$events && !$featured): ?>
        <div class="pe-nomatch">
          <h3>No past events match that</h3>
          <p><?= $group !== '' && $q === '' ? 'There are no finished events in this category yet.' : 'Try another category or a different search.' ?></p>
          <a class="pe-btn pe-btn-solid" href="/past-events.php">Show all past events</a>
        </div>
      <?php elseif ($events): ?>
        <div class="pe-grid">
          <?php foreach ($events as $e): render_past_card($e); endforeach; ?>
        </div>
        <?php if ($pageCount > 1): ?>
          <nav class="pe-pager" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= htmlspecialchars(past_list_url($q, $group, $page - 1)) ?>">&larr; Newer</a><?php else: ?><span></span><?php endif; ?>
            <span class="pe-pager-count">Page <?= $page ?> of <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?><a href="<?= htmlspecialchars(past_list_url($q, $group, $page + 1)) ?>">Older &rarr;</a><?php else: ?><span></span><?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

</div>

<section class="pe-cta" aria-labelledby="peCtaH">
  <div class="wrap pe-cta-in">
    <div>
      <p class="pe-cta-kicker">OBITICKETS &mdash; DISCOVER. BOOK. ATTEND.</p>
      <h2 id="peCtaH">YOUR NEXT EVENT COULD BE HERE!</h2>
      <p>Give your event the visibility it deserves. Publish your event on Obitickets, reach potential attendees and simplify your ticketing experience.</p>
    </div>
    <a class="pe-cta-btn" href="/event-create.php">PUBLISH YOUR EVENT <svg width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
  </div>
</section>

<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
