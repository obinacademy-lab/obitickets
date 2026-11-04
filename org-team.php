<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Team management is owner-only — organizer_can() only passes 'team.manage'
// for the NULL role (the owner), since no role is granted it in ORGANIZER_PERMISSIONS.
$ctx = require_organizer_access('team.manage');
$organizerId = $ctx['organizer_id'];
$actorId = $ctx['actor_id'];
$error = null;
$old = ['name' => '', 'email' => '', 'role' => array_key_first(ORGANIZER_ROLES)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'invite') {
        $old = ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? '')), 'role' => (string) ($_POST['role'] ?? $old['role'])];
        [$ok, $err, $became] = invite_team_member($organizerId, $actorId, $old['email'], $old['role'], $old['name'] ?: null) + [2 => null];
        if (!$ok) {
            $error = $err;
        } else {
            header('Location: /org-team.php?r=' . ($became === 'ACTIVE' ? 'invited_active' : 'invited_pending'));
            exit;
        }
    } elseif ($action === 'activate') {
        $done = activate_team_member_if_registered($organizerId, (int) ($_POST['id'] ?? 0));
        header('Location: /org-team.php?r=' . ($done ? 'activated' : 'notyet'));
        exit;
    } elseif ($action === 'role') {
        update_team_member_role($organizerId, $actorId, (int) ($_POST['id'] ?? 0), (string) ($_POST['role'] ?? ''));
        header('Location: /org-team.php?r=saved');
        exit;
    } elseif ($action === 'revoke') {
        revoke_team_member($organizerId, $actorId, (int) ($_POST['id'] ?? 0));
        header('Location: /org-team.php?r=saved');
        exit;
    }
}

$members = get_team_members($organizerId);
$counts = ['ACTIVE' => 0, 'INVITED' => 0, 'REVOKED' => 0];
foreach ($members as $m) {
    $counts[$m['status']] = ($counts[$m['status']] ?? 0) + 1;
}

// What each role really opens in the dashboard, in plain words (kept next to ORGANIZER_PERMISSIONS in meaning).
$roleBlurbs = [
    'EVENT_MANAGER' => 'Creates and edits events and ticket types, sees attendees and runs check-in.',
    'FINANCE_MANAGER' => 'Sees earnings, requests payouts and refunds. Cannot touch events.',
    'CHECKIN_STAFF' => 'Scans tickets at the door. Sees no money at all.',
    'MARKETING_MANAGER' => 'Sees the performance figures on Analytics.',
];
$statusLabels = ['ACTIVE' => 'Active', 'INVITED' => 'Invited', 'REVOKED' => 'Removed'];

/** "today", "yesterday", "12 days ago", or the date once it is more than two months back. */
function tm_ago(?string $when): string
{
    if (!$when || strtotime($when) === false) {
        return '';
    }
    $days = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($when)))) / 86400);
    if ($days <= 0) {
        return 'today';
    }
    if ($days === 1) {
        return 'yesterday';
    }
    return $days <= 60 ? $days . ' days ago' : 'on ' . date('j M Y', strtotime($when));
}

function tm_since(array $m): string
{
    if ($m['status'] === 'REVOKED') {
        return 'Access ended';
    }
    if ($m['status'] === 'INVITED') {
        return 'Invited ' . tm_ago($m['invited_at'] ?? null);
    }
    return 'Joined ' . tm_ago($m['joined_at'] ?: ($m['invited_at'] ?? null));
}

$flashMap = [
    'saved' => ['Saved.', 'success'],
    'invited_active' => ['Added. They already have an account, so they have access now.', 'success'],
    'invited_pending' => ['Invite saved. Ask them to sign up with this email, then press "Check & activate".', 'success'],
    'activated' => ['Saved. They are active now.', 'success'],
    'notyet' => ["They haven't signed up yet. Ask them to sign up with this email, then try again.", 'warning'],
];
$flash = $flashMap[$_GET['r'] ?? ''] ?? (isset($_GET['updated']) ? $flashMap['saved'] : null);

$pageTitle = 'Team';
render_organizer_head('team', $ctx);
?>

<?php if ($flash): ?><span data-flash="<?= htmlspecialchars($flash[0], ENT_QUOTES) ?>" data-flash-type="<?= $flash[1] ?>" hidden></span><?php endif; ?>

<header class="tm-top">
  <div>
    <p class="ov-eyebrow">Workspace</p>
    <h1>Team</h1>
    <p class="ov-lede">Invite people to help run your events. What each can open depends on their role, and it is checked on every action.</p>
  </div>
</header>

<div class="tm-stats">
  <div class="tm-stat"><span class="kl">Active</span><b class="kv"><?= $counts['ACTIVE'] ?></b><span class="kd">can sign in now</span></div>
  <div class="tm-stat"><span class="kl">Invited</span><b class="kv"><?= $counts['INVITED'] ?></b><span class="kd"><?= $counts['INVITED'] ? 'waiting to sign up' : 'no one waiting' ?></span></div>
  <div class="tm-stat"><span class="kl">Removed</span><b class="kv"><?= $counts['REVOKED'] ?></b><span class="kd">kept on record</span></div>
</div>

