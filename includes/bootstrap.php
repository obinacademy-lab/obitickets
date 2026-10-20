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

// No page takes an array-style query parameter, but bots and typos send them (?q[]=1). Left in place they
// became "Array to string conversion" warnings and the word "Array" in search boxes, so drop them up front.
foreach ($_GET as $getKey => $getValue) {
    if (is_array($getValue)) {
        unset($_GET[$getKey]);
    }
}

// Same for form posts: only these fields are ever arrays (ticket tiers, cart quantities, gallery removals,
// social links). An array sent to any other field (name[]=x) used to be a fatal error inside trim() on
// login, signup and every other form, so drop it before the page sees it.
$arrayPostFields = ['qty', 'tier_id', 'tier_name', 'tier_description', 'tier_price', 'tier_currency', 'tier_quantity', 'remove_media', 'link_url', 'link_on'];
foreach ($_POST as $postKey => $postValue) {
    if (is_array($postValue) && !in_array($postKey, $arrayPostFields, true)) {
        unset($_POST[$postKey]);
    }
}

// Baseline hardening headers on every response.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-auth.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/reviews.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/community.php';
require_once __DIR__ . '/community-render.php';
require_once __DIR__ . '/moments.php';
require_once __DIR__ . '/sounds.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/organizer.php';
require_once __DIR__ . '/organizer-layout.php';

track_site_visit();
