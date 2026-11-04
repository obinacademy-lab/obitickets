<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Public team page: the owner's copy, set in the premium classic layout. The photo is of the Founder & CEO.
$pageTitle = 'Our Team — The People Behind obitickets';
$pageDescription = 'Meet the team behind Obitickets: the people powering better event experiences, and what drives us to make ticketing simple and accessible across Uganda.';
$bodyClass = 'classic-page team-page';
include __DIR__ . '/includes/header.php';

$icon = static fn (string $name): string => '<svg class="i" aria-hidden="true"><use href="#tm-i-' . $name . '"/></svg>';

$tiles = [['spark', 'Creativity'], ['code', 'Technology'], ['brief', 'Business thinking'], ['heart', 'A passion for events']];
$values = [
    ['bulb', 'Innovation', 'We embrace technology and continuously seek smarter ways to improve event ticketing.'],
    ['users', 'Customer Experience', 'We put organizers and attendees at the centre of everything we do.'],
    ['shield', 'Trust & Transparency', 'We believe in clear processes, dependable service and responsible business practices.'],
    ['grow', 'Growth & Collaboration', "We work to empower event organizers and contribute to the growth of Africa's events industry."],
];
$teams = [
    ['chip', 'Technology & Development Team', 'Responsible for building, maintaining and improving the technology that powers event publishing, digital ticketing, payments and QR ticket validation.', 'Heis Mercy, Co-Founder & CTO'],
    ['support', 'Operations & Event Support Team', 'Works with event organizers and attendees to support event publishing, ticketing operations and customer assistance.', ''],
    ['mega', 'Marketing & Partnerships Team', 'Focuses on building relationships, promoting the Obitickets brand and creating opportunities for organizers, partners and event communities.', ''],
];
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="tm-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="tm-i-down" viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></symbol>
  <symbol id="tm-i-spark" viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM18.5 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></symbol>
  <symbol id="tm-i-code" viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/></symbol>
  <symbol id="tm-i-brief" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18"/></symbol>
  <symbol id="tm-i-heart" viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.600-7 10-7 10z"/></symbol>
  <symbol id="tm-i-chip" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2.5"/><path d="M9 3v3M15 3v3M9 18v3M15 18v3M3 9h3M3 15h3M18 9h3M18 15h3"/></symbol>
  <symbol id="tm-i-support" viewBox="0 0 24 24"><path d="M4 13a8 8 0 0 1 16 0M4 13v3a2 2 0 0 0 2 2h1v-6H6a2 2 0 0 0-2 1zM20 13v3a2 2 0 0 1-2 2h-1v-6h1a2 2 0 0 1 2 1z"/><path d="M17 18c0 2-2 3-5 3"/></symbol>
  <symbol id="tm-i-mega" viewBox="0 0 24 24"><path d="M3 11v3a1 1 0 0 0 1 1h3l7 4V6L7 10H4a1 1 0 0 0-1 1zM18 9a4 4 0 0 1 0 6"/></symbol>
  <symbol id="tm-i-bulb" viewBox="0 0 24 24"><path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.900c.6.5 1 1.200 1 2.100h5c0-.9.4-1.600 1-2.100A6 6 0 0 0 12 3z"/></symbol>
  <symbol id="tm-i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.500 20c0-3.400 2.900-5.600 6.500-5.600s6.500 2.200 6.500 5.600M17 4.700a3.300 3.300 0 0 1 0 6.400M18.500 14.600c2 .6 3.500 2.400 3.500 5.400"/></symbol>
  <symbol id="tm-i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.400 8-8 9-4.600-1-8-4-8-9V6z"/><path d="M8.500 12l2.500 2.500L15.500 10"/></symbol>
  <symbol id="tm-i-grow" viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8M15 7h6v6"/></symbol>
</svg>

