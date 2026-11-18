<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Log out. A click from inside obitickets (the browser says Sec-Fetch-Site: same-origin, or the Referer is this site)
// logs out at once, as before. Anything else (a link or image on another website, or a browser that sends neither)
// gets a "Log out?" button with the form token, so another site cannot log people out silently (11/18).
$sameSite = static function (): bool {
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '') {
        return $site === 'same-origin';
    }
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return $ref !== '' && $host !== '' && strcasecmp((string) parse_url($ref, PHP_URL_HOST), (string) parse_url('//' . $host, PHP_URL_HOST)) === 0;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    logout_user();
    header('Location: /');
    exit;
}
if (!current_user()) {
    header('Location: /');
    exit;
}
if ($sameSite()) {
    logout_user();
    header('Location: /');
    exit;
}

$pageTitle = 'Log out — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap">
  <section class="auth-section">
    <div class="auth-card">
      <h1>Log out?</h1>
      <p class="sub">Tap the button to log out of obitickets on this device.</p>
      <form method="post" action="/logout.php" style="margin-top:22px">
        <?= csrf_field() ?>
        <button class="btn btn-purple btn-block btn-lg" type="submit">Log out</button>
      </form>
      <a class="btn btn-line btn-block" href="/" style="margin-top:12px">Stay logged in</a>
    </div>
  </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
