<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About — obitickets';
$pageDescription = 'obitickets is a simple, honest way for Ugandan event organizers to sell tickets and get paid — and for attendees to know the ticket in their hand is real.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="ah-hero">
    <div class="hero-glow" id="heroGlow"></div>
    <span class="orb orb1"></span><span class="orb orb2"></span><span class="orb orb3"></span>
    <div class="ah-hero-grid">
      <div>
        <span class="kicker hero-in d1"><i></i>About obitickets</span>
        <h1><span class="split-heading" data-split id="heroHeading">Ticketing shouldn't run on WhatsApp screenshots.</span></h1>
        <p class="dek hero-in d3">A simple, honest way for Ugandan event organizers to sell tickets and get paid — and for attendees to know the ticket in their hand is real.</p>
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
        <span class="kicker reveal">Our story</span>
        <h3><span class="split-heading" data-split>Built because a ticket used to mean a promise, not proof.</span></h3>
        <p class="reveal reveal-delay-1">Most tickets in Uganda still change hands as a forwarded mobile money screenshot and a promise. Doors get disputed. Organizers chase payments days after the show.</p>
        <p class="reveal reveal-delay-2">We built obitickets to replace that with something both sides can actually trust: a real checkout, a real QR ticket, and a scan at the door that either says yes or no.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">Why it's different</span>
      <h2><span class="split-heading" data-split>Three things that make a ticket worth trusting.</span></h2>
    </div>
    <div class="principle-grid">
      <div class="principle-card reveal tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-shield"/></svg></div>
        <h3>A ticket that can't be faked</h3>
        <p>A unique code, generated at payment and checked once at the door. No two people can use the same one.</p>
      </div>
      <div class="principle-card reveal reveal-delay-1 tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-cal"/></svg></div>
        <h3>Sold out, actually tracked</h3>
        <p>Stock is held and checked at the moment of purchase, so two buyers can never win the same last seat.</p>
      </div>
      <div class="principle-card reveal reveal-delay-2 tilt-card">
        <div class="principle-ic"><svg width="20" height="20"><use href="#ic-heart"/></svg></div>
        <h3>Built for how Uganda pays</h3>
        <p>MTN MoMo and Airtel Money sit next to card as first-class options, not an afterthought.</p>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">On the ground</span>
      <h2><span class="split-heading" data-split>What &quot;seen properly&quot; looks like.</span></h2>
    </div>
    <div class="moment-strip">
      <div class="big curtain parallax" data-speed="0.08"><img src="/assets/images/about/attendee.jpg" alt="Attendee laughing at a night event"></div>
      <div class="stack">
        <div class="thumb curtain reveal-delay-1 parallax" data-speed="0.15"><img src="/assets/images/about/dancers.jpg" alt="Traditional dance troupe performing"></div>
        <div class="thumb curtain reveal-delay-2 parallax" data-speed="0.15"><img src="/assets/images/about/crowd.jpg" alt="Crowd at a night event"></div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="stat-band">
      <div class="stat-tile reveal tilt-card"><div class="n mono" data-count="10" data-suffix="%">0%</div><div class="l">Commission, only when you sell</div></div>
      <div class="stat-tile reveal reveal-delay-1 tilt-card"><div class="n mono">&lt;1min</div><div class="l">Average checkout time</div></div>
      <div class="stat-tile reveal reveal-delay-2 tilt-card"><div class="n mono">UGX</div><div class="l">Currently live across Uganda</div></div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta reveal">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Get started</span>
        <h2>Every ticket, seen properly.</h2>
        <p>Whichever side of the door you're on, obitickets is built to make it simple.</p>
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
