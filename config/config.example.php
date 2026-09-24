<?php
// Copy this file to config.php and fill in real values. config.php itself
// is gitignored so real credentials/secrets never get committed.

date_default_timezone_set('Africa/Kampala');

define('APP_ENV', 'development'); // 'development' or 'production'
define('APP_URL', 'http://localhost:8010');

// Regenerate for every install, especially production:
//   php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', 'CHANGE_ME');

define('DB_HOST', 'localhost');
define('DB_NAME', 'obitickets');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- iotec Pay (mobile money — from the iotec dashboard, id.iotec.io) ------
// Sign up at https://iotec.io for a Pay wallet; client_id/client_secret are
// emailed to the address you registered with. Leave blank in development —
// checkout will show a clear "not configured" error instead of crashing.
define('IOTEC_CLIENT_ID', '');
define('IOTEC_CLIENT_SECRET', '');
// Use the TEST wallet ID while developing. Swap to your live wallet ID only
// once you're ready to accept real attendee payments.
define('IOTEC_WALLET_ID', '');

// --- Email (Resend — resend.com/api-keys) -----------------------------------
// Sign up at https://resend.com, verify your sending domain (add its DNS
// records at your domain's DNS provider), then create an API key.
define('RESEND_API_KEY', '');
define('EMAIL_FROM', 'obitickets <info@obitickets.site>');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}
