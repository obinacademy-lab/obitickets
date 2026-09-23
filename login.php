<?php
require_once __DIR__ . '/includes/bootstrap.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter your email and password.';
    } else {
        [$ok, $err] = attempt_login($email, $password);
        if ($ok) {
            header('Location: ' . $next);
            exit;
        }
        $errors[] = $err;
    }
}

$pageTitle = 'Log in — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="auth-section">
    <div class="auth-card reveal">
      <a class="logo" href="/">
        <svg class="logo-mark" viewBox="0 0 34 34"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/></svg>
        obitickets
      </a>
      <h1>Welcome back</h1>
      <p class="sub">Log in to manage your tickets or your events.</p>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:24px">Log in</button>
      </form>

      <div class="auth-foot"><a href="/forgot-password.php">Forgot your password?</a></div>
      <div class="auth-foot">Don't have an account? <a href="/signup.php">Sign up</a></div>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
