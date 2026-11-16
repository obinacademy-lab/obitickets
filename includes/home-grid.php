<?php
declare(strict_types=1);

/**
 * Home page "Marketplace Grid", built exactly to the owner's pick (11/16, mockup 5): a place picker in the white
 * header, search, For you / Near me / Weekend / Free, one big banner (the most popular event), the "Popular in ..."
 * grid of square cards with the price on the picture, and the Selling tickets band.
 * The place picker remembers the buyer's last district/region choice (cookie ot_place, set by search.php too).
 */

/** The pill in the top bar: "Uganda" or the remembered place; choosing one opens Browse for that place. */
function mg_place_picker(string $place, array $districtsByRegion): string
{
    $label = $place !== '' ? $place : 'Uganda';
    $o = '<option value="">All of Uganda</option><optgroup label="Regions">';
    foreach (UG_REGIONS as $r) {
        $o .= '<option value="' . $r . '"' . ($place === $r ? ' selected' : '') . '>' . $r . ' region</option>';
    }
    $o .= '</optgroup>';
    foreach (UG_REGIONS as $r) {
        $o .= '<optgroup label="' . $r . ' districts">';
        foreach ($districtsByRegion[$r] ?? [] as $d) {
            $o .= '<option value="' . htmlspecialchars($d) . '"' . ($place === $d ? ' selected' : '') . '>' . htmlspecialchars($d) . '</option>';
        }
        $o .= '</optgroup>';
    }
    return '<form class="mg-place" action="/search.php" method="get">'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/></svg>'
        . '<span class="mg-place-l" aria-hidden="true">' . htmlspecialchars($label) . '</span>'
        . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
        . '<label class="sr-only" for="mgPlace">Show events in</label>'
        . '<select id="mgPlace" name="where">' . $o . '</select>'
        . '<noscript><button type="submit">Go</button></noscript></form>';
}

/**
 * [banner event, grid events, place label]. Most popular first (tickets sold, likes, then soonest). A remembered place
 * narrows the list when it has at least two events there; otherwise the whole country is shown.
 */
function mg_home_events(string $place): array
{
    $byId = [];
    foreach (search_events(null, null, false, 300) as $e) {
        $byId[(int) $e['id']] = $e; // carries venue_address / district for the place line
    }
    $ordered = [];
    foreach (get_popular_events(300) as $p) {
        $id = (int) $p['id'];
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
            unset($byId[$id]);
        }
    }
    $ordered = array_merge($ordered, array_values($byId));

    $label = 'Uganda';
    if ($place !== '') {
        $isRegion = in_array($place, UG_REGIONS, true);
        $here = array_values(array_filter($ordered, static function ($e) use ($place, $isRegion) {
            $d = event_district($e);
            return $d !== null && ($isRegion ? district_region($d) === $place : $d === $place);
        }));
        if (count($here) >= 2) {
            $ordered = $here;
            $label = $isRegion ? 'the ' . $place . ' region' : $place;
        }
    }
    if (!$ordered) {
        return [null, [], $label];
    }
    $banner = $ordered[0];
    $grid = array_slice($ordered, 1, 24);
    if (count($grid) < 2) {
        $grid = array_slice($ordered, 0, 24); // very few events: list them all rather than a near-empty grid
    }
    return [$banner, $grid, $label];
}

function mg_see_all_url(string $place): string
{
    return '/search.php' . ($place !== '' ? '?where=' . urlencode($place) : '');
}

/** "UGX 30,000", "Free", or "" when the event has no tickets yet. */
function mg_price(array $event): string
{
    $min = $event['min_price'] ?? null;
    if ($min === null) {
        return '';
    }
    return (float) $min <= 0.0 ? 'Free' : format_money((string) $min, $event['min_price_currency'] ?? 'UGX');
}

/** "TONIGHT", "TODAY", "TOMORROW" or "" (for the red tag on the picture). */
function mg_soon_tag(array $event): string
{
    $start = strtotime((string) $event['starts_at']);
    $end = strtotime((string) ($event['ends_at'] ?? $event['starts_at']));
    $today = date('Y-m-d');
    if (date('Y-m-d', $start) === $today || ($start < time() && $end >= time())) {
        return (int) date('G', $start) >= 17 ? 'TONIGHT' : 'TODAY';
    }
    return date('Y-m-d', $start) === date('Y-m-d', strtotime('+1 day')) ? 'TOMORROW' : '';
}

