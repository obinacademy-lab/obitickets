<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Terms of Use — obitickets';
$pageDescription = 'The terms that govern using the obitickets platform, as an attendee or as an event organizer.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Legal</span>
      <h1>Terms of Use</h1>
      <p>The rules for using obitickets, whether you're here to attend events or to sell tickets for your own.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="legal-doc">
      <p class="updated">Last updated: <?= date('j F Y') ?></p>

      <h2>1. What obitickets is</h2>
      <p>obitickets is a ticketing platform that lets organizers list events and sell tickets, and lets attendees discover events and buy tickets, paying by MTN Mobile Money or Airtel Money. obitickets provides the platform and payment processing — the event itself, its content, and whether it actually happens, is the responsibility of the organizer who listed it, not obitickets.</p>

      <h2>2. Your account</h2>
      <p>You need an account to buy or sell tickets. You're responsible for keeping your password secure and for anything that happens under your account. Tell us right away if you think someone else has access to it. You must be old enough to enter a binding contract under the laws of your country to create an account.</p>

      <h2>3. Buying tickets</h2>
      <p>When you buy a ticket, you're entering into a purchase directly for that event. Ticket prices, availability, and event details (date, time, venue) are set by the organizer, and obitickets is not responsible for an organizer's description being inaccurate, though we'll help you get in touch with them if something's wrong. Purchases are covered separately by our <a href="/purchase-terms.php">Purchase Terms</a>.</p>

      <h2>4. Selling tickets as an organizer</h2>
      <p>If you list an event, you confirm you have the right to sell tickets to it and that your event description is accurate. You're responsible for delivering the event as described, for any refunds owed under our <a href="/refunds.php">refund policy</a> if you cancel or materially change your event, and for complying with any local laws or licensing your event requires. obitickets may remove a listing that's misleading, fraudulent, or violates these terms, and may suspend an organizer account for repeated issues.</p>

      <h2>5. Fees</h2>
      <p>obitickets charges organizers a commission on each paid ticket, and charges attendees a small service fee per ticket, shown clearly before checkout. Current rates are available on our <a href="/pricing.php">pricing page</a>.</p>

      <h2>6. Acceptable use</h2>
      <p>You agree not to:</p>
      <ul>
        <li>Use the platform for any unlawful purpose, or to list an event that's fraudulent or misleading</li>
        <li>Attempt to circumvent obitickets' payment system to avoid fees</li>
        <li>Interfere with the platform's normal operation (e.g. scraping, automated abuse, attempting to breach security)</li>
        <li>Resell or transfer a ticket in a way that misrepresents its origin or price without the organizer's consent</li>
      </ul>

      <h2>7. Liability</h2>
      <p>obitickets provides the platform "as is." We aren't liable for an organizer's conduct, an event's cancellation or quality, or losses beyond the amount you paid through the platform, except where the law doesn't allow that limit.</p>

      <h2>8. Changes to these terms</h2>
      <p>We may update these terms as the platform grows. If we make a material change, we'll post the update here with a new "last updated" date. Continuing to use obitickets after a change means you accept the new terms.</p>

      <h2>9. Governing law</h2>
      <p>These terms are governed by the laws of Uganda.</p>

      <h2>10. Contact</h2>
      <p>Questions about these terms? <a href="/contact.php">Get in touch</a> and we'll help.</p>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
