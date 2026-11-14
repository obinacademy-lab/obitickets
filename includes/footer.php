<footer id="site-footer">
  <div class="wrap ft">
    <a class="logo ft-logo" href="/" aria-label="obitickets home">
      <svg class="logo-mark" viewBox="0 0 34 34" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="#DC2626"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="#DC2626" stroke="#fff" stroke-width="1.6"/></svg>
      obitickets
    </a>
    <a class="ft-wa foot-wa" href="<?= htmlspecialchars(support_whatsapp_url()) ?>" target="_blank" rel="noopener"><svg width="15" height="15" aria-hidden="true"><use href="#ic-whatsapp"/></svg> WhatsApp us</a>
    <nav class="ft-links" aria-label="Footer">
      <a href="/about.php">About</a><a href="/team.php">Team</a><a href="/contact.php">Contact</a><a href="/pricing.php">Pricing</a><a href="/help.php">Help</a><a href="/refunds.php">Refunds</a><a href="/terms.php">Terms</a><a href="/privacy.php">Privacy</a><a href="#" data-install-app hidden>Install the app</a>
    </nav>
    <div class="ft-bot">
      <span>&copy; <?= date('Y') ?> obitickets &middot; Kampala, Uganda</span>
      <button type="button" class="foot-top-btn" id="footToTop" aria-label="Back to top"><svg width="14" height="14"><use href="#ic-chev"/></svg></button>
    </div>
  </div>
</footer>
<?php
// The phone tab bar (shown by rc.css under 900px; pages with their own bottom bar hide it there).
$rcPath = isset($navPath) ? (string) $navPath : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rcTab = 'none';
if ($rcPath === '/' || $rcPath === '/index.php') { $rcTab = 'home'; }
elseif (in_array($rcPath, ['/search.php', '/past-events.php', '/past-event.php', '/event.php', '/organizer.php'], true)) { $rcTab = 'browse'; }
elseif ($rcPath === '/socials.php') { $rcTab = 'socials'; }
elseif (in_array($rcPath, ['/my-tickets.php', '/order.php', '/join.php', '/retry-payment.php'], true)) { $rcTab = 'tickets'; }
elseif (in_array($rcPath, ['/profile.php', '/login.php', '/signup.php', '/forgot-password.php', '/reset-password.php', '/verify-2fa.php', '/delete-account.php'], true)) { $rcTab = 'account'; }
$rcSignedIn = !empty($authUser);
$rcOn = static fn (string $t): string => $rcTab === $t ? ' on" aria-current="page' : '';
?>
<nav class="rc-tabs" aria-label="App">
  <a class="rc-tab<?= $rcOn('home') ?>" href="/"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Home</span></a>
  <a class="rc-tab<?= $rcOn('browse') ?>" href="/search.php"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.300-4.300"/></svg><span>Events</span></a>
  <?php if (function_exists('socials_feed') && moments_ready()): /* Socials: the event video feed, in the middle like the play button it is */ ?>
  <a class="rc-tab rc-tab-so<?= $rcOn('socials') ?>" href="/socials.php"><svg class="rc-logo" width="24" height="24" viewBox="0 0 34 34" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="#DC2626"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="#DC2626" stroke="#fff" stroke-width="1.6"/></svg><span>Socials</span></a>
  <?php endif; ?>
  <a class="rc-tab<?= $rcOn('tickets') ?>" href="/my-tickets.php"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z"/><path d="M14 5v14" stroke-dasharray="2 3"/></svg><span>Tickets</span></a>
  <a class="rc-tab<?= $rcOn('account') ?>" href="<?= $rcSignedIn ? '/profile.php' : '/login.php' ?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><span><?= $rcSignedIn ? 'Profile' : 'Log in' ?></span></a>
</nav>
<?php if (empty($hideWhatsApp)): ?>
<a class="wa-float" href="<?= htmlspecialchars(support_whatsapp_url()) ?>" target="_blank" rel="noopener" aria-label="Chat with obitickets on WhatsApp" title="Questions? Chat with us on WhatsApp">
  <svg width="28" height="28" aria-hidden="true"><use href="#ic-whatsapp"/></svg>
</a>
<?php endif; ?>
<script src="/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: time() ?>"></script>
<script src="/assets/js/motion.js?v=<?= @filemtime(__DIR__ . '/../assets/js/motion.js') ?: time() ?>"></script>
<script src="/assets/js/nav.js?v=<?= @filemtime(__DIR__ . '/../assets/js/nav.js') ?: time() ?>"></script>
</body>
</html>
