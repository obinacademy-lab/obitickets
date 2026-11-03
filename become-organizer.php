<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Where a regular account lands when it taps "Create event" / "Sell tickets": one short step, then straight on.
$user = require_login();
$next = safe_next_path($_GET['next'] ?? $_POST['next'] ?? null, '/event-create.php');

if ($user['role'] === 'ADMIN') {
    http_response_code(403);
    exit('Admins manage organizers from the admin dashboard.');
}
if (resolve_organizer_context($user)) {
    // Already an organizer (or an active team member): nothing to do here.
    header('Location: ' . ($next === '/dashboard.php' ? '/event-create.php' : $next));
    exit;
}

$errors = [];
$orgName = trim((string) $user['name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $orgName = trim((string) ($_POST['org_name'] ?? ''));
    if ($orgName === '') {
        $errors[] = 'Please enter the name people will see as the host of your events.';
    } elseif (mb_strlen($orgName) > 100) {
        $errors[] = 'Please use a shorter name (100 characters or fewer).';
    } elseif (empty($_POST['agree'])) {
        $errors[] = 'Please tick the box to confirm.';
    } elseif (upgrade_attendee_to_organizer((int) $user['id'], $orgName)) {
        header('Location: ' . ($next === '/dashboard.php' ? '/event-create.php' : $next));
        exit;
    } else {
        $errors[] = 'We could not switch on event hosting for this account. Please contact us and we will sort it out.';
    }
}

$pageTitle = 'Create events — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="auth-section">
    <div class="auth-card reveal">
      <h1>Host your own events</h1>
      <p class="sub">Your account can sell tickets in a minute. Your tickets and orders stay exactly as they are.</p>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="field">
          <label for="org_name">Host name</label>
          <input id="org_name" name="org_name" type="text" maxlength="100" value="<?= htmlspecialchars($orgName) ?>" autocomplete="organization" required>
          <small class="muted">Shown on your events and your public page. Use your name, band, church or company.</small>
        </div>

        <label class="check" style="display:flex; gap:10px; align-items:flex-start; margin-top:18px">
          <input id="agree" name="agree" type="checkbox" value="1" style="margin-top:4px" required>
          <span>I will host real events and deliver what I sell. I understand obitickets charges a small commission on paid tickets.</span>
        </label>

        <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:24px">Start creating events</button>
      </form>

      <div class="auth-foot"><a href="/">Not now, back to events</a></div>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
