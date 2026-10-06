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
    // Photos for a slideshow arrive as photos[]; a sound is either an existing one (sound_id) or a new
    // upload (own_audio) together with the beat analysis the browser did.
    $opts = [
        'photos' => $_FILES['photos'] ?? null,
        'sound_id' => (int) ($_POST['sound_id'] ?? 0),
        'own_audio' => $_FILES['own_audio'] ?? null,
        'own_title' => (string) ($_POST['own_title'] ?? ''),
        'own_artist' => (string) ($_POST['own_artist'] ?? ''),
        'own_bpm' => (float) ($_POST['own_bpm'] ?? 100),
        'own_offset' => (float) ($_POST['own_offset'] ?? 0),
        'own_duration' => (int) ($_POST['own_duration'] ?? 0),
        'rights' => !empty($_POST['rights']),
        'sound_start' => (float) ($_POST['sound_start'] ?? 0),
        'sound_mix' => (int) ($_POST['sound_mix'] ?? 70),
        'slide_beats' => (int) ($_POST['slide_beats'] ?? 2),
        'slide_fx' => (string) ($_POST['slide_fx'] ?? 'ZOOM'),
        'promo' => !empty($_POST['promo']),
    ];
    if (isset($_POST['buy_bar'])) {
        $opts['buy_bar'] = !empty($_POST['buy_bar']);
    }
    $result = create_moment(
        $event,
        $user,
        $_FILES['file'] ?? [],
        $_FILES['poster'] ?? null,
        (string) ($_POST['caption'] ?? ''),
        (int) ($_POST['focus_x'] ?? 50),
        ($_POST['fit'] ?? '') === 'FIT' ? 'FIT' : 'FILL',
        $duration,
        true,
        $opts
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
