<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Settings in the dark app look (owner's mockup, Phase 4, 11/18): one list of everything about your account.
// Every row is a link to a page that already does the work; nothing is changed here.
$user = require_login();
$uid = (int) $user['id'];
$isOrganizer = ($user['role'] ?? '') === 'ORGANIZER';
$isAdmin = ($user['role'] ?? '') === 'ADMIN';
$unread = function_exists('count_unread_notifications') ? count_unread_notifications($uid) : 0;

// Privacy (11/27): "Show me in video viewer lists". The only switch that is changed on this page itself.
$vvReady = function_exists('vv_ready') && vv_ready();
if ($vvReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'viewers') {
    verify_csrf();
    vv_set_visible($uid, !empty($_POST['on']));
    header('Location: /settings.php?saved=viewers#viewers');
    exit;
}
$vvOn = $vvReady && vv_visible($uid);

$ic = [
    'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
    'at'     => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
    'image'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5L5 19"/>',
    'lock'   => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'bell'   => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'ticket' => '<path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z"/>',
    'coin'   => '<circle cx="12" cy="12" r="9"/><path d="M14.8 9.2c-.6-.8-1.6-1.2-2.8-1.2-1.7 0-2.8.9-2.8 2.1 0 3 5.7 1.6 5.7 4.4 0 1.3-1.2 2.2-2.9 2.2-1.2 0-2.3-.5-2.9-1.3M12 6.5V8M12 16v1.5"/>',
    'heart'  => '<path d="M12 20.5s-7.5-4.6-9.3-9.4C1.5 7.6 3.9 4.5 7.2 4.5c1.9 0 3.6 1 4.8 2.6 1.2-1.6 2.9-2.6 4.8-2.6 3.3 0 5.7 3.1 4.5 6.6-1.8 4.8-9.3 9.4-9.3 9.4z"/>',
    'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'star'   => '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>',
    'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
    'help'   => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>',
    'chat'   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/>',
    'doc'    => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
    'out'    => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l-5-5 5-5M5 12h11"/>',
    'trash'  => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
    'eye'    => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
];
$svg = static fn (string $k): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$k] . '</svg>';

$groups = [
    'Account' => [
        ['/profile.php', 'user', 'Edit profile', 'Name, phone number and email'],
        ['/profile.php#about', 'at', 'Username and bio', null],
        ['/profile.php#photo', 'image', 'Profile picture and cover photo', null],
        ['/profile.php#password', 'lock', 'Password', null],
    ],
    'Activity' => [
        ['/notifications.php', 'bell', 'Notifications', $unread ? $unread . ' unread' : null],
        ['/my-tickets.php', 'ticket', 'My tickets', null],
        ['/saved.php', 'heart', 'Saved events', null],
    ],
    'Organizer' => $isOrganizer
        ? [['/org.php', 'grid', 'Organizer dashboard', 'Events, sales, payouts and videos']]
        : [['/become-organizer.php', 'star', 'Become an organizer', 'Sell tickets and post event videos']],
    'Help and legal' => [
        ['/help.php', 'help', 'Help centre', null],
        ['/contact.php', 'chat', 'Contact us', null],
        ['/terms.php', 'doc', 'Terms of service', null],
        ['/privacy.php', 'shield', 'Privacy policy', null],
        ['/refunds.php', 'doc', 'Refund policy', null],
    ],
];
if (function_exists('coins_menu_visible') && coins_menu_visible($uid)) {
    $groups['Activity'][] = ['/wallet.php', 'coin', 'OBI COINS wallet', number_format(coin_wallet($uid)['balance']) . ' OBI COINS'];
}
if ($isAdmin) {
    $groups['Organizer'] = [['/admin.php', 'shield', 'Admin', null]];
}