<div class="tm-grid">
  <section class="ov-card tm-roster" aria-label="Your team">
    <?php if (!$members): ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-people"/></svg><p><b>No team members yet.</b><br>Invite someone to help manage your events.</p></div>
    <?php else: ?>
      <div class="tm-tools">
        <div class="tm-seg" role="group" aria-label="Filter" id="tmFilters">
          <button type="button" data-f="ALL" aria-pressed="true">All <b><?= count($members) ?></b></button>
          <button type="button" data-f="ACTIVE" aria-pressed="false">Active <b><?= $counts['ACTIVE'] ?></b></button>
          <button type="button" data-f="INVITED" aria-pressed="false">Invited <b><?= $counts['INVITED'] ?></b></button>
          <button type="button" data-f="REVOKED" aria-pressed="false">Removed <b><?= $counts['REVOKED'] ?></b></button>
        </div>
        <label class="tm-search"><svg width="15" height="15" aria-hidden="true"><use href="#ic-search"/></svg><input type="search" id="tmSearch" placeholder="Search the team" aria-label="Search the team" autocomplete="off"></label>
      </div>
      <div class="tm-head" aria-hidden="true"><span>Person</span><span>Role</span><span>Status</span></div>
      <div id="tmRows">
        <?php foreach ($members as $m):
            $display = $m['user_name'] ?? $m['name'] ?? $m['email'];
            $initials = initials_from_name((string) $display);
            $isRevoked = $m['status'] === 'REVOKED';
        ?>
          <div class="tm-row tm-<?= strtolower($m['status']) ?>" data-status="<?= htmlspecialchars($m['status']) ?>" data-email="<?= htmlspecialchars(strtolower($m['email'])) ?>" data-find="<?= htmlspecialchars(strtolower($display . ' ' . $m['email'])) ?>">
            <div class="tm-who">
              <?php if (!empty($m['avatar_url'])): ?><img class="tm-av" src="<?= htmlspecialchars($m['avatar_url']) ?>" alt="" width="42" height="42"><?php else: ?><span class="tm-av tm-t<?= (int) $m['id'] % 5 ?>" aria-hidden="true"><?= htmlspecialchars($initials) ?></span><?php endif; ?>
              <div><b><?= htmlspecialchars($display) ?></b><small title="<?= htmlspecialchars($m['email']) ?>"><?= htmlspecialchars($m['email']) ?></small></div>
            </div>
            <div class="tm-role">
              <?php if ($isRevoked): ?>
                <span class="tm-muted"><?= htmlspecialchars(ORGANIZER_ROLES[$m['role']] ?? $m['role']) ?></span>
              <?php else: ?>
                <form method="post" class="tm-roleform"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <select name="role" class="tm-sel" data-who="<?= htmlspecialchars($display, ENT_QUOTES) ?>" data-prev="<?= htmlspecialchars($m['role']) ?>" aria-label="Role for <?= htmlspecialchars($display, ENT_QUOTES) ?>">
                    <?php foreach (ORGANIZER_ROLES as $val => $label): ?><option value="<?= $val ?>" <?= $m['role'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </div>
            <div class="tm-state">
              <span class="tm-chip tm-chip-<?= strtolower($m['status']) ?>"><?= $statusLabels[$m['status']] ?? htmlspecialchars($m['status']) ?></span>
              <span class="tm-muted"><?= htmlspecialchars(tm_since($m)) ?></span>
              <?php if (!$isRevoked): ?>
                <span class="tm-acts">
                  <?php if ($m['status'] === 'INVITED'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button type="submit" class="tm-lnk tm-blue" title="Check if they have signed up yet">Check &amp; activate</button></form>
                  <?php endif; ?>
                  <form method="post" data-confirm="Remove <?= htmlspecialchars($display, ENT_QUOTES) ?> from your team? They lose access straight away." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button type="submit" class="tm-lnk tm-red">Remove</button></form>
                </span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="ov-none" id="tmNone" hidden><svg width="22" height="22" aria-hidden="true"><use href="#ic-people"/></svg><p><b>No one matches that.</b><br>Try another filter or search.</p></div>
    <?php endif; ?>
  </section>

  <aside class="ov-card tm-invite" aria-labelledby="tmInvH">
    <div class="ov-ch"><div><h2 id="tmInvH">Invite a team member</h2></div></div>
    <p class="tm-hint tm-intro">If they already have an obitickets account, they get access immediately. Otherwise, ask them to sign up with this email, then come back and press "Check &amp; activate".</p>
    <form method="post" id="tmInvite" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="invite">
      <div class="tm-fld"><label for="tmName">Name <small>(optional)</small></label><input class="tm-inp" id="tmName" type="text" name="name" placeholder="Their name" value="<?= htmlspecialchars($old['name']) ?>" autocomplete="off"></div>
      <div class="tm-fld"><label for="tmEmail">Email</label><input class="tm-inp<?= $error ? ' is-bad' : '' ?>" id="tmEmail" type="email" name="email" required placeholder="person@example.com" value="<?= htmlspecialchars($old['email']) ?>" autocomplete="off"<?= $error ? ' aria-invalid="true" aria-describedby="tmErr"' : '' ?>><span class="tm-err" id="tmErr" role="alert"><?= $error ? htmlspecialchars($error) : '' ?></span></div>
      <div class="tm-fld"><label for="tmRole">Role</label>
        <select class="tm-inp tm-rolepick" id="tmRole" name="role">
          <?php foreach (ORGANIZER_ROLES as $val => $label): ?><option value="<?= $val ?>" data-hint="<?= htmlspecialchars($roleBlurbs[$val] ?? '', ENT_QUOTES) ?>" <?= $old['role'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <p class="tm-hint" id="tmHint" aria-live="polite"><?= htmlspecialchars($roleBlurbs[$old['role']] ?? '') ?></p>
      <button class="ov-btn ov-red tm-send" type="submit">Send invite</button>
    </form>
  </aside>
</div>

<script src="/assets/js/org-team.js?v=<?= @filemtime(__DIR__ . '/assets/js/org-team.js') ?: time() ?>"></script>
<?php render_organizer_foot(); ?>
