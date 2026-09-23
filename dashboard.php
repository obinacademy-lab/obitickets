<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

// Every role now has a real landing page, so dashboard.php is just a router.
if ($user['role'] === 'ADMIN') {
    header('Location: /admin.php');
} elseif ($user['role'] === 'ORGANIZER') {
    header('Location: /my-events.php');
} else {
    header('Location: /my-tickets.php');
}
exit;
