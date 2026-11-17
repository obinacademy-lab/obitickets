<?php
require_once __DIR__ . '/includes/bootstrap.php';

/**
 * "My profile": every signed-in person (attendee, organizer or admin) can change their own picture, name, phone
 * number and password. There is no id in the URL or the form: it only ever edits the account that is signed in.
 */
$user = require_login();
$userId = (int) $user['id'];

$stmt = db()->prepare('SELECT name, email, phone, role, google_id, email_verified_at, created_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$row = $stmt->fetch();
$usesGoogle = !empty($row['google_id']);

$errors = [];
$passwordError = null;
$photoError = null;
$coverError = null;
$form = ['name' => (string) $row['name'], 'phone' => (string) ($row['phone'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'details') {
        $form = ['name' => (string) ($_POST['name'] ?? ''), 'phone' => (string) ($_POST['phone'] ?? '')];
        [$errors, $clean] = validate_profile_details($form['name'], $form['phone']);
        if (!$errors) {
            update_own_profile($userId, $clean['name'], $clean['phone']);
            header('Location: /profile.php?saved=details#details');
            exit;
        }
    } elseif ($action === 'photo') {
        [$ok, $photoError] = set_user_avatar($userId, $_FILES['avatar'] ?? []);
        if ($ok) {
            header('Location: /profile.php?saved=photo#photo');
            exit;
        }
    } elseif ($action === 'remove_photo') {
        remove_user_avatar($userId);
        header('Location: /profile.php?saved=removed#photo');
        exit;
    } elseif ($action === 'cover') {
        [$ok, $coverError] = set_user_cover($userId, $_FILES['cover'] ?? []);
        if ($ok) {
            header('Location: /profile.php?saved=cover#cover');
            exit;
        }
    } elseif ($action === 'remove_cover') {
        remove_user_cover($userId);
        header('Location: /profile.php?saved=cover_removed#cover');
        exit;
    } elseif ($action === 'tagline' && $row['role'] === 'ORGANIZER') {
        set_organizer_tagline($userId, (string) ($_POST['headline'] ?? ''), (string) ($_POST['location'] ?? ''));
        header('Location: /profile.php?saved=tagline#cover');
        exit;
    } elseif ($action === 'password' && !$usesGoogle) {
        [$ok, $err] = change_own_password($userId, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
        if ($ok) {
            header('Location: /profile.php?saved=password#password');
            exit;
        }
        $passwordError = $err;
    }
}

$avatar = user_avatar_url($userId);
$cover = user_cover_url($userId);
$coversReady = profile_covers_ready();
$isOrganizer = $row['role'] === 'ORGANIZER';
$tagline = $isOrganizer ? organizer_tagline($userId) : ['headline' => '', 'location' => ''];
$saved = (string) ($_GET['saved'] ?? '');
$savedText = [
    'details' => 'Your details are saved.',
    'photo' => 'Your new picture is on.',
    'removed' => 'Your picture was removed.',
    'password' => 'Your password was changed.',
    'cover' => 'Your new cover photo is on.',
    'cover_removed' => 'Your cover photo was removed.',
    'tagline' => 'Your profile line is saved.',
][$saved] ?? null;
$roleLabel = ['ATTENDEE' => 'Attendee', 'ORGANIZER' => 'Organizer', 'ADMIN' => 'Admin'][$row['role']] ?? 'Member';

$pageTitle = 'My profile — obitickets';
$bodyClass = 'classic-page profile-page';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap pf-wrap">
  <header class="pf-head">
    <?= render_avatar_html($avatar, (string) $row['name'], 88, 'pf-av-lg') ?>
    <div>
      <h1><?= htmlspecialchars($row['name']) ?></h1>
      <p><?= htmlspecialchars($row['email']) ?> <span class="pf-chip"><?= htmlspecialchars($roleLabel) ?></span></p>
    </div>
  </header>

  <?php if ($savedText): ?><div class="alert alert-success pf-flash" role="status"><?= htmlspecialchars($savedText) ?></div><?php endif; ?>

  <section class="pf-card" id="photo" aria-labelledby="pf-h-photo">
    <h2 id="pf-h-photo">Profile picture</h2>
    <p class="pf-sub">A clear photo of your face helps organizers and other attendees recognise you. JPG, PNG, WEBP or GIF, up to 5MB. We crop it to a square.</p>
    <?php if ($photoError): ?><div class="alert alert-error"><?= htmlspecialchars($photoError) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="pf-photo" data-pf-photo>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="photo">
      <div class="pf-preview" data-pf-preview><?= render_avatar_html($avatar, (string) $row['name'], 112, 'pf-av-xl') ?></div>
      <div class="pf-photo-ctl">
        <label class="btn btn-line pf-pick" for="avatar">Choose a picture</label>
        <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="pf-file" data-pf-file>
        <span class="pf-fname" data-pf-name aria-live="polite">No picture chosen</span>
        <div class="pf-photo-btns">
          <button class="btn btn-purple" type="submit" data-pf-save disabled>Save picture</button>
        </div>
      </div>
    </form>
    <?php if ($avatar): ?>
      <form method="post" class="pf-remove">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_photo">
        <button class="pf-link" type="submit">Remove my picture</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($coversReady): ?>
  <section class="pf-card" id="cover" aria-labelledby="pf-h-cover">
    <h2 id="pf-h-cover">Cover photo</h2>
    <p class="pf-sub">The wide picture at the top of your profile. A landscape photo works best (at least 600 pixels wide). JPG, PNG, WEBP or GIF, up to 8MB. We crop it to a wide strip.</p>
    <?php if ($coverError): ?><div class="alert alert-error"><?= htmlspecialchars($coverError) ?></div><?php endif; ?>
    <div class="pf-cover" data-pf-cover-preview<?= $cover ? ' style="background-image:url(\'' . htmlspecialchars($cover, ENT_QUOTES) . '\')"' : '' ?>><?php if (!$cover): ?><span>No cover photo yet</span><?php endif; ?></div>
    <form method="post" enctype="multipart/form-data" class="pf-cover-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="cover">
      <label class="btn btn-line pf-pick" for="coverFile">Choose a cover photo</label>
      <input id="coverFile" name="cover" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="pf-file" data-pf-cover-file>
      <button class="btn btn-purple" type="submit" data-pf-cover-save disabled>Save cover</button>
    </form>
    <?php if ($cover): ?>
      <form method="post" class="pf-remove">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_cover">
        <button class="pf-link" type="submit">Remove my cover photo</button>
      </form>
    <?php endif; ?>
    <?php if ($isOrganizer): ?>
      <form method="post" class="pf-tagline">
        <?= csrf_field() ?><input type="hidden" name="action" value="tagline">
        <h3>Line under your name</h3>
        <p class="pf-sub">Shown on your public profile, like "Event Venue · Soroti City".</p>
        <div class="pf-two">
          <div class="field"><label for="headline">What you are</label><input id="headline" name="headline" type="text" maxlength="60" placeholder="Event Venue" value="<?= htmlspecialchars($tagline['headline']) ?>"></div>
          <div class="field"><label for="location">Where</label><input id="location" name="location" type="text" maxlength="60" placeholder="Soroti City" value="<?= htmlspecialchars($tagline['location']) ?>"></div>
        </div>
        <button class="btn btn-line" type="submit">Save line</button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="pf-card" id="details" aria-labelledby="pf-h-details">
    <h2 id="pf-h-details">Your details</h2>
    <form method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="details">
      <div class="field">
        <label for="name">Full name</label>
        <input id="name" name="name" type="text" value="<?= htmlspecialchars($form['name']) ?>" autocomplete="name" maxlength="80" required<?= isset($errors['name']) ? ' aria-invalid="true" aria-describedby="name-err"' : '' ?>>
        <?php if (isset($errors['name'])): ?><div class="pf-err" id="name-err"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="phone">Phone number <span class="pf-opt">(optional)</span></label>
        <input id="phone" name="phone" type="tel" inputmode="tel" value="<?= htmlspecialchars($form['phone']) ?>" autocomplete="tel" placeholder="0772 123 456"<?= isset($errors['phone']) ? ' aria-invalid="true" aria-describedby="phone-err"' : '' ?>>
        <?php if (isset($errors['phone'])): ?><div class="pf-err" id="phone-err"><?= htmlspecialchars($errors['phone']) ?></div><?php endif; ?>
        <div class="pf-hint">Used to prefill your mobile money number at checkout.</div>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" type="email" value="<?= htmlspecialchars($row['email']) ?>" disabled>
        <div class="pf-hint">Your email is how tickets reach you, so it can't be changed here. <a href="/contact.php">Contact us</a> if you need it changed.</div>
      </div>
      <button class="btn btn-purple" type="submit" style="margin-top:22px">Save details</button>
    </form>
  </section>

  <section class="pf-card" id="password" aria-labelledby="pf-h-password">
    <h2 id="pf-h-password">Password</h2>
    <?php if ($usesGoogle): ?>
      <p class="pf-sub" style="margin:0">You sign in with Google, so there is no password to change here.</p>
    <?php else: ?>
      <?php if ($passwordError): ?><div class="alert alert-error"><?= htmlspecialchars($passwordError) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="field">
          <label for="current_password">Current password</label>
          <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label for="new_password">New password</label>
          <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
          <div class="pf-hint">At least 8 characters.</div>
        </div>
        <button class="btn btn-line" type="submit" style="margin-top:22px">Change password</button>
      </form>
    <?php endif; ?>
  </section>

  <p class="pf-foot"><a href="/my-tickets.php">My tickets</a> <span aria-hidden="true">&middot;</span> <a href="/delete-account.php">Delete my account</a></p>
</div>
<style>
  .pf-cover{height:140px; margin:4px 0 14px; border-radius:16px; background:#1E181A center/cover no-repeat; display:grid; place-items:center; color:#8F8385; font-size:.85rem; font-weight:600; box-shadow:inset 0 0 0 1px rgba(0,0,0,.06)}
  .pf-cover-form{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
  .pf-tagline{margin-top:22px; padding-top:18px; border-top:1px solid rgba(0,0,0,.08)}
  .pf-tagline h3{margin:0 0 4px; font-size:1rem}
  .pf-two{display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px}
  @media (max-width:520px){ .pf-two{grid-template-columns:1fr} }
</style>
<script>
(function () {
  var input = document.querySelector('[data-pf-cover-file]'), box = document.querySelector('[data-pf-cover-preview]'), save = document.querySelector('[data-pf-cover-save]');
  if (!input || !box || !save) { return; }
  input.addEventListener('change', function () {
    var f = input.files && input.files[0];
    if (!f) { save.disabled = true; return; }
    if (!/^image\/(jpeg|png|webp|gif)$/.test(f.type) || f.size > 8 * 1024 * 1024) { input.value = ''; save.disabled = true; alert('Please choose a JPG, PNG, WEBP or GIF picture under 8MB.'); return; }
    box.style.backgroundImage = 'url("' + URL.createObjectURL(f) + '")';
    box.textContent = '';
    save.disabled = false;
  });
})();
</script>
<script src="/assets/js/profile.js?v=<?= @filemtime(__DIR__ . '/assets/js/profile.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
