<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Privacy Policy — obitickets';
$pageDescription = 'What information obitickets collects, why, and how it is used and protected.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Legal</span>
      <h1>Privacy Policy</h1>
      <p>What we collect, why, and how it's kept safe — written to actually be read, not just filed away.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="legal-doc">
      <p class="updated">Last updated: <?= date('j F Y') ?></p>

      <h2>1. Information we collect</h2>
      <p>When you create an account, we collect your name, email address, phone number (optional), and a securely hashed password — we never store your password in plain text. If you sell tickets as an organizer, we also collect your organization name and bio if you add one.</p>
      <p>When you buy a ticket, your mobile money payment is handled directly by our payment processor (iotec) — obitickets never sees or stores your mobile money PIN, and doesn't store your full mobile money account details beyond the phone number used for that order.</p>
      <p>When you log in or sign up, we automatically record the date and time, your device type, browser, and operating system, and the city/country your connection appears to come from (resolved from your IP address via a third-party lookup) — used only for account activity monitoring and security, never for advertising. We don't keep the raw IP address itself.</p>

      <h2>2. How we use it</h2>
      <ul>
        <li>To create and secure your account, and let you log in</li>
        <li>To process ticket purchases and deliver your tickets by email</li>
        <li>To show organizers their event sales and manage payouts</li>
        <li>To respond to support requests and refund claims</li>
        <li>To detect suspicious account activity (unusual login locations, for example)</li>
      </ul>

      <h2>3. Third-party services we use</h2>
      <p>We rely on a small number of trusted providers to run obitickets, each only receiving what they need to do their job:</p>
      <ul>
        <li><strong>iotec</strong> — processes MTN Mobile Money and Airtel Money payments</li>
        <li><strong>Resend</strong> — delivers transactional emails (your tickets, password resets)</li>
        <li><strong>Africa's Talking</strong> — texts your ticket code(s) to the phone number used at checkout; only that phone number and the ticket details are sent to it</li>
        <li>A QR code generation service — turns your ticket code into a scannable image; only the ticket code itself (not your name or email) is sent to it</li>
        <li>An IP geolocation lookup — resolves a login's approximate city/country for the security monitoring described above</li>
      </ul>
      <p>We don't sell your information to anyone, and we don't use advertising trackers.</p>

      <h2>4. Cookies</h2>
      <p>obitickets uses one session cookie to keep you logged in, and a CSRF token to protect your forms from being submitted by another site on your behalf. We don't use tracking or advertising cookies.</p>

      <h2>5. How long we keep it</h2>
      <p>We keep your account and order history for as long as your account is active, since it's how you view past tickets and organizers track their sales. If you ask us to delete your account, we'll remove your personal details, keeping only what we're legally required to retain (such as financial records for tax purposes).</p>

      <h2>6. Your rights</h2>
      <p>You can review and update your account details anytime from your dashboard. To request a copy of your data, ask us to correct something, or delete your account, <a href="/contact.php">contact us</a> — we'll respond within a reasonable time.</p>

      <h2>7. Security</h2>
      <p>Passwords are hashed, not stored as plain text. Payment details are handled entirely by our payment processor, never by obitickets directly. We use standard safeguards (HTTPS, access controls) to protect the data we do hold, though no system is ever 100% guaranteed secure.</p>

      <h2>8. Changes to this policy</h2>
      <p>If we make a material change to how we handle your data, we'll update this page with a new "last updated" date.</p>

      <h2>9. Contact</h2>
      <p>Questions about your privacy? <a href="/contact.php">Get in touch</a> and we'll help.</p>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
