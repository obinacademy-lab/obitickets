<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Refunds — obitickets';
$pageDescription = 'obitickets refund policy: when a ticket is eligible for a refund, and how to request one.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Refunds</span>
      <h1>Our refund policy, plainly.</h1>
      <p>No fine print you need a lawyer for — here's exactly when a ticket qualifies for a refund, and when it doesn't.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="contact-grid">
      <div class="contact-card">
        <h3>You're covered when —</h3>
        <div class="contact-info-list">
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-shield"/></svg></div>
            <div><h4>The event is cancelled</h4><p>Full refund, automatically eligible — no need to prove anything.</p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-cal"/></svg></div>
            <div><h4>The event is postponed</h4><p>You can request a full refund instead of holding your ticket for the new date.</p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-heart"/></svg></div>
            <div><h4>You were charged twice</h4><p>An accidental duplicate order is refunded in full — that one's on us to catch, but tell us if we miss it.</p></div>
          </div>
        </div>
      </div>

      <div class="contact-card">
        <h3>Generally not covered —</h3>
        <div class="contact-info-list">
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-pin"/></svg></div>
            <div><h4>Change of mind</h4><p>Once purchased, tickets are final if you simply can't make it — check event details carefully before buying.</p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-briefcase"/></svg></div>
            <div><h4>Wrong ticket tier</h4><p>Double-check your tier and quantity before paying — orders can't be edited once placed.</p></div>
          </div>
          <div class="contact-info-item">
            <div class="ic"><svg width="18" height="18"><use href="#ic-share"/></svg></div>
            <div><h4>Missed the event</h4><p>obitickets processes the payment, but the event itself is run by its organizer — reach out to them directly for anything beyond our policy above.</p></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker">How to request one</span>
      <h2>Three steps, no forms to hunt for</h2>
    </div>
    <div style="max-width:560px; margin:0 auto; display:flex; flex-direction:column; gap:26px">
      <div class="plain-row">
        <div class="ic mono">1</div>
        <div><h3>Find your order number</h3><p>It's on your confirmation page and in "My tickets" — look for the order starting with #.</p></div>
      </div>
      <div class="plain-row">
        <div class="ic mono">2</div>
        <div><h3><a href="/contact.php" style="color:inherit">Contact us</a> with it</h3><p>Choose "Report an issue" as the topic and include your order number and what happened.</p></div>
      </div>
      <div class="plain-row">
        <div class="ic mono">3</div>
        <div><h3>We'll confirm within one business day</h3><p>Eligible refunds are returned to your original payment method.</p></div>
      </div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Need a refund?</span>
        <h2>We'll sort it out quickly.</h2>
        <p>Reach out with your order number and we'll take it from there.</p>
        <div class="ah-cta-btns">
          <a class="btn btn-purple" href="/contact.php">Contact us</a>
          <a class="btn btn-line" href="/help.php">Visit help centre</a>
        </div>
      </div>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
