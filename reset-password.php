<?php
require_once __DIR__ . '/includes/bootstrap.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$errors = [];
$done = false;

if (!is_string($token) || $token === '') {
    $pageTitle = 'Reset password — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap"><section class="auth-section"><div class="auth-card"><h1>Invalid link</h1><p class="sub">This password reset link is missing its token.</p><div class="auth-foot"><a href="/forgot-password.php">Request a new link</a></div></div></section></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

function find_valid_reset(string $token): ?array
{
    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$reset = find_valid_reset($token);

if (!$reset) {
    $pageTitle = 'Reset password — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap"><section class="auth-section"><div class="auth-card"><h1>Link expired</h1><p class="sub">This password reset link is invalid or has expired.</p><div class="auth-foot"><a href="/forgot-password.php">Request a new link</a></div></div></section></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $reset['user_id']]);
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
        $done = true;
    }
}

$pageTitle = 'Reset password — obitickets';
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

      <?php if ($done): ?>
        <h1>Password updated</h1>
        <p class="sub">You can now log in with your new password.</p>
        <a class="btn btn-purple btn-block btn-lg" href="/login.php" style="margin-top:24px">Go to log in</a>
      <?php else: ?>
        <h1>Choose a new password</h1>
        <p class="sub">Make it at least 8 characters.</p>

        <?php foreach ($errors as $e): ?>
          <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

          <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
          </div>
          <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
          </div>

          <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:24px">Update password</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
