<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Public "delete my account" request page — the web link Google Play's
// account-deletion policy asks for, and a clearer path than "contact us".
// It only records a request (in the same inbox as the contact form); an admin
// confirms it with the email address on the account before anything is
// removed, so a stranger typing someone else's email can't delete their
// tickets. Order/payment records are kept where the law requires it.

const ACCOUNT_DELETION_TOPIC = 'Account deletion';

$authUser = current_user();
$name = $authUser['name'] ?? '';
$email = $authUser['email'] ?? '';
$reason = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($name === '') {
        $errors[] = 'Please tell us your name.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter the email address of the account you want deleted.';
    }
    if (empty($_POST['confirm'])) {
        $errors[] = 'Please tick the box to confirm you understand.';
    }

    if (!$errors) {
        $message = "Please delete my obitickets account.\n\nAccount email: {$email}\nLogged in when requested: " . ($authUser ? 'yes' : 'no')
            . ($reason !== '' ? "\n\nReason: " . mb_substr($reason, 0, 1000) : '');
        create_contact_message($name, $email, ACCOUNT_DELETION_TOPIC, $message);
        send_contact_notification_email('info@obitickets.site', $name, $email, ACCOUNT_DELETION_TOPIC, $message);
        header('Location: /delete-account.php?sent=1');
        exit;
    }
}

$pageTitle = 'Delete your account — obitickets';
$pageDescription = 'How to ask obitickets to delete your account and personal data, and what we keep.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="auth-section">
    <div class="auth-card reveal" style="max-width:560px">
      <h1>Delete your account</h1>
      <p class="sub">Ask us to delete your obitickets account and personal details. This applies to the website and the obitickets app.</p>

      <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success">Request received. We'll email you at the address on the account to confirm it's you, then delete the account within 30 days.</div>
      <?php else: ?>

      <div style="margin:6px 0 20px; font-size:0.92rem; line-height:1.6; color:var(--muted-2);">
        <p style="margin:0 0 10px"><strong style="color:var(--ink)">What gets deleted:</strong> your name, email, phone number, password, profile and saved details. Your login stops working and your tickets can no longer be opened.</p>
        <p style="margin:0 0 10px"><strong style="color:var(--ink)">What we keep:</strong> payment and order records that we must keep for tax and accounting purposes, with your personal details removed from them where the law allows.</p>
        <p style="margin:0"><strong style="color:var(--ink)">Before you ask:</strong> use any tickets you've already bought first, and organizers should withdraw any earnings — deleted accounts can't be recovered.</p>
      </div>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="name">Your name</label>
          <input id="name" name="name" type="text" value="<?= htmlspecialchars($name) ?>" autocomplete="name" required>
        </div>
        <div class="field">
          <label for="email">Account email</label>
          <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
        </div>
        <div class="field">
          <label for="reason">Why are you leaving? (optional)</label>
          <textarea id="reason" name="reason" rows="3" maxlength="1000" style="width:100%"><?= htmlspecialchars($reason) ?></textarea>
        </div>
        <label style="display:flex; gap:10px; align-items:flex-start; margin-top:6px; font-size:0.9rem; cursor:pointer;">
          <input type="checkbox" name="confirm" value="1" style="margin-top:3px">
          <span>I understand my account and tickets will be permanently deleted.</span>
        </label>
        <button class="btn btn-purple btn-block btn-lg" type="submit" style="margin-top:22px">Request account deletion</button>
      </form>

      <?php endif; ?>

      <div class="auth-foot"><a href="/privacy.php">Privacy policy</a> &middot; <a href="/contact.php">Contact us</a></div>
    </div>
  </section>
</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
