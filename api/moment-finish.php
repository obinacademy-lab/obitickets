<?php
// Finishes a background upload: "publish" posts a draft moment with its caption and options, "discard" throws it
// away. Only the person who uploaded the draft can do either (and only if they still manage the event).

require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);
$user = api_require_login();
if (!moments_draft_ready()) {
    json_response(['error' => 'Moments are not switched on yet.'], 503);
}

$id = (int) ($body['id'] ?? 0);
$action = (string) ($body['action'] ?? '');
try {
    if ($action === 'discard') {
        $r = discard_moment_draft($user, $id);
        json_response($r, $r['ok'] ? 200 : 404);
    }
    if ($action !== 'publish') {
        json_response(['error' => 'Unknown action.'], 400);
    }
    $opts = ['comments' => !empty($body['comments'])];
    if (array_key_exists('buy_bar', $body)) {
        $opts['buy_bar'] = !empty($body['buy_bar']);
    }
    if (!empty($body['sound_id'])) {
        $opts['sound_id'] = (int) $body['sound_id'];
        $opts['sound_start'] = (float) ($body['sound_start'] ?? 0);
        $opts['sound_mix'] = (int) ($body['sound_mix'] ?? 70);
        $opts['slide_beats'] = (int) ($body['slide_beats'] ?? 2);
        $opts['slide_fx'] = (string) ($body['slide_fx'] ?? 'ZOOM');
    }
    $r = finish_moment_draft($user, $id, (string) ($body['caption'] ?? ''), $opts);
    if (!$r['ok']) {
        json_response($r, 400);
    }
    $row = get_moment($id, (int) $user['id']);
    json_response(['ok' => true, 'id' => $id, 'moment' => $row ? moment_public($row) : null]);
} catch (Throwable $e) {
    error_log('[moments] finish: ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
