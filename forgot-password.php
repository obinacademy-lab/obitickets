<?php
require_once __DIR__ . '/includes/bootstrap.php';

$errors = [];
$sent = false;
$devResetLink = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $stmt = db()->prepare('SELECT id, name FROM users WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $user = $stmt->fetch();

        // Always show the same success message whether or not the account
        // exists, so this form can't be used to find out who has an account.
        if ($user) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            // expires_at is computed by MySQL itself (NOW() + INTERVAL), not
            // PHP, so this can never drift out of sync with the NOW() check
            // in find_valid_reset() below — even if PHP's and MySQL's clocks
            // or configured timezones ever disagree.
            $stmt = db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)');
            $stmt->execute([$user['id'], $tokenHash]);

            $resetLink = APP_URL . '/reset-password.php?token=' . $rawToken;
            send_email(
                $email,
                'Reset your obitickets password',
                "Hi {$user['name']},\n\nReset your password here (expires in 1 hour):\n{$resetLink}\n\nIf you didn't request this, you can ignore this email."
            );

            if (APP_ENV === 'development') {
                $devResetLink = $resetLink;
            }
        }

        $sent = true;
    }
}

$pageTitle = 'Forgot password — obitickets';
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
      <h1>Reset your password</h1>
      <p class="sub">Enter your email and we'll send you a reset link.</p>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <?php if ($sent): ?>
        <div class="alert alert-success">If an account exists for that email, a reset link is on its way.</div>
        <?php if ($devResetLink): ?>
          <div class="alert alert-success" style="margin-top:10px">
            <strong>Dev mode</strong> &mdash; no email provider is wired up yet, so here's the link directly:<br>
            <a href="<?= htmlspecialchars($devResetLink) ?>"><?= htmlspecialchars($devResetLink) ?></a>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
          </div>
          <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:24px">Send reset link</button>
        </form>
      <?php endif; ?>

      <div class="auth-foot"><a href="/login.php">Back to log in</a></div>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
