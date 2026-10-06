<?php
declare(strict_types=1);

/**
 * Event Community — the social layer around an event.
 *
 * Everything here is enforced on the server; the pages only render what these
 * functions allow. Who may do what:
 *
 *   guest            read posts and comments, share
 *   logged-in user   follow, react, comment, report, block
 *   ticket holder    all of the above + create posts ("Verified attendee":
 *                    derived from a PAID order for this event, never stored
 *                    or settable by the client)
 *   organizer owner  all of the above for their own event + official
 *                    announcements, pin, comment controls, hide/delete anyone's
 *                    content on their event
 *   admin            everything, on every event
 *
 * Names shown publicly are "First L." — a ticket purchase never exposes a
 * full name or any contact detail.
 */

const REACTION_TYPES = [
    'LOVE' => "\u{2764}\u{FE0F}",
    'FIRE' => "\u{1F525}",
    'FUNNY' => "\u{1F602}",
    'EXCITED' => "\u{1F60D}",
    'APPLAUSE' => "\u{1F44F}",
    'PARTY' => "\u{1F389}",
    'WOW' => "\u{1F62E}",
];

const SOCIAL_PLATFORMS = [
    'INSTAGRAM' => ['label' => 'Instagram', 'short' => 'IG', 'hosts' => ['instagram.com']],
    'TIKTOK' => ['label' => 'TikTok', 'short' => 'TT', 'hosts' => ['tiktok.com']],
    'FACEBOOK' => ['label' => 'Facebook', 'short' => 'FB', 'hosts' => ['facebook.com', 'fb.com', 'fb.me']],
    'X' => ['label' => 'X', 'short' => 'X', 'hosts' => ['x.com', 'twitter.com']],
    'YOUTUBE' => ['label' => 'YouTube', 'short' => 'YT', 'hosts' => ['youtube.com', 'youtu.be']],
    'WHATSAPP' => ['label' => 'WhatsApp', 'short' => 'WA', 'hosts' => ['wa.me', 'whatsapp.com']],
    'WEBSITE' => ['label' => 'Website', 'short' => 'Web', 'hosts' => []],
];

const REPORT_REASONS = [
    'SPAM' => 'Spam',
    'HARASSMENT' => 'Harassment or bullying',
    'NUDITY' => 'Nudity or sexual content',
    'VIOLENCE' => 'Violence',
    'HATE' => 'Hate or abuse',
    'SCAM' => 'Scam or fraud',
    'COPYRIGHT' => 'Copyright',
    'OTHER' => 'Something else',
];

const POST_MAX_CHARS = 2000;
const COMMENT_MAX_CHARS = 1000;
const MAX_PINNED_POSTS = 2;
const POST_RATE_LIMIT = 5;        // posts per user per 10 minutes
const COMMENT_RATE_LIMIT = 10;    // comments per user per 5 minutes
const REPORT_RATE_LIMIT = 10;     // reports per user per hour
const FEED_PAGE_SIZE = 10;

// =====================================================================
// Names, counts, permissions
// =====================================================================

/** "Amina Kato" -> "Amina K." — all the public ever sees of a person. */
function community_display_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0] ?? 'Someone';
    if (count($parts) < 2) {
        return $first;
    }
    return $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
}

function community_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    $b = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)) : '';
    return $a . $b;
}

function user_has_ticket_for_event(int $userId, int $eventId): bool
{
    $stmt = db()->prepare("SELECT 1 FROM orders WHERE user_id = ? AND event_id = ? AND status = 'PAID' LIMIT 1");
    $stmt->execute([$userId, $eventId]);
    return (bool) $stmt->fetchColumn();
}

function is_event_owner(?array $user, array $event): bool
{
    return $user !== null && (int) $user['id'] === (int) $event['organizer_id'];
}

/**
 * The organizer, an admin, or an active team member whose role carries
 * social.manage (Event Manager, Marketing Manager) for THIS event's organizer.
 */
function can_moderate_event(?array $user, array $event): bool
{
    if ($user === null) {
        return false;
    }
    if (is_event_owner($user, $event) || $user['role'] === 'ADMIN') {
        return true;
    }
    static $contexts = [];
    $uid = (int) $user['id'];
    if (!array_key_exists($uid, $contexts)) {
        $contexts[$uid] = resolve_organizer_context($user);
    }
    $ctx = $contexts[$uid];
    return $ctx !== null && (int) $ctx['organizer_id'] === (int) $event['organizer_id'] && organizer_can($ctx, 'social.manage');
}

/** Posting needs a ticket (or being the organizer / an admin). */
function can_post_to_event(?array $user, array $event): bool
{
    if ($user === null || ($event['status'] ?? '') !== 'PUBLISHED') {
        return false;
    }
    return can_moderate_event($user, $event) || user_has_ticket_for_event((int) $user['id'], (int) $event['id']);
}

