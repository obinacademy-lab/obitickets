<?php
declare(strict_types=1);

/**
 * Discover (the app's second tab), built to the owner's dark app mockup (11/18, Phase 2): search, category circles,
 * quick picks, Trending events, Trending videos, Popular organizers and Events around you. Everything is real data;
 * a section with nothing to show is left out.
 */

/** How many times each event's page was opened (event_views), for the eye count on trending cards. @param int[] $ids */
function dsc_view_counts(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    try {
        $in = implode(',', $ids);
        $rows = db()->query("SELECT event_id, SUM(view_count) AS n FROM event_views WHERE event_id IN ($in) GROUP BY event_id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['event_id']] = (int) $r['n'];
    }
    return $out;
}

/** Upcoming events, most popular first (tickets sold, likes, soonest), with their page views. */
function dsc_trending_events(int $limit = 10): array
{
    $events = array_slice(st_feed_events(40), 0, $limit);
    $views = dsc_view_counts(array_column($events, 'id'));
    foreach ($events as &$e) {
        $e['views'] = $views[(int) $e['id']] ?? 0;
    }
    unset($e);
    return $events;
}

/** The most engaging videos (Trending, or For You when nothing is trending yet), as tiles. */
function dsc_trending_videos(int $limit = 10): array
{
    if (!(function_exists('socials_ready') && moments_ready())) {
        return [];
    }
    $rows = socials_feed(null, 'trending', 0, $limit);
    if (count($rows) < 3) {
        $rows = socials_feed(null, 'foryou', 0, $limit);
    }
    return array_map('socials_item_public', $rows);
}

/** Organizers with something on sale, most followed first. */
function dsc_organizers(int $limit = 10): array
{
    try {
        $stmt = db()->prepare("
            SELECT op.user_id, op.org_name, op.slug, (op.verification_status = 'VERIFIED') AS verified,
                   (SELECT COUNT(*) FROM organizer_follows f WHERE f.organizer_user_id = op.user_id) AS followers,
                   (SELECT COUNT(*) FROM events e WHERE e.organizer_id = op.user_id AND e.status = 'PUBLISHED' AND e.ends_at >= NOW()) AS upcoming
            FROM organizer_profiles op
            WHERE op.slug IS NOT NULL AND op.slug <> '' AND op.org_name <> ''
            HAVING upcoming > 0
            ORDER BY followers DESC, upcoming DESC, op.org_name ASC
            LIMIT " . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Events around the buyer: in the place they last picked (cookie ot_place: a district or a region) when it has events,
 * otherwise everything coming up, soonest first. @return array{0:array,1:string} [events, label]
 */
function dsc_around(string $place, int $limit = 8): array
{
    $events = st_feed_events(60);
    usort($events, static fn ($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
    if ($place !== '') {
        $isRegion = in_array($place, UG_REGIONS, true);
        $here = array_values(array_filter($events, static function ($e) use ($place, $isRegion) {
            $d = event_district($e);
            return $d !== null && ($isRegion ? district_region($d) === $place : $d === $place);
        }));
        if ($here) {
            return [array_slice($here, 0, $limit), $isRegion ? 'Events in the ' . $place . ' region' : 'Events in ' . $place];
        }
    }
    return [array_slice($events, 0, $limit), 'Events coming up'];
}

/** "4 – 5 Dec 2026" or "Sat, 13 Dec 2026". */
function dsc_when(array $e): string
{
    $s = strtotime((string) $e['starts_at']);
    $t = strtotime((string) ($e['ends_at'] ?? $e['starts_at']));
    if (date('Y-m-d', $s) !== date('Y-m-d', $t) && $t - $s > 6 * 3600) {
        return date('M Y', $s) === date('M Y', $t) ? date('j', $s) . ' – ' . date('j M Y', $t) : date('j M', $s) . ' – ' . date('j M Y', $t);
    }
    return date('D, j M Y', $s);
}
