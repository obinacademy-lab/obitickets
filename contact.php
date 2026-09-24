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
        send_contact_notification_email('info@obitickets.site', $name, $email, $topic, $message);
        header('Location: /contact.php?sent=1');
        exit;
    }
}

$pageTitle = 'Contact — obitickets';
$pageDescription = "Have a question about an event, ticket purchase, or creating an event on ObiTickets? Our team is ready to help.";
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-grid">
      <div>
        <span class="kicker"><i></i>Contact Us</span>
        <h1>We're Here to Help</h1>
        <p>Have a question about an event, ticket purchase, or creating an event on ObiTickets? Our team is ready to help.</p>
        <p>Whether you're an <strong>event organizer looking to sell tickets</strong> or an <strong>attendee who needs assistance</strong>, feel free to reach out to us.</p>
        <div class="contact-hero-btns">
          <a class="btn btn-purple btn-lg" href="#send-message">Send us a message</a>
          <a class="btn btn-line btn-lg" href="https://wa.me/256775361998" target="_blank" rel="noopener"><svg width="16" height="16"><use href="#ic-phone"/></svg>WhatsApp us</a>
        </div>
        <div class="contact-trust-row">
          <div class="contact-trust-item"><span class="n">&lt;1 day</span><span class="l">Average reply time</span></div>
          <div class="contact-trust-item"><span class="n">Real people</span><span class="l">No ticket bots</span></div>
          <div class="contact-trust-item"><span class="n">Kampala</span><span class="l">Based in Uganda</span></div>
        </div>
      </div>
      <div class="contact-photo-wrap">
        <div class="contact-photo-mini-ring"></div>
        <div class="contact-photo-main">
          <div class="contact-photo-main-inner curtain"><img src="/assets/images/contact/agent-1.jpg" alt="An ObiTickets support team member ready to help"></div>
          <div class="contact-photo-badge">
            <svg width="20" height="20"><use href="#ic-shield"/></svg>
            <div><div class="n">Always ready</div><div class="l">to help you out</div></div>
          </div>
        </div>
        <div class="contact-photo-mini"><img src="/assets/images/contact/agent-2.jpg" alt="An ObiTickets support team member on a call"></div>
      </div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:20px">
    <div class="touch-band reveal">
      <div class="touch-band-accent"></div>
      <div class="touch-band-grid">
        <div class="touch-band-item">
          <div class="touch-band-ic"><svg width="20" height="20"><use href="#ic-phone"/></svg></div>
          <h4>WhatsApp / Phone</h4>
          <div class="val"><a href="tel:+256775361998">+256 775 361 998</a></div>
        </div>
        <div class="touch-band-item">
          <div class="touch-band-ic"><svg width="20" height="20"><use href="#ic-mail"/></svg></div>
          <h4>Email</h4>
          <div class="val" style="font-size:1.02rem;"><a href="mailto:info@obitickets.site">info@obitickets.site</a></div>
        </div>
        <div class="touch-band-item">
          <div class="touch-band-ic"><svg width="20" height="20"><use href="#ic-pin"/></svg></div>
          <h4>Location</h4>
          <div class="val">Kampala, Uganda</div>
        </div>
      </div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0" id="send-message">
    <div class="contact-grid">
      <div class="contact-card">
        <h3>Send Us a Message</h3>
        <p style="margin-top:8px">Have a question or need assistance?</p>

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
              <label for="name">Name</label>
              <input id="name" name="name" type="text" value="<?= htmlspecialchars($name) ?>" required>
            </div>
            <div class="field">
              <label for="email">Email Address</label>
              <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" required>
            </div>
          </div>
          <div class="field">
            <label for="topic">Subject</label>
            <select id="topic" name="topic">
              <?php foreach (CONTACT_TOPICS as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $topic === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="message">Your Message</label>
            <textarea id="message" name="message" rows="6" required><?= htmlspecialchars($message) ?></textarea>
          </div>
          <button class="btn btn-purple btn-lg btn-block" type="submit" style="margin-top:22px">Send Message</button>
        </form>
      </div>

      <div class="people-card reveal reveal-delay-1">
        <div class="people-block-head">
          <span class="kicker"><i></i>Who we help</span>
          <h3>Event Organizers</h3>
        </div>
        <div class="people-item">
          <div class="ic"><svg width="18" height="18"><use href="#ic-briefcase"/></svg></div>
          <div>
            <p>Need help setting up your event, managing tickets, or getting started with ObiTickets?</p>
            <p><strong>Contact our team and we'll be happy to assist.</strong></p>
          </div>
        </div>
        <div class="people-div"></div>
        <div class="people-block-head">
          <h3>Attendees</h3>
        </div>
        <div class="people-item">
          <div class="ic"><svg width="18" height="18"><use href="#ic-heart"/></svg></div>
          <div>
            <p>Having an issue with your ticket or need help with an event booking?</p>
            <p>Please contact us with your <strong>name, event name, and ticket details</strong> so our team can assist you faster.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
