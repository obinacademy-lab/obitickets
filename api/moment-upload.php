<?php
// Receives one moment (a 9:16 photo or video) from the organizer's Event Social page.
// Multipart on purpose: the browser sends the file with a progress bar, plus a small
// cover frame it captured from the video. All checks live in includes/moments.php.

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
// A request bigger than post_max_size arrives with $_POST and $_FILES both empty.
if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_response(['error' => 'That file is too large for this server. Videos can be up to ' . round(moment_video_max_bytes() / 1048576) . ' MB.'], 413);
}

$user = api_require_login();
if (!csrf_valid((string) ($_POST['csrf_token'] ?? ''))) {
    json_response(['error' => 'Invalid or expired session. Please refresh the page and try again.'], 403);
}
if (!moments_ready()) {
    json_response(['error' => 'Moments are not switched on yet.'], 503);
}

try {
    $event = get_event_by_id((int) ($_POST['event_id'] ?? 0));
    if (!$event) {
        json_response(['error' => 'Event not found.'], 404);
    }
    $duration = isset($_POST['duration']) && $_POST['duration'] !== '' ? max(0, (int) round((float) $_POST['duration'])) : null;
    $result = create_moment(
        $event,
        $user,
        $_FILES['file'] ?? [],
        $_FILES['poster'] ?? null,
        (string) ($_POST['caption'] ?? ''),
        (int) ($_POST['focus_x'] ?? 50),
        ($_POST['fit'] ?? '') === 'FIT' ? 'FIT' : 'FILL',
        $duration
    );
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 400);
    }
    $row = get_moment($result['id'], (int) $user['id']);
    json_response(['ok' => true, 'id' => $result['id'], 'moment' => $row ? moment_public($row) : null]);
} catch (Throwable $e) {
    error_log('[moments] upload: ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
