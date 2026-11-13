<?php
declare(strict_types=1);

/**
 * Past Events: reads finished events from the existing `events` table (no separate records), groups
 * categories into the five public filters, and holds the admin actions for the page.
 *
 * Works before migration 024 has been run (no hide/feature/highlights yet) and with it.
 */

/** The five public filters: key => label. "All Past Events" is the sixth, unfiltered view. */
const PAST_GROUPS = [
    'concerts' => 'Concerts & Entertainment',
    'business' => 'Business & Conferences',
    'workshops' => 'Workshops & Training',
    'festivals' => 'Festivals & Special Events',
    'lifestyle' => 'Lifestyle & Networking',
];

/** Used for any category the admin has not mapped yet (and before migration 024). */
const PAST_DEFAULT_GROUPS = [
    'Music' => 'concerts', 'Comedy' => 'concerts', 'Conference' => 'business',
    'Sports' => 'festivals', 'Faith' => 'festivals', 'Fashion' => 'lifestyle', 'Community' => 'lifestyle',
];

/** Whether migration 024 has added the Past Events columns. */
function past_events_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW COLUMNS FROM events LIKE 'past_hidden'")->fetch()
                && (bool) db()->query("SHOW COLUMNS FROM event_categories LIKE 'past_group'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** category name => filter key, for every category that has one. */
function past_group_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            $rows = db()->query('SELECT name' . (past_events_ready() ? ', past_group' : '') . ' FROM event_categories')->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $g = $r['past_group'] ?? null;
            $g = ($g !== null && isset(PAST_GROUPS[$g])) ? $g : (PAST_DEFAULT_GROUPS[$r['name']] ?? null);
            if ($g !== null) {
                $map[$r['name']] = $g;
            }
        }
        // categories that were removed from the table keep working for old events
        foreach (PAST_DEFAULT_GROUPS as $name => $g) {
            $map[$name] = $map[$name] ?? $g;
        }
    }
    return $map;
}

/** @return list<string> category names that sit in a filter */
function past_categories_for_group(string $group): array
{
    return array_values(array_keys(array_filter(past_group_map(), static fn ($g) => $g === $group)));
}

/** The "this event is finished and may be shown" rule, as SQL on alias e. */
function past_visible_sql(): string
{
    if (past_events_ready()) {
        return "e.status = 'PUBLISHED' AND (e.ends_at < NOW() OR e.completed_manually_at IS NOT NULL) AND e.past_hidden = 0";
    }
    return "e.status = 'PUBLISHED' AND e.ends_at < NOW()";
}

function past_event_url(string $slug): string
{
    return '/past-event.php?slug=' . rawurlencode($slug);
}

/** Plain-text teaser: tags stripped, spaces collapsed, cut at a word with an ellipsis. */
function past_excerpt(?string $text, int $length = 120): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
    if (mb_strlen($t) <= $length) {
        return $t;
    }
    $cut = mb_substr($t, 0, $length);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
}

