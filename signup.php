<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    header('Location: /dashboard.php');
    exit;
}

$errors = [];
$name = $email = $phone = '';
$role = 'ATTENDEE';

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
    }

    if (!$errors) {
        [$ok, $err] = register_user($name, $email, $password, $role, $phone !== '' ? $phone : null);
        if ($ok) {
            header('Location: /dashboard.php');
            exit;
        }
        $errors[] = $err;
    }
}

$pageTitle = 'Sign up — obitickets';
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
      <h1>Create your account</h1>
      <p class="sub">Buy tickets or start selling &mdash; it only takes a minute.</p>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="field">
          <label for="name">Full name</label>
          <input id="name" name="name" type="text" value="<?= htmlspecialchars($name) ?>" autocomplete="name" required>
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
        </div>

        <div class="field">
          <label for="phone">Phone (optional)</label>
          <input id="phone" name="phone" type="tel" value="<?= htmlspecialchars($phone) ?>" placeholder="+256 7xx xxx xxx" autocomplete="tel">
        </div>

        <div class="field">
          <label for="role">I want to</label>
          <select id="role" name="role">
            <option value="ATTENDEE" <?= $role === 'ATTENDEE' ? 'selected' : '' ?>>Attend events</option>
            <option value="ORGANIZER" <?= $role === 'ORGANIZER' ? 'selected' : '' ?>>Sell tickets for my events</option>
          </select>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
          <div class="field-hint">At least 8 characters.</div>
        </div>

        <div class="field">
          <label for="confirm_password">Confirm password</label>
          <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
        </div>

        <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:24px">Create account</button>
      </form>

      <div class="auth-foot">Already have an account? <a href="/login.php">Log in</a></div>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
