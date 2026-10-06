<?php
// JSON endpoint behind the event community UI: follow, react, comment, report,
// block, moderate, and "load more" (which returns ready-made HTML so the page
// and the endpoint always render posts and comments identically).
//
// Reading (comments / feed_more) is open to guests; everything that changes
// something needs a logged-in user. All permission checks live in
// includes/community.php — nothing here trusts the browser.

require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);

$action = (string) ($body['action'] ?? '');
$user = current_user();
$readActions = ['comments', 'feed_more', 'moment_view', 'moment_share'];
if (!in_array($action, $readActions, true) && !$user) {
    json_response(['error' => 'Please log in to do that.', 'login' => true], 401);
}
$uid = $user ? (int) $user['id'] : null;
$intOf = static fn ($v) => (int) $v;

/** Event row + render context for a post (null if the post/event isn't public). */
$ctxForPost = static function (int $postId) use ($user): ?array {
    $post = get_post($postId);
    if (!$post) {
        return null;
    }
    $event = get_community_event((int) $post['event_id']);
    return $event ? [$post, community_ctx($event, $user, (string) $event['org_name'])] : null;
};

try {
    switch ($action) {
        case 'follow_event':
            $r = set_event_follow($intOf($body['event_id'] ?? 0), $uid, !empty($body['follow']));
            json_response($r, $r['ok'] ? 200 : 400);

        case 'follow_organizer':
            $r = set_organizer_follow($intOf($body['organizer_id'] ?? 0), $uid, !empty($body['follow']));
            json_response($r, $r['ok'] ? 200 : 400);

        case 'react':
            $reaction = isset($body['reaction']) && $body['reaction'] !== '' ? (string) $body['reaction'] : null;
            $id = $intOf($body['id'] ?? 0);
            if (($body['target'] ?? 'post') === 'comment') {
                $r = set_comment_reaction($id, $uid, $reaction);
            } else {
                $r = set_post_reaction($id, $uid, $reaction);
                if ($r['ok']) {
                    $r['summary_html'] = community_reaction_summary_html($r['breakdown'], $r['reaction_count']);
                }
            }
            json_response($r, $r['ok'] ? 200 : 400);

        case 'react_event':
            $eventId = $intOf($body['event_id'] ?? 0);
            $reaction = isset($body['reaction']) && $body['reaction'] !== '' ? (string) $body['reaction'] : null;
            $r = set_event_reaction($eventId, $uid, $reaction);
            if ($r['ok']) {
                $r['summary_html'] = community_reaction_summary_html($r['breakdown'], $r['total']);
                $r['pulse_reactions'] = get_event_social_counts($eventId)['reactions'] + $r['total'];
            }
            json_response($r, $r['ok'] ? 200 : 400);

        case 'moment_view':
        case 'moment_share':
            // Guests count too; one count per visitor per moment per session keeps the numbers honest.
            $momentId = $intOf($body['id'] ?? 0);
            $statKey = $action === 'moment_share' ? 'moment_shares' : 'moment_views';
            if (!isset($_SESSION[$statKey]) || !is_array($_SESSION[$statKey])) {
                $_SESSION[$statKey] = [];
            }
            if (isset($_SESSION[$statKey][$momentId])) {
                json_response(['ok' => true, 'counted' => false]);
            }
            $counted = record_moment_stat($momentId, $action === 'moment_share' ? 'share' : 'view');
            if ($counted) {
                $_SESSION[$statKey][$momentId] = time();
                $_SESSION[$statKey] = array_slice($_SESSION[$statKey], -300, null, true);
            }
            json_response(['ok' => true, 'counted' => $counted]);

        case 'comments':
            $found = $ctxForPost($intOf($body['post_id'] ?? 0));
            if (!$found || $found[0]['status'] !== 'PUBLISHED') {
                json_response(['error' => 'Post not found.'], 404);
            }
            [$post, $ctx] = $found;
            $afterId = !empty($body['after_id']) ? $intOf($body['after_id']) : null;
            $comments = get_post_comments((int) $post['id'], $uid, 20, $afterId);
            $html = '';
            foreach ($comments as $c) {
                $html .= community_comment_html($c, $ctx);
            }
            json_response(['ok' => true, 'html' => $html, 'count' => count($comments), 'has_more' => count($comments) === 20, 'last_id' => $comments ? (int) end($comments)['id'] : null]);

        case 'comment_add':
            $found = $ctxForPost($intOf($body['post_id'] ?? 0));
            if (!$found) {
                json_response(['error' => 'Post not found.'], 404);
            }
            [$post, $ctx] = $found;
            $parent = !empty($body['parent_id']) ? $intOf($body['parent_id']) : null;
            $r = add_comment($user, (int) $post['id'], (string) ($body['body'] ?? ''), $parent);
            if (!$r['ok']) {
                json_response($r, 400);
            }
            $row = get_comment_for_render($r['id'], $uid);
            $total = db()->prepare("SELECT COUNT(*) FROM post_comments WHERE post_id = ? AND status = 'PUBLISHED'");
            $total->execute([(int) $post['id']]);
            // parent_id is the thread the reply was filed under (the top-level comment), which can differ
            // from the comment that was answered.
            $threadId = $r['parent_id'] ?? null;
            json_response(['ok' => true, 'html' => $row ? community_comment_html($row, $ctx, $threadId !== null) : '', 'parent_id' => $threadId, 'count' => (int) $total->fetchColumn()]);

        case 'feed_more':
            $event = get_community_event($intOf($body['event_id'] ?? 0));
            if (!$event) {
                json_response(['error' => 'Event not found.'], 404);
            }
            $ctx = community_ctx($event, $user, (string) $event['org_name']);
            $posts = get_event_feed((int) $event['id'], $uid, FEED_PAGE_SIZE, $intOf($body['before_id'] ?? 0) ?: null);
            $html = '';
            foreach ($posts as $p) {
                $html .= community_post_html($p, $ctx);
            }
            json_response(['ok' => true, 'html' => $html, 'has_more' => count($posts) === FEED_PAGE_SIZE, 'last_id' => $posts ? (int) end($posts)['id'] : null]);

        case 'report':
            $type = ($body['type'] ?? '') === 'COMMENT' ? 'COMMENT' : 'POST';
            $r = report_content($type, $intOf($body['id'] ?? 0), $uid, (string) ($body['reason'] ?? ''), isset($body['details']) ? (string) $body['details'] : null);
            json_response($r, $r['ok'] ? 200 : 400);

        case 'block':
            $ok = set_user_block($uid, $intOf($body['user_id'] ?? 0), !empty($body['block']));
            json_response(['ok' => $ok, 'error' => $ok ? null : "You can't block yourself."], $ok ? 200 : 400);

        case 'moderate':
            $type = ($body['type'] ?? '') === 'COMMENT' ? 'COMMENT' : 'POST';
            $r = moderate_content($user, $type, $intOf($body['id'] ?? 0), strtoupper((string) ($body['act'] ?? '')));
            json_response($r, $r['ok'] ? 200 : 403);

        case 'pin':
            $r = set_post_pinned($user, $intOf($body['id'] ?? 0), !empty($body['pin']));
            json_response($r, $r['ok'] ? 200 : 400);

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    // Never leak SQL or paths to the browser; the details go to the server log.
    error_log('[community api] ' . $action . ': ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
