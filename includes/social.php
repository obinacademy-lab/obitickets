<?php
declare(strict_types=1);

/**
 * Following people and private messages (11/18, migration 037).
 *
 * Follows: everyone can follow everyone. Following an ORGANIZER still writes organizer_follows (the feed and the
 * organizer pages read it); following anyone else writes user_follows. The lists merge both.
 *
 * Messages: one conversation per pair of people. If the person you write to already follows you, the chat opens
 * straight away; otherwise your first messages wait in their "Message requests" until they accept or reply (you may
 * send up to DM_REQUEST_MAX before that). Blocking (user_blocks) stops messages both ways. Everything is checked
 * here, never in the browser. Message text is plain text: it is escaped on output and links are never made live.
 */

const DM_MAX_CHARS = 1000;
const DM_REQUEST_MAX = 3;          // messages a stranger may send before you accept
const DM_RATE_PER_MINUTE = 20;
const DM_RATE_PER_DAY = 300;
const DM_REPORT_REASONS = ['Spam' => 'Spam or scam', 'Harassment' => 'Harassment or bullying', 'Fake' => 'Pretending to be someone else', 'Other' => 'Something else'];

function social_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'dm_messages'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

// =====================================================================
// People
// =====================================================================

/** A person as the lists, chats and profiles show them. Null if they do not exist or are not active. */
function social_person(int $userId): ?array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $about = function_exists('profile_about_ready') && profile_about_ready() ? ', u.username' : ', NULL AS username';
    $st = db()->prepare("SELECT u.id, u.name, u.role, u.avatar_url, u.account_status$about, o.slug AS org_slug, o.org_name, o.verified
                         FROM users u LEFT JOIN organizer_profiles o ON o.user_id = u.id WHERE u.id = ?");
    $st->execute([$userId]);
    $r = $st->fetch();
    if (!$r || (isset($r['account_status']) && !in_array(strtoupper((string) $r['account_status']), ['', 'ACTIVE'], true))) {
        return $cache[$userId] = null;
    }
    return $cache[$userId] = social_person_from_row($r);
}

/** @param array $r a users row joined with organizer_profiles (org_slug, org_name, verified) */
function social_person_from_row(array $r): array
{
    $isOrg = ($r['role'] ?? '') === 'ORGANIZER' && !empty($r['org_slug']);
    $name = $isOrg && !empty($r['org_name']) ? (string) $r['org_name'] : (string) $r['name'];
    $username = (string) ($r['username'] ?? '');
    $avatar = (string) ($r['avatar_url'] ?? '');
    return [
        'id' => (int) $r['id'],
        'name' => $name,
        'username' => $username,
        'handle' => $username !== '' ? '@' . $username : ($isOrg ? '@' . $r['org_slug'] : ''),
        'avatar' => preg_match('#^/uploads/avatars/[a-f0-9]{32}\.jpg$#', $avatar) ? $avatar : null,
        'organizer' => $isOrg,
        'verified' => $isOrg && (int) ($r['verified'] ?? 0) === 1,
        'url' => $isOrg ? '/organizer.php?slug=' . urlencode((string) $r['org_slug']) : '/u.php?id=' . (int) $r['id'],
    ];
}

/** True if either person has blocked the other. */
function social_blocked_between(int $a, int $b): bool
{
    $st = db()->prepare('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1');
    $st->execute([$a, $b, $b, $a]);
    return (bool) $st->fetchColumn();
}

function social_i_blocked(int $viewerId, int $otherId): bool
{
    $st = db()->prepare('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?');
    $st->execute([$viewerId, $otherId]);
    return (bool) $st->fetchColumn();
}

// =====================================================================
// Follows
// =====================================================================

function social_is_following(int $viewerId, int $targetId): bool
{
    if ($viewerId === $targetId) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM organizer_follows WHERE user_id = ? AND organizer_user_id = ?
                         UNION SELECT 1 FROM user_follows WHERE follower_id = ? AND followee_id = ? LIMIT 1');
    $st->execute([$viewerId, $targetId, $viewerId, $targetId]);
    return (bool) $st->fetchColumn();
}

