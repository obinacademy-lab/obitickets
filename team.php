<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Public team page, "Backstage Pass": the owner's copy, with the team hanging as event passes.
// Three people are named (Obin Ivan, Founder & CEO; Mujjuzi Jeremiah, Events Manager; Mungutye Ethel, Accountant). There are no other team members.
$pageTitle = 'Our Team — The People Behind obitickets';
$pageDescription = 'Meet the team behind Obitickets: the people powering better event experiences, and what drives us to make ticketing simple and accessible across Uganda.';
$bodyClass = 'classic-page team-page motion-page';
include __DIR__ . '/includes/header.php';

$icon = static fn (string $name): string => '<svg class="tm-i" aria-hidden="true"><use href="#tm-i-' . $name . '"/></svg>';
$drawIcon = static fn (string $name): string => '<svg class="tm-i pm-draw" aria-hidden="true"><use href="#tm-i-' . $name . '"/></svg>';
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

$tiles = [['spark', 'Creativity'], ['code', 'Technology'], ['brief', 'Business thinking'], ['heart', 'A passion for events']];
$values = [
    ['bulb', 'Innovation', 'We embrace technology and continuously seek smarter ways to improve event ticketing.'],
    ['users', 'Customer Experience', 'We put organizers and attendees at the centre of everything we do.'],
    ['shield', 'Trust & Transparency', 'We believe in clear processes, dependable service and responsible business practices.'],
    ['grow', 'Growth & Collaboration', "We work to empower event organizers and contribute to the growth of Africa's events industry."],
];
// kind: person (photo + name) or team (icon only). Order is the owner's: CEO, Technology, Operations, Marketing; then the accountant.
$passes = [
    ['person', '/assets/images/team/obin-ivan.jpg', 'Founder & CEO', 'Obin Ivan', 'Founder & Chief Executive Officer (CEO)', "Provides the strategic vision, business direction and leadership behind Obitickets, guiding the platform's growth and development.", 'Obin Ivan, Founder and CEO of Obitickets'],
    ['team', 'chip', 'Our leadership team', '', 'Technology & Development Team', 'Responsible for building, maintaining and improving the technology that powers event publishing, digital ticketing, payments and QR ticket validation.', ''],
    ['person', '/assets/images/team/mujjuzi-jeremiah.jpg', 'Events Manager', 'Mujjuzi Jeremiah', 'Operations & Event Support Team', 'Works with event organizers and attendees to support event publishing, ticketing operations and customer assistance.', 'Mujjuzi Jeremiah, Events Manager at Obitickets'],
    ['team', 'mega', 'Our leadership team', '', 'Marketing & Partnerships Team', 'Focuses on building relationships, promoting the Obitickets brand and creating opportunities for organizers, partners and event communities.', ''],
    ['person', '/assets/images/team/mungutye-ethel.jpg', 'Accountant', 'Mungutye Ethel', '', '', 'Mungutye Ethel, Accountant at Obitickets'],
];
$ticker = ['Discover. Book. Attend.', "Uganda's Events. One Destination."];
?>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="tm-i-arrow" viewBox="0 0 24 24"><path pathLength="1" d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="tm-i-down" viewBox="0 0 24 24"><path pathLength="1" d="M12 5v14M6 13l6 6 6-6"/></symbol>
  <symbol id="tm-i-spark" viewBox="0 0 24 24"><path pathLength="1" d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM18.5 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></symbol>
  <symbol id="tm-i-code" viewBox="0 0 24 24"><path pathLength="1" d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/></symbol>
  <symbol id="tm-i-brief" viewBox="0 0 24 24"><rect pathLength="1" x="3" y="7" width="18" height="13" rx="2.5"/><path pathLength="1" d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18"/></symbol>
  <symbol id="tm-i-heart" viewBox="0 0 24 24"><path pathLength="1" d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></symbol>
  <symbol id="tm-i-chip" viewBox="0 0 24 24"><rect pathLength="1" x="6" y="6" width="12" height="12" rx="2.5"/><path pathLength="1" d="M9 3v3M15 3v3M9 18v3M15 18v3M3 9h3M3 15h3M18 9h3M18 15h3"/></symbol>
  <symbol id="tm-i-mega" viewBox="0 0 24 24"><path pathLength="1" d="M3 11v3a1 1 0 0 0 1 1h3l7 4V6L7 10H4a1 1 0 0 0-1 1zM18 9a4 4 0 0 1 0 6"/></symbol>
  <symbol id="tm-i-bulb" viewBox="0 0 24 24"><path pathLength="1" d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/></symbol>
  <symbol id="tm-i-users" viewBox="0 0 24 24"><circle pathLength="1" cx="9" cy="8" r="3.5"/><path pathLength="1" d="M2.5 20c0-3.4 2.9-5.6 6.5-5.6s6.5 2.2 6.5 5.6M17 4.7a3.3 3.3 0 0 1 0 6.4M18.5 14.6c2 .6 3.5 2.4 3.5 5.4"/></symbol>
  <symbol id="tm-i-shield" viewBox="0 0 24 24"><path pathLength="1" d="M12 3l8 3v6c0 5-3.4 8-8 9-4.6-1-8-4-8-9V6z"/><path pathLength="1" d="M8.5 12l2.5 2.5L15.5 10"/></symbol>
  <symbol id="tm-i-grow" viewBox="0 0 24 24"><path pathLength="1" d="M3 17l6-6 4 4 8-8M15 7h6v6"/></symbol>
