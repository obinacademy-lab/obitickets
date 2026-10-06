<?php
// Step 2: Google sends the browser back here with a one-time code. Verify it
// is the sign-in we started, trade it for the person's verified Google
// identity, then log them in (or create their account).
require __DIR__ . '/../includes/bootstrap.php';

$oauth = $_SESSION['google_oauth'] ?? null;
$mode = ($oauth['mode'] ?? 'login') === 'signup' ? 'signup' : 'login';

/** Back to the page they started from, with a message to show there. */
function google_fail(string $message, string $mode): never
{
    unset($_SESSION['google_oauth']);
    $_SESSION['google_error'] = $message;
    header('Location: /' . $mode . '.php');
    exit;
}

if (!google_login_enabled()) {
    google_fail('Google sign-in isn\'t available right now. Please use your email and password.', $mode);
}
if (isset($_GET['error'])) { // they pressed "Cancel" on Google's screen, or Google refused
    google_fail('Google sign-in was cancelled.', $mode);
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
if (!$oauth || $code === '' || $state === '' || !hash_equals($oauth['state'], $state) || time() - (int) $oauth['started'] > GOOGLE_STATE_TTL) {
    google_fail('That Google sign-in expired. Please try again.', $mode);
}

try {
    $claims = google_exchange_code($code, $oauth);
    $result = google_resolve_user($claims, $oauth['role']);
} catch (Throwable $e) {
    error_log('[google] sign-in failed: ' . $e->getMessage());
    google_fail('We couldn\'t complete Google sign-in. Please try again, or use your email and password.', $mode);
}

if ($result['status'] === 'error') {
    google_fail($result['message'], $mode);
}

if ($result['status'] === 'needs_password_link') {
    unset($_SESSION['google_oauth']);
    $_SESSION['google_notice'] = 'You already have an obitickets account for ' . $result['email'] . '. Log in with your password once to connect Google — after that, "Continue with Google" works.';
    header('Location: /login.php' . ($oauth['next'] !== '/dashboard.php' ? '?next=' . urlencode($oauth['next']) : ''));
    exit;
}

google_sign_in($result['user_id'], $result['role'], $result['created']);
header('Location: ' . $oauth['next']);
exit;
