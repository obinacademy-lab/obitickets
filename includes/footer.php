<?php
// The phone tab bar, as in the app mockup: Home, Discover, Create, Tickets, Profile
// (shown by rc.css under 900px; pages with their own bottom bar hide it there).
$rcPath = isset($navPath) ? (string) $navPath : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rcTab = 'none';
if ($rcPath === '/' || $rcPath === '/index.php') { $rcTab = 'home'; }
elseif (in_array($rcPath, ['/discover.php', '/search.php', '/past-events.php', '/past-event.php', '/event.php', '/organizer.php', '/socials.php', '/saved.php'], true)) { $rcTab = 'discover'; }
elseif (in_array($rcPath, ['/my-tickets.php', '/order.php', '/join.php', '/retry-payment.php'], true)) { $rcTab = 'tickets'; }
elseif (in_array($rcPath, ['/me.php', '/settings.php', '/profile.php', '/notifications.php', '/login.php', '/signup.php', '/forgot-password.php', '/reset-password.php', '/verify-2fa.php', '/delete-account.php'], true)) { $rcTab = 'account'; }
if (isset($rcTabForce) && is_string($rcTabForce)) { $rcTab = $rcTabForce; }   // a page can say which tab it belongs to
$rcSignedIn = !empty($authUser);
$rcOn = static fn (string $t): string => $rcTab === $t ? ' on" aria-current="page' : '';
// Create: a choice screen (/create.php): organizers pick what to make, everyone else learns how to become one.
$rcCreate = $rcSignedIn ? '/create.php' : '/login.php?next=' . rawurlencode('/create.php');
?>
<nav class="rc-tabs" aria-label="App">
  <a class="rc-tab<?= $rcOn('home') ?>" href="/"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Home</span></a>
  <a class="rc-tab<?= $rcOn('discover') ?>" href="/discover.php"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.300-4.300"/></svg><span>Discover</span></a>
  <a class="rc-tab rc-tab-create" href="<?= htmlspecialchars($rcCreate) ?>"><i aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></i><span>Create</span></a>
  <a class="rc-tab<?= $rcOn('tickets') ?>" href="/my-tickets.php"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z"/><path d="M14 5v14" stroke-dasharray="2 3"/></svg><span>Tickets</span></a>
  <a class="rc-tab<?= $rcOn('account') ?>" href="<?= $rcSignedIn ? '/me.php' : '/login.php' ?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><?php if ($rcSignedIn && function_exists('dm_has_unread') && dm_has_unread((int) $authUser['id'])): ?><b class="rc-dot" aria-label="New messages"></b><?php endif; ?><span><?= $rcSignedIn ? 'Profile' : 'Log in' ?></span></a>
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
