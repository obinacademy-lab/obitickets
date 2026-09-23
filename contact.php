<?php
require_once __DIR__ . '/includes/bootstrap.php';

$authUser = current_user();
$name = $authUser['name'] ?? '';
$email = $authUser['email'] ?? '';
$topic = CONTACT_TOPICS[0];
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $topic = in_array($_POST['topic'] ?? '', CONTACT_TOPICS, true) ? $_POST['topic'] : CONTACT_TOPICS[0];
    $message = trim($_POST['message'] ?? '');

    if ($name === '') {
        $errors[] = 'Please tell us your name.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($message === '') {
        $errors[] = "Please add a message — let us know what's up.";
    }

    if (!$errors) {
        create_contact_message($name, $email, $topic, $message);
        send_email(
            'hello@obitickets.demo',
            'New contact message: ' . $topic,
            "From: {$name} <{$email}>\n\n{$message}"
        );
        header('Location: /contact.php?sent=1');
        exit;
    }
}

$pageTitle = 'Contact — obitickets';
$pageDescription = 'Get in touch with the obitickets team — questions, organizer support, or anything else.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Contact</span>
      <h1>Talk to a real person, not a ticket bot.</h1>
      <p>Questions about an order, help listing your event, or just want to say hi — drop us a message and we'll get back to you.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="contact-grid">
      <div class="contact-card">
        <h3>Send a message</h3>

        <?php if (isset($_GET['sent'])): ?>
          <div class="alert alert-success" style="margin-top:18px">Thanks — your message is in. We'll reply by email soon.</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
          <div class="alert alert-error" style="margin-top:18px"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate style="margin-top:8px">
          <?= csrf_field() ?>
          <div class="field-grid-2">
            <div class="field">
              <label for="name">Your name</label>
              <input id="name" name="name" type="text" value="<?= htmlspecialchars($name) ?>" required>
            </div>
            <div class="field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" required>
            </div>
          </div>
          <div class="field">
            <label for="topic">What's this about?</label>
            <select id="topic" name="topic">
              <?php foreach (CONTACT_TOPICS as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $topic === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="message">Message</label>
            <textarea id="message" name="message" rows="6" required><?= htmlspecialchars($message) ?></textarea>
          </div>
          <button class="btn btn-purple btn-lg btn-block" type="submit" style="margin-top:22px">Send message</button>
        </form>
      </div>

      <div class="contact-card">
        <h3>Other ways to reach us</h3>
        <div class="contact-info-list">
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-share"/></svg></div>
            <div><h4>Email</h4><p><a href="mailto:hello@obitickets.demo">hello@obitickets.demo</a></p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-briefcase"/></svg></div>
            <div><h4>Organizer support</h4><p>Already selling on obitickets? Use the <a href="/my-events.php">organizer dashboard</a> for order-specific help.</p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-shield"/></svg></div>
            <div><h4>Response time</h4><p>We typically reply within one business day.</p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-pin"/></svg></div>
            <div><h4>Based in</h4><p>Kampala, Uganda</p></div>
          </div>
        </div>
      </div>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
