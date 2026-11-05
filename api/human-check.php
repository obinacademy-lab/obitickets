<?php
// Reports a passed Turnstile check: verifies the token with Cloudflare and remembers it in the session (includes/turnstile.php).
require __DIR__ . '/../includes/bootstrap.php';

$body = json_body();
api_csrf_verify($body);

if (!turnstile_enabled()) {
    json_response(['ok' => true]);
}
if (!turnstile_verify((string) ($body['token'] ?? ''))) {
    ipguard_note('human_fail');
    json_response(['error' => 'The security check did not pass. Please refresh the page and try again.', 'codes' => $GLOBALS['turnstile_codes'] ?? []], 400);
}
human_mark_passed();
json_response(['ok' => true]);
