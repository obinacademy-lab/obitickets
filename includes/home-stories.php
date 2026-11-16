<?php
declare(strict_types=1);

/**
 * Home page "Stories Feed", built exactly to the owner's pick (11/16, mockup 3): story circles (Near me, Tonight,
 * Weekend, Free, then categories), big event posts from organizers with a Follow button and a buy bar, and a dark
 * "Trending in ..." top three after the first post. Pictures cover their frames, as in the mockup.
 */

/** Upcoming published events, most popular first, with what a post needs (organizer, address, a line of text). */
function st_feed_events(int $limit = 40): array
{
    $mode = events_online_ready() ? 'e.attend_mode' : "'IN_PERSON'";
    $district = function_exists('places_ready') && places_ready() ? ', e.district' : '';
    $stmt = db()->prepare("
        SELECT e.id, e.title, e.slug, e.category, e.venue_name, e.venue_address{$district}, e.description, e.banner_emoji,
               e.banner_image, e.starts_at, e.ends_at, {$mode} AS attend_mode, e.organizer_id,
               COALESCE(op.org_name, u.name) AS organizer_name, op.slug AS organizer_slug,
               (SELECT MIN(t.price) FROM ticket_types t WHERE t.event_id = e.id) AS min_price,
               (SELECT t.currency FROM ticket_types t WHERE t.event_id = e.id ORDER BY t.price ASC LIMIT 1) AS min_price_currency,
               (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
                  JOIN orders o ON o.id = oi.order_id JOIN ticket_types t ON t.id = oi.ticket_type_id
                 WHERE t.event_id = e.id AND o.status = 'PAID') AS tickets_sold
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        LEFT JOIN organizer_profiles op ON op.user_id = u.id
        WHERE e.status = 'PUBLISHED' AND e.ends_at >= NOW()
        ORDER BY tickets_sold DESC, e.likes_count DESC, e.starts_at ASC
        LIMIT " . (int) $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Splits the feed: [first post, trending three, the other posts (soonest first), trending place label].
 * A remembered place (cookie ot_place) names the Trending box when at least two events are there.
 */
function st_home_layout(array $events, string $place): array
{
    if (!$events) {
        return [null, [], [], 'Uganda'];
    }
    $label = 'Uganda';
    $trendPool = $events;
    if ($place !== '') {
        $isRegion = in_array($place, UG_REGIONS, true);
        $here = array_values(array_filter($events, static function ($e) use ($place, $isRegion) {
            $d = event_district($e);
            return $d !== null && ($isRegion ? district_region($d) === $place : $d === $place);
        }));
        if (count($here) >= 2) {
            $trendPool = $here;
            $label = $isRegion ? 'the ' . $place . ' region' : $place;
        }
    }
    $first = $events[0];
    $trending = array_slice(array_values(array_filter($trendPool, static fn ($e) => (int) $e['id'] !== (int) $first['id'])), 0, 3);
    $used = array_merge([(int) $first['id']], array_map(static fn ($e) => (int) $e['id'], $trending));
    $rest = array_values(array_filter($events, static fn ($e) => !in_array((int) $e['id'], $used, true)));
    usort($rest, static fn ($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
    return [$first, $trending, $rest, $label];
}

/** The story circles: Near me, then quick picks and categories that have events (each with a real picture). */
function st_stories(array $events): array
{
    $todayEnd = strtotime('tomorrow') - 1;
    $win = function_exists('search_when_window') ? search_when_window('weekend') : null;
    $pick = static function (callable $test) use ($events): ?array {
        foreach ($events as $e) {
            if ($test($e)) {
                return $e;
            }
        }
        return null;
    };
    $stories = [];
    $add = static function (string $label, string $url, ?array $e, string $icon) use (&$stories) {
        $stories[] = ['label' => $label, 'url' => $url, 'img' => $e['banner_image'] ?? '', 'live' => $e !== null, 'icon' => $icon];
    };
    $add('Tonight', '/search.php?when=today', $pick(static fn ($e) => strtotime((string) $e['starts_at']) <= $todayEnd), 'ic-clock');
    $add('Weekend', '/search.php?when=weekend', $win ? $pick(static fn ($e) => strtotime((string) $e['starts_at']) <= $win[1] && strtotime((string) $e['ends_at']) > $win[0]) : null, 'ic-cal');
    $add('Free', '/search.php?free=1', $pick(static fn ($e) => $e['min_price'] !== null && (float) $e['min_price'] <= 0.0), 'ic-ticket');
    $icons = category_icon_map();
    foreach (EVENT_CATEGORIES as $cat) {
        $e = $pick(static fn ($ev) => ($ev['category'] ?? '') === $cat);
        $add((string) $cat, '/search.php?category=' . urlencode((string) $cat), $e, (string) ($icons[$cat] ?? 'ic-ticket'));
    }
    // Circles with events first (bright ring), the rest after (grey ring), keeping each group's order.
    usort($stories, static fn ($a, $b) => (int) $b['live'] <=> (int) $a['live']);
    return $stories;
}

function st_price(array $e): string
{
    $min = $e['min_price'] ?? null;
    if ($min === null) {
        return '';
    }
    return (float) $min <= 0.0 ? 'Free' : format_money((string) $min, $e['min_price_currency'] ?? 'UGX');
}

/** "Njeru, Jinja"-style town for the organizer line: the district when known, else the first part of the venue. */
function st_town(array $e): string
{
    if (($e['attend_mode'] ?? 'IN_PERSON') === 'ONLINE') {
        return 'Online';
    }
    $d = event_district($e);
    return $d ?? trim(explode(',', (string) ($e['venue_name'] ?? ''))[0]);
}

/** One line of the event's own description (first sentence, ~110 characters), or when the gates open. */
function st_line(array $e): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($e['description'] ?? ''))) ?? '');
    if ($text !== '') {
        if (preg_match('/^(.{20,140}?[.!?])(\s|$)/u', $text, $m)) {
            $text = $m[1];
        }
        if (mb_strlen($text) > 120) {
            $text = rtrim(mb_substr($text, 0, 117)) . '…';
        }
        return $text;
    }
    return 'Starts ' . date('g:i A', strtotime((string) $e['starts_at'])) . ' at ' . (string) $e['venue_name'] . '.';
}

