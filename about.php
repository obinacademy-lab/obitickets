<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About — obitickets';
$pageDescription = 'Obitickets is a modern event discovery and ticketing platform designed to transform how people discover events, purchase tickets and manage event experiences.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

$aboutWhy = [
    'Simple and convenient online ticket booking.',
    'Easy event publishing for organizers.',
    'Digital tickets with QR code validation.',
    'Convenient supported mobile money payments.',
    'A central destination for discovering events.',
    'Opportunities for organizers to reach more attendees.',
];
?>

<div class="wrap">

  <section class="ah-hero">
    <div class="hero-glow" id="heroGlow"></div>
    <span class="orb orb1"></span><span class="orb orb2"></span><span class="orb orb3"></span>
    <div class="ah-hero-grid">
      <div>
        <span class="kicker hero-in d1"><i></i>About Obitickets</span>
        <h1><span class="split-heading" data-split id="heroHeading">Ticketing Made Simple</span><span class="ah-sub hero-in d3">&mdash; For Organizers and Attendees Across Africa.</span></h1>
        <p class="dek hero-in d3">Obitickets is a modern event discovery and ticketing platform designed to transform how people discover events, purchase tickets and manage event experiences.</p>
        <div class="ah-hero-btns hero-in d4">
          <span class="magnetic"><a class="btn btn-purple" href="/">Browse events</a></span>
          <span class="magnetic"><a class="btn btn-line" href="/signup.php">Start selling</a></span>
        </div>
      </div>
      <div class="tilt-wrap hero-in d5" id="tiltHero">
        <div class="glow-frame reveal" id="heroImgWrap">
          <div class="frame-inner curtain" id="heroCurtain"><img src="/assets/images/about/dancers.jpg" alt="Traditional dance troupe performing at a community festival in Uganda"></div>
          <div class="badge"><span class="n mono">10%</span><span class="l">Commission &mdash;<br>only when you sell</span></div>
        </div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="story-grid">
      <div class="story-media curtain parallax" data-speed="0.12"><img src="/assets/images/about/crowd.jpg" alt="Crowd dancing at a night event"></div>
      <div class="story-text ah-lead">
        <p class="reveal">We bring event organizers, promoters and attendees together in one convenient digital marketplace, making it easier to publish events, sell tickets online and connect people with experiences worth attending.</p>
        <p class="reveal reveal-delay-1">Our mission is to simplify event ticketing through accessible technology, convenient digital payments and smarter event management solutions.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <h2 class="reveal">What We Do</h2>
      <p class="reveal reveal-delay-1">Obitickets provides a platform where:</p>
    </div>
    <div class="principle-grid principle-grid-2 principle-grid-wide">
      <div class="principle-card reveal tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-briefcase"/></svg></div>
        <p><strong>Event Organizers</strong> can register, publish events, promote their experiences and manage ticket sales.</p>
      </div>
      <div class="principle-card reveal reveal-delay-1 tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-people"/></svg></div>
        <p><strong>Attendees</strong> can discover upcoming events, explore available tickets and book their places online.</p>
      </div>
      <div class="principle-card reveal tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-ticket"/></svg></div>
        <p><strong>Secure Digital Ticketing</strong> makes it easier to generate and validate tickets using QR code technology.</p>
      </div>
      <div class="principle-card reveal reveal-delay-1 tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-cash"/></svg></div>
        <p><strong>Convenient Payments</strong> allow customers to purchase tickets through supported mobile money payment options, including MTN Mobile Money and Airtel Money.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="story-grid">
      <div class="story-media curtain parallax" data-speed="0.14"><img src="/assets/images/about/dancers.jpg" alt="Traditional dance troupe performing at a community festival in Uganda"></div>
      <div class="story-text ah-lead">
        <span class="kicker reveal">Our Vision</span>
        <p class="reveal reveal-delay-1">To become a trusted event discovery and ticketing destination across Africa, connecting people to experiences while empowering event organizers with accessible digital tools to grow their events.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="story-grid">
      <div class="story-media curtain parallax" data-speed="0.1"><img src="/assets/images/about/attendee.jpg" alt="Attendee laughing at a night event"></div>
      <div class="story-text ah-lead">
        <span class="kicker reveal">Our Mission</span>
        <p class="reveal reveal-delay-1">To make event discovery, ticket booking and event management simpler, more accessible and more efficient through technology and innovation.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <h2 class="reveal">Why Choose Obitickets?</h2>
    </div>
    <ul class="why-grid">
      <?php foreach ($aboutWhy as $i => $why): ?>
        <li class="why-item reveal<?= $i % 3 ? ' reveal-delay-' . ($i % 3) : '' ?>"><span class="why-ic"><svg width="16" height="16" aria-hidden="true"><use href="#ic-check"/></svg></span><span><?= htmlspecialchars($why) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-promise reveal">
      <span class="kicker" style="justify-content:center">Our Promise</span>
      <p>At Obitickets, we believe every great event begins with an opportunity to connect. We are committed to making the journey from discovering an event to attending it simpler and more convenient for everyone.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta reveal">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Get started</span>
        <h2>OBITICKETS &mdash; DISCOVER. BOOK. ATTEND.</h2>
        <p>Uganda's Events. One Destination.</p>
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