/** @return array{followers:int,following:int} */
function social_counts(int $userId): array
{
    $st = db()->prepare('SELECT COUNT(*) FROM (SELECT user_id AS id FROM organizer_follows WHERE organizer_user_id = ?
                                               UNION SELECT follower_id FROM user_follows WHERE followee_id = ?) t');
    $st->execute([$userId, $userId]);
    $followers = (int) $st->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM (SELECT organizer_user_id AS id FROM organizer_follows WHERE user_id = ?
                                               UNION SELECT followee_id FROM user_follows WHERE follower_id = ?) t');
    $st->execute([$userId, $userId]);
    return ['followers' => $followers, 'following' => (int) $st->fetchColumn()];
}

/**
 * Follow or unfollow anyone. Organizers go through set_organizer_follow (the feed reads organizer_follows).
 * @return array{ok:bool, following?:bool, followers?:int, error?:string}
 */
function social_follow(int $viewerId, int $targetId, bool $follow): array
{
    if ($viewerId === $targetId) {
        return ['ok' => false, 'error' => "You can't follow yourself."];
    }
    $target = social_person($targetId);
    if (!$target) {
        return ['ok' => false, 'error' => 'This person is not available.'];
    }
    if ($follow && social_blocked_between($viewerId, $targetId)) {
        return ['ok' => false, 'error' => "You can't follow this person."];
    }
    if ($target['organizer']) {
        $r = set_organizer_follow($targetId, $viewerId, $follow);
        if (!$r['ok']) {
            return $r;
        }
    } elseif ($follow) {
        $ins = db()->prepare('INSERT IGNORE INTO user_follows (follower_id, followee_id) VALUES (?, ?)');
        $ins->execute([$viewerId, $targetId]);
        if ($ins->rowCount() > 0 && function_exists('community_notify')) {
            $me = social_person($viewerId);
            community_notify($targetId, $viewerId, 'follow', community_user_name($viewerId) . ' started following you', null, $me ? $me['url'] : '/me.php', true);
        }
    } else {
        db()->prepare('DELETE FROM user_follows WHERE follower_id = ? AND followee_id = ?')->execute([$viewerId, $targetId]);
    }
    return ['ok' => true, 'following' => $follow, 'followers' => social_counts($targetId)['followers']];
}

/**
 * Followers or Following of a person, newest first, as people (with whether the viewer follows each one).
 * @return array<int,array>
 */
function social_follow_list(int $userId, string $which, ?int $viewerId, int $limit = 50, int $offset = 0): array
{
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $sql = $which === 'following'
        ? 'SELECT id, MAX(at) AS at FROM (SELECT organizer_user_id AS id, created_at AS at FROM organizer_follows WHERE user_id = ?
                                     UNION ALL SELECT followee_id, created_at FROM user_follows WHERE follower_id = ?) t GROUP BY id'
        : 'SELECT id, MAX(at) AS at FROM (SELECT user_id AS id, created_at AS at FROM organizer_follows WHERE organizer_user_id = ?
                                     UNION ALL SELECT follower_id, created_at FROM user_follows WHERE followee_id = ?) t GROUP BY id';
    $st = db()->prepare($sql . ' ORDER BY at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $st->execute([$userId, $userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $p = social_person((int) $r['id']);
        if (!$p || ($viewerId && social_blocked_between($viewerId, $p['id']))) {
            continue;
        }
        $p['i_follow'] = $viewerId ? social_is_following($viewerId, $p['id']) : false;
        $p['is_me'] = $viewerId === $p['id'];
        $out[] = $p;
    }
    return $out;
}

// =====================================================================
// Messages
// =====================================================================

function dm_pair(int $a, int $b): array
{
    return $a < $b ? [$a, $b] : [$b, $a];
}

