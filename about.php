<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About — obitickets';
$pageDescription = 'Obitickets is a modern event discovery and ticketing platform designed to transform how people discover events, purchase tickets and manage event experiences.';
$bodyClass = 'classic-page about-mosaic';
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

<div class="am">

  <section class="wrap am-hero">
    <div class="am-copy">
      <span class="am-kick hero-in d1">About Obitickets</span>
      <h1 class="hero-in d1">Ticketing Made <em>Simple</em></h1>
      <p class="am-sub hero-in d3">&mdash; For Organizers and Attendees Across Africa.</p>
      <p class="am-lead hero-in d3">Obitickets is a modern event discovery and ticketing platform designed to transform how people discover events, purchase tickets and manage event experiences.</p>
      <div class="am-btns hero-in d4">
        <a class="am-btn am-btn-red" href="/">Browse events <svg width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
        <a class="am-btn am-btn-line" href="/signup.php">Start selling</a>
      </div>
    </div>
    <div class="am-mos hero-in d5">
      <figure class="am-ph am-m1"><img src="/assets/images/about/about-friends.jpg" width="640" height="1146" alt="Two friends sharing drinks and smiling" fetchpriority="high"><span class="am-tag"><b>10%</b><small>Commission, only when you sell</small></span></figure>
      <figure class="am-ph"><img src="/assets/images/about/about-rugby.jpg" width="640" height="954" alt="Players competing for the ball in a rugby match"></figure>
      <figure class="am-ph"><img src="/assets/images/about/about-party.jpg" width="560" height="560" alt="Guests laughing at a party"></figure>
    </div>
  </section>

  <section class="wrap am-two">
    <p class="reveal">We bring event organizers, promoters and attendees together in one convenient digital marketplace, making it easier to publish events, sell tickets online and connect people with experiences worth attending.</p>
    <p class="reveal reveal-delay-1">Our mission is to simplify event ticketing through accessible technology, convenient digital payments and smarter event management solutions.</p>
  </section>

  <section class="wrap am-what">
    <h2 class="reveal">What We Do</h2>
    <p class="am-ln reveal reveal-delay-1">Obitickets provides a platform where:</p>
    <div class="am-grid">
      <div class="am-g reveal">
        <span class="am-ic"><svg width="22" height="22" aria-hidden="true"><use href="#ic-briefcase"/></svg></span>
        <p><strong>Event Organizers</strong> can register, publish events, promote their experiences and manage ticket sales.</p>
      </div>
      <div class="am-g reveal reveal-delay-1">
        <span class="am-ic"><svg width="22" height="22" aria-hidden="true"><use href="#ic-people"/></svg></span>
        <p><strong>Attendees</strong> can discover upcoming events, explore available tickets and book their places online.</p>
      </div>
      <div class="am-g reveal">
        <span class="am-ic"><svg width="22" height="22" aria-hidden="true"><use href="#ic-ticket"/></svg></span>
        <p><strong>Secure Digital Ticketing</strong> makes it easier to generate and validate tickets using QR code technology.</p>
      </div>
      <div class="am-g reveal reveal-delay-1">
        <span class="am-ic"><svg width="22" height="22" aria-hidden="true"><use href="#ic-cash"/></svg></span>
        <p><strong>Convenient Payments</strong> allow customers to purchase tickets through supported mobile money payment options, including MTN Mobile Money and Airtel Money.
          <span class="am-pay"><img src="/assets/images/brands/mtn-momo-logo.png" width="63" height="36" alt="MTN MoMo"><img src="/assets/images/brands/airtel-money-logo.png" width="36" height="36" alt="Airtel Money"></span></p>
      </div>
    </div>
  </section>

  <section class="am-band am-v">
    <img src="/assets/images/about/about-club.jpg" width="1170" height="1560" alt="" loading="lazy">
    <div class="wrap"><div class="am-band-in reveal"><span class="am-kick">Our Vision</span><p>To become a trusted event discovery and ticketing destination across Africa, connecting people to experiences while empowering event organizers with accessible digital tools to grow their events.</p></div></div>
  </section>

  <section class="am-band am-m">
    <img src="/assets/images/about/about-conference.jpg" width="736" height="1120" alt="" loading="lazy">
    <div class="wrap"><div class="am-band-in reveal"><span class="am-kick">Our Mission</span><p>To make event discovery, ticket booking and event management simpler, more accessible and more efficient through technology and innovation.</p></div></div>
  </section>

  <section class="wrap am-why">
    <h2 class="reveal">Why Choose Obitickets?</h2>
    <ul class="am-six">
      <?php foreach ($aboutWhy as $i => $why): ?>
        <li class="reveal<?= $i % 3 ? ' reveal-delay-' . ($i % 3) : '' ?>"><svg width="18" height="18" aria-hidden="true"><use href="#ic-check"/></svg><span><?= htmlspecialchars($why) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="wrap am-promise reveal">
    <span class="am-kick">Our Promise</span>
    <p>At Obitickets, we believe every great event begins with an opportunity to connect. We are committed to making the journey from discovering an event to attending it simpler and more convenient for everyone.</p>
  </section>

  <section class="wrap am-endwrap">
    <div class="am-end reveal">
      <img class="am-end-img" src="/assets/images/about/about-club.jpg" width="1170" height="1560" alt="" loading="lazy">
      <div class="am-end-copy">
        <h2><span class="am-end-brand">OBITICKETS &mdash;</span> <span class="am-w">DISCOVER.</span> <span class="am-w">BOOK.</span> <span class="am-w am-w3">ATTEND.</span></h2>
        <p class="am-end-sub">Uganda's Events. One Destination.</p>
      </div>
      <div class="am-glass">
        <div class="am-paystrip"><span>Pay with</span><img src="/assets/images/brands/mtn-momo-logo.png" width="63" height="36" alt="MTN MoMo"><img src="/assets/images/brands/airtel-money-logo.png" width="36" height="36" alt="Airtel Money"></div>
        <a class="am-btn am-btn-red" href="/">Browse events <svg width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
        <a class="am-btn am-btn-white" href="/signup.php">Start selling &mdash; it's free</a>
      </div>
    </div>
  </section>

</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
