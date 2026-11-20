<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Where to send the new account afterwards — a buyer who came here from the
// checkout login wall must land back in checkout (the cart survives in the
// session), not on an empty "My tickets" page.
$next = safe_next_path($_GET['next'] ?? $_POST['next'] ?? null);

if (current_user()) {
    header('Location: ' . $next);
    exit;
}

$errors = [];
if (!empty($_SESSION['google_error'])) {
    $errors[] = $_SESSION['google_error'];
}
unset($_SESSION['google_error']);
$name = $email = $phone = '';
$role = ($_GET['role'] ?? '') === 'ORGANIZER' ? 'ORGANIZER' : 'ATTENDEE';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'ORGANIZER' ? 'ORGANIZER' : 'ATTENDEE';

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Please fill in your name, email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Please use a shorter name (100 characters or fewer).';
    } elseif ($phone !== '' && !preg_match('/^\+?[0-9][0-9 ()\-]{7,19}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number, for example +256 7xx xxx xxx, or leave it blank.';
    }

    if (!$errors && !human_ok()) {
        $errors[] = 'Please wait a moment for the quick security check to finish, then press the button again. If this keeps happening, turn off any ad or tracking blocker for this page, or try another browser.';
    }

    if (!$errors) {
        [$ok, $err] = register_user($name, $email, $password, $role, $phone !== '' ? $phone : null);
        if ($ok) {
            header('Location: ' . $next);
            exit;
        }
        $errors[] = $err;
    }
    if ($errors) {
        ipguard_note('signup_fail');
        ipguard_enforce();
    }
}

$pageTitle = 'Sign up — obitickets';
$bodyClass = 'classic-page auth-ep';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/auth-event.css?v=<?= @filemtime(__DIR__ . '/assets/css/auth-event.css') ?: time() ?>">

<div class="ae ae-up">
  <div class="ae-hero">
    <img class="ae-photo" src="/assets/images/about/dancers.jpg" alt="" fetchpriority="high">
    <a class="ae-logo" href="/" aria-label="obitickets home">
      <svg class="logo-mark" viewBox="0 0 34 34" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="#DC2626"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="#DC2626" stroke="#fff" stroke-width="1.6"/></svg>
      obitickets
    </a>
    <div class="ae-head">
      <p class="ae-tag">Join the crowd.</p>
      <h1 id="aeH">Create your account</h1>
    </div>
  </div>

  <section class="ae-sheet" aria-labelledby="aeH">

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

      <div class="ae-role" role="radiogroup" aria-label="I want to">
        <label><input type="radio" name="role" value="ATTENDEE" <?= $role === 'ATTENDEE' ? 'checked' : '' ?>><span>Attend events</span></label>
        <label><input type="radio" name="role" value="ORGANIZER" <?= $role === 'ORGANIZER' ? 'checked' : '' ?>><span>Sell tickets</span></label>
      </div>

      <div class="field">
        <label for="name">Full name</label>
        <input id="name" name="name" type="text" value="<?= htmlspecialchars($name) ?>" placeholder="Your name" autocomplete="name" required>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" placeholder="you@example.com" autocomplete="email" required>
      </div>
      <div class="field">
        <label for="phone">Phone <span class="ae-opt">(optional)</span></label>
        <input id="phone" name="phone" type="tel" value="<?= htmlspecialchars($phone) ?>" placeholder="07XX XXX XXX" autocomplete="tel">
      </div>
      <div class="ae-pair">
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="pwHint">
        </div>
        <div class="field">
          <label for="confirm_password">Confirm</label>
          <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
        </div>
      </div>
      <p class="ae-hint" id="pwHint">At least 8 characters.</p>

      <button class="ae-btn" type="submit">Create account</button>
    </form>

    <div class="ae-alt">
      <?php render_human_check(); ?>
      <?php render_google_button('signup', $next, $role); ?>
    </div>

    <p class="ae-switch">Have an account? <a href="/login.php<?= $next !== '/dashboard.php' ? '?next=' . urlencode($next) : '' ?>">Log in</a></p>
    <p class="ae-terms">By signing up you agree to our <a href="/terms.php">Terms of Use</a> and <a href="/privacy.php">Privacy Policy</a>.</p>
  </section>
</div>

<script>
// Keep the Google button's role in step with the "I want to" choice.
(function () {
  var btn = document.getElementById('googleBtn'), radios = document.querySelectorAll('input[name="role"]');
  if (!btn || !radios.length) return;
  function sync() {
    var on = document.querySelector('input[name="role"]:checked');
    var u = new URL(btn.getAttribute('href'), location.origin);
    if (on && on.value === 'ORGANIZER') u.searchParams.set('role', 'ORGANIZER'); else u.searchParams.delete('role');
    btn.setAttribute('href', u.pathname + u.search);
  }
  radios.forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