/** The conversation if the viewer is in it, with 'me' = 'low'|'high' and 'other_id'. */
function dm_conversation(int $convId, int $viewerId): ?array
{
    $st = db()->prepare('SELECT * FROM dm_conversations WHERE id = ? AND (user_low = ? OR user_high = ?)');
    $st->execute([$convId, $viewerId, $viewerId]);
    $c = $st->fetch();
    if (!$c) {
        return null;
    }
    $c['me'] = (int) $c['user_low'] === $viewerId ? 'low' : 'high';
    $c['other_id'] = $c['me'] === 'low' ? (int) $c['user_high'] : (int) $c['user_low'];
    $c['is_request_for_me'] = $c['accepted_at'] === null && (int) $c['started_by'] !== $viewerId;
    return $c;
}

function dm_find(int $a, int $b): ?int
{
    [$lo, $hi] = dm_pair($a, $b);
    $st = db()->prepare('SELECT id FROM dm_conversations WHERE user_low = ? AND user_high = ?');
    $st->execute([$lo, $hi]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Opens (or finds) the chat between the viewer and someone. Not created until it is needed: a conversation row
 * is only written here, by someone choosing to message.
 * @return array{ok:bool, id?:int, error?:string}
 */
function dm_open(int $viewerId, int $otherId): array
{
    if ($viewerId === $otherId) {
        return ['ok' => false, 'error' => "You can't message yourself."];
    }
    if (!social_person($otherId)) {
        return ['ok' => false, 'error' => 'This person is not available.'];
    }
    if (social_blocked_between($viewerId, $otherId)) {
        return ['ok' => false, 'error' => "You can't message this person."];
    }
    $id = dm_find($viewerId, $otherId);
    if ($id) {
        return ['ok' => true, 'id' => $id];
    }
    [$lo, $hi] = dm_pair($viewerId, $otherId);
    // they already follow you: it is a normal chat for them, not a request
    $accepted = social_is_following($otherId, $viewerId) ? date('Y-m-d H:i:s') : null;
    try {
        db()->prepare('INSERT INTO dm_conversations (user_low, user_high, started_by, accepted_at) VALUES (?, ?, ?, ?)')
            ->execute([$lo, $hi, $viewerId, $accepted]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (PDOException $e) {
        $id = dm_find($viewerId, $otherId);   // both opened it at the same moment
        return $id ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'Could not open the chat. Please try again.'];
    }
}

/** Plain text only: no control characters, at most DM_MAX_CHARS, a few blank lines at most. */
function dm_clean(string $body): string
{
    $body = str_replace("\r", '', $body);
    $body = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', '', $body) ?? '';
    $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? '';
    return trim($body);
}

/** @return array{ok:bool, message?:array, error?:string} */
function dm_send(int $viewerId, int $convId, string $body, ?int $eventId = null): array
{
    $c = dm_conversation($convId, $viewerId);
    if (!$c) {
        return ['ok' => false, 'error' => 'Chat not found.'];
    }
    if (social_blocked_between($viewerId, $c['other_id'])) {
        return ['ok' => false, 'error' => "You can't message this person."];
    }
    $body = dm_clean($body);
    if (mb_strlen($body) > DM_MAX_CHARS) {
        return ['ok' => false, 'error' => 'That message is too long (limit ' . DM_MAX_CHARS . ' characters).'];
    }
    if ($eventId !== null) {
        $ev = db()->prepare("SELECT id FROM events WHERE id = ? AND status = 'PUBLISHED'");
        $ev->execute([$eventId]);
        if (!$ev->fetchColumn()) {
            return ['ok' => false, 'error' => 'That event is not available.'];
        }
    }
    if ($body === '' && $eventId === null) {
        return ['ok' => false, 'error' => 'Write a message first.'];
    }
    $rate = db()->prepare('SELECT SUM(created_at >= (NOW() - INTERVAL 1 MINUTE)), COUNT(*) FROM dm_messages WHERE sender_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)');
    $rate->execute([$viewerId]);
    [$perMin, $perDay] = array_map('intval', $rate->fetch(PDO::FETCH_NUM));
    if ($perMin >= DM_RATE_PER_MINUTE || $perDay >= DM_RATE_PER_DAY) {
        return ['ok' => false, 'error' => "You're sending messages too quickly. Please wait a moment."];
    }
    if ($c['accepted_at'] === null) {
        if ($c['is_request_for_me']) {
            db()->prepare('UPDATE dm_conversations SET accepted_at = NOW() WHERE id = ?')->execute([$convId]);   // replying accepts
        } else {
            $n = db()->prepare('SELECT COUNT(*) FROM dm_messages WHERE conversation_id = ? AND sender_id = ?');
            $n->execute([$convId, $viewerId]);
            if ((int) $n->fetchColumn() >= DM_REQUEST_MAX) {
                return ['ok' => false, 'error' => 'Wait for them to accept your message request before sending more.'];
            }
        }
    }
    db()->prepare('INSERT INTO dm_messages (conversation_id, sender_id, body, event_id) VALUES (?, ?, ?, ?)')->execute([$convId, $viewerId, $body, $eventId]);
    $mid = (int) db()->lastInsertId();
    $readCol = $c['me'] === 'low' ? 'low_read_id' : 'high_read_id';
    db()->prepare("UPDATE dm_conversations SET last_message_at = NOW(), $readCol = ? WHERE id = ?")->execute([$mid, $convId]);
    $msgs = dm_messages($viewerId, $convId, $mid - 1, 1);
    return ['ok' => true, 'message' => $msgs[0] ?? null];
}

/**
 * Messages after $afterId (oldest first), or the latest $limit when $afterId is 0, or older ones with $beforeId.
 * Hidden: anything before the viewer's own "delete chat".
 */
function dm_messages(int $viewerId, int $convId, int $afterId = 0, int $limit = 60, int $beforeId = 0): array
{
    $c = dm_conversation($convId, $viewerId);
    if (!$c) {
        return [];
    }
    $cleared = (int) ($c['me'] === 'low' ? $c['low_cleared_id'] : $c['high_cleared_id']);
    $limit = max(1, min(100, $limit));
    $sel = 'SELECT m.id, m.sender_id, m.body, m.event_id, m.created_at, e.title AS ev_title, e.slug AS ev_slug, e.starts_at AS ev_starts, e.venue_name AS ev_venue, e.banner_image AS ev_banner
            FROM dm_messages m LEFT JOIN events e ON e.id = m.event_id AND e.status = "PUBLISHED" WHERE m.conversation_id = ? AND m.id > ?';
    if ($afterId > 0) {
        $st = db()->prepare($sel . ' AND m.id > ? ORDER BY m.id ASC LIMIT ' . $limit);
        $st->execute([$convId, $cleared, $afterId]);
        $rows = $st->fetchAll();
    } else {
        $st = db()->prepare($sel . ($beforeId > 0 ? ' AND m.id < ' . (int) $beforeId : '') . ' ORDER BY m.id DESC LIMIT ' . $limit);
        $st->execute([$convId, $cleared]);
        $rows = array_reverse($st->fetchAll());
    }
    return array_map(static fn ($r) => dm_message_public($r, $viewerId), $rows);
}

function dm_message_public(array $r, int $viewerId): array
{
    $ev = null;
    if (!empty($r['event_id']) && !empty($r['ev_slug'])) {
        $img = (string) ($r['ev_banner'] ?? '');
        $ev = [
            'title' => (string) $r['ev_title'],
            'url' => '/event.php?slug=' . urlencode((string) $r['ev_slug']),
            'when' => date('D j M · g:i A', strtotime((string) $r['ev_starts'])),
            'venue' => (string) $r['ev_venue'],
            'image' => preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $img) ? $img : null,
        ];
    }
    return [
        'id' => (int) $r['id'],
        'mine' => (int) $r['sender_id'] === $viewerId,
        'body' => (string) $r['body'],
        'event' => $ev,
        'at' => strtotime((string) $r['created_at']),
        'time' => date('g:i A', strtotime((string) $r['created_at'])),
    ];
}

function dm_mark_read(int $viewerId, int $convId, int $lastId): void
{
    $c = dm_conversation($convId, $viewerId);
    if (!$c) {
        return;
    }
    $col = $c['me'] === 'low' ? 'low_read_id' : 'high_read_id';
    db()->prepare("UPDATE dm_conversations SET $col = GREATEST($col, ?) WHERE id = ?")->execute([$lastId, $convId]);
}

/** What the other person has read up to (for "Seen"). */
function dm_other_read_id(array $c): int
{
    return (int) ($c['me'] === 'low' ? $c['high_read_id'] : $c['low_read_id']);
}

/**
 * The inbox: 'chats' (accepted, or ones you started) or 'requests' (strangers waiting for you), newest first.
 * Conversations with a block either way, or with nothing left after your "delete chat", are left out.
 */
function dm_inbox(int $viewerId, string $tab = 'chats', int $limit = 60): array
{
    $isReq = $tab === 'requests';
    $st = db()->prepare('
        SELECT c.*, (c.user_low = :v1) AS am_low,
               (SELECT m.id FROM dm_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_id
        FROM dm_conversations c
        WHERE (c.user_low = :v2 OR c.user_high = :v3) AND c.last_message_at IS NOT NULL
          AND ' . ($isReq ? 'c.accepted_at IS NULL AND c.started_by <> :v4' : '(c.accepted_at IS NOT NULL OR c.started_by = :v4)') . '
          AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = :v5 AND b.blocked_id = IF(c.user_low = :v6, c.user_high, c.user_low))
                                                       OR (b.blocked_id = :v7 AND b.blocker_id = IF(c.user_low = :v8, c.user_high, c.user_low)))
        ORDER BY c.last_message_at DESC LIMIT ' . max(1, min(100, $limit)));
    foreach (['v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8'] as $k) {
        $st->bindValue(':' . $k, $viewerId, PDO::PARAM_INT);
    }
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $amLow = (int) $c['am_low'] === 1;
        $cleared = (int) ($amLow ? $c['low_cleared_id'] : $c['high_cleared_id']);
        if ((int) $c['last_id'] <= $cleared) {
            continue;   // deleted on my side and nothing new since
        }
        $other = social_person($amLow ? (int) $c['user_high'] : (int) $c['user_low']);
        if (!$other) {
            continue;
        }
        $read = (int) ($amLow ? $c['low_read_id'] : $c['high_read_id']);
        $last = db()->prepare('SELECT sender_id, body, event_id FROM dm_messages WHERE id = ?');
        $last->execute([(int) $c['last_id']]);
        $lm = $last->fetch() ?: ['sender_id' => 0, 'body' => '', 'event_id' => null];
        $preview = (string) $lm['body'] !== '' ? (string) $lm['body'] : ($lm['event_id'] ? 'Shared an event' : '');
        $unread = db()->prepare('SELECT COUNT(*) FROM dm_messages WHERE conversation_id = ? AND id > ? AND id > ? AND sender_id <> ?');
        $unread->execute([(int) $c['id'], $read, $cleared, $viewerId]);
        $out[] = [
            'id' => (int) $c['id'],
            'other' => $other,
            'preview' => ((int) $lm['sender_id'] === $viewerId ? 'You: ' : '') . mb_substr(preg_replace('/\s+/u', ' ', $preview) ?? '', 0, 80),
            'at' => strtotime((string) $c['last_message_at']),
            'unread' => (int) $unread->fetchColumn(),
            'pending' => $c['accepted_at'] === null && (int) $c['started_by'] === $viewerId,
        ];
    }
    return $out;
}

/** @return array{chats:int,requests:int} chats with unread messages, and waiting requests */
function dm_unread_counts(int $viewerId): array
{
    if (!social_ready()) {
        return ['chats' => 0, 'requests' => 0];
    }
    $chats = 0;
    foreach (dm_inbox($viewerId, 'chats') as $c) {
        if ($c['unread'] > 0) { $chats++; }
    }
    return ['chats' => $chats, 'requests' => count(dm_inbox($viewerId, 'requests'))];
}

/** One quick query for the tab bar dot: is any message from someone else unread (and not blocked)? */
function dm_has_unread(int $viewerId): bool
{
    if (!social_ready()) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM dm_conversations c JOIN dm_messages m ON m.conversation_id = c.id
        WHERE m.sender_id <> :v1
          AND ((c.user_low = :v2 AND m.id > GREATEST(c.low_read_id, c.low_cleared_id))
            OR (c.user_high = :v3 AND m.id > GREATEST(c.high_read_id, c.high_cleared_id)))
          AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = :v4 AND b.blocked_id = m.sender_id) OR (b.blocker_id = m.sender_id AND b.blocked_id = :v5))
        LIMIT 1');
    foreach (['v1', 'v2', 'v3', 'v4', 'v5'] as $k) {
        $st->bindValue(':' . $k, $viewerId, PDO::PARAM_INT);
    }
    $st->execute();
    return (bool) $st->fetchColumn();
}

