<?php
// JSON endpoint behind obitickets Socials: more of the feed, comments as data (the feed draws them itself),
// and the organizer's plan: start the free month, start a mobile money payment, check on it.
//
// Reading (feed, comments) is open to visitors. Commenting needs an account. The plan actions need an organizer
// account (the owner or a member of their team). All rules live in includes/socials.php and includes/community.php.

// ioTec calls (token + collection or status) can each take up to 15s.
set_time_limit(45);

require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);

$action = (string) ($body['action'] ?? '');
$user = current_user();
$uid = $user ? (int) $user['id'] : null;

/** One comment in the shape the feed script draws. */
$commentOut = static function (array $c) use (&$commentOut): array {
    $isOrg = (int) ($c['is_organizer'] ?? 0) === 1;
    $name = community_display_name((string) $c['author_name']);
    return [
        'id' => (int) $c['id'],
        'userId' => (int) $c['user_id'],
        'name' => $name,
        'initials' => community_initials((string) $c['author_name']),
        'avatar' => function_exists('user_avatar_url') ? user_avatar_url((int) $c['user_id']) : null,
        'organizer' => $isOrg,
        'ticket' => (int) ($c['is_verified_attendee'] ?? 0) === 1,
        'body' => (string) $c['body'],
        'sticker' => socials_sticker_of((string) $c['body']),
        'when' => community_time_ago((string) $c['created_at']),
        'likes' => (int) ($c['reaction_count'] ?? 0),
        'mine' => !empty($c['my_reaction']),
        'replyTo' => !empty($c['reply_to_name']) ? community_display_name((string) $c['reply_to_name']) : null,
        'replies' => array_map($commentOut, $c['replies'] ?? []),
    ];
};

try {
    switch ($action) {
        case 'feed':
            $tab = in_array($body['tab'] ?? '', ['following', 'trending'], true) ? (string) $body['tab'] : 'foryou';
            $offset = max(0, (int) ($body['offset'] ?? 0));
            $start = !empty($body['start']) ? (int) $body['start'] : null;
            $org = max(0, (int) ($body['org'] ?? 0));
            // one organizer's posts (the player opened from their card), or the main feed
            $rows = $org ? socials_feed($uid, 'profile', $offset, SOCIALS_FEED_PAGE, null, $org) : socials_feed($uid, $tab, $offset, SOCIALS_FEED_PAGE, $start);
            json_response(['ok' => true, 'items' => array_map('socials_item_public', $rows), 'has_more' => count($rows) === SOCIALS_FEED_PAGE]);

        case 'org_cards':
            // more organizer cards for the list, drawn here so the page and this match exactly
            $tab = in_array($body['tab'] ?? '', ['following', 'trending'], true) ? (string) $body['tab'] : 'foryou';
            $cards = socials_organizer_cards($uid, $tab, max(0, (int) ($body['offset'] ?? 0)), SOCIALS_FEED_PAGE);
            json_response(['ok' => true, 'html' => implode('', array_map('socials_org_card_html', $cards)), 'count' => count($cards), 'has_more' => count($cards) === SOCIALS_FEED_PAGE]);

        case 'comments':
            $post = get_post((int) ($body['post_id'] ?? 0));
            if (!$post || $post['status'] !== 'PUBLISHED') {
                json_response(['error' => 'Post not found.'], 404);
            }
            $after = !empty($body['after_id']) ? (int) $body['after_id'] : null;
            $rows = get_post_comments((int) $post['id'], $uid, 20, $after);
            json_response(['ok' => true, 'comments' => array_map($commentOut, $rows), 'has_more' => count($rows) === 20,
                'last_id' => $rows ? (int) end($rows)['id'] : null, 'open' => (int) $post['comments_enabled'] === 1]);

        case 'comment_add':
            if (!$user) {
                json_response(['error' => 'Please log in to comment.', 'login' => true], 401);
            }
            $post = get_post((int) ($body['post_id'] ?? 0));
            if (!$post || $post['status'] !== 'PUBLISHED') {
                json_response(['error' => 'Post not found.'], 404);
            }
            $parent = !empty($body['parent_id']) ? (int) $body['parent_id'] : null;
            $text = (string) ($body['body'] ?? '');
            if (!empty($body['sticker'])) {
                // A sticker is sent by name and stored as "[sticker:name]"; only names in the set are accepted.
                $name = (string) $body['sticker'];
                if (!isset(SOCIALS_STICKERS[$name])) {
                    json_response(['error' => 'Unknown sticker.'], 400);
                }
                $text = '[sticker:' . $name . ']';
            }
            $r = add_comment($user, (int) $post['id'], $text, $parent);
            if (!$r['ok']) {
                json_response($r, 400);
            }
            $row = get_comment_for_render((int) $r['id'], $uid);
            $total = db()->prepare("SELECT COUNT(*) FROM post_comments WHERE post_id = ? AND status = 'PUBLISHED'");
            $total->execute([(int) $post['id']]);
            json_response(['ok' => true, 'comment' => $row ? $commentOut($row) : null, 'parent_id' => $r['parent_id'] ?? null, 'count' => (int) $total->fetchColumn()]);

        case 'trial_start':
        case 'pay_start':
        case 'pay_poll':
        case 'status':
            if (!$user) {
                json_response(['error' => 'Please log in.', 'login' => true], 401);
            }
            if (!socials_ready()) {
                json_response(['error' => 'Socials is not switched on yet.'], 503);
            }
            $ctx = resolve_organizer_context($user);
            if (!$ctx) {
                json_response(['error' => 'Socials plans are for organizer accounts.'], 403);
            }
            $orgId = (int) $ctx['organizer_id'];

            if ($action === 'status') {
                json_response(['ok' => true, 'status' => socials_status($orgId)]);
            }
            if ($action === 'trial_start') {
                $r = socials_start_trial($orgId);
                json_response($r, $r['ok'] ? 200 : 400);
            }
            if ($action === 'pay_start') {
                $phone = trim((string) ($body['phone'] ?? ''));
                // The same abuse guard as ticket checkout: a prompt goes to whatever number is typed.
                if (function_exists('payguard_check') && ($blocked = payguard_check((int) $user['id'], $phone))) {
                    json_response(['error' => $blocked['message']], 429);
                }
                $r = socials_payment_start($user, $orgId, $phone);
                json_response($r['ok'] ? ['ok' => true, 'paymentId' => $r['id']] : ['error' => $r['error']], $r['ok'] ? 200 : 400);
            }
            // pay_poll
            $result = socials_payment_poll($orgId, (int) ($body['paymentId'] ?? 0));
            json_response($result + ['plan' => socials_status($orgId)]);

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    // Never leak SQL or paths to the browser; the details go to the server log.
    error_log('[socials api] ' . $action . ': ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
