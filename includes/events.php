<?php
declare(strict_types=1);

/** "UGX 80,000" or "Free entry" for a zero/null price. */
function format_money(?string $amount, string $currency = 'UGX'): string
{
    if ($amount === null || (float) $amount <= 0.0) {
        return 'Free entry';
    }
    return $currency . ' ' . number_format((float) $amount, 0);
}

/** First letter of up to the first 2 words of a name, e.g. "Obin Ivan" -> "OI". */
function initials_from_name(string $name): string
{
    $initials = '';
    foreach (explode(' ', trim($name)) as $word) {
        if ($word === '') {
            continue;
        }
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        if (mb_strlen($initials) >= 2) {
            break;
        }
    }
    return $initials;
}

/**
 * Shared select list + join used by every event listing query below, so a
 * card always has what it needs (title, venue, dates, cheapest ticket).
 */
const EVENT_SELECT = "
    e.id, e.title, e.slug, e.category, e.venue_name, e.banner_emoji, e.banner_image, e.starts_at, e.ends_at,
    (SELECT MIN(t.price) FROM ticket_types t WHERE t.event_id = e.id) AS min_price,
    (SELECT t.currency FROM ticket_types t WHERE t.event_id = e.id ORDER BY t.price ASC LIMIT 1) AS min_price_currency
";