/** One highlight per line, bullets stripped. @return list<string> */
function past_highlights_list(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', (string) $text) ?: [] as $line) {
        $line = trim(preg_replace('/^[\s\-*\x{2022}\x{2013}]+/u', '', trim($line)) ?? '');
        if ($line !== '') {
            $out[] = mb_substr($line, 0, 200);
        }
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

/**
 * Finished events for the listing.
 *
 * @param list<int> $excludeIds events to leave out (the Featured row shows them already)
 * @return array{events: list<array>, total: int}
 */
function past_events_list(string $q = '', string $group = '', int $limit = 12, int $offset = 0, array $excludeIds = []): array
{
    $where = [past_visible_sql()];
    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(e.title LIKE ? OR e.venue_name LIKE ? OR COALESCE(op.org_name, u.name) LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($params, $like, $like, $like);
    }
    if ($group !== '' && isset(PAST_GROUPS[$group])) {
        $cats = past_categories_for_group($group);
        if (!$cats) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'e.category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
            array_push($params, ...$cats);
        }
    }
    if ($excludeIds) {
        $where[] = 'e.id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        array_push($params, ...array_map('intval', $excludeIds));
    }
    $sqlWhere = implode(' AND ', $where);
    $from = 'FROM events e JOIN users u ON u.id = e.organizer_id LEFT JOIN organizer_profiles op ON op.user_id = u.id';

    $count = db()->prepare("SELECT COUNT(*) $from WHERE $sqlWhere");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = db()->prepare('SELECT ' . event_select_sql() . ", e.description, COALESCE(op.org_name, u.name) AS organizer_name,
            (SELECT COUNT(*) FROM event_media m WHERE m.event_id = e.id AND m.media_type = 'IMAGE') AS photo_count
        $from WHERE $sqlWhere ORDER BY e.ends_at DESC, e.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
    $stmt->execute($params);
    return ['events' => $stmt->fetchAll(), 'total' => $total];
}

/** Events an admin has featured (newest first). Empty before migration 024. @return list<array> */
function past_featured_events(int $limit = 3): array
{
    if (!past_events_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT ' . event_select_sql() . ", e.description, COALESCE(op.org_name, u.name) AS organizer_name,
            (SELECT COUNT(*) FROM event_media m WHERE m.event_id = e.id AND m.media_type = 'IMAGE') AS photo_count
        FROM events e JOIN users u ON u.id = e.organizer_id LEFT JOIN organizer_profiles op ON op.user_id = u.id
        WHERE " . past_visible_sql() . ' AND e.past_featured = 1 ORDER BY e.ends_at DESC, e.id DESC LIMIT ' . (int) $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** How many visible finished events sit in each filter. @return array{all: int, concerts: int, business: int, workshops: int, festivals: int, lifestyle: int} */
function past_group_counts(): array
{
    $counts = array_fill_keys(array_keys(PAST_GROUPS), 0);
    $counts = ['all' => 0] + $counts;
    $map = past_group_map();
    $rows = db()->query('SELECT e.category, COUNT(*) AS n FROM events e WHERE ' . past_visible_sql() . ' GROUP BY e.category')->fetchAll();
    foreach ($rows as $r) {
        $counts['all'] += (int) $r['n'];
        $g = $map[$r['category']] ?? null;
        if ($g !== null) {
            $counts[$g] += (int) $r['n'];
        }
    }
    return $counts;
}

/** One finished, public event by its address; null when it is unknown, hidden or not finished yet. */
function get_past_event(string $slug): ?array
{
    $stmt = db()->prepare('SELECT e.*, COALESCE(op.org_name, u.name) AS organizer_name, op.bio AS organizer_bio, op.verified AS organizer_verified
        FROM events e JOIN users u ON u.id = e.organizer_id LEFT JOIN organizer_profiles op ON op.user_id = u.id
        WHERE e.slug = ? AND ' . past_visible_sql());
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------
// Admin actions (called from admin-event-detail.php / admin-categories.php,
// which have already checked the admin's permission and the CSRF token)
// ---------------------------------------------------------------------

/**
 * Saves the Past Events panel of one event: description, highlights, public on/off, featured, and "mark completed".
 *
 * @return list<string> error messages (empty on success)
 */
function admin_save_past_settings(int $adminId, int $eventId, array $post): array
{
    if (!past_events_ready()) {
        return ['Run migration 024_past_events.sql first.'];
    }
    $description = trim((string) ($post['description'] ?? ''));
    $highlights = trim((string) ($post['highlights'] ?? ''));
    if (mb_strlen($description) > 6000) {
        return ['The description is too long (6,000 characters at most).'];
    }
    if (mb_strlen($highlights) > 2500) {
        return ['The highlights are too long (2,500 characters at most).'];
    }
    $hidden = empty($post['public']) ? 1 : 0;
    $featured = !empty($post['featured']) ? 1 : 0;

    db()->prepare('UPDATE events SET description = ?, highlights = ?, past_hidden = ?, past_featured = ? WHERE id = ?')
        ->execute([$description !== '' ? $description : null, $highlights !== '' ? $highlights : null, $hidden, $featured, $eventId]);
    log_admin_action($adminId, 'event.past_settings', 'event', $eventId, ['public' => !$hidden, 'featured' => (bool) $featured]);
    return [];
}

/** Marks an event completed by hand. If it has not ended yet it ends now, so ticket sales stop everywhere. */
function admin_mark_event_completed(int $adminId, int $eventId): void
{
    if (!past_events_ready()) {
        return;
    }
    db()->prepare("UPDATE events SET completed_manually_at = NOW(), ends_at = LEAST(ends_at, NOW()) WHERE id = ? AND status = 'PUBLISHED' AND completed_manually_at IS NULL")
        ->execute([$eventId]);
    log_admin_action($adminId, 'event.mark_completed', 'event', $eventId);
}

/** Sets (or clears, with '') the Past Events filter of a category. */
function admin_set_category_past_group(int $adminId, int $categoryId, string $group): bool
{
    if (!past_events_ready() || ($group !== '' && !isset(PAST_GROUPS[$group]))) {
        return false;
    }
    db()->prepare('UPDATE event_categories SET past_group = ? WHERE id = ?')->execute([$group !== '' ? $group : null, $categoryId]);
    log_admin_action($adminId, 'category.past_group', 'category', $categoryId, ['group' => $group]);
    return true;
}

// ---------------------------------------------------------------------
// Card shown on the listing page and under a past event
// ---------------------------------------------------------------------

/** One event card. $feature makes it the larger Featured version. */
function render_past_card(array $e, bool $feature = false): void
{
    $url = past_event_url($e['slug']);
    $mode = $e['attend_mode'] ?? 'IN_PERSON';
    $where = $mode === 'ONLINE' ? 'Online event' : ($mode === 'HYBRID' ? $e['venue_name'] . ' + online' : $e['venue_name']);
    $art = trim((string) ($e['banner_image'] ?? ''));
    $safeArt = str_replace(["'", '"', '\\', '(', ')', ' ', "\n", "\r"], '', $art);
    $excerpt = past_excerpt($e['description'] ?? '', $feature ? 170 : 110);
    $photos = (int) ($e['photo_count'] ?? 0);
    ?>
    <article class="pe-card<?= $feature ? ' pe-card-feature' : '' ?> reveal">
      <?php $peFly = ($art !== '' && function_exists('flyer_ratio')) ? flyer_ratio($art) : ''; // the frame takes the flyer's own shape, so it is shown whole ?>
      <a class="pe-art" href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true"<?= $peFly !== '' ? ' style="--fly:' . $peFly . '"' : '' ?>>
        <?php if ($art !== ''): ?>
          <span class="pe-art-bg" style="--art:url(<?= htmlspecialchars($safeArt, ENT_QUOTES) ?>)"></span>
          <img src="<?= htmlspecialchars($art) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="pe-art-fallback"><?= htmlspecialchars($e['banner_emoji']) ?></span>
        <?php endif; ?>
        <span class="pe-pill">Past event</span>
        <?php if ($photos > 0): ?><span class="pe-photos"><?= $photos ?> photo<?= $photos === 1 ? '' : 's' ?></span><?php endif; ?>
      </a>
      <div class="pe-body">
        <span class="pe-cat"><?= htmlspecialchars($e['category']) ?></span>
        <h3 class="pe-title"><a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($e['title']) ?></a></h3>
        <ul class="pe-meta">
          <li><svg width="15" height="15" aria-hidden="true"><use href="#ic-cal"/></svg><span><?= htmlspecialchars(format_event_date_range($e['starts_at'], $e['ends_at'])) ?></span></li>
          <li><svg width="15" height="15" aria-hidden="true"><use href="#ic-pin"/></svg><span><?= htmlspecialchars($where) ?></span></li>
          <li><svg width="15" height="15" aria-hidden="true"><use href="#ic-user"/></svg><span>By <?= htmlspecialchars($e['organizer_name']) ?></span></li>
        </ul>
        <?php if ($excerpt !== ''): ?><p class="pe-desc"><?= htmlspecialchars($excerpt) ?></p><?php endif; ?>
        <a class="pe-btn" href="<?= htmlspecialchars($url) ?>" aria-label="View details: <?= htmlspecialchars($e['title'], ENT_QUOTES) ?>">View details <svg width="15" height="15" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
      </div>
    </article>
    <?php
}
