<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Team management is owner-only — organizer_can() only passes 'team.manage'
// for the NULL role (the owner), since no role is granted it in ORGANIZER_PERMISSIONS.
$ctx = require_organizer_access('team.manage');
$organizerId = $ctx['organizer_id'];
$actorId = $ctx['actor_id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'invite') {
        [$ok, $err] = invite_team_member($organizerId, $actorId, (string) ($_POST['email'] ?? ''), (string) ($_POST['role'] ?? ''), trim((string) ($_POST['name'] ?? '')) ?: null);
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /org-team.php?updated=1');
            exit;
        }
    } elseif ($action === 'activate') {
        activate_team_member_if_registered($organizerId, (int) $_POST['id']);
        header('Location: /org-team.php?updated=1');
        exit;
    } elseif ($action === 'role') {
        update_team_member_role($organizerId, $actorId, (int) $_POST['id'], (string) ($_POST['role'] ?? ''));
        header('Location: /org-team.php?updated=1');
        exit;
    } elseif ($action === 'revoke') {
        revoke_team_member($organizerId, $actorId, (int) $_POST['id']);
        header('Location: /org-team.php?updated=1');
        exit;
    }
}

$members = get_team_members($organizerId);
$statusMeta = ['ACTIVE' => 'admin-badge-success', 'INVITED' => 'admin-badge-warn', 'REVOKED' => 'admin-badge-muted'];

$pageTitle = 'Team Members';
render_organizer_head('team', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Team members</h1><p><?= count($members) ?> invited &middot; permissions are enforced on every action, not just hidden in the UI</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div>
    <?php if (!$members): ?>
      <div class="admin-empty">
        <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-people"/></svg></div>
        <h3>No team members yet</h3>
        <p>Invite someone to help manage your events.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Person</th><th>Role</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($members as $m): ?>
              <tr class="<?= $m['status'] === 'INVITED' ? 'admin-row-attention' : '' ?>">
                <td><div class="row-user"><span class="avatar"><?= htmlspecialchars(initials_from_name($m['user_name'] ?? $m['name'] ?? $m['email'])) ?></span><div><?= htmlspecialchars($m['user_name'] ?? $m['name'] ?? $m['email']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($m['email']) ?></span></div></div></td>
                <td>
                  <?php if ($m['status'] === 'REVOKED'): ?><?= htmlspecialchars(ORGANIZER_ROLES[$m['role']] ?? $m['role']) ?>
                  <?php else: ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                      <select name="role" class="auto-submit" onfocus="this.dataset.prev=this.selectedIndex" onchange="var el=this; window.adminConfirm('Change this person\'s role?').then(function(ok){ if(ok){ el.form.requestSubmit(); } else { el.selectedIndex=el.dataset.prev; } });">
                        <?php foreach (ORGANIZER_ROLES as $val => $label): ?><option value="<?= $val ?>" <?= $m['role'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                      </select>
                    </form>
                  <?php endif; ?>
                </td>
                <td><span class="admin-badge <?= $statusMeta[$m['status']] ?? 'admin-badge-muted' ?>"><?= $m['status'] === 'INVITED' ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars($m['status']) ?></span></td>
                <td style="white-space:nowrap">
                  <?php if ($m['status'] === 'INVITED'): ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                      <button type="submit" class="link" style="background:none; border:none; color:var(--purple); cursor:pointer; padding:0; font-weight:700" title="Check if they've signed up yet">Check &amp; activate</button></form>
                  <?php endif; ?>
                  <?php if ($m['status'] !== 'REVOKED'): ?>
                    <?php if ($m['status'] === 'INVITED'): ?>&middot;<?php endif; ?>
                    <form method="post" style="display:inline" data-confirm="Remove this person from your team?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                      <button type="submit" class="link" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:0; font-weight:700">Remove</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:4px">Invite a team member</h3>
    <p style="color:var(--muted-2); font-size:0.8rem; margin-bottom:14px">If they already have an obitickets account, they get access immediately. Otherwise, ask them to sign up with this email, then come back and press "Check &amp; activate".</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="invite">
      <div class="admin-form-row"><label>Name (optional)</label><input type="text" name="name" placeholder="Their name"></div>
      <div class="admin-form-row"><label>Email</label><input type="email" name="email" required placeholder="person@example.com"></div>
      <div class="admin-form-row"><label>Role</label>
        <select name="role">
          <?php foreach (ORGANIZER_ROLES as $val => $label): ?><option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-purple btn-block" type="submit">Send invite</button>
    </form>
  </div>
</div>

<?php render_organizer_foot(); ?>
