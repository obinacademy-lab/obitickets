<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('audit.view');

$st = env_status();
$fromEnv = $st['source'] === 'env';
$legacyPath = rtrim((string) ($st['root'] ?? dirname(__DIR__)), '/\\') . '/config/config.php';   // the project folder is dev's root and the live public_html alike
$legacyFile = is_file($legacyPath);
$isSet = static fn (string $k): bool => defined($k) && (string) constant($k) !== '';
$inEnv = array_flip($st['from_env'] ?? []);

// Every setting the site uses: label, what it is for. Values are NEVER shown, only set / empty.
$rows = [
    ['APP_ENV', 'Mode', 'production on the live site'],
    ['APP_URL', 'Website address', 'used in emails and links'],
    ['APP_SECRET', 'Site secret', 'signs links; must be a long random value'],
    ['DB_HOST', 'Database server', ''], ['DB_NAME', 'Database name', ''], ['DB_USER', 'Database user', ''], ['DB_PASS', 'Database password', ''],
    ['IOTEC_CLIENT_ID', 'ioTec Pay client ID', 'mobile money'], ['IOTEC_CLIENT_SECRET', 'ioTec Pay client secret', 'mobile money'], ['IOTEC_WALLET_ID', 'ioTec wallet', 'mobile money'],
    ['IOTEC_MSG_CLIENT_ID', 'ioTec SMS client ID', 'text messages'], ['IOTEC_MSG_API_KEY', 'ioTec SMS key', 'text messages'],
    ['RESEND_API_KEY', 'Resend email key', 'tickets and receipts by email'], ['EMAIL_FROM', 'Email sender name', ''],
    ['GOOGLE_CLIENT_ID', 'Google sign-in ID', 'optional'], ['GOOGLE_CLIENT_SECRET', 'Google sign-in secret', 'optional'],
    ['TURNSTILE_SITE_KEY', 'Bot check site key', 'optional'], ['TURNSTILE_SECRET_KEY', 'Bot check secret', 'optional'],
];
$secretLen = defined('APP_SECRET') ? strlen((string) APP_SECRET) : 0;
$legacyHoldsSecrets = $legacyFile && env_legacy_values($legacyPath) !== [];
$checks = [
    [$fromEnv, 'Settings are read from the .env file', $fromEnv ? '.env found ' . (realpath(dirname((string) $st['file'])) === realpath((string) ($st['root'] ?? dirname(__DIR__))) ? 'INSIDE the website folder. On the live server move it one folder up, next to public_html.' : 'outside the website folder (good).') : 'Still using the old config.php' . (!empty($st['missing']) && $st['file'] ? ' because the .env is missing: ' . implode(', ', $st['missing']) : ($st['file'] ? '' : ' (no .env found)'))],
    [$fromEnv && !$legacyHoldsSecrets, 'The old config.php no longer holds secrets', !$legacyFile ? 'Not present' : ($legacyHoldsSecrets ? ($fromEnv ? 'config/config.php still holds the old values. Empty it from the "Move secrets to .env" page now that the .env works.' : 'It is still the active source until the .env is complete.') : 'Emptied')],
    [APP_ENV === 'production', 'APP_ENV is production', 'It is ' . APP_ENV],
    [$secretLen >= 32, 'APP_SECRET is long enough (32+ characters)', $secretLen . ' characters'],
    [ipguard_ready(), 'IP blocker is switched on', ipguard_ready() ? 'Migration 027 applied' : 'Run migration/027_ip_guard.sql in phpMyAdmin'],
    [turnstile_enabled(), 'Bot check keys are set', turnstile_enabled() ? 'On' : 'Off (optional)'],
];

$pageTitle = 'Settings check';
render_admin_head('configcheck');
?>

<div class="admin-page-head">
  <div><h1>Settings check</h1><p>Where each setting comes from. Values are never shown here, only whether each one is set.</p></div>
  <?php if (admin_can('admins.manage')): ?><a class="btn btn-line" href="/admin-env-setup.php">Move secrets to .env &rarr;</a><?php endif; ?>
</div>

<div class="admin-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:12px">Health</h3>
  <?php foreach ($checks as [$ok, $label, $detail]): ?>
    <div style="display:flex; gap:12px; align-items:flex-start; padding:9px 0; border-top:1px solid var(--line)">
      <span class="admin-badge <?= $ok ? 'admin-badge-success' : 'admin-badge-danger' ?>" style="flex:none"><?= $ok ? 'OK' : 'Fix' ?></span>
      <div><b><?= htmlspecialchars($label) ?></b><br><span class="muted" style="font-size:.85rem"><?= htmlspecialchars($detail) ?></span></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="admin-table-wrap"><table class="admin-table">
  <thead><tr><th>Setting</th><th>What it is</th><th>Status</th><th>Comes from</th></tr></thead>
  <tbody>
  <?php foreach ($rows as [$key, $label, $note]): $set = $isSet($key); ?>
    <tr>
      <td class="mono"><?= htmlspecialchars($key) ?></td>
      <td><?= htmlspecialchars($label) ?><?= $note !== '' ? '<br><span class="muted" style="font-size:.78rem">' . htmlspecialchars($note) . '</span>' : '' ?></td>
      <td><span class="admin-badge <?= $set ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= $set ? 'Set' : 'Empty' ?></span></td>
      <td class="muted"><?= !$set ? '—' : (isset($inEnv[$key]) ? '.env' : ($fromEnv ? 'default' : 'config.php')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php render_admin_foot(); ?>
