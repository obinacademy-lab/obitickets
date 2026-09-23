<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Our team — obitickets';
$pageDescription = 'Meet the two people behind obitickets, and why we built it.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Our team</span>
      <h1>Two people, one broken ticket line.</h1>
      <p>obitickets is built by a small team in Kampala who got tired of watching organizers and attendees deal in screenshots and promises instead of real tickets.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="ah-section-head">
      <span class="kicker">Why we started this</span>
      <h2>A ticket should just work.</h2>
    </div>
    <div style="max-width:640px; margin:0 auto; text-align:center;">
      <p style="color:var(--muted); line-height:1.75;">We kept seeing the same thing at events around Kampala: doors run off forwarded mobile money screenshots, organizers chasing payments days after the show, and attendees with nothing to prove they'd actually paid. So we built obitickets &mdash; a real checkout, a real QR ticket, and a scan at the door that just says yes or no.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="team-grid">
      <div class="team-card">
        <div class="team-avatar">OI</div>
        <h3>Obin Ivan</h3>
        <div class="role">Founder &amp; CEO</div>
        <p>Leads product and the day-to-day running of obitickets &mdash; focused on keeping it simple for organizers and honest for attendees.</p>
      </div>
      <div class="team-card">
        <div class="team-avatar">HM</div>
        <h3>Heis Mercy</h3>
        <div class="role">Co-Founder &amp; CTO</div>
        <p>Builds and runs the engineering behind obitickets &mdash; from checkout and payments to the QR check-in that happens at the door.</p>
      </div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Get in touch</span>
        <h2>Want to talk to us directly?</h2>
        <p>Questions, feedback, or just want to say hi &mdash; we'd love to hear from you.</p>
        <div class="ah-cta-btns">
          <a class="btn btn-purple" href="/contact.php">Contact us</a>
          <a class="btn btn-line" href="/">Browse events</a>
        </div>
      </div>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