/** A short "where": the first part of the venue ("Lugogo Cricket Oval" stays, "Speke Resort, Munyonyo" -> "Speke Resort"). */
function mg_short_place(array $event): string
{
    if (($event['attend_mode'] ?? 'IN_PERSON') === 'ONLINE') {
        return 'Online';
    }
    $venue = trim(explode(',', (string) ($event['venue_name'] ?? ''))[0]);
    return $venue !== '' ? $venue : (string) (event_district($event) ?? '');
}

/** The banner's "where": venue and town ("Lake Bunyonyi, Kabale"). */
function mg_long_place(array $event): string
{
    if (($event['attend_mode'] ?? 'IN_PERSON') === 'ONLINE') {
        return 'Online event';
    }
    $venue = (string) ($event['venue_name'] ?? '');
    $d = event_district($event);
    return ($d !== null && stripos($venue, $d) === false) ? trim($venue . ', ' . $d, ', ') : $venue;
}

/** "SAT 21 NOV · 2:00 PM", or "SAT 5 – SUN 6 DEC" for an event over several days. */
function mg_banner_date(array $event): string
{
    $s = strtotime((string) $event['starts_at']);
    $e = strtotime((string) ($event['ends_at'] ?? $event['starts_at']));
    if (date('Y-m-d', $s) !== date('Y-m-d', $e) && $e - $s > 6 * 3600) {
        $first = date('M', $s) === date('M', $e) ? date('D j', $s) : date('D j M', $s);
        return strtoupper($first . ' – ' . date('D j M', $e));
    }
    return strtoupper(date('D j M', $s)) . ' · ' . date('g:i A', $s);
}

function render_mg_banner(array $event): void
{
    $url = '/event.php?slug=' . urlencode((string) $event['slug']);
    $price = mg_price($event);
    $img = (string) ($event['banner_image'] ?? '');
    ?>
    <a class="mg-banner" href="<?= htmlspecialchars($url) ?>">
      <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt=""><?php else: ?><span class="mg-banner-bg" style="background:<?= htmlspecialchars(landing_gradient($event)) ?>"></span><?php endif; ?>
      <span class="mg-banner-shade" aria-hidden="true"></span>
      <span class="mg-banner-in">
        <span class="mg-banner-tx">
          <span class="mg-banner-date"><?= htmlspecialchars(mg_banner_date($event)) ?></span>
          <b><?= htmlspecialchars((string) $event['title']) ?></b>
          <span class="mg-banner-place"><?= htmlspecialchars(mg_long_place($event)) ?></span>
        </span>
        <?php if ($price !== ''): ?><span class="mg-banner-price"><?= htmlspecialchars($price) ?></span><?php endif; ?>
      </span>
    </a>
    <?php
}

function render_mg_card(array $event): void
{
    $url = '/event.php?slug=' . urlencode((string) $event['slug']);
    $price = mg_price($event);
    $tag = mg_soon_tag($event);
    $img = (string) ($event['banner_image'] ?? '');
    $meta = date('D j M', strtotime((string) $event['starts_at']));
    $where = mg_short_place($event);
    ?>
    <a class="mg-card" href="<?= htmlspecialchars($url) ?>">
      <span class="mg-img">
        <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><span class="mg-emoji" style="background:<?= htmlspecialchars(landing_gradient($event)) ?>" aria-hidden="true"><?= htmlspecialchars((string) ($event['banner_emoji'] ?? '')) ?></span><?php endif; ?>
        <?php if ($tag !== ''): ?><span class="mg-tag"><?= $tag ?></span><?php endif; ?>
        <?php if ($price !== ''): ?><span class="mg-price<?= $price === 'Free' ? ' free' : '' ?>"><?= htmlspecialchars($price) ?></span><?php endif; ?>
      </span>
      <b class="mg-title"><?= htmlspecialchars((string) $event['title']) ?></b>
      <span class="mg-meta"><?= htmlspecialchars($meta . ($where !== '' ? ' · ' . $where : '')) ?></span>
    </a>
    <?php
}
