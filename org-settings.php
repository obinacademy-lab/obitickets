<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('dashboard.view');
$organizerId = $ctx['organizer_id'];
$actor = current_user();
$error = null;
$passwordError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'profile') {
        if (!$ctx['is_owner']) {
            http_response_code(403);
            exit('Only the account owner can change organization settings.');
        }
        update_organizer_profile($organizerId, [
            'name' => trim((string) $_POST['name']),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'org_name' => trim((string) $_POST['org_name']),
            'bio' => trim((string) ($_POST['bio'] ?? '')),
            'payout_provider' => in_array($_POST['payout_provider'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY', 'BANK'], true) ? $_POST['payout_provider'] : 'MTN_MOMO',
            'payout_phone' => trim((string) ($_POST['payout_phone'] ?? '')),
        ]);
        header('Location: /org-settings.php?updated=1');
        exit;
    } elseif ($action === 'password') {
        [$ok, $err] = change_own_password((int) $actor['id'], (string) $_POST['current_password'], (string) $_POST['new_password']);
        if (!$ok) {
            $passwordError = $err;
        } else {
            header('Location: /org-settings.php?password_updated=1');
            exit;
        }
    }
}

$stmt = db()->prepare('SELECT * FROM organizer_profiles WHERE user_id = ?');
$stmt->execute([$organizerId]);
$profile = $stmt->fetch() ?: [];
$stmt = db()->prepare('SELECT name, email, phone FROM users WHERE id = ?');
$stmt->execute([$organizerId]);
$ownerUser = $stmt->fetch();

$pageTitle = 'Settings';
render_organizer_head('settings', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Settings</h1><p>Manage your profile, organization and security</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if (isset($_GET['password_updated'])): ?><span data-flash="Password changed." hidden></span><?php endif; ?>
<?php if ($passwordError): ?><span data-flash="<?= htmlspecialchars($passwordError, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$ctx['is_owner']): ?>
  <div class="admin-card" style="margin-bottom:20px; border-color:var(--warn-border); background:var(--warn-bg)">
    <p style="margin:0; font-size:0.86rem; color:var(--warn)">You're a team member on this account — organization and payment settings can only be changed by the owner. You can still change your own password below.</p>
  </div>
<?php endif; ?>

<div class="admin-grid-2">
  <div class="admin-card">
    <h3 style="margin-bottom:14px">Profile &amp; organization</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="admin-form-grid">
        <div class="admin-form-row"><label>Your name</label><input type="text" name="name" value="<?= htmlspecialchars($ownerUser['name']) ?>" required <?= $ctx['is_owner'] ? '' : 'disabled' ?>></div>
        <div class="admin-form-row"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($ownerUser['phone'] ?? '') ?>" <?= $ctx['is_owner'] ? '' : 'disabled' ?>></div>
      </div>
      <div class="admin-form-row"><label>Organization name</label><input type="text" name="org_name" value="<?= htmlspecialchars($profile['org_name'] ?? '') ?>" required <?= $ctx['is_owner'] ? '' : 'disabled' ?>></div>
      <div class="admin-form-row"><label>About your organization</label><textarea name="bio" rows="3" style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:10px; font-family:inherit; font-size:0.9rem; resize:vertical;" <?= $ctx['is_owner'] ? '' : 'disabled' ?>><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea></div>
      <h4 style="margin:20px 0 10px; font-size:0.88rem">Payout method</h4>
      <div class="admin-form-grid">
        <div class="admin-form-row"><label>Provider</label>
          <select name="payout_provider" <?= $ctx['is_owner'] ? '' : 'disabled' ?>>
            <option value="MTN_MOMO" <?= ($profile['payout_provider'] ?? '') === 'MTN_MOMO' ? 'selected' : '' ?>>MTN MoMo</option>
            <option value="AIRTEL_MONEY" <?= ($profile['payout_provider'] ?? '') === 'AIRTEL_MONEY' ? 'selected' : '' ?>>Airtel Money</option>
            <option value="BANK" <?= ($profile['payout_provider'] ?? '') === 'BANK' ? 'selected' : '' ?>>Bank transfer</option>
          </select>
        </div>
        <div class="admin-form-row"><label>Phone / account</label><input type="text" name="payout_phone" value="<?= htmlspecialchars($profile['payout_phone'] ?? '') ?>" <?= $ctx['is_owner'] ? '' : 'disabled' ?>></div>
      </div>
      <?php if ($ctx['is_owner']): ?><button class="btn btn-purple btn-block" type="submit" style="margin-top:8px">Save changes</button><?php endif; ?>
    </form>
  </div>

  <div style="display:flex; flex-direction:column; gap:20px;">
    <div class="admin-card">
      <h3 style="margin-bottom:4px">Verification status</h3>
      <p style="color:var(--muted-2); font-size:0.82rem; margin-bottom:10px">Verified organizers build more trust with buyers.</p>
      <span class="admin-badge <?= ($profile['verification_status'] ?? 'UNVERIFIED') === 'VERIFIED' ? 'admin-badge-success' : 'admin-badge-muted' ?>"><?= htmlspecialchars(ucfirst(strtolower($profile['verification_status'] ?? 'UNVERIFIED'))) ?></span>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Change password</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="admin-form-row"><label>Current password</label><input type="password" name="current_password" required></div>
        <div class="admin-form-row"><label>New password</label><input type="password" name="new_password" required minlength="8"></div>
        <button class="btn btn-block" type="submit" style="background:var(--ink); color:#fff">Update password</button>
      </form>
    </div>
  </div>
</div>

<?php render_organizer_foot(); ?>
