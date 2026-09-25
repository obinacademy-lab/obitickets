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

<div class="admin-kpi-grid" style="margin-bottom:20px">
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Platform commission</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-shield"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) round(PLATFORM_COMMISSION_RATE * 100) ?>">0</span>%</span>
    <span class="sub">Of every paid order</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Buyer service fee</span>
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span>
    </div>
    <span class="num"><span data-count-to="<?= (int) SERVICE_FEE_PER_TICKET ?>" data-count-prefix="UGX ">0</span></span>
    <span class="sub">Per ticket</span>
  </div>
  <div class="admin-kpi-card">
    <div class="admin-kpi-card-top">
      <span class="lbl">Payment gateway</span>
      <span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-check"/></svg></span>
    </div>
    <span class="num" style="font-size:1.15rem; font-family:inherit; font-weight:800">iotec</span>
    <span class="sub">Mobile money collection</span>
  </div>
</div>

<div class="admin-grid-2">
  <div class="admin-card">
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px">
      <span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-settings"/></svg></span>
      <h3 style="margin:0">Platform basics</h3>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <div class="admin-form-row"><label>Platform name</label><input type="text" name="platform_name" value="<?= htmlspecialchars($settings['platform_name'] ?? '') ?>"></div>
      <div class="admin-form-row"><label>Support email</label><input type="email" name="support_email" value="<?= htmlspecialchars($settings['support_email'] ?? '') ?>"></div>
      <div class="admin-form-row"><label>Default currency</label><input type="text" name="default_currency" value="<?= htmlspecialchars($settings['default_currency'] ?? '') ?>" maxlength="3" style="text-transform:uppercase"></div>
      <button class="btn btn-purple" type="submit">Save settings</button>
    </form>
  </div>

  <div class="admin-card">
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px">
      <span class="admin-kpi-ic" style="background:var(--warn-bg); color:var(--warn)"><svg width="17" height="17"><use href="#ic-info"/></svg></span>
      <h3 style="margin:0">Why rates aren't editable here</h3>
    </div>
    <p style="color:var(--muted-2); font-size:0.82rem; margin:10px 0 0">These live as constants in <code>includes/payments.php</code> rather than here — every past order stores its own commission/fee amount, so changing the live rate needs its own careful pass rather than a quick settings edit.</p>
  </div>
</div>

<?php render_admin_foot(); ?>
