<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Pricing — obitickets';
$pageDescription = 'Simple, honest pricing for obitickets: free to list, 10% commission only on tickets you actually sell, and a flat UGX 700 buyer fee. No monthly fees, no surprises.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';

$exampleTicketPrice = 20000;
$exampleQty = 1;
$exampleSubtotal = $exampleTicketPrice * $exampleQty;
$exampleBuyerFee = SERVICE_FEE_PER_TICKET * $exampleQty;
$exampleBuyerTotal = $exampleSubtotal + $exampleBuyerFee;
$exampleCommission = round($exampleSubtotal * PLATFORM_COMMISSION_RATE);
$examplePayout = $exampleSubtotal - $exampleCommission;
?>

<div class="wrap">

  <section class="ah-hero">
    <div class="hero-glow" id="heroGlow"></div>
    <span class="orb orb1"></span><span class="orb orb2"></span><span class="orb orb3"></span>
    <div class="ah-hero-grid">
      <div>
        <span class="kicker hero-in d1"><i></i>Pricing</span>
        <h1><span class="split-heading" data-split id="heroHeading">Simple pricing. No surprises.</span></h1>
        <p class="dek hero-in d3">Listing your event is free. We only make money when you do — a flat 10% commission on tickets you actually sell, nothing upfront, nothing if the event doesn't sell.</p>
        <div class="ah-hero-btns hero-in d4">
          <span class="magnetic"><a class="btn btn-purple" href="/signup.php">Start selling</a></span>
          <span class="magnetic"><a class="btn btn-line" href="/">Browse events</a></span>
        </div>
      </div>
      <div class="tilt-wrap hero-in d5" id="tiltHero">
        <div class="glow-frame reveal" id="heroImgWrap">
          <div class="frame-inner curtain" id="heroCurtain"><img src="/assets/images/about/crowd.jpg" alt="Crowd dancing at a night event"></div>
          <div class="badge"><span class="n mono">0</span><span class="l">Monthly fees —<br>ever</span></div>
        </div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">How it works</span>
      <h2><span class="split-heading" data-split>One rate for organizers, one flat fee for attendees.</span></h2>
    </div>
    <div class="price-grid">
      <div class="price-card reveal tilt-card">
        <div class="price-card-ic"><svg width="20" height="20"><use href="#ic-briefcase"/></svg></div>
        <h3>For organizers</h3>
        <span class="price-num">10<span class="u">%</span></span>
        <p>Commission on the ticket subtotal — only ever charged on tickets you actually sell.</p>
        <ul class="price-list">
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> Free to list, no setup or monthly fees</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> Unlimited ticket tiers, your own pricing</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> Real-time sales dashboard</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> QR check-in scanning at the door</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> MTN MoMo, Airtel Money and card, built in</li>
        </ul>
      </div>
      <div class="price-card reveal reveal-delay-1 tilt-card">
        <div class="price-card-ic"><svg width="20" height="20"><use href="#ic-pin"/></svg></div>
        <h3>For attendees</h3>
        <span class="price-num mono">UGX 700</span>
        <p>A flat service fee added at checkout, on top of the ticket price — the same on every ticket, regardless of price.</p>
        <ul class="price-list">
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> Instant, scannable QR ticket</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> Pay by MTN MoMo, Airtel Money or card</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> No hidden charges at the door</li>
          <li><svg width="16" height="16"><use href="#ic-shield"/></svg> Real event photos before you buy</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">See the math</span>
      <h2><span class="split-heading" data-split>A UGX 20,000 ticket, start to finish.</span></h2>
    </div>
    <div class="price-example reveal">
      <h3>Regular ticket</h3>
      <div class="checkout-card" style="border:none; padding:20px 0 0">
        <div class="checkout-line"><span>Ticket price</span><span class="mono"><?= 'UGX ' . number_format($exampleSubtotal) ?></span></div>
        <div class="checkout-line"><span>Buyer service fee</span><span class="mono"><?= 'UGX ' . number_format($exampleBuyerFee) ?></span></div>
        <div class="checkout-line total"><span>Buyer pays</span><span class="mono"><?= 'UGX ' . number_format($exampleBuyerTotal) ?></span></div>
      </div>
      <div class="checkout-card" style="border:none; padding:8px 0 0">
        <div class="checkout-line"><span>obitickets commission (10%)</span><span class="mono">&minus;<?= 'UGX ' . number_format($exampleCommission) ?></span></div>
        <div class="checkout-line total"><span>You receive</span><span class="mono"><?= 'UGX ' . number_format($examplePayout) ?></span></div>
      </div>
    </div>
  </section>

  <section class="ah-section">
    <div class="ah-section-head">
      <span class="kicker reveal">Questions</span>
      <h2><span class="split-heading" data-split>Pricing, plainly.</span></h2>
    </div>
    <div class="reveal" style="max-width:680px; margin:0 auto">
      <div class="faq-item">
        <div class="faq-q">Are there setup or monthly fees?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">No. Listing your event is free and there's no subscription. We only make money from the 10% commission on tickets that actually sell.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">What if my event doesn't sell any tickets?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">You pay nothing. Commission is only ever taken from tickets that actually sell — there's no cost for listing an event that doesn't take off.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Is the buyer's service fee the same as your commission?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">No — they're separate. The UGX 700 fee is paid by the attendee on top of the ticket price; the 10% commission comes out of what you as the organizer receive. They never overlap.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Can I set my own ticket prices and tiers?<svg width="16" height="16"><use href="#ic-chev"/></svg></div>
        <div class="faq-a">Yes — you control pricing entirely, with as many ticket tiers as you like (Regular, VIP, early bird, and so on).</div>
      </div>
    </div>
  </section>

  <section class="ah-section" style="padding-top:0">
    <div class="ah-cta reveal">
      <div class="cta-ring"></div>
      <div class="ah-cta-inner">
        <span class="kicker" style="justify-content:center">Get started</span>
        <h2>Ready to sell your first ticket?</h2>
        <p>It takes a few minutes to list your event — no upfront cost, ever.</p>
        <div class="ah-cta-btns">
          <span class="magnetic"><a class="btn btn-purple" href="/signup.php">Start selling — it's free</a></span>
          <span class="magnetic"><a class="btn btn-line" href="/">Browse events</a></span>
        </div>
      </div>
    </div>
  </section>

</div>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
