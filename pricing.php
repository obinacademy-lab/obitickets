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
$bodyClass = 'classic-page pricing-page motion-page';
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
$zeros = [
    ['Registration fees', 'Create your organizer account for free.'],
    ['Monthly subscriptions', 'Nothing to pay while you are not selling.'],
    ['Complicated plans', 'One standard rate on every paid ticket.'],
];
$ticks = [
    'No hidden organizer registration fees',
    'No monthly subscriptions',
    "One standard commission, {$rate}% on paid tickets",
    'Attendees pay a flat UGX ' . number_format($buyerFee) . ' service fee per paid ticket, separate from your commission',
];
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$ic = static fn (string $id, int $px = 16): string => '<svg width="' . $px . '" height="' . $px . '" aria-hidden="true"><use href="#' . $id . '"/></svg>';
$img = '/assets/images/about/';
?>

<main class="pr">

  <section class="pr-hero">
    <div class="pr-wrap pr-hg">
      <div>
        <p class="pr-kick" data-in="up">Obitickets pricing</p>
        <h1 data-split data-in="split">Simple, Transparent Ticketing. No Complicated Pricing.</h1>
        <p class="pr-lede" data-in="up" style="--d:500">At Obitickets, we believe event organizers should focus on creating great experiences while we make ticket selling simpler. Our pricing is straightforward: <b>pay only when you sell a ticket.</b></p>
        <div class="pr-acts" data-in="up" style="--d:700">
          <a class="pr-btn pri" data-mag href="/signup.php?role=ORGANIZER">Register as an organizer <?= $ic('ic-arrow') ?></a>
          <a class="pr-btn sec" data-mag data-jump="standard" href="#standard">See how it works</a>
        </div>
      </div>
      <div class="pr-ph" data-in="clipup" style="--d:200">
        <img data-prog data-par style="--par:50px" src="<?= $img ?>crowd.jpg" alt="" fetchpriority="high" decoding="async">
        <div class="pr-chip"><b><span data-count="<?= $h($rate) ?>" data-dur="1800"><?= $h($rate) ?></span>%</b><span class="u">Commission per paid ticket</span></div>
      </div>
    </div>
  </section>

  <section>
    <div class="pr-wrap"><ul class="pr-zeros">
      <?php foreach ($zeros as $i => [$t, $d]): ?>
      <li data-in="up"<?= $i ? ' style="--d:' . ($i * 120) . '"' : '' ?>><b data-count="0" data-from="100" data-dur="1500">0</b><div><strong><?= $h($t) ?></strong><span><?= $h($d) ?></span></div></li>
      <?php endforeach; ?>
    </ul></div>
  </section>

  <section class="pr-std" id="standard">
    <div class="pr-wrap pr-sg">
      <div class="pr-pic" data-in="clipup">
        <img data-prog data-par style="--par:50px" src="<?= $img ?>about-club.jpg" alt="" loading="lazy" decoding="async">
        <div class="pr-rc"><div class="pr-rcpt">
          <h3>Example: if you sell a ticket for UGX <?= number_format($exampleTicket) ?></h3>
          <div class="ln"><span>Ticket price</span><b>UGX <?= number_format($exampleTicket) ?></b></div>
          <div class="ln m"><span>Obitickets commission (<?= $h($rate) ?>%)</span><b>UGX <?= number_format($exampleCommission) ?></b></div>
          <div class="ln t"><span>Organizer balance</span><b data-count="<?= (int) $exampleBalance ?>" data-pre="UGX ">UGX <?= number_format($exampleBalance) ?></b></div>
          <p class="note">The organizer balance is before any applicable payment gateway charges, taxes or other agreed deductions.</p>
        </div></div>
      </div>
      <div>
        <p class="pr-kick" data-in="up">Our standard pricing</p>
        <div class="pr-big" data-in="up"><span data-count="<?= $h($rate) ?>" data-dur="1800"><?= $h($rate) ?></span><sup>%</sup></div>
        <p class="pr-u">Commission per paid ticket</p>
        <p class="pr-sent" data-scrub>Obitickets charges a standard <?= $h($rate) ?>% platform commission on every successfully sold paid ticket, regardless of the event category or ticket price.</p>
        <p class="pr-mut" data-in="up">The commission is automatically calculated and deducted from the ticket proceeds.</p>
      </div>
    </div>
  </section>

  <section class="pr-calcsec">
    <div class="pr-wrap" data-in="up">
      <div class="pr-calc" id="prCalc" data-rate="<?= $h((string) PLATFORM_COMMISSION_RATE) ?>" data-rate-label="<?= $h($rate) ?>" role="group" aria-labelledby="prCalcH">
        <div class="cf">
          <div class="ch"><h2 id="prCalcH">Try your own ticket</h2><p class="pr-sub2">See exactly what stays with you.</p></div>
          <label class="pr-cl" for="prPrice">Ticket price</label>
          <div class="pr-money"><span>UGX</span><input id="prPrice" inputmode="numeric" value="<?= number_format($exampleTicket) ?>" autocomplete="off"></div>
          <input id="prPriceR" type="range" min="5000" max="1000000" step="5000" value="<?= $exampleTicket ?>" aria-label="Ticket price slider">
          <label class="pr-cl" for="prQtyR">Tickets sold <output id="prQty" for="prQtyR" aria-live="polite">1</output></label>
          <input id="prQtyR" type="range" min="1" max="200" step="1" value="1">
          <label class="pr-free"><input type="checkbox" id="prFree"> Free ticket</label>
        </div>
        <div class="cr">
          <div class="pr-out" aria-live="polite">
            <div class="ln"><span id="prGl">Ticket sales</span><b id="prGross">UGX <?= number_format($exampleTicket) ?></b></div>
            <div class="ln minus"><span id="prCl">Commission (<?= $h($rate) ?>%)</span><b id="prCom">&minus; UGX <?= number_format($exampleCommission) ?></b></div>
            <div class="bal"><span>You keep</span><b id="prBal">UGX <?= number_format($exampleBalance) ?></b></div>
          </div>
          <div class="pr-split" aria-hidden="true"><i class="keep" id="prKeep" style="width:<?= 100 - (float) $rate ?>%"></i><i class="take" id="prTake" style="width:<?= (float) $rate ?>%"></i></div>
          <div class="pr-splitl"><span id="prKeepL"><?= 100 - (float) $rate ?>% to you</span><span id="prTakeL"><?= $h($rate) ?>% commission</span></div>
          <p class="pr-fine">Before any applicable payment gateway charges, taxes or other agreed deductions. Amounts are rounded to the nearest shilling.</p>
          <p class="pr-fine">Attendees also pay a flat UGX <?= number_format($buyerFee) ?> service fee per paid ticket at checkout, on top of the ticket price. It does not come out of your balance.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="pr-freeb" style="--bgi:url('<?= $img ?>dancers.jpg')">
    <span class="pr-fbg" data-prog data-par style="--par:90px" aria-hidden="true"></span>
    <div class="pr-wrap pr-fg">
      <div class="pr-zr" data-in="up"><span data-count="0" data-from="100" data-dur="1600">0</span><small>%</small></div>
      <div data-in="up" style="--d:150"><p class="pr-kick">Hosting a free event?</p><h2>Free events? Zero commission!</h2><p>You can publish free events on Obitickets without paying ticket-sales commission.</p><span class="pr-eq">Free tickets = 0% commission</span></div>
    </div>
  </section>

  <section class="pr-feat">
    <div class="pr-wrap pr-fgrid">
      <div class="pr-fl" data-in="up">
        <p class="pr-kick">What you get with Obitickets</p>
        <h2>Everything you need to sell tickets, included.</h2>
        <p class="s">Your <?= $h($rate) ?>% platform commission gives you access to our event ticketing tools and features.</p>
        <div class="pr-fpic"><img src="<?= $img ?>about-conference.jpg" alt="" loading="lazy" decoding="async"></div>
      </div>
      <ul class="pr-feats">
        <?php foreach ($features as $i => $f): ?>
        <li class="pr-ft" data-in="up"<?= $i ? ' style="--d:' . ($i * 60) . '"' : '' ?>><span class="ic"><?= $ic($f[0], 22) ?></span><h3><?= $h($f[1]) ?></h3><p><?= $h($f[2]) ?></p></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section class="pr-sts">
    <div class="pr-wrap pr-sth">
      <p class="pr-kick" data-in="up">How our pricing works</p>
      <h2 data-split data-in="split">Six steps, from sign-up to your balance.</h2>
      <ol class="pr-steps">
        <?php foreach ($steps as $i => $t): $key = $i === 4; ?>
        <li data-in="up"<?= $i ? ' style="--d:' . ($i * 80) . '"' : '' ?>><div class="pr-st<?= $key ? ' key' : '' ?>"><span class="n"><?= $i + 1 ?></span><?php if ($key): ?><small>The only charge</small><?php endif; ?><p><?= $h($t) ?></p></div></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <section class="pr-clear">
    <div class="pr-wrap pr-cg">
      <div class="pr-cl2" data-in="up"><p class="pr-kick">Transparent pricing</p><h2>No surprises.</h2><p class="s">We believe organizers deserve to understand exactly what they pay.</p></div>
      <div>
        <p class="pr-pp" data-in="up">Our standard platform commission is <b><?= $h($rate) ?>%</b>. Any applicable payment gateway charges, taxes or other agreed deductions are accounted for separately and disclosed appropriately.</p>
        <ul class="pr-ticks">
          <?php foreach ($ticks as $i => $t): ?>
          <li data-in="up"<?= $i ? ' style="--d:' . ($i * 80) . '"' : '' ?>><span><?= $ic('ic-check', 14) ?></span><?= $h($t) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </section>

  <section class="pr-cta" style="--bgi:url('<?= $img ?>crowd.jpg')">
    <div class="pr-wrap">
      <p class="pr-kick" data-in="up">Create your event today</p>
      <h2 data-split data-in="split">Ready to sell tickets?</h2>
      <p class="l" data-in="up" style="--d:300">Take your event online with Obitickets. Register as an organizer, publish your event and start reaching potential attendees.</p>
      <div data-in="up" style="--d:450"><a class="pr-btn pri" data-mag href="/signup.php?role=ORGANIZER">Register as an organizer <?= $ic('ic-arrow') ?></a></div>
      <div class="pr-sign" data-in="up" style="--d:600"><b>OBITICKETS</b><span>Discover. Book. Attend.</span><em>Uganda's Events. One Destination.</em></div>
    </div>
  </section>

</main>

<script src="/assets/js/page-motion.js?v=<?= @filemtime(__DIR__ . '/assets/js/page-motion.js') ?: time() ?>"></script>
<script src="/assets/js/pricing.js?v=<?= @filemtime(__DIR__ . '/assets/js/pricing.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
