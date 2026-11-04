<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Pricing: every figure on this page is read from the code that charges it (PLATFORM_COMMISSION_RATE and
// SERVICE_FEE_PER_TICKET in includes/payments.php), so what the page says and what checkout takes cannot disagree.
$rate = commission_percent_label();
$exampleTicket = 100000;
$exampleCommission = round($exampleTicket * PLATFORM_COMMISSION_RATE);
$exampleBalance = $exampleTicket - $exampleCommission;
$buyerFee = SERVICE_FEE_PER_TICKET;

$pageTitle = 'Pricing — Simple, Transparent Ticketing | obitickets';
$pageDescription = "Simple, transparent ticketing on obitickets: free organizer registration, no monthly subscriptions, a {$rate}% commission only on paid tickets you sell, and 0% commission on free events.";
$bodyClass = 'classic-page pricing-page';
include __DIR__ . '/includes/header.php';

$features = [
    ['ic-user', 'Free organizer registration', 'Create your organizer account and get started without paying a registration fee.'],
    ['ic-cal', 'Event publishing', 'Create, publish and manage your events through the Obitickets platform.'],
    ['ic-ticket', 'Online ticket sales', 'Make your event tickets available to attendees online.'],
    ['ic-phone', 'MTN & Airtel Mobile Money', 'Accept ticket payments through supported mobile money payment options.'],
    ['ic-scan', 'Digital QR tickets', 'Generate digital tickets with QR codes for convenient event entry validation.'],
    ['ic-chart', 'Event management', 'Manage your ticket categories, monitor sales and access available event reports through your organizer dashboard.'],
    ['ic-search', 'Event discovery', 'Give your event an opportunity to be discovered by people browsing Obitickets.'],
];
$steps = [
    'Register your organizer account for free.',
    'Create and publish your event.',
    'Set your ticket prices and quantities.',
    'Share your event and start receiving bookings.',
    "Obitickets deducts its {$rate}% commission from successful paid ticket sales.",
    'Your remaining payable balance is calculated after applicable deductions.',
];
?>

<main class="pr">

  <section class="pr-hero">
    <div class="pr-wrap pr-hero-in">
      <div>
        <p class="pr-kick pr-gold pr-rise" style="--d:100">Obitickets pricing</p>
        <h1><span class="split-heading" data-split>Simple, Transparent Ticketing. No Complicated Pricing.</span></h1>
        <p class="pr-lede pr-rise" style="--d:500">At Obitickets, we believe event organizers should focus on creating great experiences while we make ticket selling simpler. Our pricing is straightforward: <b>pay only when you sell a ticket.</b></p>
        <div class="pr-acts pr-rise" style="--d:700">
          <a class="pr-btn pr-btn-gold" href="/signup.php?role=ORGANIZER">Register as an organizer <svg width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
          <a class="pr-btn pr-btn-ghost" href="#standard">See how it works</a>
        </div>
      </div>
      <ul class="pr-zeros pr-rise" style="--d:450">
        <li><b>0</b><span><strong>Registration fees</strong>Create your organizer account for free.</span></li>
        <li><b>0</b><span><strong>Monthly subscriptions</strong>Nothing to pay while you are not selling.</span></li>
        <li><b>0</b><span><strong>Complicated plans</strong>One standard rate on every paid ticket.</span></li>
      </ul>
    </div>
  </section>

  <section class="pr-sec" id="standard">
    <div class="pr-wrap pr-std">
      <div class="reveal">
        <p class="pr-kick">Our standard pricing</p>
        <div class="pr-big"><span><?= htmlspecialchars($rate) ?></span><sup>%</sup></div>
        <p class="pr-big-l">Commission per paid ticket</p>
        <div class="pr-rule"></div>
        <div class="pr-prose">
          <p>Obitickets charges a standard <b><?= htmlspecialchars($rate) ?>% platform commission</b> on every successfully sold paid ticket, regardless of the event category or ticket price.</p>
          <p>The commission is automatically calculated and deducted from the ticket proceeds.</p>
        </div>
        <div class="pr-receipt">
          <h2>Example: if you sell a ticket for UGX <?= number_format($exampleTicket) ?></h2>
          <div class="ln"><span>Ticket price</span><b>UGX <?= number_format($exampleTicket) ?></b></div>
          <div class="ln minus"><span>Obitickets commission (<?= htmlspecialchars($rate) ?>%)</span><b>UGX <?= number_format($exampleCommission) ?></b></div>
          <div class="ln tot"><span>Organizer balance</span><b>UGX <?= number_format($exampleBalance) ?></b></div>
          <p class="note">The organizer balance is before any applicable payment gateway charges, taxes or other agreed deductions.</p>
        </div>
      </div>

      <aside class="pr-calc reveal reveal-delay-1" id="prCalc" data-rate="<?= htmlspecialchars((string) PLATFORM_COMMISSION_RATE) ?>" data-rate-label="<?= htmlspecialchars($rate) ?>" aria-labelledby="prCalcH">
        <h2 id="prCalcH">Try your own ticket</h2>
        <p class="pr-sub2">See exactly what stays with you.</p>
        <label for="prPrice">Ticket price</label>
        <div class="pr-money"><span>UGX</span><input id="prPrice" inputmode="numeric" value="<?= number_format($exampleTicket) ?>" autocomplete="off"></div>
        <div class="pr-chips" role="group" aria-label="Quick prices">
          <?php foreach ([20000, 50000, 100000, 250000] as $p): ?><button type="button" data-p="<?= $p ?>" aria-pressed="<?= $p === $exampleTicket ? 'true' : 'false' ?>"><?= number_format($p) ?></button><?php endforeach; ?>
        </div>
        <div class="pr-row2">
          <div><span class="pr-lb">Tickets sold</span><div class="pr-step"><button type="button" data-q="-1" aria-label="One fewer ticket">&minus;</button><output id="prQty" aria-live="polite">1</output><button type="button" data-q="1" aria-label="One more ticket">+</button></div></div>
          <label class="pr-free"><input type="checkbox" id="prFree"><i></i>Free ticket</label>
        </div>
        <div class="pr-out" aria-live="polite">
          <div class="ln"><span id="prGl">Ticket sales</span><b id="prGross">UGX <?= number_format($exampleTicket) ?></b></div>
          <div class="ln minus"><span id="prCl">Commission (<?= htmlspecialchars($rate) ?>%)</span><b id="prCom">&minus; UGX <?= number_format($exampleCommission) ?></b></div>
          <div class="bal"><span>You keep</span><b id="prBal">UGX <?= number_format($exampleBalance) ?></b></div>
        </div>
        <div class="pr-split" aria-hidden="true"><i class="keep" id="prKeep" style="width:<?= 100 - (float) $rate ?>%"></i><i class="take" id="prTake" style="width:<?= (float) $rate ?>%"></i></div>
        <div class="pr-splitl"><span id="prKeepL"><?= 100 - (float) $rate ?>% to you</span><span id="prTakeL"><?= htmlspecialchars($rate) ?>% commission</span></div>
        <p class="pr-fine">Before any applicable payment gateway charges, taxes or other agreed deductions. Amounts are rounded to the nearest shilling.</p>
        <p class="pr-fine">Attendees also pay a flat UGX <?= number_format($buyerFee) ?> service fee per paid ticket at checkout, on top of the ticket price. It does not come out of your balance.</p>
      </aside>
    </div>
  </section>

  <section class="pr-free-band">
    <div class="pr-wrap pr-free-in">
      <div class="pr-zr reveal">0<small>%</small></div>
      <div class="reveal reveal-delay-1">
        <p class="pr-kick pr-gold">Hosting a free event?</p>
        <h2>Free events? Zero commission!</h2>
        <p>You can publish free events on Obitickets without paying ticket-sales commission.</p>
        <div class="pr-eq">Free tickets = 0% commission</div>
      </div>
    </div>
  </section>

  <section class="pr-sec pr-get">
    <div class="pr-wrap">
      <div class="pr-head2 reveal">
        <div><p class="pr-kick">What you get with Obitickets</p><h2>Everything you need to sell tickets, included.</h2></div>
        <p class="pr-sub">Your <?= htmlspecialchars($rate) ?>% platform commission gives you access to our event ticketing tools and features.</p>
      </div>
      <div class="pr-feats">
        <?php foreach ($features as $i => $f): ?>
          <article class="pr-feat reveal<?= $i === count($features) - 1 ? ' pr-last' : '' ?>">
            <span class="ic"><svg width="22" height="22" aria-hidden="true"><use href="#<?= $f[0] ?>"/></svg></span>
            <h3><?= htmlspecialchars($f[1]) ?></h3>
            <p><?= htmlspecialchars($f[2]) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="pr-sec pr-how">
    <div class="pr-wrap">
      <div class="reveal pr-head1"><p class="pr-kick">How our pricing works</p><h2>Six steps, from sign-up to your balance.</h2></div>
      <ol class="pr-steps">
        <?php foreach ($steps as $i => $t): $key = $i === 4; ?>
          <li class="pr-st reveal<?= $key ? ' pr-key' : '' ?>"><span class="n"><?= $i + 1 ?></span><?php if ($key): ?><small>The only charge</small><?php endif; ?><p><?= htmlspecialchars($t) ?></p></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <section class="pr-sec pr-clear">
    <div class="pr-wrap pr-clear-in">
      <div class="reveal"><p class="pr-kick">Transparent pricing</p><h2>No surprises.</h2><div class="pr-rule"></div><p class="pr-sub" style="margin-top:0">We believe organizers deserve to understand exactly what they pay.</p></div>
      <div class="reveal reveal-delay-1">
        <div class="pr-prose"><p>Our standard platform commission is <b><?= htmlspecialchars($rate) ?>%</b>. Any applicable payment gateway charges, taxes or other agreed deductions are accounted for separately and disclosed appropriately.</p></div>
        <ul class="pr-ticks">
          <li><span><svg width="16" height="16" aria-hidden="true"><use href="#ic-check"/></svg></span>No hidden organizer registration fees</li>
          <li><span><svg width="16" height="16" aria-hidden="true"><use href="#ic-check"/></svg></span>No monthly subscriptions</li>
          <li><span><svg width="16" height="16" aria-hidden="true"><use href="#ic-check"/></svg></span>One standard commission, <?= htmlspecialchars($rate) ?>% on paid tickets</li>
          <li><span><svg width="16" height="16" aria-hidden="true"><use href="#ic-check"/></svg></span>Attendees pay a flat UGX <?= number_format($buyerFee) ?> service fee per paid ticket, separate from your commission</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="pr-cta">
    <div class="pr-wrap reveal">
      <p class="pr-kick pr-gold">Create your event today</p>
      <h2>Ready to sell tickets?</h2>
      <p class="l">Take your event online with Obitickets. Register as an organizer, publish your event and start reaching potential attendees.</p>
      <a class="pr-btn pr-btn-gold" href="/signup.php?role=ORGANIZER">Register as an organizer <svg width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
      <div class="pr-sign"><b>OBITICKETS</b><span>Discover. Book. Attend.</span><em>Uganda's Events. One Destination.</em></div>
    </div>
  </section>

</main>

<script src="/assets/js/pricing.js?v=<?= @filemtime(__DIR__ . '/assets/js/pricing.js') ?: time() ?>"></script>
<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