/** @return array{going:int, following:int, reactions:int, comments:int, posts:int} real counts only */
function get_event_social_counts(int $eventId): array
{
    $stmt = db()->prepare("
        SELECT
          (SELECT COUNT(DISTINCT user_id) FROM orders WHERE event_id = :e1 AND status = 'PAID') AS going,
          (SELECT COUNT(*) FROM event_follows WHERE event_id = :e2) AS following,
          (SELECT COUNT(*) FROM post_reactions r JOIN event_posts p ON p.id = r.post_id
              WHERE p.event_id = :e3 AND p.status = 'PUBLISHED') AS reactions,
          (SELECT COUNT(*) FROM post_comments c JOIN event_posts p ON p.id = c.post_id
              WHERE p.event_id = :e4 AND p.status = 'PUBLISHED' AND c.status = 'PUBLISHED') AS comments,
          (SELECT COUNT(*) FROM event_posts WHERE event_id = :e5 AND status = 'PUBLISHED') AS posts
    ");
    $stmt->execute([':e1' => $eventId, ':e2' => $eventId, ':e3' => $eventId, ':e4' => $eventId, ':e5' => $eventId]);
    $row = $stmt->fetch();
    return array_map('intval', $row);
}

// =====================================================================
// Organizer social links
// =====================================================================

/**
 * Turns whatever an organizer typed into a safe https URL for that platform.
 * Only https is ever stored — javascript:, data:, ftp:, http:// (upgraded),
 * credentials in the URL, odd ports and look-alike hosts are all refused.
 *
 * @return array{0: ?string, 1: ?string} [normalized url, error message]
 */
function normalize_social_url(string $platform, string $input): array
{
    $input = trim($input);
    if ($input === '') {
        return [null, null];
    }
    if (!isset(SOCIAL_PLATFORMS[$platform])) {
        return [null, 'Unknown platform.'];
    }
    $label = SOCIAL_PLATFORMS[$platform]['label'];
    if (mb_strlen($input) > 300 || preg_match('/[\x00-\x20\x7F]/', $input)) {
        return [null, "$label: that link isn't valid."];
    }

    // WhatsApp: a phone number becomes a wa.me link.
    if ($platform === 'WHATSAPP' && preg_match('/^\+?[0-9()\-]{7,20}$/', $input)) {
        $digits = preg_replace('/\D/', '', $input);
        if (str_starts_with($digits, '0')) {
            $digits = '256' . substr($digits, 1);
        }
        return ['https://wa.me/' . $digits, null];
    }

    // Allow "instagram.com/name" without a scheme, but nothing with another scheme.
    if (preg_match('~^http://~i', $input)) {
        $input = 'https://' . substr($input, 7);
    } elseif (!preg_match('~^https://~i', $input)) {
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $input)) {
            return [null, "$label: only https:// links are accepted."];
        }
        $input = 'https://' . $input;
    }

    $parts = parse_url($input);
    if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return [null, "$label: that link isn't valid."];
    }
    if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return [null, "$label: that link isn't valid."];
    }
    $host = strtolower($parts['host']);
    if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
        return [null, "$label: that link isn't valid."];
    }

    $allowed = SOCIAL_PLATFORMS[$platform]['hosts'];
    if ($allowed) {
        $ok = false;
        foreach ($allowed as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return [null, "$label: that doesn't look like a $label link."];
        }
    }

    $url = 'https://' . $host . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return [mb_strlen($url) > 500 ? null : $url, mb_strlen($url) > 500 ? "$label: that link is too long." : null];
}

/** @return list<array{platform:string,label:string,short:string,url:string,is_enabled:bool}> in a fixed platform order */
function get_organizer_social_links(int $organizerUserId, bool $enabledOnly = true): array
{
    $stmt = db()->prepare('SELECT platform, url, is_enabled FROM organizer_social_links WHERE organizer_user_id = ?');
    $stmt->execute([$organizerUserId]);
    $byPlatform = [];
    foreach ($stmt->fetchAll() as $row) {
        $byPlatform[$row['platform']] = $row;
    }
    $out = [];
    foreach (SOCIAL_PLATFORMS as $key => $meta) {
        if (!isset($byPlatform[$key])) {
            continue;
        }
        $enabled = (int) $byPlatform[$key]['is_enabled'] === 1;
        if ($enabledOnly && !$enabled) {
            continue;
        }
        $out[] = ['platform' => $key, 'label' => $meta['label'], 'short' => $meta['short'], 'url' => $byPlatform[$key]['url'], 'is_enabled' => $enabled];
    }
    return $out;
}

/**
 * @param array<string, array{url?: string, enabled?: mixed}> $input keyed by platform
 * @return list<string> error messages; nothing is saved unless the list is empty
 */
