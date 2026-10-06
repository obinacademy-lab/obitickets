<?php
// Step 1 of "Continue with Google": remember who is asking (login vs sign-up,
// where to go afterwards) and send the browser to Google's account chooser.
require __DIR__ . '/../includes/bootstrap.php';

$mode = ($_GET['mode'] ?? '') === 'signup' ? 'signup' : 'login';
$next = safe_next_path($_GET['next'] ?? null);

if (current_user()) {
    header('Location: ' . $next);
    exit;
}

if (!google_login_enabled()) {
    $_SESSION['google_error'] = 'Google sign-in isn\'t available right now. Please use your email and password.';
    header('Location: /' . $mode . '.php');
    exit;
}

header('Location: ' . google_begin($mode, $next, (string) ($_GET['role'] ?? 'ATTENDEE')));
exit;
