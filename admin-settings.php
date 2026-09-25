<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('settings.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!admin_can('settings.manage')) {
        // No named sub-role is granted settings.manage in this release — only
        // a NULL-admin_role (super admin) account can reach this point.
        http_response_code(403);
        exit('You do not have permission to change settings.');
    }
    foreach (['platform_name', 'support_email', 'default_currency'] as $key) {
        if (isset($_POST[$key])) {
            set_setting((int) $admin['id'], $key, trim((string) $_POST[$key]));
        }
    }
    header('Location: /admin-settings.php?updated=1');
    exit;
}

$settings = get_all_settings();

$pageTitle = 'Settings';
render_admin_head('settings');
?>

<div class="admin-page-head">
  <div><h1>General settings</h1><p>Platform-wide basics. Payment/ticketing rates stay in code — see the note below.</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div class="admin-card">
    <form method="post">
      <?= csrf_field() ?>
      <div class="admin-form-row"><label>Platform name</label><input type="text" name="platform_name" value="<?= htmlspecialchars($settings['platform_name'] ?? '') ?>"></div>
      <div class="admin-form-row"><label>Support email</label><input type="email" name="support_email" value="<?= htmlspecialchars($settings['support_email'] ?? '') ?>"></div>
      <div class="admin-form-row"><label>Default currency</label><input type="text" name="default_currency" value="<?= htmlspecialchars($settings['default_currency'] ?? '') ?>" maxlength="3" style="text-transform:uppercase"></div>
      <button class="btn btn-purple" type="submit">Save settings</button>
    </form>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:10px">Payment &amp; ticketing (read-only)</h3>
    <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:14px">These live as constants in <code>includes/payments.php</code> rather than here — every past order stores its own commission/fee amount, so changing the live rate needs its own careful pass rather than a quick settings edit.</p>
    <div class="admin-detail-row"><span class="k">Platform commission</span><span class="v mono"><?= number_format(PLATFORM_COMMISSION_RATE * 100, 0) ?>%</span></div>
    <div class="admin-detail-row"><span class="k">Buyer service fee</span><span class="v mono">UGX <?= number_format(SERVICE_FEE_PER_TICKET, 0) ?> / ticket</span></div>
    <div class="admin-detail-row"><span class="k">Payment gateway</span><span class="v">iotec (mobile money collection)</span></div>
  </div>
</div>

<?php render_admin_foot(); ?>
