<?php
require_once __DIR__ . '/includes/bootstrap.php';

$authUser = current_user();
$name = $authUser['name'] ?? '';
$email = $authUser['email'] ?? '';
$phone = '';
$topic = CONTACT_TOPICS[0];
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $topic = in_array($_POST['topic'] ?? '', CONTACT_TOPICS, true) ? $_POST['topic'] : CONTACT_TOPICS[0];
    $message = trim($_POST['message'] ?? '');

    if ($name === '') {
        $errors[] = 'Please tell us your name.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^\+?[0-9][0-9 ()\-]{7,19}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number, for example +256 7xx xxx xxx, or leave it blank.';
    }
    if ($message === '') {
        $errors[] = "Please add a message — let us know what's up.";
    }

    if (!$errors) {
        // The phone number travels with the message (first line), so the team sees it in the admin inbox and in the email.
        $fullMessage = $phone !== '' ? "Phone: {$phone}\n\n{$message}" : $message;
        create_contact_message($name, $email, $topic, $fullMessage);
        send_contact_notification_email('info@obitickets.site', $name, $email, $topic, $fullMessage);
        header('Location: /contact.php?sent=1#send');
        exit;
    }
}

$pageTitle = 'Contact — obitickets';
$pageDescription = "Have a question, need help booking a ticket, or want to publish your event on Obitickets? Our team is ready to assist you.";
$bodyClass = 'classic-page contact-concierge';
include __DIR__ . '/includes/header.php';

$ctIcons = [
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.4"/><path d="M3.5 7l8.5 6.5L20.5 7"/>',
    'wa' => '<path d="M4 20l1.3-4.2A8.4 8.4 0 1 1 8.4 18.8z"/><path d="M9.2 9.2c.3 2.6 2.6 4.9 5.6 5.6l1-1.5-2-1-.8.8c-.9-.4-1.7-1.2-2.1-2.1l.8-.8-1-2z"/>',
    'globe' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.6 3.6 5.4 3.6 8.5s-1.1 5.9-3.6 8.5c-2.5-2.6-3.6-5.4-3.6-8.5S9.5 6.1 12 3.5z"/>',
    'brief' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
    'ticket' => '<path d="M4 8a2 2 0 0 0 0 4v0a2 2 0 0 0 0 4v1.5h16V16a2 2 0 0 0 0-4v0a2 2 0 0 0 0-4V6.5H4z"/><path d="M14 7.5v9" stroke-dasharray="2 2"/>',
    'hand' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.6c2.7 0 4.5 1.8 4.5 4.4"/>',
    'send' => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
    'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
];
$ctIcon = static function (string $n) use ($ctIcons): string {
    return '<span class="ct-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' . $ctIcons[$n] . '</svg></span>';
};
$ctArrow = '<svg class="ct-arr" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ctIcons['arrow'] . '</svg>';
?>

