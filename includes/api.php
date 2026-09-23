<?php
declare(strict_types=1);

/** Small shared helpers for the JSON endpoints under public/api/. */

/** Reads a JSON request body as an associative array. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Sends a JSON response and exits. */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** CSRF check for JSON API endpoints (token passed in the JSON body). */
function api_csrf_verify(array $body): void
{
    if (!csrf_valid((string) ($body['csrf_token'] ?? ''))) {
        json_response(['error' => 'Invalid or expired session. Please refresh the page and try again.'], 403);
    }
}

/** Requires login for a JSON API endpoint; sends a 401 JSON error otherwise. */
function api_require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'You must be logged in.'], 401);
    }
    return $user;
}