function save_organizer_social_links(int $organizerUserId, array $input): array
{
    $errors = [];
    $clean = [];
    foreach (SOCIAL_PLATFORMS as $key => $meta) {
        $row = $input[$key] ?? [];
        [$url, $err] = normalize_social_url($key, (string) ($row['url'] ?? ''));
        if ($err) {
            $errors[] = $err;
        }
        $clean[$key] = [$url, !empty($row['enabled'])];
    }
    if ($errors) {
        return $errors;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($clean as $key => [$url, $enabled]) {
            if ($url === null) {
                $pdo->prepare('DELETE FROM organizer_social_links WHERE organizer_user_id = ? AND platform = ?')->execute([$organizerUserId, $key]);
                continue;
            }
            $pdo->prepare('
                INSERT INTO organizer_social_links (organizer_user_id, platform, url, is_enabled) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE url = VALUES(url), is_enabled = VALUES(is_enabled)
            ')->execute([$organizerUserId, $key, $url, $enabled ? 1 : 0]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [];
}

// =====================================================================
// Follows
// =====================================================================

function is_following_event(int $eventId, ?int $userId): bool
{
    if (!$userId) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM event_follows WHERE event_id = ? AND user_id = ?');
    $stmt->execute([$eventId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/** @return array{ok:bool, following?:bool, followers?:int, error?:string} */
function set_event_follow(int $eventId, int $userId, bool $follow): array
{
    $stmt = db()->prepare("SELECT id FROM events WHERE id = ? AND status = 'PUBLISHED'");
    $stmt->execute([$eventId]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'This event is not available.'];
    }
    if ($follow) {
        db()->prepare('INSERT IGNORE INTO event_follows (event_id, user_id) VALUES (?, ?)')->execute([$eventId, $userId]);
    } else {
        db()->prepare('DELETE FROM event_follows WHERE event_id = ? AND user_id = ?')->execute([$eventId, $userId]);
    }
    $count = db()->prepare('SELECT COUNT(*) FROM event_follows WHERE event_id = ?');
    $count->execute([$eventId]);
    return ['ok' => true, 'following' => $follow, 'followers' => (int) $count->fetchColumn()];
}

function is_following_organizer(int $organizerUserId, ?int $userId): bool
{
    if (!$userId) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM organizer_follows WHERE organizer_user_id = ? AND user_id = ?');
    $stmt->execute([$organizerUserId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function count_organizer_followers(int $organizerUserId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM organizer_follows WHERE organizer_user_id = ?');
    $stmt->execute([$organizerUserId]);
    return (int) $stmt->fetchColumn();
}

/** @return array{ok:bool, following?:bool, followers?:int, error?:string} */
function set_organizer_follow(int $organizerUserId, int $userId, bool $follow): array
{
    if ($organizerUserId === $userId) {
        return ['ok' => false, 'error' => "You can't follow yourself."];
    }
    $stmt = db()->prepare("SELECT 1 FROM organizer_profiles WHERE user_id = ?");
    $stmt->execute([$organizerUserId]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'This organizer is not available.'];
    }
    if ($follow) {
        db()->prepare('INSERT IGNORE INTO organizer_follows (organizer_user_id, user_id) VALUES (?, ?)')->execute([$organizerUserId, $userId]);
    } else {
        db()->prepare('DELETE FROM organizer_follows WHERE organizer_user_id = ? AND user_id = ?')->execute([$organizerUserId, $userId]);
    }
    return ['ok' => true, 'following' => $follow, 'followers' => count_organizer_followers($organizerUserId)];
}

// =====================================================================
// Blocks
// =====================================================================

function set_user_block(int $blockerId, int $blockedId, bool $block): bool
{
    if ($blockerId === $blockedId) {
        return false;
    }
    if ($block) {
        db()->prepare('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)')->execute([$blockerId, $blockedId]);
    } else {
        db()->prepare('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?')->execute([$blockerId, $blockedId]);
    }
    return true;
}

/** SQL fragment: hides content authored by someone the viewer blocked, or who blocked the viewer. */
function community_block_filter(string $authorColumn): string
{
    return "NOT EXISTS (SELECT 1 FROM user_blocks ub
        WHERE (ub.blocker_id = :viewer_b1 AND ub.blocked_id = $authorColumn)
           OR (ub.blocked_id = :viewer_b2 AND ub.blocker_id = $authorColumn))";
}

// =====================================================================
// Posts
// =====================================================================

/** Strips control characters and runaway blank lines; returns trimmed text. */
function community_clean_text(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
    return trim($text);
}

function community_rate_limited(string $table, string $userColumn, int $userId, int $max, int $seconds): bool
{
    // $table / $userColumn are internal constants, never user input.
    $stmt = db()->prepare("SELECT COUNT(*) FROM $table WHERE $userColumn = ? AND created_at >= (NOW() - INTERVAL $seconds SECOND)");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() >= $max;
}

/**
 * @return array{ok:bool, id?:int, error?:string}
 */
function create_event_post(array $event, array $user, string $type, string $body, ?string $imagePath = null, bool $pin = false, bool $commentsEnabled = true): array
{
    $type = in_array($type, ['TEXT', 'IMAGE', 'ORGANIZER_UPDATE'], true) ? $type : 'TEXT';
    if (!can_post_to_event($user, $event)) {
        return ['ok' => false, 'error' => 'Only people with a ticket for this event can post here.'];
    }
    $isOfficial = $type === 'ORGANIZER_UPDATE';
    if ($isOfficial && !can_moderate_event($user, $event)) {
        return ['ok' => false, 'error' => 'Only the organizer can post official updates.'];
    }

    $body = community_clean_text($body);
    if ($body === '' && !$imagePath) {
        return ['ok' => false, 'error' => 'Write something first.'];
    }
    if (mb_strlen($body) > POST_MAX_CHARS) {
        return ['ok' => false, 'error' => 'That post is too long (limit ' . POST_MAX_CHARS . ' characters).'];
    }
    if ($imagePath && $type === 'TEXT') {
        $type = 'IMAGE';
    }
    if (community_rate_limited('event_posts', 'author_id', (int) $user['id'], POST_RATE_LIMIT, 600)) {
        return ['ok' => false, 'error' => "You're posting too quickly. Please wait a few minutes."];
    }

    $pin = $pin && $isOfficial;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($pin) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_posts WHERE event_id = ? AND is_pinned = 1 AND status = 'PUBLISHED' FOR UPDATE");
            $stmt->execute([(int) $event['id']]);
            if ((int) $stmt->fetchColumn() >= MAX_PINNED_POSTS) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'You can pin up to ' . MAX_PINNED_POSTS . ' posts. Unpin one first.'];
            }
        }
        $pdo->prepare('INSERT INTO event_posts (event_id, author_id, post_type, body, image_path, is_pinned, comments_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $event['id'], (int) $user['id'], $type, $body !== '' ? $body : null, $imagePath, $pin ? 1 : 0, $commentsEnabled ? 1 : 0]);
        $postId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($isOfficial) {
        try {
            notify_event_followers_of_update($event, $body);
        } catch (Throwable $e) {
            error_log('[community] follower notification failed: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'id' => $postId];
}

/**
 * Tells followers about an official update — at most one such notification
 * per event every 3 hours, so a burst of announcements never spams anyone.
 */
function notify_event_followers_of_update(array $event, string $body): void
{
    $link = '/event.php?slug=' . urlencode($event['slug']) . '#community';
    $recent = db()->prepare("SELECT 1 FROM notifications WHERE type = 'event_update' AND link = ? AND created_at >= (NOW() - INTERVAL 3 HOUR) LIMIT 1");
    $recent->execute([$link]);
    if ($recent->fetchColumn()) {
        return;
    }
    $title = 'New update: ' . mb_substr((string) $event['title'], 0, 120);
    $snippet = mb_substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 200);
    db()->prepare("
        INSERT INTO notifications (user_id, type, title, body, link)
        SELECT f.user_id, 'event_update', ?, ?, ?
        FROM event_follows f WHERE f.event_id = ? AND f.user_id <> ?
        LIMIT 5000
    ")->execute([$title, $snippet, $link, (int) $event['id'], (int) $event['organizer_id']]);
}

/**
 * One page of an event's feed. First page (no $beforeId): pinned posts, then newest.
 * Later pages: older unpinned posts only. Counts and the viewer's own reaction
 * arrive in the same query, and reaction breakdowns in one more — no per-post queries.
 *
 * @return list<array<string,mixed>>
 */
function get_event_feed(int $eventId, ?int $viewerId, int $limit = FEED_PAGE_SIZE, ?int $beforeId = null): array
{
    $limit = max(1, min(30, $limit));
    $viewer = $viewerId ?? 0;
    $paging = $beforeId ? 'AND p.is_pinned = 0 AND p.id < :before' : '';
    $sql = "
        SELECT p.id, p.event_id, p.author_id, p.post_type, p.body, p.image_path, p.is_pinned, p.comments_enabled, p.created_at,
               u.name AS author_name,
               (e.organizer_id = p.author_id) AS is_organizer,
               EXISTS(SELECT 1 FROM orders o WHERE o.user_id = p.author_id AND o.event_id = p.event_id AND o.status = 'PAID') AS is_verified_attendee,
               (SELECT COUNT(*) FROM post_reactions r WHERE r.post_id = p.id) AS reaction_count,
               (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id AND c.status = 'PUBLISHED') AS comment_count,
               (SELECT r.reaction FROM post_reactions r WHERE r.post_id = p.id AND r.user_id = :viewer) AS my_reaction
        FROM event_posts p
        JOIN users u ON u.id = p.author_id
        JOIN events e ON e.id = p.event_id
        WHERE p.event_id = :event AND p.status = 'PUBLISHED' AND u.account_status = 'ACTIVE'
          AND " . community_block_filter('p.author_id') . "
          $paging
        ORDER BY p.is_pinned DESC, p.id DESC
        LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':viewer', $viewer, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_b1', $viewer, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_b2', $viewer, PDO::PARAM_INT);
    $stmt->bindValue(':event', $eventId, PDO::PARAM_INT);
    if ($beforeId) {
        $stmt->bindValue(':before', $beforeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $posts = $stmt->fetchAll();

    if ($posts) {
        $ids = array_map(static fn ($p) => (int) $p['id'], $posts);
        $in = implode(',', $ids); // integers only, cast above
        $breakdown = [];
        foreach (db()->query("SELECT post_id, reaction, COUNT(*) AS n FROM post_reactions WHERE post_id IN ($in) GROUP BY post_id, reaction ORDER BY n DESC")->fetchAll() as $r) {
            $breakdown[(int) $r['post_id']][$r['reaction']] = (int) $r['n'];
        }
        foreach ($posts as &$p) {
            $p['reactions'] = array_slice($breakdown[(int) $p['id']] ?? [], 0, 3, true);
        }
        unset($p);
    }
    return $posts;
}

function get_post(int $postId): ?array
{
    $stmt = db()->prepare("
        SELECT p.*, e.organizer_id AS event_organizer_id, e.slug AS event_slug, e.title AS event_title, e.status AS event_status
        FROM event_posts p JOIN events e ON e.id = p.event_id WHERE p.id = ?
    ");
    $stmt->execute([$postId]);
    return $stmt->fetch() ?: null;
}

function get_event_for_post(array $post): array
{
    return ['id' => (int) $post['event_id'], 'organizer_id' => (int) $post['event_organizer_id'], 'slug' => $post['event_slug'], 'title' => $post['event_title'], 'status' => $post['event_status']];
}

function set_post_pinned(array $user, int $postId, bool $pin): array
{
    $post = get_post($postId);
    if (!$post || $post['status'] !== 'PUBLISHED') {
        return ['ok' => false, 'error' => 'Post not found.'];
    }
    $event = get_event_for_post($post);
    if (!can_moderate_event($user, $event)) {
        return ['ok' => false, 'error' => "You can't change this post."];
    }
    if ($pin) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM event_posts WHERE event_id = ? AND is_pinned = 1 AND status = 'PUBLISHED' AND id <> ?");
        $stmt->execute([$event['id'], $postId]);
        if ((int) $stmt->fetchColumn() >= MAX_PINNED_POSTS) {
            return ['ok' => false, 'error' => 'You can pin up to ' . MAX_PINNED_POSTS . ' posts. Unpin one first.'];
        }
    }
    db()->prepare('UPDATE event_posts SET is_pinned = ? WHERE id = ?')->execute([$pin ? 1 : 0, $postId]);
    return ['ok' => true];
}

function set_post_comments_enabled(array $user, int $postId, bool $enabled): array
{
    $post = get_post($postId);
    if (!$post || !can_moderate_event($user, get_event_for_post($post))) {
        return ['ok' => false, 'error' => "You can't change this post."];
    }
    db()->prepare('UPDATE event_posts SET comments_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $postId]);
    return ['ok' => true];
}

// =====================================================================
// Reactions
// =====================================================================

/** Set (or with null, clear) the user's one reaction on a post. @return array{ok:bool, error?:string, reaction_count?:int, my_reaction?:?string} */
function set_post_reaction(int $postId, int $userId, ?string $reaction): array
{
    if ($reaction !== null && !isset(REACTION_TYPES[$reaction])) {
        return ['ok' => false, 'error' => 'Unknown reaction.'];
    }
    $post = get_post($postId);
    if (!$post || $post['status'] !== 'PUBLISHED') {
        return ['ok' => false, 'error' => 'Post not found.'];
    }
    if ($reaction === null) {
        db()->prepare('DELETE FROM post_reactions WHERE post_id = ? AND user_id = ?')->execute([$postId, $userId]);
    } else {
        db()->prepare('INSERT INTO post_reactions (post_id, user_id, reaction) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)')
            ->execute([$postId, $userId, $reaction]);
    }
    $count = db()->prepare('SELECT COUNT(*) FROM post_reactions WHERE post_id = ?');
    $count->execute([$postId]);
    return ['ok' => true, 'reaction_count' => (int) $count->fetchColumn(), 'my_reaction' => $reaction, 'breakdown' => post_reaction_breakdown($postId)];
}

/** Top three reactions on a post, most used first. @return array<string,int> */
function post_reaction_breakdown(int $postId): array
{
    $stmt = db()->prepare('SELECT reaction, COUNT(*) AS n FROM post_reactions WHERE post_id = ? GROUP BY reaction ORDER BY n DESC LIMIT 3');
    $stmt->execute([$postId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['reaction']] = (int) $r['n'];
    }
    return $out;
}

function set_comment_reaction(int $commentId, int $userId, ?string $reaction): array
{
    if ($reaction !== null && !isset(REACTION_TYPES[$reaction])) {
        return ['ok' => false, 'error' => 'Unknown reaction.'];
    }
    $stmt = db()->prepare("SELECT c.id FROM post_comments c JOIN event_posts p ON p.id = c.post_id WHERE c.id = ? AND c.status = 'PUBLISHED' AND p.status = 'PUBLISHED'");
    $stmt->execute([$commentId]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'Comment not found.'];
    }
    if ($reaction === null) {
        db()->prepare('DELETE FROM comment_reactions WHERE comment_id = ? AND user_id = ?')->execute([$commentId, $userId]);
    } else {
        db()->prepare('INSERT INTO comment_reactions (comment_id, user_id, reaction) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)')
            ->execute([$commentId, $userId, $reaction]);
    }
    $count = db()->prepare('SELECT COUNT(*) FROM comment_reactions WHERE comment_id = ?');
    $count->execute([$commentId]);
    return ['ok' => true, 'reaction_count' => (int) $count->fetchColumn(), 'my_reaction' => $reaction];
}

// =====================================================================
// Comments (top level + one level of replies)
// =====================================================================

/** @return array{ok:bool, id?:int, error?:string} */
function add_comment(array $user, int $postId, string $body, ?int $parentId = null): array
{
    $post = get_post($postId);
    if (!$post || $post['status'] !== 'PUBLISHED' || $post['event_status'] !== 'PUBLISHED') {
        return ['ok' => false, 'error' => 'Post not found.'];
    }
    if ((int) $post['comments_enabled'] !== 1) {
        return ['ok' => false, 'error' => 'Comments are turned off for this post.'];
    }
    $body = community_clean_text($body);
    if ($body === '') {
        return ['ok' => false, 'error' => 'Write a comment first.'];
    }
    if (mb_strlen($body) > COMMENT_MAX_CHARS) {
        return ['ok' => false, 'error' => 'That comment is too long (limit ' . COMMENT_MAX_CHARS . ' characters).'];
    }
    if (community_rate_limited('post_comments', 'user_id', (int) $user['id'], COMMENT_RATE_LIMIT, 300)) {
        return ['ok' => false, 'error' => "You're commenting too quickly. Please wait a moment."];
    }
    if ($parentId !== null) {
        $stmt = db()->prepare("SELECT post_id, parent_id, status FROM post_comments WHERE id = ?");
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();
        if (!$parent || (int) $parent['post_id'] !== $postId || $parent['parent_id'] !== null || $parent['status'] !== 'PUBLISHED') {
            return ['ok' => false, 'error' => "You can't reply to that comment."];
        }
    }
    db()->prepare('INSERT INTO post_comments (post_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)')
        ->execute([$postId, (int) $user['id'], $parentId, $body]);
    $commentId = (int) db()->lastInsertId();

    // Tell the post's author (not for their own comment) — one row per comment, never grouped silently away.
    if ((int) $post['author_id'] !== (int) $user['id']) {
        try {
            create_notification((int) $post['author_id'], 'post_comment',
                community_display_name((string) $user['name']) . ' commented on your post',
                mb_substr($body, 0, 150),
                '/event.php?slug=' . urlencode($post['event_slug']) . '#post-' . $postId);
        } catch (Throwable $e) {
            error_log('[community] comment notification failed: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'id' => $commentId];
}

/**
 * Comments for one post, oldest first, with replies attached to their parent.
 * @return list<array<string,mixed>> each top-level comment has a 'replies' list
 */
function get_post_comments(int $postId, ?int $viewerId, int $limit = 20, ?int $afterId = null): array
{
    $limit = max(1, min(50, $limit));
    $viewer = $viewerId ?? 0;
    $select = "
        SELECT c.id, c.post_id, c.user_id, c.parent_id, c.body, c.created_at, u.name AS author_name,
               (e.organizer_id = c.user_id) AS is_organizer,
               EXISTS(SELECT 1 FROM orders o WHERE o.user_id = c.user_id AND o.event_id = p.event_id AND o.status = 'PAID') AS is_verified_attendee,
               (SELECT COUNT(*) FROM comment_reactions r WHERE r.comment_id = c.id) AS reaction_count,
               (SELECT r.reaction FROM comment_reactions r WHERE r.comment_id = c.id AND r.user_id = :viewer) AS my_reaction
        FROM post_comments c
        JOIN users u ON u.id = c.user_id
        JOIN event_posts p ON p.id = c.post_id
        JOIN events e ON e.id = p.event_id
        WHERE c.post_id = :post AND c.status = 'PUBLISHED' AND u.account_status = 'ACTIVE'
          AND " . community_block_filter('c.user_id');

    $stmt = db()->prepare($select . ' AND c.parent_id IS NULL' . ($afterId ? ' AND c.id > :after' : '') . " ORDER BY c.id ASC LIMIT $limit");
    $stmt->bindValue(':viewer', $viewer, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_b1', $viewer, PDO::PARAM_INT);
    $stmt->bindValue(':viewer_b2', $viewer, PDO::PARAM_INT);
    $stmt->bindValue(':post', $postId, PDO::PARAM_INT);
    if ($afterId) {
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $top = $stmt->fetchAll();
    foreach ($top as &$c) {
        $c['replies'] = [];
    }
    unset($c);
    if (!$top) {
        return [];
    }

    $in = implode(',', array_map(static fn ($c) => (int) $c['id'], $top));
    $rs = db()->prepare($select . " AND c.parent_id IN ($in) ORDER BY c.id ASC LIMIT 200");
    $rs->bindValue(':viewer', $viewer, PDO::PARAM_INT);
    $rs->bindValue(':viewer_b1', $viewer, PDO::PARAM_INT);
    $rs->bindValue(':viewer_b2', $viewer, PDO::PARAM_INT);
    $rs->bindValue(':post', $postId, PDO::PARAM_INT);
    $rs->execute();
    $byParent = [];
    foreach ($rs->fetchAll() as $reply) {
        $byParent[(int) $reply['parent_id']][] = $reply;
    }
    foreach ($top as &$c) {
        $c['replies'] = $byParent[(int) $c['id']] ?? [];
    }
    unset($c);
    return $top;
}

// =====================================================================
// Reports and moderation
// =====================================================================

/** @return array{0: ?array, 1: ?array} [content row with author_id/status/event_id, event row] */
function community_load_content(string $type, int $contentId): array
{
    if ($type === 'POST') {
        $stmt = db()->prepare('SELECT id, event_id, author_id AS owner_id, status, body FROM event_posts WHERE id = ?');
    } elseif ($type === 'COMMENT') {
        $stmt = db()->prepare('SELECT c.id, p.event_id, c.user_id AS owner_id, c.status, c.body FROM post_comments c JOIN event_posts p ON p.id = c.post_id WHERE c.id = ?');
    } else {
        return [null, null];
    }
    $stmt->execute([$contentId]);
    $content = $stmt->fetch();
    if (!$content) {
        return [null, null];
    }
    $ev = db()->prepare('SELECT id, organizer_id, slug, title, status FROM events WHERE id = ?');
    $ev->execute([(int) $content['event_id']]);
    return [$content, $ev->fetch() ?: null];
}

/** @return array{ok:bool, error?:string} */
function report_content(string $type, int $contentId, int $reporterId, string $reason, ?string $details = null): array
{
    if (!isset(REPORT_REASONS[$reason])) {
        return ['ok' => false, 'error' => 'Choose a reason.'];
    }
    [$content, $event] = community_load_content($type, $contentId);
    if (!$content || $content['status'] !== 'PUBLISHED') {
        return ['ok' => false, 'error' => 'That content is no longer available.'];
    }
    if ((int) $content['owner_id'] === $reporterId) {
        return ['ok' => false, 'error' => "You can't report your own content."];
    }
    if (community_rate_limited('content_reports', 'reporter_id', $reporterId, REPORT_RATE_LIMIT, 3600)) {
        return ['ok' => false, 'error' => 'You have sent a lot of reports. Please try again later.'];
    }
    $details = $details !== null ? mb_substr(community_clean_text($details), 0, 500) : null;
    db()->prepare('INSERT IGNORE INTO content_reports (event_id, content_type, content_id, reporter_id, reason, details) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int) $content['event_id'], $type, $contentId, $reporterId, $reason, $details !== '' ? $details : null]);
    return ['ok' => true];
}

/**
 * The open-report queue for one event: one row per reported item, with how
 * many people reported it and why.
 * @return list<array<string,mixed>>
 */
function get_event_reports(int $eventId): array
{
    $stmt = db()->prepare("
        SELECT r.content_type, r.content_id, COUNT(*) AS report_count, MAX(r.created_at) AS last_reported,
               GROUP_CONCAT(DISTINCT r.reason ORDER BY r.reason SEPARATOR ',') AS reasons
        FROM content_reports r
        WHERE r.event_id = ? AND r.status = 'OPEN'
        GROUP BY r.content_type, r.content_id
        ORDER BY last_reported DESC LIMIT 50
    ");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        [$content] = community_load_content($row['content_type'], (int) $row['content_id']);
        $row['snippet'] = $content ? mb_substr((string) $content['body'], 0, 200) : '';
        $row['content_status'] = $content['status'] ?? 'DELETED';
        $row['author_name'] = '';
        if ($content) {
            $a = db()->prepare('SELECT name FROM users WHERE id = ?');
            $a->execute([(int) $content['owner_id']]);
            $row['author_name'] = community_display_name((string) $a->fetchColumn());
        }
        $row['reasons'] = array_filter(explode(',', (string) $row['reasons']));
    }
    unset($row);
    return $rows;
}

/**
 * Hide / restore / delete a post or comment. The author may delete their own;
 * the event's organizer and admins may do any of the three on their event.
 * Every action is written to moderation_log and closes the item's open reports.
 *
 * @return array{ok:bool, error?:string}
 */
function moderate_content(array $actor, string $type, int $contentId, string $action): array
{
    if (!in_array($action, ['HIDE', 'RESTORE', 'DELETE'], true)) {
        return ['ok' => false, 'error' => 'Unknown action.'];
    }
    [$content, $event] = community_load_content($type, $contentId);
    if (!$content || !$event) {
        return ['ok' => false, 'error' => 'That content no longer exists.'];
    }

    $isAuthor = (int) $content['owner_id'] === (int) $actor['id'];
    $isAdmin = $actor['role'] === 'ADMIN';
    $canModerate = can_moderate_event($actor, $event);
    if (!$canModerate && !($isAuthor && $action === 'DELETE')) {
        return ['ok' => false, 'error' => "You can't do that."];
    }
    if ($content['status'] === 'DELETED' && !$isAdmin) {
        return ['ok' => false, 'error' => 'That content has been deleted.'];
    }
    if ($action === 'RESTORE' && $content['status'] === 'PUBLISHED') {
        return ['ok' => true];
    }
    if ($action === 'RESTORE' && $content['status'] === 'DELETED' && !$isAdmin) {
        return ['ok' => false, 'error' => 'Only an admin can restore deleted content.'];
    }

    $newStatus = ['HIDE' => 'HIDDEN', 'RESTORE' => 'PUBLISHED', 'DELETE' => 'DELETED'][$action];
    $table = $type === 'POST' ? 'event_posts' : 'post_comments';
    $role = $isAdmin ? 'ADMIN' : ($canModerate ? 'ORGANIZER' : 'AUTHOR');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE $table SET status = ? WHERE id = ?")->execute([$newStatus, $contentId]);
        if ($type === 'POST' && $newStatus !== 'PUBLISHED') {
            $pdo->prepare('UPDATE event_posts SET is_pinned = 0 WHERE id = ?')->execute([$contentId]);
        }
        $pdo->prepare('INSERT INTO moderation_log (event_id, actor_id, actor_role, content_type, content_id, action) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int) $event['id'], (int) $actor['id'], $role, $type, $contentId, $action]);
        if ($action !== 'RESTORE') {
            $pdo->prepare("UPDATE content_reports SET status = 'ACTIONED', handled_by = ?, handled_at = NOW() WHERE content_type = ? AND content_id = ? AND status = 'OPEN'")
                ->execute([(int) $actor['id'], $type, $contentId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

/** "Keep it": close an item's open reports without touching the content. */
function dismiss_reports(array $actor, string $type, int $contentId): array
{
    [$content, $event] = community_load_content($type, $contentId);
    if (!$content || !$event || !can_moderate_event($actor, $event)) {
        return ['ok' => false, 'error' => "You can't do that."];
    }
    db()->prepare("UPDATE content_reports SET status = 'DISMISSED', handled_by = ?, handled_at = NOW() WHERE content_type = ? AND content_id = ? AND status = 'OPEN'")
        ->execute([(int) $actor['id'], $type, $contentId]);
    return ['ok' => true];
}

/** Content hidden by moderators that can still be restored, newest first. @return list<array<string,mixed>> */
function get_hidden_content(int $eventId, int $limit = 20): array
{
    $stmt = db()->prepare("
        SELECT 'POST' AS content_type, p.id AS content_id, p.body, u.name AS author_name, p.created_at
        FROM event_posts p JOIN users u ON u.id = p.author_id WHERE p.event_id = :e1 AND p.status = 'HIDDEN'
        UNION ALL
        SELECT 'COMMENT', c.id, c.body, u.name, c.created_at
        FROM post_comments c JOIN event_posts p ON p.id = c.post_id JOIN users u ON u.id = c.user_id
        WHERE p.event_id = :e2 AND c.status = 'HIDDEN'
        ORDER BY created_at DESC LIMIT " . (int) $limit);
    $stmt->execute([':e1' => $eventId, ':e2' => $eventId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['author_name'] = community_display_name((string) $r['author_name']);
        $r['body'] = mb_substr((string) $r['body'], 0, 200);
    }
    return $rows;
}

/** One comment in the same shape get_post_comments() returns, for showing a just-posted comment. */
function get_comment_for_render(int $commentId, ?int $viewerId): ?array
{
    $stmt = db()->prepare("
        SELECT c.id, c.post_id, c.user_id, c.parent_id, c.body, c.created_at, u.name AS author_name,
               (e.organizer_id = c.user_id) AS is_organizer,
               EXISTS(SELECT 1 FROM orders o WHERE o.user_id = c.user_id AND o.event_id = p.event_id AND o.status = 'PAID') AS is_verified_attendee,
               (SELECT COUNT(*) FROM comment_reactions r WHERE r.comment_id = c.id) AS reaction_count,
               (SELECT r.reaction FROM comment_reactions r WHERE r.comment_id = c.id AND r.user_id = :viewer) AS my_reaction
        FROM post_comments c
        JOIN users u ON u.id = c.user_id
        JOIN event_posts p ON p.id = c.post_id
        JOIN events e ON e.id = p.event_id
        WHERE c.id = :id AND c.status = 'PUBLISHED'");
    $stmt->bindValue(':viewer', $viewerId ?? 0, PDO::PARAM_INT);
    $stmt->bindValue(':id', $commentId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['replies'] = [];
    return $row;
}

/** The published event row (with the organizer's display name) the community renders against. */
function get_community_event(int $eventId): ?array
{
    $stmt = db()->prepare("
        SELECT e.*, COALESCE(op.org_name, u.name) AS org_name
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        LEFT JOIN organizer_profiles op ON op.user_id = e.organizer_id
        WHERE e.id = ? AND e.status = 'PUBLISHED'");
    $stmt->execute([$eventId]);
    return $stmt->fetch() ?: null;
}
