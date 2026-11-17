<?php
require_once __DIR__ . '/includes/bootstrap.php';

// "Me": your own profile in the dark app look (owner's mockup, Phase 4, 11/18). Cover photo, picture, name,
// @username, bio, your numbers, Edit profile + Settings, then Tickets / Saved / Liked. Editing happens on
// /profile.php (Edit profile); account things on /settings.php. Only ever shows the signed-in person.
$user = require_login();
$uid = (int) $user['id'];

$st = db()->prepare('SELECT name, role, created_at FROM users WHERE id = ?');
$st->execute([$uid]);
$me = $st->fetch();
$name = (string) $me['name'];
$avatar = user_avatar_url($uid);
$cover = function_exists('user_cover_url') ? (string) (user_cover_url($uid) ?? '') : '';
$about = function_exists('user_about') ? user_about($uid) : ['username' => '', 'bio' => ''];
$isOrganizer = $me['role'] === 'ORGANIZER';
$orgSlug = '';
if ($isOrganizer) {
    $os = db()->prepare('SELECT slug FROM organizer_profiles WHERE user_id = ?');
    $os->execute([$uid]);
    $orgSlug = (string) ($os->fetchColumn() ?: '');
}

// tickets: upcoming first
$orders = get_orders_for_user($uid);
$upcoming = array_values(array_filter($orders, static fn ($o) => !empty($o['event_upcoming'])));
usort($upcoming, static fn ($a, $b) => strcmp((string) $a['event_starts_at'], (string) $b['event_starts_at']));
$past = array_values(array_filter($orders, static fn ($o) => empty($o['event_upcoming'])));
$ticketCount = 0;
foreach ($orders as $o) { if ($o['status'] === 'PAID') { $ticketCount += max(1, (int) $o['ticket_count']); } }

$following = 0;
try {
    $fs = db()->prepare('SELECT COUNT(*) FROM organizer_follows WHERE user_id = ?');
    $fs->execute([$uid]);
    $following = (int) $fs->fetchColumn();
} catch (Throwable $e) {
    $following = 0;
}

// saved and liked videos
$videos = static function (string $join) use ($uid): array {
    if (!function_exists('moment_select_sql')) { return []; }
    try {
        $q = db()->prepare('SELECT x.* FROM (' . moment_select_sql() . ') x ' . $join . ' WHERE x.status = "PUBLISHED" AND COALESCE(x.is_draft, 0) = 0 ORDER BY t.created_at DESC LIMIT 30');
        $q->execute([$uid]);
        return $q->fetchAll();
    } catch (Throwable $e) {
        error_log('[me] videos: ' . $e->getMessage());
        return [];
    }
};
$saved = function_exists('post_saves_ready') && post_saves_ready() ? $videos('JOIN post_saves t ON t.post_id = x.id AND t.user_id = ?') : [];
$liked = $videos('JOIN post_reactions t ON t.post_id = x.id AND t.user_id = ?');

$compact = static fn (int $n): string => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M' : ($n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K' : (string) $n);
$tile = static function (array $p): void {
    $thumb = (string) ($p['media_type'] === 'VIDEO' ? ($p['poster_path'] ?: '') : $p['media_path']);
    $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $thumb) ? $thumb : '';
    ?>
    <a class="op-tile" href="/socials.php?v=<?= (int) $p['id'] ?>" aria-label="<?= $p['media_type'] === 'VIDEO' ? 'Video' : 'Post' ?>">
      <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars((string) $p['media_path']) ?>#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video><?php endif; ?>
      <span class="op-views"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><?= htmlspecialchars(moment_compact((int) $p['view_count'])) ?></span>
    </a>
    <?php
};
$row = static function (array $o): void {
    $ts = strtotime((string) $o['event_starts_at']);
    $img = (string) ($o['event_banner'] ?? '');
    $img = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $img) ? $img : '';
    ?>
    <a class="op-row" href="/order.php?id=<?= (int) $o['id'] ?>">
      <span class="op-row-art"><?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><i aria-hidden="true">&#127915;</i><?php endif; ?></span>
      <span class="op-row-tx">
        <b><?= htmlspecialchars((string) $o['event_title']) ?></b>
        <small><?= htmlspecialchars(date('j M Y', $ts) . ' · ' . (string) $o['event_venue']) ?></small>
      </span>
      <?php if ($o['status'] !== 'PAID'): ?><em class="op-row-pill"><?= htmlspecialchars(ucfirst(strtolower((string) $o['status']))) ?></em><?php endif; ?>
      <svg class="op-row-go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
    </a>
    <?php
};

$pageTitle = 'Me — obitickets';
$bodyClass = 'ev-page app-dark op-app me-page';
$hideWhatsApp = true;
$rcTabForce = 'account';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/socials.css?v=<?= @filemtime(__DIR__ . '/assets/css/socials.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/organizer-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/organizer-app.css') ?: time() ?>">

