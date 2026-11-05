<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('admins.manage');

// Moves the site's secrets into a .env file WITHOUT any secret passing through a browser or a person: the server reads
// the current settings and writes the file itself. Nothing on this page ever shows a value, only whether each one matches.
$st = env_status();
$root = (string) ($st['root'] ?? dirname(__DIR__));
$target = env_target_file($root);
$legacyFile = $root . '/config/config.php';
$fromEnv = $st['source'] === 'env';
$error = null;

$envRead = env_read($root);
$envFileExists = $envRead['file'] !== null;
$legacyVals = is_file($legacyFile) ? env_legacy_values($legacyFile) : [];
$compare = ($envFileExists && $legacyVals) ? env_compare($envRead['values'], $legacyVals) : [];
$compareLegacyKeys = array_filter($compare, static fn ($s, $k) => array_key_exists($k, $legacyVals), ARRAY_FILTER_USE_BOTH);
$allSame = $compareLegacyKeys !== [] && count(array_filter($compareLegacyKeys, static fn ($s) => $s !== 'same')) === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if ($fromEnv || $envFileExists) {
            $error = 'A .env file already exists, so nothing was created.';
        } else {
            $values = [];
            foreach (array_merge(array_keys(ENV_DEFAULTS), ['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY', 'TRUSTED_IP_HEADER', 'SALES_NOTIFY_EMAIL', 'SUPPORT_WHATSAPP_NUMBER', 'IPGUARD_ALLOW']) as $k) {
                if (defined($k)) {
                    $values[$k] = (string) constant($k);
                }
            }
            $error = env_create_file($root, $values);
            if ($error === null) {
                log_admin_action((int) $admin['id'], 'env.create', 'settings', null, ['keys' => count($values)]);
                header('Location: /admin-env-setup.php?created=1');
                exit;
            }
        }
    } elseif ($action === 'set') {
        $key = (string) ($_POST['key'] ?? '');
        $error = $fromEnv ? env_set_value($root, $key, (string) ($_POST['value'] ?? '')) : 'The site is not running from the .env yet.';
        if ($error === null) {
            log_admin_action((int) $admin['id'], 'env.set', 'settings', null, ['key' => $key]);   // the name only, never the value
            header('Location: /admin-env-setup.php?set=' . urlencode($key));
            exit;
        }
    } elseif ($action === 'wipe') {
        if (!$fromEnv || !is_file($legacyFile) || !$allSame || empty($_POST['confirm'])) {
            $error = 'Not done: the site must be running from the .env, every value must match the old config.php, and the box must be ticked.';
        } elseif (@file_put_contents($legacyFile, "<?php\n// The site's settings and secrets now live in the .env file outside the web root (see includes/env.php).\n// Nothing secret is stored here any more.\n", LOCK_EX) === false) {
            $error = 'The old config.php could not be changed. Empty it by hand in File Manager.';
        } else {
            log_admin_action((int) $admin['id'], 'env.wipe_config', 'settings', null, null);
            header('Location: /admin-env-setup.php?wiped=1');
            exit;
        }
    }
}

$badge = ['same' => 'admin-badge-success', 'differs' => 'admin-badge-danger', 'only in .env' => 'admin-badge-muted', 'only in config.php' => 'admin-badge-danger'];
$pageTitle = 'Move secrets to .env';
render_admin_head('configcheck');
?>

<div class="admin-page-head">
  <div><h1>Move the secrets to a .env file</h1><p>The server writes the file itself, outside the public folder. Nothing secret is shown here or passes through your browser.</p></div>
</div>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success" style="margin-bottom:16px">The .env file was created and checked. The site switches to it on the next page load.</div><?php endif; ?>
<?php if (isset($_GET['wiped'])): ?><div class="alert alert-success" style="margin-bottom:16px">The old config.php no longer holds any secrets.</div><?php endif; ?>
<?php if (isset($_GET['set']) && in_array($_GET['set'], ENV_REPLACEABLE, true)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($_GET['set']) ?> was updated in the .env and is in use from the next page load.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="admin-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:12px">Where things stand</h3>
  <div style="display:grid; gap:10px">
    <div>Running from: <b><?= $fromEnv ? 'the .env file' : 'the old config.php' ?></b></div>
    <div>The .env belongs at: <span class="mono"><?= htmlspecialchars($target) ?></span> <span class="muted">(one folder above the website, so no web address reaches it)</span></div>
    <div>.env file: <b><?= $envFileExists ? 'exists' : 'not created yet' ?></b><?= $envFileExists && !$envRead['complete'] ? ' <span class="admin-badge admin-badge-danger">incomplete: ' . htmlspecialchars(implode(', ', $envRead['missing'])) . '</span>' : '' ?></div>
    <div>Old config.php: <b><?= is_file($legacyFile) ? ($legacyVals ? 'still holds the old values' : 'emptied') : 'gone' ?></b></div>
  </div>
</div>

<?php if (!$fromEnv && !$envFileExists): ?>
  <div class="admin-card" style="margin-bottom:20px">
    <h3>Step 1 &middot; Create the .env</h3>
    <p class="muted" style="margin:8px 0 14px">Copies every current setting into a new .env file, then reads it back to make sure it is complete and identical. If anything looks wrong it removes the file again and changes nothing.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><button class="btn btn-purple" type="submit">Create the .env file</button></form>
  </div>
<?php endif; ?>

<?php if ($compare): ?>
  <div class="admin-card" style="margin-bottom:20px">
    <h3 style="margin-bottom:12px">Does the .env match the old config.php?</h3>
    <div style="display:flex; flex-wrap:wrap; gap:8px">
      <?php foreach ($compare as $k => $s): ?>
        <span class="admin-badge <?= $badge[$s] ?? 'admin-badge-muted' ?>"><span class="mono"><?= htmlspecialchars($k) ?></span> &middot; <?= htmlspecialchars($s) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($fromEnv): ?>
  <div class="admin-card" style="margin-bottom:20px">
    <h3>Replace a key</h3>
    <p class="muted" style="margin:8px 0 14px">After you make a new key at the provider (for example a new Resend key), choose it here and paste it in. The server saves it into the .env and checks the file. The value is never shown again and never stored anywhere else.</p>
    <form method="post" autocomplete="off" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="set">
      <select name="key" required><?php foreach (ENV_REPLACEABLE as $k): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option><?php endforeach; ?></select>
      <input type="password" name="value" required autocomplete="new-password" placeholder="Paste the new value" style="min-width:260px; flex:1">
      <button class="btn btn-purple" type="submit">Save to .env</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($fromEnv && $legacyVals): ?>
  <div class="admin-card">
    <h3>Step 2 &middot; Remove the secrets from the old config.php</h3>
    <?php if ($allSame): ?>
      <p class="muted" style="margin:8px 0 14px">Every value in the .env matches the old file, so it is safe to erase the old copy. This empties config/config.php; the values stay in the .env.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="wipe">
        <label style="display:flex; gap:10px; align-items:center; margin-bottom:14px"><input type="checkbox" name="confirm" value="1"> I understand the old values will be erased from config.php</label>
        <button class="btn btn-purple" type="submit">Empty the old config.php</button></form>
    <?php else: ?>
      <p class="muted" style="margin-top:8px">Not offered yet: some values in the .env differ from the old file (see the list above). Fix the .env first.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