</svg>

<div class="tm-pbar" data-pagep aria-hidden="true"><i></i></div>

<main class="tm">

  <section class="tm-hero">
    <div class="tm-wrap tm-hero-in">
      <div>
        <p class="tm-kick" data-in="up">Meet the team behind Obitickets</p>
        <h1 data-split data-in="split">The People Powering Better Event Experiences</h1>
        <p class="tm-lede" data-in="up" style="--d:600">Behind every great experience is a team driven by innovation, creativity and a shared vision.</p>
        <div data-in="up" style="--d:800; margin-top:28px"><a class="tm-btn red" data-mag data-jump="leadership" href="#leadership">Meet our leadership <?= $icon('down') ?></a></div>
      </div>
      <div class="tm-pol" aria-hidden="true">
        <figure class="tm-p1" data-in="down" style="--d:250"><img src="/assets/images/about/about-club.jpg" alt="" loading="eager" decoding="async"><span>Discover</span></figure>
        <figure class="tm-p2" data-in="down" style="--d:450"><img src="/assets/images/about/dancers.jpg" alt="" loading="eager" decoding="async"><span>Book</span></figure>
        <figure class="tm-p3" data-in="down" style="--d:650"><img src="/assets/images/about/crowd.jpg" alt="" loading="eager" decoding="async"><span>Attend</span></figure>
      </div>
    </div>
  </section>

  <div class="tm-tk pm-mq" aria-hidden="true"><div>
    <?php for ($r = 0; $r < 4; $r++): foreach ($ticker as $t): ?><span><?= $h($t) ?></span><?php endforeach; endfor; ?>
  </div></div>

  <section class="tm-sec">
    <div class="tm-wrap tm-intro-in">
      <p class="tm-big" data-in="up">At Obitickets, we are passionate about using technology to make <b>event discovery, ticket booking and event management</b> simpler, smarter and more accessible.</p>
      <p class="tm-prose" data-in="up" style="--d:150">Our team is committed to connecting event organizers with audiences, supporting the growth of the events industry and creating better experiences for everyone.</p>
    </div>
  </section>

  <section class="tm-sec tm-pvc">
    <div class="tm-wrap">
      <div class="tm-head" data-in="up">
        <div><h2>Our People. Our Vision. Our Commitment.</h2><div class="tm-rule"></div></div>
        <p class="tm-p">We bring together creativity, technology, business thinking and a passion for events to build a platform that serves both event organizers and attendees.</p>
      </div>
      <div class="tm-stubs">
        <?php foreach ($tiles as $i => [$ic, $label]): ?>
        <div data-in="flip"<?= $i ? ' style="--d:' . ($i * 110) . '"' : '' ?>><article class="tm-stub"><span class="ic"><?= $drawIcon($ic) ?></span><h3><?= $h($label) ?></h3></article></div>
        <?php endforeach; ?>
      </div>
      <div class="tm-rtk" data-in="flip" data-tilt>
        <i class="tm-notch t" aria-hidden="true"></i><i class="tm-notch b" aria-hidden="true"></i>
        <div class="tm-rmain"><p data-in="up" style="--d:300">From developing innovative ticketing solutions to supporting organizers and improving the customer experience, every part of our work is guided by one goal:</p></div>
        <div class="tm-rstub"><p class="tm-goal" data-split data-in="split" style="--d:500">making ticketing simple and accessible.</p></div>
      </div>
    </div>
  </section>

  <section class="tm-sec" id="leadership">
    <div class="tm-wrap">
      <div class="tm-head" data-in="up">
        <div><h2>Meet Our Leadership</h2><div class="tm-rule"></div></div>
        <p class="tm-p">Our leadership team provides the vision, direction and commitment that drive Obitickets forward.</p>
      </div>
      <div class="tm-lan">
        <i class="tm-strap" data-in="clip" style="--d:100"></i>
        <div class="tm-passes">
          <?php foreach ($passes as $i => [$kind, $media, $role, $name, $title, $text, $alt]): ?>
          <div data-in="down" style="--d:<?= 200 + $i * 150 ?>"><div class="tm-pw"><div class="tm-clip"></div><div class="tm-pass" data-tilt>
            <div class="tm-ph<?= $kind === 'team' && $i === 3 ? ' g' : '' ?>"><?php if ($kind === 'person'): ?><img src="<?= $h($media) ?>" width="<?= $i === 0 ? 650 : 480 ?>" height="<?= $i === 0 ? 1080 : 480 ?>" alt="<?= $h($alt) ?>" loading="lazy" decoding="async"><?php else: ?><?= $icon($media) ?><?php endif; ?></div>
            <div class="tm-role"><?= $h($role) ?></div>
            <div class="tm-body">
              <?php if ($name !== ''): ?><span class="nm"><?= $h($name) ?></span><?php endif; ?>
              <?php if ($title !== ''): ?><h3<?= $name === '' ? ' class="t"' : '' ?>><?= $h($title) ?></h3><?php endif; ?>
              <?php if ($text !== ''): ?><p><?= $h($text) ?></p><?php endif; ?>
              <div class="tm-bar"></div>
              <p class="tm-foot">Discover. Book. Attend.</p>
            </div>
          </div></div></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="tm-sec tm-drive">
    <div class="tm-wrap">
      <div data-in="up"><h2>What Drives Us</h2><div class="tm-rule"></div></div>
      <div class="tm-vals">
        <?php foreach ($values as $i => [$ic, $title, $text]): ?>
        <article class="tm-vl" data-in="up"<?= $i ? ' style="--d:' . ($i * 100) . '"' : '' ?>><span class="ic"><?= $drawIcon($ic) ?></span><h3><?= $h($title) ?></h3><p><?= $h($text) ?></p></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="tm-future">
    <div class="tm-fph" data-in="clipup"><img data-prog data-par style="--par:90px" src="/assets/images/about/about-conference.jpg" alt="" loading="lazy" decoding="async"></div>
    <div class="tm-wrap"><div class="tm-fc" data-in="flip" style="--d:200">
      <h2>Building the Future of Event Ticketing</h2>
      <p>We believe that every event deserves an opportunity to reach its audience, and everyone deserves a simpler way to discover and attend great experiences.</p>
      <p>Our journey is only beginning, and we invite event organizers, partners and event lovers to grow with us.</p>
    </div></div>
  </section>

  <section class="tm-cta">
    <div class="tm-wrap">
      <div class="tm-ticket" data-in="flip" data-tilt>
        <div class="tm-tmain">
          <h2 data-split data-in="split" style="--d:200">Let's Build Something Great Together</h2>
          <div class="tm-rule"></div>
          <p class="tm-l" data-in="up" style="--d:500">Whether you are an event organizer, a business partner or someone passionate about unforgettable experiences, there is a place for you in the Obitickets journey.</p>
          <div data-in="up" style="--d:700; margin-top:30px"><a class="tm-btn red" data-mag href="/contact.php">Get in touch <?= $icon('arrow') ?></a></div>
        </div>
        <div class="tm-tstub">
          <div class="tm-barc" data-in="clip" style="--d:600" aria-hidden="true"></div>
          <div class="tm-sign" data-in="up" style="--d:800"><span>Discover. Book. Attend.</span><b>OBITICKETS</b><em>Uganda's Events. One Destination.</em></div>
        </div>
      </div>
    </div>
  </section>

</main>

<script src="/assets/js/page-motion.js?v=<?= @filemtime(__DIR__ . '/assets/js/page-motion.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