function trending_music_events(int $limit = 6): array
{
    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW() AND e.category = 'Music'
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function this_weekend_events(int $limit = 6): array
{
    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at BETWEEN NOW() AND NOW() + INTERVAL 14 DAY
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function free_community_events(int $limit = 6): array
{
    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW()
          AND EXISTS (SELECT 1 FROM ticket_types t WHERE t.event_id = e.id AND t.price = 0)
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Powers search.php: an optional keyword (matched against title, venue and
 * description), an optional exact category, and an optional "free events
 * only" filter — any combination of the three, all AND-ed together.
 */
function search_events(?string $q, ?string $category, bool $freeOnly = false, int $limit = 60): array
{
    $conditions = ["e.status = 'PUBLISHED'", 'e.starts_at >= NOW()'];
    $params = [];

    $q = trim((string) $q);
    if ($q !== '') {
        $conditions[] = '(e.title LIKE ? OR e.venue_name LIKE ? OR e.description LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    if ($category !== null && $category !== '' && in_array($category, EVENT_CATEGORIES, true)) {
        $conditions[] = 'e.category = ?';
        $params[] = $category;
    }

    if ($freeOnly) {
        $conditions[] = 'EXISTS (SELECT 1 FROM ticket_types t WHERE t.event_id = e.id AND t.price = 0)';
    }

    $sql = 'SELECT ' . EVENT_SELECT . ' FROM events e WHERE ' . implode(' AND ', $conditions)
        . ' ORDER BY e.starts_at ASC LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Live count of upcoming published events per category, for the homepage's
 * category strip. Every category from EVENT_CATEGORIES is always present
 * (zero-filled first) — a category with no upcoming events shows "0
 * Events" rather than disappearing from the strip.
 */
function get_category_counts(): array
{
    $counts = array_fill_keys(EVENT_CATEGORIES, 0);
    $stmt = db()->query("
        SELECT category, COUNT(*) AS cnt
        FROM events
        WHERE status = 'PUBLISHED' AND starts_at >= NOW()
        GROUP BY category
    ");
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['category']] = (int) $row['cnt'];
    }
    return $counts;
}

/** Feeds the homepage's scrolling "ON SALE NOW" ticker strip. */
/**
 * The events the homepage's hero carousel rotates through. Prefers
 * upcoming events that have a real photo (a banner-less event would look
 * bare blown up that large); pads with photo-less events if there aren't
 * enough with one yet, rather than showing an empty carousel.
 */
function get_hero_carousel_events(int $limit = 4): array
{
    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW() AND e.banner_image IS NOT NULL
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute();
    $events = $stmt->fetchAll();
    if (count($events) >= 2) {
        return $events;
    }

    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW()
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Feeds the homepage's large-card grid, below the hero carousel. */
function upcoming_events(int $limit = 8, array $excludeEventIds = []): array
{
    $sql = "SELECT " . EVENT_SELECT . " FROM events e WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW()";
    $params = [];
    $excludeEventIds = array_filter(array_map('intval', $excludeEventIds));
    if ($excludeEventIds) {
        $placeholders = implode(',', array_fill(0, count($excludeEventIds), '?'));
        $sql .= " AND e.id NOT IN ($placeholders)";
        $params = array_values($excludeEventIds);
    }
    $sql .= ' ORDER BY e.starts_at ASC LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Same row shape as upcoming_events(), plus the organizer's display name —
 * only the homepage's event cards need this (for the avatar-initials
 * badge), so a dedicated query rather than growing the shared EVENT_SELECT
 * for every other caller that doesn't need it.
 */
function upcoming_events_with_organizer(int $limit = 6): array
{
    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . ", COALESCE(op.org_name, u.name) AS organizer_name
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        LEFT JOIN organizer_profiles op ON op.user_id = u.id
        WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW()
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_event_by_slug(string $slug): ?array
{
    $stmt = db()->prepare("
        SELECT e.*, u.name AS organizer_user_name,
               op.org_name, op.bio AS organizer_bio, op.verified AS organizer_verified
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        LEFT JOIN organizer_profiles op ON op.user_id = u.id
        WHERE e.slug = ? AND e.status = 'PUBLISHED'
    ");
    $stmt->execute([$slug]);
    $event = $stmt->fetch();
    return $event ?: null;
}

function get_ticket_types_for_event(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM ticket_types WHERE event_id = ? ORDER BY sort_order ASC, price ASC');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** Past-event photos/videos an organizer has added, oldest upload first. */
function get_event_media(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM event_media WHERE event_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function get_similar_events(int $eventId, string $category, int $limit = 2): array
{
    $stmt = db()->prepare("
        SELECT " . EVENT_SELECT . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at >= NOW() AND e.category = ? AND e.id != ?
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute([$category, $eventId]);
    return $stmt->fetchAll();
}

/**
 * Renders one event card (see includes/shelf-card.php). A real function
 * (not a bare include) so its $event/$tintIndex locals can never collide
 * with a same-named variable in the including page's own scope.
 */
function render_shelf_card(array $event, int $tintIndex = 0): void
{
    include __DIR__ . '/shelf-card.php';
}

/** "Thu 25 – Sun 28 Sep 2026" for a multi-day event, "Sun 20 Sep 2026" for a single-day one. */
function format_event_date_range(string $startsAt, string $endsAt): string
{
    $start = strtotime($startsAt);
    $end = strtotime($endsAt);
    if (date('Y-m-d', $start) === date('Y-m-d', $end)) {
        return date('D j M Y', $start);
    }
    if (date('M Y', $start) === date('M Y', $end)) {
        return date('D j', $start) . ' – ' . date('D j M Y', $end);
    }
    return date('D j M Y', $start) . ' – ' . date('D j M Y', $end);
}

// =====================================================================
// Organizer event-management: create/edit events and their ticket types.
// =====================================================================

// Names of the active rows in `event_categories`, in display order — the
// admin Categories page manages that table; every place that used to hold a
// hardcoded list reads this constant unchanged. events.category stays a
// plain VARCHAR (not a foreign key), so disabling/renaming a category here
// never touches past events.
define('EVENT_CATEGORIES', array_column(
    db()->query('SELECT name FROM event_categories WHERE active = 1 ORDER BY sort_order, name')->fetchAll(),
    'name'
));

/** name => icon symbol id, for every active category — used wherever a category strip/select renders an icon. */
function category_icon_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = array_column(
            db()->query('SELECT name, icon_key FROM event_categories WHERE active = 1 ORDER BY sort_order, name')->fetchAll(),
            'icon_key', 'name'
        );
    }
    return $map;
}

/** Every category row (active or not), for the admin Categories page. */
function get_all_categories_admin(): array
{
    return db()->query('
        SELECT c.*, (SELECT COUNT(*) FROM events e WHERE e.category = c.name) AS event_count
        FROM event_categories c
        ORDER BY c.sort_order, c.name
    ')->fetchAll();
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function create_category(string $name, string $iconKey): array
{
    $name = trim($name);
    if ($name === '') {
        return [false, 'Category name is required.'];
    }
    $stmt = db()->prepare('SELECT id FROM event_categories WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        return [false, 'A category with that name already exists.'];
    }
    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM event_categories')->fetchColumn();
    db()->prepare('INSERT INTO event_categories (name, slug, icon_key, sort_order) VALUES (?, ?, ?, ?)')
        ->execute([$name, slugify($name), $iconKey, $maxOrder + 1]);
    return [true, null];
}

function update_category(int $id, string $name, string $iconKey): void
{
    db()->prepare('UPDATE event_categories SET name = ?, icon_key = ? WHERE id = ?')
        ->execute([trim($name), $iconKey, $id]);
}

function set_category_active(int $id, bool $active): void
{
    db()->prepare('UPDATE event_categories SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

function reorder_category(int $id, int $direction): void
{
    $stmt = db()->prepare('SELECT id, sort_order FROM event_categories ORDER BY sort_order, name');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $index = null;
    foreach ($rows as $i => $row) {
        if ((int) $row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    $swapWith = $index !== null ? $index + $direction : null;
    if ($swapWith === null || $swapWith < 0 || $swapWith >= count($rows)) {
        return;
    }
    $a = $rows[$index];
    $b = $rows[$swapWith];
    db()->prepare('UPDATE event_categories SET sort_order = ? WHERE id = ?')->execute([$b['sort_order'], $a['id']]);
    db()->prepare('UPDATE event_categories SET sort_order = ? WHERE id = ?')->execute([$a['sort_order'], $b['id']]);
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function delete_category(int $id): array
{
    $stmt = db()->prepare('SELECT name FROM event_categories WHERE id = ?');
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    if (!$name) {
        return [false, 'Category not found.'];
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE category = ?');
    $stmt->execute([$name]);
    if ((int) $stmt->fetchColumn() > 0) {
        return [false, 'This category is used by existing events — disable it instead of deleting, or move those events to another category first.'];
    }
    db()->prepare('DELETE FROM event_categories WHERE id = ?')->execute([$id]);
    return [true, null];
}
const EVENT_EMOJIS = ['🎵', '💼', '🎤', '🏟️', '🙏', '👗', '🎓', '🎨', '🧒', '🎉', '🎬', '⚽'];
// obitickets currently operates in Uganda only — every ticket is priced in
// UGX. Kept as a list (not a single constant) so a future multi-country
// launch is a one-line change, not a rewrite of every call site that loops
// over it.
const TICKET_CURRENCIES = ['UGX'];

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
}

function generate_unique_slug(string $title, ?int $excludeEventId = null): string
{
    $base = slugify($title);
    if ($base === '') {
        $base = 'event';
    }
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM events WHERE slug = ?';
        $params = [$slug];
        if ($excludeEventId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeEventId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

/** "2026-09-25T14:00" (from a <input type=datetime-local>) -> "2026-09-25 14:00:00". */
function to_mysql_datetime(string $local): string
{
    $local = str_replace('T', ' ', trim($local));
    if (strlen($local) === 16) {
        $local .= ':00';
    }
    return $local;
}

/** "2026-09-25 14:00:00" (from MySQL) -> "2026-09-25T14:00" (for a datetime-local input). */
function to_local_datetime_input(string $mysqlDatetime): string
{
    return substr(str_replace(' ', 'T', $mysqlDatetime), 0, 16);
}

function get_events_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare('
        SELECT e.*,
            (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_sold,
            (SELECT t.currency FROM ticket_types t WHERE t.event_id = e.id ORDER BY t.price ASC LIMIT 1) AS currency,
            (SELECT COALESCE(SUM(o.subtotal_amount), 0) FROM orders o WHERE o.event_id = e.id AND o.status = "PAID") AS gross_revenue,
            (SELECT COALESCE(SUM(o.commission_amount), 0) FROM orders o WHERE o.event_id = e.id AND o.status = "PAID") AS commission_owed
        FROM events e
        WHERE e.organizer_id = ?
        ORDER BY e.starts_at DESC
    ');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

function get_event_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Reads the repeated tier_name[]/tier_price[]/... fields a submitted event
 * form posts, skipping any row whose name was left blank (a user removing
 * a row client-side, or just never filling in a spare one).
 *
 * @return list<array{id: ?int, name: string, description: string, price: mixed, currency: string, quantity_total: mixed}>
 */
function parse_tier_submission(array $post): array
{
    $names = $post['tier_name'] ?? [];
    $descriptions = $post['tier_description'] ?? [];
    $prices = $post['tier_price'] ?? [];
    $currencies = $post['tier_currency'] ?? [];
    $quantities = $post['tier_quantity'] ?? [];
    $ids = $post['tier_id'] ?? [];

    $tiers = [];
    foreach ($names as $i => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $tiers[] = [
            'id' => isset($ids[$i]) && $ids[$i] !== '' ? (int) $ids[$i] : null,
            'name' => $name,
            'description' => trim((string) ($descriptions[$i] ?? '')),
            'price' => $prices[$i] ?? '0',
            'currency' => in_array($currencies[$i] ?? '', TICKET_CURRENCIES, true) ? $currencies[$i] : 'UGX',
            'quantity_total' => $quantities[$i] ?? '0',
        ];
    }
    return $tiers;
}

/**
 * @return array{0: list<array>, 1: list<string>} [validTiers, errorMessages]
 */
function validate_tiers(array $tiers): array
{
    $valid = [];
    $errors = [];
    foreach ($tiers as $t) {
        if (!is_numeric($t['price']) || (float) $t['price'] < 0) {
            $errors[] = "Ticket \"{$t['name']}\" needs a valid price (0 or more).";
            continue;
        }
        if (!ctype_digit((string) $t['quantity_total']) || (int) $t['quantity_total'] < 1) {
            $errors[] = "Ticket \"{$t['name']}\" needs a quantity of at least 1.";
            continue;
        }
        $valid[] = $t;
    }
    if (!$valid) {
        $errors[] = 'Add at least one ticket type with a name, price and quantity.';
    }
    return [$valid, $errors];
}

/** Creates an event (auto-slugged from its title) plus its ticket types, as one transaction. */
function create_event(int $organizerId, array $fields, array $tiers): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $slug = generate_unique_slug($fields['title']);
        $stmt = $pdo->prepare('
            INSERT INTO events (organizer_id, title, slug, category, description, venue_name, venue_address, banner_emoji, banner_image, starts_at, ends_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $organizerId, $fields['title'], $slug, $fields['category'], $fields['description'],
            $fields['venue_name'], $fields['venue_address'], $fields['banner_emoji'], $fields['banner_image'] ?? null,
            $fields['starts_at'], $fields['ends_at'], $fields['status'],
        ]);
        $eventId = (int) $pdo->lastInsertId();

        $sort = 0;
        foreach ($tiers as $tier) {
            $stmt = $pdo->prepare('
                INSERT INTO ticket_types (event_id, name, description, price, currency, quantity_total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$eventId, $tier['name'], $tier['description'], $tier['price'], $tier['currency'], $tier['quantity_total'], $sort++]);
        }

        $pdo->commit();
        return $eventId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Updates an event's own fields. The slug is intentionally never changed here, so existing links never break. */
function update_event(int $eventId, array $fields): void
{
    $stmt = db()->prepare('
        UPDATE events SET title = ?, category = ?, description = ?, venue_name = ?, venue_address = ?, banner_emoji = ?, banner_image = ?, starts_at = ?, ends_at = ?, status = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $fields['title'], $fields['category'], $fields['description'], $fields['venue_name'],
        $fields['venue_address'], $fields['banner_emoji'], $fields['banner_image'] ?? null, $fields['starts_at'], $fields['ends_at'],
        $fields['status'], $eventId,
    ]);
}

function count_event_media(int $eventId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM event_media WHERE event_id = ?');
    $stmt->execute([$eventId]);
    return (int) $stmt->fetchColumn();
}

/** @return array{IMAGE: int, VIDEO: int} How many of each media type this event already has, to check against EVENT_MEDIA_MAX_IMAGES/EVENT_MEDIA_MAX_VIDEOS. */
function count_event_media_by_type(int $eventId): array
{
    $counts = ['IMAGE' => 0, 'VIDEO' => 0];
    $stmt = db()->prepare('SELECT media_type, COUNT(*) AS n FROM event_media WHERE event_id = ? GROUP BY media_type');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['media_type']] = (int) $row['n'];
    }
    return $counts;
}

/**
 * Records already-uploaded gallery files against an event. Takes the
 * [media_type, file_path] pairs handle_event_media_uploads() already saved
 * to disk — this function only ever writes rows, never touches the
 * filesystem itself.
 *
 * @param list<array{media_type: string, file_path: string}> $items
 */
function add_event_media(int $eventId, array $items): void
{
    if (!$items) {
        return;
    }
    $nextSort = count_event_media($eventId);
    $stmt = db()->prepare('INSERT INTO event_media (event_id, media_type, file_path, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($items as $item) {
        $stmt->execute([$eventId, $item['media_type'], $item['file_path'], $nextSort++]);
    }
}

/**
 * Deletes one gallery row, but only if it actually belongs to $eventId —
 * this is the ownership check that stops one organizer from deleting
 * another organizer's media by guessing a media id in the form post.
 *
 * @return ?string The file_path that was deleted (for the caller to also remove from disk), or null if nothing matched
 */
function delete_event_media(int $mediaId, int $eventId): ?string
{
    $stmt = db()->prepare('SELECT file_path FROM event_media WHERE id = ? AND event_id = ?');
    $stmt->execute([$mediaId, $eventId]);
    $filePath = $stmt->fetchColumn();
    if ($filePath === false) {
        return null;
    }
    db()->prepare('DELETE FROM event_media WHERE id = ? AND event_id = ?')->execute([$mediaId, $eventId]);
    return $filePath;
}

/**
 * Replaces an event's ticket types with the submitted set: updates rows
 * that carried an id, inserts rows that didn't, and removes rows that were
 * dropped from the form — but only if nothing has sold against them yet,
 * so an organizer editing a live event can never delete a tier out from
 * under an attendee who already bought one.
 */
function replace_ticket_types(int $eventId, array $tiers): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $keepIds = array_values(array_filter(array_column($tiers, 'id')));
        if ($keepIds) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $pdo->prepare("DELETE FROM ticket_types WHERE event_id = ? AND quantity_sold = 0 AND id NOT IN ($placeholders)")
                ->execute(array_merge([$eventId], $keepIds));
        } else {
            $pdo->prepare('DELETE FROM ticket_types WHERE event_id = ? AND quantity_sold = 0')->execute([$eventId]);
        }

        $sort = 0;
        foreach ($tiers as $tier) {
            if (!empty($tier['id'])) {
                $stmt = $pdo->prepare('UPDATE ticket_types SET name = ?, description = ?, price = ?, currency = ?, quantity_total = ?, sort_order = ? WHERE id = ? AND event_id = ?');
                $stmt->execute([$tier['name'], $tier['description'], $tier['price'], $tier['currency'], $tier['quantity_total'], $sort, $tier['id'], $eventId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO ticket_types (event_id, name, description, price, currency, quantity_total, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$eventId, $tier['name'], $tier['description'], $tier['price'], $tier['currency'], $tier['quantity_total'], $sort]);
            }
            $sort++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