function st_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : 'OT';
}

function render_st_post(array $e, int $n, ?array $viewer): void
{
    $url = '/event.php?slug=' . urlencode((string) $e['slug']);
    $orgId = (int) $e['organizer_id'];
    $orgName = (string) ($e['organizer_name'] ?? 'Organizer');
    $orgUrl = !empty($e['organizer_slug']) ? '/organizer.php?slug=' . urlencode((string) $e['organizer_slug']) : $url;
    $ts = strtotime((string) $e['starts_at']);
    $price = st_price($e);
    $img = (string) ($e['banner_image'] ?? '');
    $isOwn = $viewer && (int) $viewer['id'] === $orgId;
    $following = $viewer && !$isOwn && function_exists('is_following_organizer') && is_following_organizer($orgId, (int) $viewer['id']);
    ?>
    <article class="st-post">
      <div class="st-who">
        <a class="st-ava<?= $n % 2 ? ' alt' : '' ?>" href="<?= htmlspecialchars($orgUrl) ?>" aria-hidden="true" tabindex="-1"><?= htmlspecialchars(st_initials($orgName)) ?></a>
        <span class="st-who-tx"><a href="<?= htmlspecialchars($orgUrl) ?>"><b><?= htmlspecialchars($orgName) ?></b></a><span><?= htmlspecialchars(st_town($e) . ' · ' . date('D j M', $ts)) ?></span></span>
        <?php if (!$isOwn): ?><button type="button" class="st-follow" data-org="<?= $orgId ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Following' : 'Follow' ?></button><?php endif; ?>
      </div>
      <a class="st-pic" href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="<?= $n === 0 ? 'eager' : 'lazy' ?>"><?php else: ?><span class="st-emoji" style="background:<?= htmlspecialchars(landing_gradient($e)) ?>"><?= htmlspecialchars((string) ($e['banner_emoji'] ?? '')) ?></span><?php endif; ?>
        <span class="st-date"><b><?= date('j', $ts) ?></b><span><?= strtoupper(date('M', $ts)) ?></span></span>
      </a>
      <h2 class="st-title"><a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars((string) $e['title']) ?></a></h2>
      <p class="st-line"><?= htmlspecialchars(st_line($e)) ?></p>
      <div class="st-buy">
        <?php if ($price === ''): ?>
          <span class="st-buy-p"><small>Tickets</small><b>Coming soon</b></span>
          <a class="st-buy-btn" href="<?= htmlspecialchars($url) ?>">See event</a>
        <?php elseif ($price === 'Free'): ?>
          <span class="st-buy-p"><small>Entry</small><b class="free">Free</b></span>
          <a class="st-buy-btn" href="<?= htmlspecialchars($url) ?>">Get a pass</a>
        <?php else: ?>
          <span class="st-buy-p"><small>From</small><b><?= htmlspecialchars($price) ?></b></span>
          <a class="st-buy-btn" href="<?= htmlspecialchars($url) ?>">Get tickets</a>
        <?php endif; ?>
      </div>
    </article>
    <?php
}

function render_st_trending(array $events, string $label): void
{
    if (!$events) {
        return;
    }
    ?>
    <section class="st-trend" aria-labelledby="stTrend">
      <h2 id="stTrend">Trending in <?= htmlspecialchars($label) ?></h2>
      <ol>
        <?php foreach ($events as $i => $e):
            $url = '/event.php?slug=' . urlencode((string) $e['slug']);
            $price = st_price($e);
            $img = (string) ($e['banner_image'] ?? '');
        ?>
        <li>
          <span class="st-rank"><?= $i + 1 ?></span>
          <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><span class="st-thumb" style="background:<?= htmlspecialchars(landing_gradient($e)) ?>"><?= htmlspecialchars((string) ($e['banner_emoji'] ?? '')) ?></span><?php endif; ?>
          <a class="st-trend-tx" href="<?= htmlspecialchars($url) ?>"><b><?= htmlspecialchars((string) $e['title']) ?></b><span><?= htmlspecialchars(mg_trend_when($e) . ($price !== '' ? ' · ' . $price : '')) ?></span></a>
          <a class="st-go" href="<?= htmlspecialchars($url) ?>" aria-label="Buy <?= htmlspecialchars((string) $e['title']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php
}

/** "Tonight", "Tomorrow" or "Mon 30 Nov". */
function mg_trend_when(array $e): string
{
    $ts = strtotime((string) $e['starts_at']);
    if (date('Y-m-d', $ts) === date('Y-m-d') || ($ts < time() && strtotime((string) $e['ends_at']) >= time())) {
        return (int) date('G', $ts) >= 17 ? 'Tonight' : 'Today';
    }
    return date('Y-m-d', $ts) === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D j M', $ts);
}
