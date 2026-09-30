<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingUserId) {
    header('Location: /login.php');
    exit;
}

$stmt = db()->prepare('SELECT id, name, role, totp_secret FROM users WHERE id = ?');
$stmt->execute([$pendingUserId]);
$user = $stmt->fetch();
if (!$user || !$user['totp_secret']) {
    unset($_SESSION['pending_2fa_user_id']);
    header('Location: /login.php');
    exit;
}

$next = $_GET['next'] ?? $_POST['next'] ?? '/admin.php';
if (!is_string($next) || $next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
    $next = '/admin.php';
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // A blunt cap on guesses against this pending login — after this many
    // wrong codes, force all the way back to a fresh password check rather
    // than letting the 6-digit code be brute-forced at leisure.
    $_SESSION['two_factor_attempts'] = ($_SESSION['two_factor_attempts'] ?? 0) + 1;
    if ($_SESSION['two_factor_attempts'] > 8) {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['two_factor_attempts']);
        header('Location: /login.php?error=too_many_attempts');
        exit;
    }

    $code = trim((string) ($_POST['code'] ?? ''));
    $verified = totp_verify($user['totp_secret'], $code) || totp_verify_and_consume_recovery_code((int) $user['id'], $code);

    if ($verified) {
        finalize_login((int) $user['id'], $user['role']);
        header('Location: ' . $next);
        exit;
    }
    $error = 'Incorrect code. Please try again.';
}

$pageTitle = 'Verify it\'s you — obitickets';
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
      <h1>Two-factor verification</h1>
      <p class="sub">Hi <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>, enter the 6-digit code from your authenticator app.</p>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <div class="field">
          <label for="code">Authentication code</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required autofocus placeholder="123456">
        </div>
        <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:24px">Verify</button>
      </form>

      <div class="auth-foot">Lost your device? Enter one of your recovery codes above instead.</div>
      <div class="auth-foot"><a href="/login.php">Back to log in</a></div>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