$pageTitle = 'Settings — obitickets';
$bodyClass = 'classic-page app-dark st-page';
$hideWhatsApp = true;
$rcTabForce = 'account';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<div class="wrap st">
  <div class="ad-top">
    <a class="ad-icon" href="/me.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Settings</h1>
  </div>

  <?php if ($vvReady): ?>
    <h2 class="st-h" id="viewers">Privacy</h2>
    <div class="st-list">
      <form method="post" class="st-row st-switch-row"<?= $vvOn ? ' data-confirm="Hide yourself from video viewer lists? You will be removed from the lists you are already in, and you will not see who viewed your own videos either."' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="viewers"><?php if (!$vvOn): ?><input type="hidden" name="on" value="1"><?php endif; ?>
        <i><?= $svg('eye') ?></i>
        <span><b>Show me in video viewer lists</b><small><?= $vvOn ? 'On: when you watch a video, the person who posted it can see you watched it and message you.' : 'Off: nobody sees that you watched their video, and you cannot see who watched yours.' ?></small></span>
        <button type="submit" class="st-switch<?= $vvOn ? ' on' : '' ?>" role="switch" aria-checked="<?= $vvOn ? 'true' : 'false' ?>" aria-label="Show me in video viewer lists"><i></i></button>
      </form>
    </div>
    <?php if (($_GET['saved'] ?? '') === 'viewers'): ?><p class="st-saved" role="status">Saved.</p><?php endif; ?>
  <?php endif; ?>

  <?php foreach ($groups as $label => $rows): ?>
    <h2 class="st-h"><?= htmlspecialchars($label) ?></h2>
    <div class="st-list">
      <?php foreach ($rows as [$href, $icon, $title, $sub]): ?>
        <a class="st-row" href="<?= htmlspecialchars($href) ?>">
          <i><?= $svg($icon) ?></i>
          <span><b><?= htmlspecialchars($title) ?></b><?php if ($sub): ?><small><?= htmlspecialchars($sub) ?></small><?php endif; ?></span>
          <svg class="st-go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="st-list st-end">
    <a class="st-row" href="/logout.php"><i><?= $svg('out') ?></i><span><b>Log out</b></span></a>
    <a class="st-row st-danger" href="/delete-account.php"><i><?= $svg('trash') ?></i><span><b>Delete my account</b></span></a>
  </div>
  <p class="st-foot">obitickets &middot; Ticketing made simple</p>
</div>
<style>
  .st{max-width:620px; padding-bottom:28px}
  .st .ad-top h1{font-size:22px}
  .st-h{margin:22px 0 8px; font-size:12.5px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--ad-mute)}
  .st-list{border-radius:16px; background:var(--ad-card); box-shadow:inset 0 0 0 1px var(--ad-line); overflow:hidden}
  .st-end{margin-top:22px}
  body.st-page a.st-row{display:flex; align-items:center; gap:14px; min-height:58px; padding:10px 14px; color:#fff; transition:background .2s}
  .st-row + .st-row{border-top:1px solid var(--ad-line)}
  body.st-page a.st-row:hover{background:rgba(255,255,255,.04); color:#fff}
  .st-row > i{flex:none; width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,.07); display:grid; place-items:center; color:#fff}
  .st-row > span{flex:1; min-width:0; display:grid; gap:2px}
  .st-row b{font-size:15px; font-weight:700}
  .st-row small{font-size:12.5px; color:var(--ad-mute)}
  .st-go{flex:none; color:var(--ad-mute)}
  body.st-page a.st-danger,body.st-page a.st-danger:hover{color:#FF6B76}
  .st-danger > i{background:rgba(240,49,63,.12); color:#FF6B76}
  .st-row:focus-visible{outline:3px solid #fff; outline-offset:-3px}
  .st-foot{margin:22px 0 0; text-align:center; color:var(--ad-mute); font-size:12.5px}
  .st-switch-row{display:flex; align-items:center; gap:14px; min-height:58px; padding:10px 14px; margin:0; color:#fff}
  .st-switch{flex:none; width:48px; height:28px; border:0; border-radius:99px; background:rgba(255,255,255,.18); position:relative; cursor:pointer; padding:0}
  .st-switch i{position:absolute; top:3px; left:3px; width:22px; height:22px; border-radius:50%; background:#fff; transition:transform .2s}
  .st-switch.on{background:#F0313F}
  .st-switch.on i{transform:translateX(20px)}
  .st-switch:focus-visible{outline:3px solid #fff; outline-offset:2px}
  .st-saved{margin:8px 2px 0; font-size:12.5px; color:#34D399; font-weight:700}
</style>
<script>document.querySelectorAll('form.st-switch-row[data-confirm]').forEach(function (f) { f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); } }); });</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
