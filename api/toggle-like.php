<?php
require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);

$eventId = (int) ($body['eventId'] ?? 0);
$event = $eventId > 0 ? get_event_by_id($eventId) : null;
if (!$event || $event['status'] !== 'PUBLISHED') {
    json_response(['error' => 'Invalid event.'], 404);
}

$user = current_user();
$userId = $user ? (int) $user['id'] : null;
$sessionToken = $userId ? null : session_id();

try {
    $result = toggle_event_like($eventId, $userId, $sessionToken);
    json_response($result);
} catch (Throwable $e) {
    json_response(['error' => 'Something went wrong.'], 400);
}
