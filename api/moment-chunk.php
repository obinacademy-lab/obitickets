<?php
// Big videos in pieces (11/18): "start" gives an upload id and the piece size, "part" appends the next piece,
// "cancel" throws the pieces away. The moment itself is then posted to /api/moment-upload.php with chunk_id.
// Only people who may post moments for that event can start one. All checks live in includes/moments.php.

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_response(['error' => 'That piece is too large for this server.'], 413);
}
$user = api_require_login();
if (!csrf_valid((string) ($_POST['csrf_token'] ?? ''))) {
    json_response(['error' => 'Invalid or expired session. Please refresh the page and try again.'], 403);
}
if (!moments_ready()) {
    json_response(['error' => 'Moments are not switched on yet.'], 503);
}
$uid = (int) $user['id'];

try {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'start':
            $event = get_event_by_id((int) ($_POST['event_id'] ?? 0));
            if (!$event || !can_moderate_event($user, $event)) {
                json_response(['error' => 'You can only post videos for events you manage.'], 403);
            }
            if (function_exists('socials_can_post_event') && !socials_can_post_event($event, $user)) {
                json_response(['error' => socials_paywall_message(), 'paywall' => true], 402);   // before any big upload
            }
            $duration = (float) ($_POST['duration'] ?? 0);
            if ($duration > MOMENT_MAX_SECONDS + 1) {
                json_response(['error' => 'Videos can be up to ' . (MOMENT_MAX_SECONDS / 60) . ' minutes long.'], 400);
            }
            $r = moment_chunk_start($uid, (int) ($_POST['size'] ?? 0), (string) ($_POST['type'] ?? ''));
            json_response($r, $r['ok'] ? 200 : 400);

        case 'part':
            $piece = $_FILES['chunk'] ?? null;
            if (!$piece || ($piece['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                json_response(['error' => "We couldn't receive that piece. Please try again."], 400);
            }
            $r = moment_chunk_add($uid, (string) ($_POST['id'] ?? ''), (int) ($_POST['offset'] ?? -1), (string) $piece['tmp_name'], (int) $piece['size']);
            json_response($r, $r['ok'] ? 200 : 409);

        case 'cancel':
            $dir = moment_chunk_dir($uid, (string) ($_POST['id'] ?? ''));
            if ($dir) {
                moment_chunk_remove($dir);
            }
            json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('[moments] chunk: ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
