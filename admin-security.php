<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('dashboard.view');

// Re-read fresh from the DB on every load — current_user()'s result is
// cached for the request and admin.php etc never select these columns, so
// $admin here could otherwise be stale right after a POST changes them.
$stmt = db()->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
$stmt->execute([$admin['id']]);
$totp = $stmt->fetch();

$error = null;
$freshCodes = $_SESSION['fresh_recovery_codes'] ?? null;
unset($_SESSION['fresh_recovery_codes']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'start_setup') {
        $secret = totp_generate_secret();
        db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 0, totp_recovery_codes = NULL WHERE id = ?')
            ->execute([$secret, $admin['id']]);
        header('Location: /admin-security.php');
        exit;
    }

    if ($action === 'confirm_setup') {
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($totp['totp_secret'] && totp_verify($totp['totp_secret'], $code)) {
            $codes = totp_generate_recovery_codes();
            db()->prepare('UPDATE users SET totp_enabled = 1, totp_recovery_codes = ? WHERE id = ?')
                ->execute([totp_hash_recovery_codes($codes), $admin['id']]);
            log_admin_action((int) $admin['id'], 'admin.2fa_enable', 'user', (int) $admin['id']);
            $_SESSION['fresh_recovery_codes'] = $codes;
            header('Location: /admin-security.php');
            exit;
        }
        $error = 'That code didn\'t match. Make sure your authenticator app is showing the current code and try again.';
    } elseif ($action === 'disable') {
        db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL WHERE id = ?')
            ->execute([$admin['id']]);
        log_admin_action((int) $admin['id'], 'admin.2fa_disable', 'user', (int) $admin['id']);
        header('Location: /admin-security.php?updated=1');
        exit;
    } elseif ($action === 'regenerate_codes' && (int) $totp['totp_enabled'] === 1) {
        $codes = totp_generate_recovery_codes();
        db()->prepare('UPDATE users SET totp_recovery_codes = ? WHERE id = ?')
            ->execute([totp_hash_recovery_codes($codes), $admin['id']]);
        log_admin_action((int) $admin['id'], 'admin.2fa_regenerate_codes', 'user', (int) $admin['id']);
        $_SESSION['fresh_recovery_codes'] = $codes;
        header('Location: /admin-security.php');
        exit;
    }

    // Re-fetch — a code was rejected above (setup not yet confirmed), so the
    // page below still needs $totp to reflect the current, unconfirmed state.
    $stmt->execute([$admin['id']]);
    $totp = $stmt->fetch();
}

$provisioningUri = $totp['totp_secret'] ? totp_provisioning_uri($totp['totp_secret'], $admin['email']) : null;
$qrUrl = $provisioningUri ? 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query(['size' => '220x220', 'margin' => '0', 'data' => $provisioningUri]) : null;

$pageTitle = 'Two-Factor Authentication';
render_admin_head('2fa');
?>

<div class="admin-page-head">
  <div><h1>Two-factor authentication</h1><p>Protects your own admin login with a code from your phone, on top of your password.</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Two-factor authentication turned off." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if ($freshCodes): ?>
<div class="admin-card" style="max-width:560px; border-color:var(--warn); margin-bottom:20px">
  <h3 style="margin-bottom:4px">Save your recovery codes now</h3>
  <p style="color:var(--muted-2); font-size:0.86rem; margin-bottom:16px">Each code works once, and gets you back in if you ever lose access to your authenticator app. This is the only time they'll be shown — store them somewhere safe.</p>
  <div class="mono" style="background:var(--paper); border:1px solid var(--line); border-radius:12px; padding:16px 20px; font-size:0.98rem; line-height:2; letter-spacing:0.03em;">
    <?php foreach ($freshCodes as $code): ?><?= htmlspecialchars($code) ?><br><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="admin-card" style="max-width:560px">
  <?php if ((int) $totp['totp_enabled'] === 1): ?>
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px">
      <span class="admin-badge admin-badge-success"><span class="admin-badge-dot"></span>Enabled</span>
    </div>
    <p style="color:var(--muted-2); font-size:0.86rem; margin:10px 0 20px">Two-factor authentication is protecting your account. You'll need a code from your authenticator app every time you log in.</p>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <form method="post" data-confirm="Generate new recovery codes? Your old codes will stop working.">
        <?= csrf_field() ?><input type="hidden" name="action" value="regenerate_codes">
        <button class="btn btn-line" type="submit">Regenerate recovery codes</button>
      </form>
      <form method="post" data-confirm="Turn off two-factor authentication for your account?" data-danger>
        <?= csrf_field() ?><input type="hidden" name="action" value="disable">
        <button class="btn btn-line" type="submit" style="color:var(--danger); border-color:var(--danger)">Disable</button>
      </form>
    </div>

  <?php elseif ($totp['totp_secret']): ?>
    <h3 style="margin-bottom:16px">Scan this with your authenticator app</h3>
    <img src="<?= htmlspecialchars($qrUrl) ?>" width="220" height="220" alt="Two-factor setup QR code" style="border-radius:12px; border:1px solid var(--line);">
    <p style="color:var(--muted-2); font-size:0.8rem; margin:14px 0 4px">Can't scan it? Enter this code manually:</p>
    <p class="mono" style="font-size:0.98rem; letter-spacing:0.06em; background:var(--paper); border-radius:8px; padding:10px 14px; display:inline-block;"><?= htmlspecialchars($totp['totp_secret']) ?></p>
    <form method="post" style="margin-top:20px; max-width:260px">
      <?= csrf_field() ?><input type="hidden" name="action" value="confirm_setup">
      <div class="admin-form-row"><label>Enter the 6-digit code to confirm</label><input type="text" name="code" inputmode="numeric" maxlength="6" required autofocus placeholder="123456"></div>
      <button class="btn btn-purple" type="submit" style="margin-top:6px">Turn on two-factor authentication</button>
    </form>

  <?php else: ?>
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px">
      <span class="admin-badge admin-badge-muted">Not enabled</span>
    </div>
    <p style="color:var(--muted-2); font-size:0.86rem; margin:10px 0 20px">Add a second step to your login using any authenticator app (Google Authenticator, Authy, 1Password, etc).</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="start_setup">
      <button class="btn btn-purple" type="submit">Set up two-factor authentication</button>
    </form>
  <?php endif; ?>
</div>

<?php render_admin_foot(); ?>
