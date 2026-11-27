<?php
/*
 * Who viewed a video (11/27, migration 051). Like TikTok's post viewers:
 *  - a signed-in person who watches a video (1.5 s, the same moment the view counter counts) is recorded once per video, with the time;
 *  - only the video's OWNER (the event's organizer) can see the list, and message the people in it (normal chat rules: someone who
 *    does not follow you gets it as a message request; blocked people never appear and cannot be messaged);
 *  - "Show me in video viewer lists" (Settings, on by default): switched off, the person is not recorded at all, and cannot see
 *    who viewed their own videos either (fair, like TikTok). Guests are never recorded; they only count in the view number.
 */

const VV_PAGE = 30;
const VV_RECORD_EVERY_SECONDS = 600;   // a person re-watching the same video moves to the top at most every 10 minutes

function vv_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) db()->query("SHOW TABLES LIKE 'moment_viewers'")->fetch() && (bool) db()->query("SHOW COLUMNS FROM users LIKE 'show_in_viewers'")->fetch();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Whether this person appears in viewer lists (and may see their own). */
function vv_visible(int $userId): bool
{
    if (!vv_ready()) {
        return false;
    }
    $st = db()->prepare('SELECT show_in_viewers FROM users WHERE id = ?');
    $st->execute([$userId]);
    return (string) $st->fetchColumn() === '1';
}

/** Settings switch. Switching it off also removes the person from every list they are already in. */
function vv_set_visible(int $userId, bool $on): void
{
    if (!vv_ready()) {
        return;
    }
    db()->prepare('UPDATE users SET show_in_viewers = ? WHERE id = ?')->execute([$on ? 1 : 0, $userId]);
    if (!$on) {
        db()->prepare('DELETE FROM moment_viewers WHERE user_id = ?')->execute([$userId]);
    }
}

/** The owner of a published video (the event's organizer), or null. */
function vv_owner_of(int $postId): ?int
{
    $st = db()->prepare("SELECT e.organizer_id FROM event_moments m JOIN event_posts p ON p.id = m.post_id JOIN events e ON e.id = m.event_id
                         WHERE m.post_id = ? AND p.status = 'PUBLISHED'");
    $st->execute([$postId]);
    $v = $st->fetchColumn();
    return $v ? (int) $v : null;
}

/**
 * Records that a signed-in person viewed a video. Not for the owner's own views, not for someone who switched it off.
 * Safe to call on every view: it is one upsert, and the caller throttles per session.
 */
function vv_record(int $postId, int $userId): bool
{
    if (!vv_ready() || $userId <= 0) {
        return false;
    }
    $owner = vv_owner_of($postId);
    if ($owner === null || $owner === $userId || !vv_visible($userId)) {
        return false;
    }
    db()->prepare('INSERT INTO moment_viewers (post_id, user_id) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed_at = NOW()')->execute([$postId, $userId]);
    return true;
}

/**
 * The list the owner sees: newest first, a page at a time (cursor = "last_viewed_at|user_id" of the last row shown).
 * Leaves out: anyone blocked either way, people who switched the list off, accounts that are not active.
 * @return array{ok:bool, error?:string, off?:bool, rows?:list<array>, next?:?string, total?:int}
 */
function vv_list(int $postId, int $viewerId, ?string $cursor = null, int $limit = VV_PAGE): array
{
    if (!vv_ready()) {
        return ['ok' => false, 'error' => 'Viewer lists are not available yet.'];
    }
    $owner = vv_owner_of($postId);
    if ($owner === null || $owner !== $viewerId) {
        return ['ok' => false, 'error' => 'Only the person who posted this video can see who viewed it.'];
    }
    if (!vv_visible($viewerId)) {
        return ['ok' => true, 'off' => true, 'rows' => [], 'next' => null, 'total' => 0];
    }
    $limit = max(1, min(100, $limit));
    $about = function_exists('profile_about_ready') && profile_about_ready() ? ', u.username' : ', NULL AS username';
    $where = "v.post_id = ? AND u.show_in_viewers = 1 AND COALESCE(u.account_status, 'ACTIVE') IN ('ACTIVE', '')
              AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = v.user_id) OR (b.blocker_id = v.user_id AND b.blocked_id = ?))";
    $args = [$postId, $viewerId, $viewerId];
    $total = db()->prepare("SELECT COUNT(*) FROM moment_viewers v JOIN users u ON u.id = v.user_id WHERE $where");
    $total->execute($args);
    if ($cursor !== null && preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\|(\d+)$/', $cursor, $m)) {
        $where .= ' AND (v.last_viewed_at < ? OR (v.last_viewed_at = ? AND v.user_id < ?))';
        array_push($args, $m[1], $m[1], (int) $m[2]);
    }
    $st = db()->prepare("SELECT v.user_id, v.view_count, v.last_viewed_at, u.id, u.name, u.role, u.avatar_url, u.account_status$about,
                                o.slug AS org_slug, o.org_name, o.verified,
                                EXISTS (SELECT 1 FROM organizer_follows f WHERE f.user_id = v.user_id AND f.organizer_user_id = ?) AS follows_me
                         FROM moment_viewers v JOIN users u ON u.id = v.user_id LEFT JOIN organizer_profiles o ON o.user_id = u.id
                         WHERE $where ORDER BY v.last_viewed_at DESC, v.user_id DESC LIMIT " . ($limit + 1));
    $st->execute(array_merge([$viewerId], $args));
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $out = [];
    foreach ($rows as $r) {
        $p = social_person_from_row($r);
        $ts = strtotime((string) $r['last_viewed_at']);
        $out[] = [
            'id' => $p['id'], 'name' => $p['name'], 'handle' => $p['handle'], 'avatar' => $p['avatar'] ?? (function_exists('user_avatar_url') ? user_avatar_url($p['id']) : null),
            'initials' => function_exists('community_initials') ? community_initials($p['name']) : mb_strtoupper(mb_substr($p['name'], 0, 2)),
            'url' => $p['url'], 'verified' => $p['verified'], 'followsYou' => (int) $r['follows_me'] === 1,
            'ago' => function_exists('social_ago') ? social_ago($ts) : date('j M', $ts), 'times' => (int) $r['view_count'],
            'chat' => '/chat.php?to=' . $p['id'],
        ];
    }
    $last = end($rows);
    return ['ok' => true, 'rows' => $out, 'total' => (int) $total->fetchColumn(),
            'next' => $more && $last ? $last['last_viewed_at'] . '|' . (int) $last['user_id'] : null];
}

/** How many listed viewers a video has (for the owner's button). */
function vv_count(int $postId): int
{
    if (!vv_ready()) {
        return 0;
    }
    $st = db()->prepare("SELECT COUNT(*) FROM moment_viewers v JOIN users u ON u.id = v.user_id WHERE v.post_id = ? AND u.show_in_viewers = 1 AND COALESCE(u.account_status, 'ACTIVE') IN ('ACTIVE', '')");
    $st->execute([$postId]);
    return (int) $st->fetchColumn();
}
