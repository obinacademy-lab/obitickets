<?php
// JSON endpoint for following people and private messages (includes/social.php does every check).
// Every call needs a signed-in person and the page's form token.
require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);
$user = current_user();
if (!$user) {
    json_response(['error' => 'Please log in to do that.', 'login' => true], 401);
}
if (!social_ready()) {
    json_response(['error' => 'Messages are not switched on yet.'], 503);
}
$uid = (int) $user['id'];
$int = static fn ($k) => (int) ($body[$k] ?? 0);

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'follow':
            $r = social_follow($uid, $int('user_id'), !empty($body['follow']));
            json_response($r, $r['ok'] ? 200 : 400);

        case 'open':
            $r = dm_open($uid, $int('user_id'));
            json_response($r + ($r['ok'] ? ['url' => '/chat.php?c=' . $r['id']] : []), $r['ok'] ? 200 : 400);

        case 'send':
            $ev = $int('event_id');
            $r = dm_send($uid, $int('conversation_id'), (string) ($body['body'] ?? ''), $ev > 0 ? $ev : null);
            json_response($r, $r['ok'] ? 200 : 400);

        case 'poll':
            $cid = $int('conversation_id');
            $c = dm_conversation($cid, $uid);
            if (!$c) {
                json_response(['error' => 'Chat not found.'], 404);
            }
            $msgs = dm_messages($uid, $cid, max(0, $int('after')), 60);
            if ($msgs) {
                dm_mark_read($uid, $cid, (int) end($msgs)['id']);
            }
            json_response(['ok' => true, 'messages' => $msgs, 'seen' => dm_other_read_id($c), 'accepted' => $c['accepted_at'] !== null]);

        case 'older':
            $msgs = dm_messages($uid, $int('conversation_id'), 0, 40, max(1, $int('before')));
            json_response(['ok' => true, 'messages' => $msgs]);

        case 'accept':
            json_response(['ok' => dm_accept($uid, $int('conversation_id'))]);

        case 'clear':
            json_response(['ok' => dm_clear($uid, $int('conversation_id'))]);

        case 'block':
            $c = dm_conversation($int('conversation_id'), $uid);
            $other = $c ? $c['other_id'] : $int('user_id');
            $ok = $other > 0 && social_person($other) && set_user_block($uid, $other, empty($body['unblock']));
            json_response(['ok' => (bool) $ok], $ok ? 200 : 400);

        case 'report':
            $r = dm_report($uid, $int('conversation_id'), (string) ($body['reason'] ?? ''), !empty($body['block']));
            json_response($r, $r['ok'] ? 200 : 400);

        case 'inbox':
            $tab = ($body['tab'] ?? '') === 'requests' ? 'requests' : 'chats';
            json_response(['ok' => true, 'items' => array_map(static fn ($i) => $i + ['ago' => social_ago($i['at'])], dm_inbox($uid, $tab)), 'counts' => dm_unread_counts($uid)]);

        case 'unread':
            json_response(['ok' => true] + dm_unread_counts($uid));
    }
    json_response(['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('[social api] ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