function dm_accept(int $viewerId, int $convId): bool
{
    $c = dm_conversation($convId, $viewerId);
    if (!$c || !$c['is_request_for_me']) {
        return false;
    }
    db()->prepare('UPDATE dm_conversations SET accepted_at = NOW() WHERE id = ?')->execute([$convId]);
    return true;
}

/** "Delete chat": hides everything so far on your side only (the other person keeps their copy). */
function dm_clear(int $viewerId, int $convId): bool
{
    $c = dm_conversation($convId, $viewerId);
    if (!$c) {
        return false;
    }
    $col = $c['me'] === 'low' ? 'low_cleared_id' : 'high_cleared_id';
    db()->prepare("UPDATE dm_conversations SET $col = (SELECT COALESCE(MAX(id), 0) FROM dm_messages WHERE conversation_id = ?) WHERE id = ?")->execute([$convId, $convId]);
    return true;
}

/** @return array{ok:bool,error?:string} */
function dm_report(int $viewerId, int $convId, string $reason, bool $alsoBlock): array
{
    $c = dm_conversation($convId, $viewerId);
    if (!$c) {
        return ['ok' => false, 'error' => 'Chat not found.'];
    }
    if (!isset(DM_REPORT_REASONS[$reason])) {
        return ['ok' => false, 'error' => 'Pick a reason.'];
    }
    $dup = db()->prepare("SELECT 1 FROM dm_reports WHERE conversation_id = ? AND reporter_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)");
    $dup->execute([$convId, $viewerId]);
    if (!$dup->fetchColumn()) {
        db()->prepare('INSERT INTO dm_reports (conversation_id, reporter_id, reported_id, reason) VALUES (?, ?, ?, ?)')->execute([$convId, $viewerId, $c['other_id'], $reason]);
    }
    if ($alsoBlock) {
        set_user_block($viewerId, $c['other_id'], true);
    }
    return ['ok' => true];
}

/** "2m", "3h", "Yesterday", "12 Nov" */
function social_ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) { return 'now'; }
    if ($d < 3600) { return floor($d / 60) . 'm'; }
    if ($d < 86400) { return floor($d / 3600) . 'h'; }
    if ($d < 172800) { return 'Yesterday'; }
    if ($d < 604800) { return date('D', $ts); }
    return date('j M', $ts);
}

/** A round picture or initials. */
function social_avatar_html(array $p, int $size = 48): string
{
    $s = 'width:' . $size . 'px;height:' . $size . 'px';
    if ($p['avatar']) {
        return '<img class="sx-av" src="' . htmlspecialchars($p['avatar']) . '" alt="" style="' . $s . '" loading="lazy">';
    }
    return '<span class="sx-av sx-av-i" aria-hidden="true" style="' . $s . ';font-size:' . round($size * 0.36) . 'px">' . htmlspecialchars(initials_from_name($p['name'])) . '</span>';
}