<div class="ct">

  <section class="wrap ct-hero">
    <div class="ct-hero-copy">
      <span class="ct-kick hero-in d1">Contact Obitickets</span>
      <h1 class="hero-in d1">We're Here to Help!</h1>
      <p class="ct-p1 hero-in d3">Have a question, need assistance with booking a ticket, or interested in publishing your event on Obitickets? Our team is ready to assist you.</p>
      <p class="ct-p2 hero-in d3">Whether you're an event organizer looking to reach more people or an attendee looking for help with your booking, we're just a message away.</p>
    </div>
    <aside class="ct-touch hero-in d5" aria-labelledby="touchH">
      <h2 id="touchH">Get in Touch</h2>
      <a class="ct-it" href="mailto:<?= htmlspecialchars(CONTACT_EMAIL) ?>">
        <?= $ctIcon('mail') ?><span class="ct-tx"><span class="ct-lbl">Email</span><span class="ct-val"><?= htmlspecialchars(CONTACT_EMAIL) ?></span></span><?= $ctArrow ?>
      </a>
      <a class="ct-it" href="<?= htmlspecialchars(contact_phone_whatsapp_url()) ?>" target="_blank" rel="noopener" data-wa-chat>
        <?= $ctIcon('wa') ?><span class="ct-tx"><span class="ct-lbl">WhatsApp / Phone</span><span class="ct-val"><?= htmlspecialchars(CONTACT_PHONE_DISPLAY) ?></span></span><?= $ctArrow ?>
      </a>
      <a class="ct-it" href="https://obitickets.site" target="_blank" rel="noopener">
        <?= $ctIcon('globe') ?><span class="ct-tx"><span class="ct-lbl">Website</span><span class="ct-val">https://obitickets.site</span></span><?= $ctArrow ?>
      </a>
    </aside>
  </section>

  <section class="wrap ct-help">
    <h2 class="reveal">How Can We Help You?</h2>
    <div class="ct-hgrid">
      <article class="ct-hc reveal">
        <div class="ct-im"><img src="/assets/images/contact/contact-organizers.jpg" width="560" height="995" alt="" loading="lazy" style="object-position:center 28%"></div>
        <div class="ct-bd"><?= $ctIcon('brief') ?><h3>For Event Organizers</h3><p>Need help registering, publishing an event, managing ticket sales or reaching more attendees? Contact our team for assistance.</p></div>
      </article>
      <article class="ct-hc reveal reveal-delay-1">
        <div class="ct-im"><img src="/assets/images/contact/contact-buyers.jpg" width="560" height="840" alt="" loading="lazy" style="object-position:center 35%"></div>
        <div class="ct-bd"><?= $ctIcon('ticket') ?><h3>For Ticket Buyers</h3><p>Having trouble booking a ticket, completing payment or accessing your digital ticket? We're here to help.</p></div>
      </article>
      <article class="ct-hc reveal reveal-delay-2">
        <div class="ct-im"><img src="/assets/images/contact/contact-partners.jpg" width="640" height="853" alt="" loading="lazy" style="object-position:center 55%"></div>
        <div class="ct-bd"><?= $ctIcon('hand') ?><h3>For Partnerships &amp; Business Inquiries</h3><p>Interested in partnering with Obitickets or exploring business opportunities? We'd love to hear from you.</p></div>
      </article>
    </div>
  </section>

  <section class="wrap ct-formwrap" id="send">
    <div class="ct-form reveal">
      <div class="ct-side">
        <img src="/assets/images/contact/contact-message.jpg" width="735" height="1103" alt="" loading="lazy">
        <div class="ct-side-in">
          <h2>Send Us a Message</h2>
          <p>Have a specific question or inquiry? Complete the contact form below and our team will get back to you.</p>
        </div>
      </div>
      <div class="ct-fm">
        <?php if (isset($_GET['sent'])): ?>
          <div class="alert alert-success">Thanks — your message is in. We'll reply by email soon.</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
          <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <form method="post" action="/contact.php#send" novalidate>
          <?= csrf_field() ?>
          <div class="ct-fgrid">
            <div class="ct-fld">
              <label for="name">Full Name</label>
              <input id="name" name="name" type="text" value="<?= htmlspecialchars($name) ?>" autocomplete="name" required>
            </div>
            <div class="ct-fld">
              <label for="email">Email Address</label>
              <input id="email" name="email" type="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
            </div>
            <div class="ct-fld ct-full">
              <label for="phone">Phone Number</label>
              <input id="phone" name="phone" type="tel" value="<?= htmlspecialchars($phone) ?>" placeholder="+256 7xx xxx xxx" autocomplete="tel">
            </div>
            <div class="ct-fld ct-full">
              <label for="topic">Subject / Inquiry Type</label>
              <select id="topic" name="topic">
                <?php foreach (CONTACT_TOPICS as $t): ?>
                  <option value="<?= htmlspecialchars($t) ?>" <?= $topic === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ct-fld ct-full">
              <label for="message">Your Message</label>
              <textarea id="message" name="message" rows="6" required><?= htmlspecialchars($message) ?></textarea>
            </div>
          </div>
          <button class="ct-send" type="submit">SEND MESSAGE <svg viewBox="0 0 24 24" aria-hidden="true"><?= $ctIcons['send'] ?></svg></button>
          <p class="ct-note">We typically reply within one business day.</p>
        </form>
      </div>
    </div>
  </section>

  <section class="wrap ct-commit reveal">
    <h2>Our Commitment</h2>
    <p>At Obitickets, we value every organizer, attendee and partner. We're committed to providing helpful support and making your event ticketing experience as simple and convenient as possible.</p>
    <div class="ct-rule"></div>
    <p class="ct-closing">OBITICKETS &mdash; DISCOVER. BOOK. ATTEND.</p>
    <p class="ct-closing-sub">Uganda's Events. One Destination.</p>
  </section>

</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