<div class="ev ev-profile op-page">
  <header class="op-head">
    <div class="op-cover"<?= $cover !== '' ? ' style="background-image:url(\'' . htmlspecialchars($cover, ENT_QUOTES) . '\')"' : '' ?> aria-hidden="true"></div>
    <div class="ev-wrap op-top">
      <span class="op-me-t"><?= $about['username'] !== '' ? '@' . htmlspecialchars($about['username']) : 'Me' ?></span>
      <a class="op-tbtn" href="/settings.php" aria-label="Settings"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></a>
    </div>
    <span class="op-avatar"><?php if ($avatar): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><b aria-hidden="true"><?= htmlspecialchars(initials_from_name($name)) ?></b><?php endif; ?></span>
  </header>

  <section class="ev-wrap op-hero">
    <div class="op-name"><h1><?= htmlspecialchars($name) ?></h1></div>
    <p class="op-line"><?= $about['username'] !== '' ? '@' . htmlspecialchars($about['username']) : ($isOrganizer ? 'Event Organizer' : 'Member since ' . htmlspecialchars(date('M Y', strtotime((string) $me['created_at'])))) ?></p>

    <div class="op-stats">
      <div><b><?= htmlspecialchars($compact($ticketCount)) ?></b><span><?= $ticketCount === 1 ? 'Ticket' : 'Tickets' ?></span></div>
      <div><b><?= htmlspecialchars($compact($following)) ?></b><span>Following</span></div>
      <div><b><?= htmlspecialchars($compact(count($saved))) ?></b><span>Saved</span></div>
    </div>

    <div class="op-actions">
      <a class="op-follow op-edit" href="/profile.php">Edit profile</a>
      <?php if ($isOrganizer && $orgSlug !== ''): ?>
        <a class="op-send" href="/organizer.php?slug=<?= urlencode($orgSlug) ?>" aria-label="See my public page"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></a>
      <?php else: ?>
        <a class="op-send" href="/settings.php" aria-label="Settings"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg></a>
      <?php endif; ?>
    </div>

    <?php if ($about['bio'] !== ''): ?>
      <p class="op-bio"><?= nl2br(htmlspecialchars($about['bio'])) ?></p>
    <?php elseif (function_exists('profile_about_ready') && profile_about_ready()): ?>
      <p class="op-bio op-bio-empty"><a href="/profile.php#about">+ Add a username and bio</a></p>
    <?php endif; ?>
  </section>

  <div class="ev-wrap op-tabs" role="tablist" aria-label="Me">
    <button type="button" role="tab" id="meTabTickets" aria-controls="meTickets" aria-selected="true">Tickets</button>
    <button type="button" role="tab" id="meTabSaved" aria-controls="meSaved" aria-selected="false" tabindex="-1">Saved</button>
    <button type="button" role="tab" id="meTabLiked" aria-controls="meLiked" aria-selected="false" tabindex="-1">Liked</button>
  </div>

  <div class="ev-wrap ev-profile-body">
    <section class="op-panel" id="meTickets" role="tabpanel" aria-labelledby="meTabTickets">
      <?php if ($upcoming): ?>
        <h2 class="op-h">Upcoming (<?= count($upcoming) ?>)</h2>
        <div class="op-rows"><?php foreach (array_slice($upcoming, 0, 10) as $o) { $row($o); } ?></div>
      <?php endif; ?>
      <?php if ($past): ?>
        <h2 class="op-h">Past (<?= count($past) ?>)</h2>
        <div class="op-rows"><?php foreach (array_slice($past, 0, 10) as $o) { $row($o); } ?></div>
      <?php endif; ?>
      <?php if (!$orders): ?>
        <div class="ev-empty"><b>No tickets yet</b><span>Tickets you get land here, ready to show at the gate.</span></div>
      <?php endif; ?>
      <a class="me-more" href="/my-tickets.php">Open my ticket wallet</a>
    </section>
    <section class="op-panel" id="meSaved" role="tabpanel" aria-labelledby="meTabSaved" hidden>
      <?php if ($saved): ?><div class="op-grid"><?php foreach ($saved as $p) { $tile($p); } ?></div>
      <?php else: ?><div class="ev-empty"><b>Nothing saved yet</b><span>Tap Save on a video and it waits for you here.</span></div><?php endif; ?>
    </section>
    <section class="op-panel" id="meLiked" role="tabpanel" aria-labelledby="meTabLiked" hidden>
      <?php if ($liked): ?><div class="op-grid"><?php foreach ($liked as $p) { $tile($p); } ?></div>
      <?php else: ?><div class="ev-empty"><b>No liked videos yet</b><span>Videos you like show up here.</span></div><?php endif; ?>
    </section>
  </div>
</div>
<style>
  .op-me-t{flex:1; padding-left:12px; font-weight:800; font-size:16px; color:#fff; text-shadow:0 1px 8px rgba(0,0,0,.6)}
  .op-bio-empty a{color:#FF8A94; font-weight:700}
  .me-more{display:flex; justify-content:center; align-items:center; height:46px; margin-top:16px; border-radius:12px; background:rgba(255,255,255,.08); color:#fff; font-weight:800}
  body.me-page a.me-more,body.me-page a.me-more:hover{color:#fff}
  body.me-page a.op-send{color:#fff}
</style>
<script>
(function () {
  var tabs = document.querySelectorAll('.op-tabs [role="tab"]');
  tabs.forEach(function (t, i) {
    function pick(x, focus) {
      tabs.forEach(function (y) { var on = y === x; y.setAttribute('aria-selected', on ? 'true' : 'false'); y.tabIndex = on ? 0 : -1; document.getElementById(y.getAttribute('aria-controls')).hidden = !on; });
      if (focus) { x.focus(); }
    }
    t.addEventListener('click', function () { pick(t, false); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
      e.preventDefault();
      pick(tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length], true);
    });
  });
})();
</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
