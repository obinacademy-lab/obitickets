<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (current_user()) {
    header('Location: /dashboard.php');
    exit;
}

$next = $_GET['next'] ?? $_POST['next'] ?? '/dashboard.php';
// Must be a same-site path: reject anything that isn't a single leading
// slash, since "//evil.com" or "/\evil.com" are parsed by browsers as a
// protocol-relative absolute URL — an open redirect straight off a login.
if (!is_string($next) || $next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
    $next = '/dashboard.php';
}

$errors = [];
$email = '';

// Messages left by /auth/google-callback.php (shown once).
$googleNotice = $_SESSION['google_notice'] ?? null;
if (!empty($_SESSION['google_error'])) {
    $errors[] = $_SESSION['google_error'];
}
unset($_SESSION['google_notice'], $_SESSION['google_error']);
if ($googleNotice && !empty($_SESSION['google_link_pending']['email'])) {
    $email = $_SESSION['google_link_pending']['email']; // prefill: it's the account they must log in to
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter your email and password.';
    } else {
        [$ok, $err, $needsTwoFactor] = attempt_login($email, $password);
        if ($ok) {
            google_link_pending_account($email); // connects Google if they just came from "Continue with Google"
            header('Location: ' . ($needsTwoFactor ? '/verify-2fa.php?next=' . urlencode($next) : $next));
            exit;
        }
        $errors[] = $err;
        ipguard_note('login_fail');
        ipguard_enforce();
    }
}

$pageTitle = 'Log in — obitickets';
$bodyClass = 'classic-page auth-ep';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/auth-event.css?v=<?= @filemtime(__DIR__ . '/assets/css/auth-event.css') ?: time() ?>">

<div class="ae ae-in">
  <div class="ae-hero">
    <div class="ae-wall" aria-hidden="true">
      <div class="ae-col"><img src="/assets/images/about/crowd.jpg" alt="" fetchpriority="high"><img src="/assets/images/about/about-friends.jpg" alt=""></div>
      <div class="ae-col"><img src="/assets/images/about/about-party.jpg" alt=""><img src="/assets/images/about/about-rugby.jpg" alt=""></div>
      <div class="ae-col"><img src="/assets/images/about/dancers.jpg" alt=""><img src="/assets/images/about/about-conference.jpg" alt=""></div>
    </div>
    <a class="ae-logo" href="/" aria-label="obitickets home">
      <svg class="logo-mark" viewBox="0 0 34 34" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="#DC2626"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="#DC2626" stroke="#fff" stroke-width="1.6"/></svg>
      obitickets
    </a>
  </div>

  <section class="ae-sheet" aria-labelledby="aeH">
    <h1 id="aeH">See what's on. Log in.</h1>

    <?php if ($googleNotice): ?>
      <div class="alert alert-success"><?= htmlspecialchars($googleNotice) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" placeholder="you@example.com" autocomplete="email" required>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <div class="ae-forgot"><a href="/forgot-password.php">Forgot password?</a></div>
      <div class="ae-go">
        <button class="ae-btn" type="submit">Log in</button>
        <?php render_google_button('login', $next); ?>
      </div>
    </form>

    <div class="ae-alt">
      <?php if (google_login_enabled()) { render_human_check(); } ?>
    </div>

    <p class="ae-switch">New here? <a href="/signup.php<?= $next !== '/dashboard.php' ? '?next=' . urlencode($next) : '' ?>">Create an account</a></p>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
