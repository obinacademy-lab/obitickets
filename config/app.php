<?php
declare(strict_types=1);

// Loads the site's settings. This file holds NO secrets: they come from the .env file (see includes/env.php).
// It must run before session_start() in bootstrap.php, because APP_ENV decides the session cookie's "secure" flag.

date_default_timezone_set('Africa/Kampala');

require_once __DIR__ . '/../includes/env.php';
$GLOBALS['env_status'] = env_boot(dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');

if (APP_ENV === 'production' && strlen((string) APP_SECRET) < 32) {
    error_log('[config] APP_SECRET is shorter than 32 characters; use: php -r "echo bin2hex(random_bytes(32));"');
}
