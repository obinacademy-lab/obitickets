<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Help centre — obitickets';
$pageDescription = 'Answers to common questions about buying tickets, selling events, check-in and payments on obitickets.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Help centre</span>
      <h1>Answers, not runaround.</h1>
      <p>The most common questions from attendees and organizers — if yours isn't here, just <a href="/contact.php" style="color:var(--purple-deep); font-weight:700;">send us a message</a>.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="ah-section-head">
      <span class="kicker">For attendees</span>
      <h2>Buying and using tickets</h2>
    </div>
    <div style="max-width:680px; margin:0 auto">
      <div class="faq-item">
        <div class="faq-q">How do I buy a ticket?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Find an event, choose your ticket tiers on the event page, and pay by MTN MoMo, Airtel Money or card. Your ticket — a scannable QR code — lands instantly on the confirmation page and in "My tickets".</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Where do I find my tickets after buying?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Go to "My tickets" from the menu once you're logged in — every order and its QR codes are listed there, not just the one from your confirmation email.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">What happens at the door?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Show your QR code (on your phone or printed) to be scanned in. Each code is unique and can only be used once, so there's nothing to fake and no list to be misspelled on.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Can I get a refund?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">See our <a href="/refunds.php" style="color:var(--purple-deep); font-weight:700;">refunds policy</a> — generally tickets are final unless the event is cancelled or postponed.</div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker">For organizers</span>
      <h2>Selling and managing events</h2>
    </div>
    <div style="max-width:680px; margin:0 auto">
      <div class="faq-item">
        <div class="faq-q">How do I list an event?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Sign up, then use "Create an event" to add your event details, banner, gallery photos and ticket tiers. You can save it as a draft or publish it right away.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">How much does it cost to sell tickets?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Listing is free. We take a 10% commission, only on tickets that actually sell — see our <a href="/pricing.php" style="color:var(--purple-deep); font-weight:700;">pricing page</a> for the full breakdown.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">How do I check attendees in at the door?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Open your event's check-in page from "My events". You can type or scan (with any USB barcode/QR scanner) each ticket code as attendees arrive — it's checked against sold tickets instantly.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Can I edit an event after publishing it?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Yes — details, banner, gallery and ticket tiers can all be edited from "My events". A tier that already has sales against it can be updated but not deleted, so a buyer's ticket is never pulled out from under them.</div>
      </div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Still stuck?</span>
        <h2>We're happy to help.</h2>
        <p>Send us a message and we'll get back to you, usually within one business day.</p>
        <div class="ah-cta-btns">
          <a class="btn btn-purple" href="/contact.php">Contact us</a>
          <a class="btn btn-line" href="/">Browse events</a>
        </div>
      </div>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