<main class="tm">

  <section class="tm-hero">
    <div class="tm-wrap tm-hero-in">
      <div>
        <p class="tm-kick tm-gold tm-rise" style="--d:100">Meet the team behind Obitickets</p>
        <h1><span class="split-heading" data-split>The People Powering Better Event Experiences</span></h1>
        <p class="tm-lede tm-rise" style="--d:600">Behind every great experience is a team driven by innovation, creativity and a shared vision.</p>
        <div class="tm-acts tm-rise" style="--d:800"><a class="tm-btn tm-btn-ghost" href="#leadership">Meet our leadership <?= $icon('down') ?></a></div>
      </div>
      <div class="tm-mosaic" aria-hidden="true">
        <figure class="tm-m1 tm-rise" style="--d:200"><img src="/assets/images/about/about-club.jpg" alt="" loading="eager" decoding="async"></figure>
        <figure class="tm-m2 tm-rise" style="--d:350"><img src="/assets/images/about/dancers.jpg" alt="" loading="eager" decoding="async"></figure>
        <figure class="tm-m3 tm-rise" style="--d:500"><img src="/assets/images/about/crowd.jpg" alt="" loading="eager" decoding="async"></figure>
        <span class="tm-tag tm-rise" style="--d:800">Discover. Book. Attend.</span>
      </div>
    </div>
  </section>

  <section class="tm-sec tm-intro">
    <div class="tm-wrap">
      <div class="reveal"><p class="tm-big">At Obitickets, we are passionate about using technology to make <b>event discovery, ticket booking and event management</b> simpler, smarter and more accessible.</p></div>
      <div class="tm-prose reveal reveal-delay-1"><p>Our team is committed to connecting event organizers with audiences, supporting the growth of the events industry and creating better experiences for everyone.</p></div>
    </div>
  </section>

  <section class="tm-sec tm-pvc">
    <div class="tm-wrap">
      <div class="tm-head reveal">
        <div><h2>Our People. Our Vision. Our Commitment.</h2><div class="tm-rule"></div></div>
        <div class="tm-prose"><p>We bring together creativity, technology, business thinking and a passion for events to build a platform that serves both event organizers and attendees.</p></div>
      </div>
      <div class="tm-tiles">
        <?php foreach ($tiles as $i => [$ic, $label]): ?>
        <article class="tm-tile reveal<?= $i ? ' reveal-delay-' . min($i, 3) : '' ?>"><span class="tm-ic"><?= $icon($ic) ?></span><h3><?= $h($label) ?></h3></article>
        <?php endforeach; ?>
      </div>
      <div class="tm-quote reveal"><p>From developing innovative ticketing solutions to supporting organizers and improving the customer experience, every part of our work is guided by one goal: <b>making ticketing simple and accessible.</b></p></div>
    </div>
  </section>

  <section class="tm-sec tm-lead" id="leadership">
    <div class="tm-wrap">
      <div class="tm-head reveal">
        <div><h2>Meet Our Leadership</h2><div class="tm-rule"></div></div>
        <div class="tm-prose"><p>Our leadership team provides the vision, direction and commitment that drive Obitickets forward.</p></div>
      </div>
      <div class="tm-bento">
        <article class="tm-card tm-feat reveal">
          <img class="tm-photo" src="/assets/images/team/obin-ivan.jpg" width="650" height="1080" alt="Obin Ivan, Founder and CEO of Obitickets" loading="lazy" decoding="async">
          <span class="tm-who">Founder &amp; CEO</span>
          <span class="tm-name">Obin Ivan</span>
          <h3>Founder &amp; Chief Executive Officer (CEO)</h3>
          <p>Provides the strategic vision, business direction and leadership behind Obitickets, guiding the platform's growth and development.</p>
        </article>
        <?php foreach ($teams as $i => [$ic, $title, $text, $who]): ?>
        <article class="tm-card reveal reveal-delay-<?= $i + 1 ?>">
          <span class="tm-em"><?= $icon($ic) ?></span>
          <div>
            <h3><?= $h($title) ?></h3>
            <p><?= $h($text) ?></p>
            <?php if ($who !== ''): ?><span class="tm-who"><?= $h($who) ?></span><?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="tm-sec tm-drive">
    <div class="tm-wrap">
      <div class="tm-head reveal"><h2>What Drives Us</h2><div class="tm-rule"></div></div>
      <ul class="tm-vals">
        <?php foreach ($values as $i => [$ic, $title, $text]): ?>
        <li class="tm-val reveal<?= $i ? ' reveal-delay-' . min($i, 3) : '' ?>"><span class="tm-ic"><?= $icon($ic) ?></span><h3><?= $h($title) ?></h3><p><?= $h($text) ?></p></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section class="tm-future" style="--bgi:url('/assets/images/about/about-conference.jpg')">
    <span class="tm-future-bg" aria-hidden="true"></span>
    <div class="tm-wrap tm-future-in">
      <div class="reveal"><h2>Building the Future of Event Ticketing</h2><div class="tm-rule"></div></div>
      <div class="reveal reveal-delay-1">
        <p>We believe that every event deserves an opportunity to reach its audience, and everyone deserves a simpler way to discover and attend great experiences.</p>
        <p>Our journey is only beginning, and we invite event organizers, partners and event lovers to grow with us.</p>
      </div>
    </div>
  </section>

  <section class="tm-cta">
    <div class="tm-wrap reveal">
      <h2>Let's Build Something Great Together</h2>
      <p class="tm-l">Whether you are an event organizer, a business partner or someone passionate about unforgettable experiences, there is a place for you in the Obitickets journey.</p>
      <a class="tm-btn tm-btn-gold" href="/contact.php">Get in touch <?= $icon('arrow') ?></a>
      <div class="tm-sign"><span>Discover. Book. Attend.</span><b>OBITICKETS</b><em>Uganda's Events. One Destination.</em></div>
    </div>
  </section>

</main>

<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
