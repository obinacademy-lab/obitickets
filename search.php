<?php
require_once __DIR__ . '/includes/bootstrap.php';

$getStr = static fn (string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';

$q = $getStr('q');
$category = $getStr('category');
$category = in_array($category, EVENT_CATEGORIES, true) ? $category : '';
$freeOnly = isset($_GET['free']);
$onlineOnly = isset($_GET['online']);

// When: a date chip. Where: all of Uganda, a region or a district. Near: "Near me" turned into the nearest district
// in the browser, so only a district name ever reaches the server (never the buyer's position).
$when = $getStr('when');
$when = isset(SEARCH_WHEN[$when]) ? $when : '';
$where = $getStr('where');
$where = (in_array($where, UG_REGIONS, true) || is_uganda_district($where)) ? $where : '';
$near = $getStr('near');
$near = is_uganda_district($near) ? $near : '';
if ($near !== '') {
    $where = '';
}
const NEAR_KM = 100;

$window = $when !== '' ? search_when_window($when) : null;
$byPlace = $where !== '' || $near !== '';
$results = search_events($q, $category ?: null, $freeOnly, $byPlace ? 400 : 60, $onlineOnly, $window);

if ($byPlace) {
    $kept = [];
    foreach ($results as $ev) {
        $d = event_district($ev);
        if ($d === null) {
            continue;
        }
        if ($near !== '') {
            $km = district_distance_km($near, $d);
            if ($km > NEAR_KM) {
                continue;
            }
            $ev['_km'] = $d === $near ? 0.0 : $km;
        } elseif (in_array($where, UG_REGIONS, true) ? district_region($d) !== $where : $d !== $where) {
            continue;
        }
        $ev['_district'] = $d;
        $kept[] = $ev;
    }
    if ($near !== '') {
        // Same district first, then the closest towns; soonest first within each.
        usort($kept, static fn ($a, $b) => [(int) round($a['_km'] / 15), $a['starts_at']] <=> [(int) round($b['_km'] / 15), $b['starts_at']]);
    }
    $results = array_slice($kept, 0, 60);
}

$placeLabel = $near !== '' ? 'near ' . $near : ($where !== '' ? (in_array($where, UG_REGIONS, true) ? 'in the ' . $where . ' region' : 'in ' . $where) : '');

$titleBits = [];
if ($q !== '') {
    $titleBits[] = '"' . $q . '"';
}
if ($category !== '') {
    $titleBits[] = $category;
}
if ($when !== '') {
    $titleBits[] = SEARCH_WHEN[$when];
}
if ($placeLabel !== '') {
    $titleBits[] = ucfirst($placeLabel);
}
$pageTitle = ($titleBits ? implode(' · ', $titleBits) . ' — ' : '') . 'Search — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

/**
 * Builds a search.php URL from the current filters with some changed. Category, Free and Online stay one-at-a-time
 * (they share the chip row); the date and place filters are kept unless they are changed too.
 */
function search_url(string $q, string $category = '', bool $freeOnly = false, bool $onlineOnly = false, ?array $place = null): string
{
    $place = $place ?? ($GLOBALS['searchPlace'] ?? []);
    $params = array_filter([
        'q' => $q !== '' ? $q : null,
        'category' => $category !== '' ? $category : null,
        'free' => $freeOnly ? '1' : null,
        'online' => $onlineOnly ? '1' : null,
        'when' => ($place['when'] ?? '') !== '' ? $place['when'] : null,
        'where' => ($place['where'] ?? '') !== '' ? $place['where'] : null,
        'near' => ($place['near'] ?? '') !== '' ? $place['near'] : null,
    ], static fn ($v) => $v !== null);
    return '/search.php' . ($params ? '?' . http_build_query($params) : '');
}

$searchPlace = ['when' => $when, 'where' => $where, 'near' => $near];
/** The same page with the date / place filters changed and the category row kept. */
$browseUrl = static fn (array $change): string => search_url($q, $category, $freeOnly, $onlineOnly, array_merge($searchPlace, $change));

$catStripIcons = category_icon_map();

if ($category !== '') {
    $heroTitle = $category;
} elseif ($q !== '') {
    $heroTitle = 'Search results';
} elseif ($near !== '') {
    $heroTitle = 'Events near you';
} else {
    $heroTitle = 'Browse events';
}

$hasFilter = $category !== '' || $q !== '' || $freeOnly || $onlineOnly || $when !== '' || $byPlace;
if ($byPlace || $when !== '') {
    $emptyTitle = 'No events' . ($placeLabel !== '' ? ' ' . htmlspecialchars($placeLabel) : '') . ($when !== '' ? ' ' . htmlspecialchars(strtolower(SEARCH_WHEN[$when])) : '') . ' yet';
} elseif ($category !== '') {
    $emptyTitle = 'No ' . htmlspecialchars($category) . ' events right now';
} elseif ($q !== '') {
    $emptyTitle = 'No events found for &ldquo;' . htmlspecialchars($q) . '&rdquo;';
} elseif ($freeOnly) {
    $emptyTitle = 'No free events right now';
} else {
    $emptyTitle = 'No events published yet';
}
$suggestedCats = array_slice(array_values(array_filter(EVENT_CATEGORIES, static fn ($c) => $c !== $category)), 0, 3);

$districtsByRegion = [];
$districtPoints = [];
foreach (uganda_districts() as $dName => [$dRegion, $dLat, $dLng]) {
    $districtsByRegion[$dRegion][] = $dName;
    $districtPoints[] = [$dName, $dLat, $dLng];
}
?>

<section class="rc-head">
  <div class="wrap">
    <h1><?= htmlspecialchars($heroTitle) ?></h1>
    <p><?= count($results) ?> event<?= count($results) === 1 ? '' : 's' ?> on sale<?= $q !== '' ? ' for &ldquo;' . htmlspecialchars($q) . '&rdquo;' : '' ?><?= $placeLabel !== '' ? ' ' . htmlspecialchars($placeLabel) : '' ?></p>
    <form class="rc-search" action="/search.php" method="get" role="search">
      <label class="sr-only" for="rcQ">Search events</label>
      <input id="rcQ" type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search events, venues or towns" autocomplete="off">
      <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
      <?php if ($freeOnly): ?><input type="hidden" name="free" value="1"><?php endif; ?>
      <?php if ($onlineOnly): ?><input type="hidden" name="online" value="1"><?php endif; ?>
      <?php foreach ($searchPlace as $pk => $pv): if ($pv !== ''): ?><input type="hidden" name="<?= $pk ?>" value="<?= htmlspecialchars($pv) ?>"><?php endif; endforeach; ?>
      <button type="submit" aria-label="Search"><svg width="19" height="19" aria-hidden="true"><use href="#ic-search"/></svg></button>
    </form>
  </div>
</section>

<div class="wrap">
  <section class="rc-browse">

    <div class="sx-bar">
      <div class="sx-where">
        <button type="button" class="sx-near<?= $near !== '' ? ' on' : '' ?>" id="sxNear" data-url="<?= htmlspecialchars($browseUrl(['near' => '__D__', 'where' => ''])) ?>" data-pts="<?= htmlspecialchars(json_encode($districtPoints)) ?>">
          <svg width="16" height="16" aria-hidden="true"><use href="#ic-pin"/></svg>
          <span><?= $near !== '' ? 'Near ' . htmlspecialchars($near) : 'Near me' ?></span>
        </button>
        <?php if ($near !== ''): ?><a class="sx-clear" href="<?= htmlspecialchars($browseUrl(['near' => ''])) ?>" aria-label="Stop showing events near <?= htmlspecialchars($near) ?>"><svg width="12" height="12" aria-hidden="true"><use href="#ic-x"/></svg></a><?php endif; ?>
        <form class="sx-pick" action="/search.php" method="get">
          <?php foreach (['q' => $q, 'category' => $category, 'when' => $when] as $hk => $hv): if ($hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= htmlspecialchars($hv) ?>"><?php endif; endforeach; ?>
          <?php if ($freeOnly): ?><input type="hidden" name="free" value="1"><?php endif; ?>
          <?php if ($onlineOnly): ?><input type="hidden" name="online" value="1"><?php endif; ?>
          <label class="sr-only" for="sxWhere">Where</label>
          <select id="sxWhere" name="where" class="<?= $where !== '' ? 'on' : '' ?>">
            <option value="">All of Uganda</option>
            <optgroup label="Regions">
              <?php foreach (UG_REGIONS as $r): ?><option value="<?= $r ?>"<?= $where === $r ? ' selected' : '' ?>><?= $r ?> region</option><?php endforeach; ?>
            </optgroup>
            <?php foreach (UG_REGIONS as $r): ?>
              <optgroup label="<?= $r ?> districts">
                <?php foreach ($districtsByRegion[$r] ?? [] as $dn): ?><option value="<?= htmlspecialchars($dn) ?>"<?= $where === $dn ? ' selected' : '' ?>><?= htmlspecialchars($dn) ?></option><?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="sx-go">Go</button></noscript>
        </form>
      </div>
      <nav class="sx-when" aria-label="When">
        <a class="sx-chip<?= $when === '' ? ' on' : '' ?>" href="<?= htmlspecialchars($browseUrl(['when' => ''])) ?>">Any time</a>
        <?php foreach (SEARCH_WHEN as $wk => $wl): ?>
          <a class="sx-chip<?= $when === $wk ? ' on' : '' ?>" href="<?= htmlspecialchars($browseUrl(['when' => $wk])) ?>"<?= $when === $wk ? ' aria-current="true"' : '' ?>><?= htmlspecialchars($wl) ?></a>
        <?php endforeach; ?>
      </nav>
      <p class="sx-msg" id="sxMsg" role="status" hidden></p>
    </div>

    <div class="search-cats">
      <a class="cat-chip <?= $category === '' && !$freeOnly && !$onlineOnly ? 'active' : '' ?>" href="<?= htmlspecialchars(search_url($q)) ?>">
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
      <?php if (events_online_ready()): ?>
      <a class="cat-chip <?= $onlineOnly ? 'active' : '' ?>" href="<?= htmlspecialchars(search_url($q, '', false, true)) ?>">
        <span class="cat-chip-ic"><svg width="16" height="16"><use href="#ic-monitor"/></svg></span>
        <span class="cat-chip-label">Online</span>
      </a>
      <?php endif; ?>
    </div>

    <div class="rc-sechead" style="margin-top:26px">
      <h2><?= count($results) ?> event<?= count($results) === 1 ? '' : 's' ?> found<?= $q !== '' ? ' for &ldquo;' . htmlspecialchars($q) . '&rdquo;' : '' ?><?= $placeLabel !== '' ? ' ' . htmlspecialchars($placeLabel) : '' ?><?= $when !== '' ? ' &middot; ' . htmlspecialchars(SEARCH_WHEN[$when]) : '' ?></h2>
      <a href="/past-events.php">Past events</a>
    </div>

    <?php if (!$results): ?>
      <div class="empty-state hero-in" style="margin-top:8px">
        <div class="empty-badge"><svg width="32" height="32"><use href="#<?= $byPlace ? 'ic-pin' : ($when !== '' ? 'ic-cal' : 'ic-search') ?>"/></svg></div>
        <h3><?= $emptyTitle ?></h3>
        <?php if ($byPlace): $wideRegion = district_region($near !== '' ? $near : $where); ?>
          <p>Try a wider area<?= $when !== '' ? ' or another date' : '' ?> &mdash; new events are added every week.</p>
          <div class="empty-actions">
            <?php if ($wideRegion !== null): ?><a class="btn btn-purple" href="<?= htmlspecialchars($browseUrl(['near' => '', 'where' => $wideRegion])) ?>">All of the <?= htmlspecialchars($wideRegion) ?> region <svg width="15" height="15" class="btn-arrow"><use href="#ic-arrow"/></svg></a><?php endif; ?>
            <a class="btn btn-line" href="<?= htmlspecialchars($browseUrl(['near' => '', 'where' => ''])) ?>">All of Uganda</a>
          </div>
        <?php elseif ($when !== ''): ?>
          <p>Nothing on <?= htmlspecialchars(strtolower(SEARCH_WHEN[$when])) ?> yet &mdash; see what is coming up later.</p>
          <div class="empty-actions">
            <a class="btn btn-purple" href="<?= htmlspecialchars($browseUrl(['when' => ''])) ?>">Any time <svg width="15" height="15" class="btn-arrow"><use href="#ic-arrow"/></svg></a>
          </div>
        <?php else: ?>
        <p>New events are added every week &mdash; try another category, or check back soon.</p>
        <?php if ($hasFilter): ?>
          <div class="empty-actions">
            <a class="btn btn-purple" href="/search.php">Browse all events <svg width="15" height="15" class="btn-arrow"><use href="#ic-arrow"/></svg></a>
          </div>
        <?php endif; ?>
        <?php if ($suggestedCats): ?>
          <div class="empty-suggestions">
            <span class="empty-suggestions-label">Try instead</span>
            <?php foreach ($suggestedCats as $sc): ?>
              <a class="sugg-chip" href="<?= htmlspecialchars(search_url('', $sc)) ?>">
                <span class="sugg-chip-ic"><svg width="13" height="13"><use href="#<?= $catStripIcons[$sc] ?>"/></svg></span>
                <?= htmlspecialchars($sc) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="event-tile-grid" style="margin-top:32px">
        <?php foreach ($results as $event):
            $price = format_money($event['min_price'] ?? null, $event['min_price_currency'] ?? 'UGX');
            $priceLabel = $price === 'Free entry' ? (((int) ($event['paid_tier_count'] ?? 0)) > 0 ? 'Free tickets available' : $price) : 'From ' . $price;
            $tileOnline = ($event['attend_mode'] ?? 'IN_PERSON') !== 'IN_PERSON';
            $hasImage = !empty($event['banner_image']);
            $eventUrl = '/event.php?slug=' . urlencode($event['slug']);
            $tilePlace = '';
            if (isset($event['_km'])) {
                $tilePlace = $event['_km'] < 1 ? 'In ' . $event['_district'] : '~' . max(5, (int) (round($event['_km'] / 5) * 5)) . ' km · ' . $event['_district'];
            } elseif (isset($event['_district']) && in_array($where, UG_REGIONS, true)) {
                $tilePlace = $event['_district'];
            }
        ?>
          <div class="event-tile reveal">
            <?php $tileFly = ($hasImage && function_exists('flyer_ratio')) ? flyer_ratio((string) $event['banner_image']) : ''; ?>
            <a class="event-tile-art" href="<?= htmlspecialchars($eventUrl) ?>"<?= $tileFly !== '' ? ' style="--fly:' . $tileFly . '"' : '' ?>>
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
              <?php if ($tilePlace !== ''): ?><span class="sx-dist"><svg width="12" height="12" aria-hidden="true"><use href="#ic-pin"/></svg> <?= htmlspecialchars($tilePlace) ?></span><?php endif; ?>
              <h3><a href="<?= htmlspecialchars($eventUrl) ?>"><?= htmlspecialchars($event['title']) ?></a></h3>
              <div class="event-tile-meta"><svg width="14" height="14"><use href="#<?= $tileOnline ? 'ic-monitor' : 'ic-pin' ?>"/></svg> <?= htmlspecialchars($event['venue_name'] . (($event['attend_mode'] ?? '') === 'HYBRID' ? ' + online' : '')) ?></div>
              <div class="event-tile-price"><?= htmlspecialchars($priceLabel) ?></div>
            </div>
            <a class="btn btn-purple" href="<?= htmlspecialchars($eventUrl) ?>">Get tickets <svg width="14" height="14" class="btn-arrow"><use href="#ic-arrow"/></svg></a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="trust-badges" style="margin-top:64px">
      <div class="trust-item"><span class="trust-ic"><svg width="20" height="20"><use href="#ic-cal"/></svg></span> Real, scannable QR tickets</div>
      <div class="trust-item"><span class="trust-ic"><svg width="20" height="20"><use href="#ic-shield"/></svg></span> Secure MTN &amp; Airtel checkout</div>
      <div class="trust-item"><span class="trust-ic"><svg width="20" height="20"><use href="#ic-bolt"/></svg></span> Instant delivery, no waiting</div>
    </div>

  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<script src="/assets/js/search-near.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
