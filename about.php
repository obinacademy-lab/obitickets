<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About — obitickets';
$pageDescription = 'ObiTickets is a modern event ticketing platform that makes it easier for organizers to create events, sell tickets, and connect with their audiences — all in one place.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="ah-hero">
    <div class="hero-glow" id="heroGlow"></div>
    <span class="orb orb1"></span><span class="orb orb2"></span><span class="orb orb3"></span>
    <div class="ah-hero-grid">
      <div>
        <span class="kicker hero-in d1"><i></i>About Us</span>
        <h1><span class="split-heading" data-split id="heroHeading">Ticketing Made Simple.</span></h1>
        <p class="dek hero-in d3">ObiTickets is a modern event ticketing platform that makes it easier for organizers to create events, sell tickets, and connect with their audiences — all in one place.</p>
        <div class="ah-hero-btns hero-in d4">
          <span class="magnetic"><a class="btn btn-purple" href="/">Browse events</a></span>
          <span class="magnetic"><a class="btn btn-line" href="/signup.php">Start selling</a></span>
        </div>
      </div>
      <div class="tilt-wrap hero-in d5" id="tiltHero">
        <div class="glow-frame reveal" id="heroImgWrap">
          <div class="frame-inner curtain" id="heroCurtain"><img src="/assets/images/about/dancers.jpg" alt="Traditional dance troupe performing at a community festival in Uganda"></div>
          <div class="badge"><span class="n mono">10%</span><span class="l">Commission —<br>only when you sell</span></div>
        </div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="story-grid">
      <div class="story-media curtain parallax" data-speed="0.12"><img src="/assets/images/about/crowd.jpg" alt="Crowd dancing at a night event"></div>
      <div class="story-text">
        <span class="kicker reveal">What we do</span>
        <h3><span class="split-heading" data-split>From conferences and workshops to concerts, trainings, festivals, networking events, and more.</span></h3>
        <p class="reveal reveal-delay-1">ObiTickets gives organizers the tools to manage their ticketing experience while making it simple for attendees to discover and purchase tickets.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="principle-grid principle-grid-2">
      <div class="principle-card reveal tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-briefcase"/></svg></div>
        <h3>For Event Organizers</h3>
        <p>Create and publish your event, set your ticket prices, and start selling to your audience through a simple digital platform.</p>
        <p>ObiTickets helps you spend less time managing ticket sales manually and more time focusing on creating a successful event.</p>
      </div>
      <div class="principle-card reveal reveal-delay-1 tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-heart"/></svg></div>
        <h3>For Attendees</h3>
        <p>Discover events, explore event details, choose your ticket, and book your experience with ease.</p>
        <p>We make it simple to go from <strong>discovering an event to attending it.</strong></p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">Our mission</span>
      <h2><span class="split-heading" data-split>Simple, accessible, and efficient ticketing.</span></h2>
      <p class="reveal reveal-delay-1">Our mission is to make event ticketing simple, accessible, and efficient for organizers and attendees. We are building a platform that connects great events with the people who want to experience them.</p>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">Our vision</span>
      <h2><span class="split-heading" data-split>Create, launch, and sell tickets without complicated technology.</span></h2>
      <p class="reveal reveal-delay-1">We envision a future where anyone can create, launch, and sell tickets for an event without complicated technology.</p>
      <p class="reveal reveal-delay-2 mono" style="color:var(--purple-deep); font-weight:600; margin-top:20px;">Create. Sell. Connect. Attend.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta reveal">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Get started</span>
        <h2>Welcome to ObiTickets.</h2>
        <p>Your simple solution for event ticketing.</p>
        <div class="ah-cta-btns">
          <span class="magnetic"><a class="btn btn-purple" href="/">Browse events</a></span>
          <span class="magnetic"><a class="btn btn-line" href="/signup.php">Start selling &mdash; it's free</a></span>
        </div>
      </div>
    </div>
  </section>

</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
