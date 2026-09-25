<?php
declare(strict_types=1);

// config.php must load before session_start() — it defines APP_ENV, which
// the session cookie's "secure" flag below depends on.
require_once __DIR__ . '/../config/config.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => APP_ENV === 'production',
]);

// Baseline hardening headers on every response.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/contact.php';
