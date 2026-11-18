<?php
require_once __DIR__ . '/includes/bootstrap.php';

// A member's public profile (11/18), in the My Profile look: picture, @username, Followers / Following (tap to see
// them), Follow + Message, the bio. Organizers have their own page (organizer.php); your own profile is /me.php.
// Nothing private is shown here: no tickets, no email, no phone.
$viewer = current_user();
$vid = $viewer ? (int) $viewer['id'] : null;
$id = (int) ($_GET['id'] ?? 0);
$person = $id > 0 && social_ready() ? social_person($id) : null;
if ($person && $person['organizer']) {
    header('Location: ' . $person['url']);
    exit;
}
if ($person && $vid === $id) {
    header('Location: /me.php');
    exit;
}
if (!$person || ($vid && social_blocked_between($vid, $id) && !social_i_blocked($vid, $id))) {
    http_response_code(404);
    $pageTitle = 'Not found — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap"><section class="auth-section"><div class="auth-card"><h1>Profile not found</h1><p class="sub">This profile is not available.</p><a class="btn btn-purple btn-block" href="/" style="margin-top:20px">Home</a></div></section></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$iBlocked = $vid ? social_i_blocked($vid, $id) : false;
$about = function_exists('user_about') ? user_about($id) : ['username' => '', 'bio' => ''];
$cover = function_exists('user_cover_url') ? (string) (user_cover_url($id) ?? '') : '';
$counts = social_counts($id);
$boosted = $iBlocked || !function_exists('user_boosted_videos') ? [] : user_boosted_videos($id);
$following = $vid ? social_is_following($vid, $id) : false;
$followsYou = $vid ? social_is_following($id, $vid) : false;
$compact = static fn (int $n): string => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M' : ($n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K' : (string) $n);
$login = '/login.php?next=' . rawurlencode('/u.php?id=' . $id);

$pageTitle = ($person['handle'] !== '' ? $person['handle'] : $person['name']) . ' — obitickets';
$bodyClass = 'classic-page app-dark mp-page sx-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/me-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/me-app.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/social-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/social-app.css') ?: time() ?>">

<div class="mp<?= $cover !== '' ? ' has-cover' : '' ?>"<?= $vid ? '' : ' data-login="' . htmlspecialchars($login, ENT_QUOTES) . '"' ?>>
  <?php if ($cover !== ''): ?><div class="mp-cover" style="background-image:url('<?= htmlspecialchars($cover, ENT_QUOTES) ?>')" aria-hidden="true"></div><?php endif; ?>
  <div class="wrap mp-in">
    <div class="mp-top" style="grid-template-columns:44px 1fr 44px">
      <a class="mp-ic" href="/discover.php" data-back aria-label="Back"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
      <h1><?= htmlspecialchars($person['name']) ?></h1>
      <?php if ($vid): ?>
        <div class="cx-menu">
          <button type="button" class="mp-ic" data-menu-btn aria-haspopup="true" aria-expanded="false" aria-label="More"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></button>
          <div class="cx-menu-list" hidden>
            <button type="button" data-copy="<?= htmlspecialchars(rtrim(APP_URL, '/') . '/u.php?id=' . $id, ENT_QUOTES) ?>">Copy profile link</button>
            <button type="button" class="danger" data-block-user="<?= $id ?>" data-blocked="<?= $iBlocked ? '1' : '0' ?>"><?= $iBlocked ? 'Unblock' : 'Block' ?></button>
          </div>
        </div>
      <?php else: ?><span></span><?php endif; ?>
    </div>

    <div class="mp-av"><?php if ($person['avatar']): ?><img src="<?= htmlspecialchars($person['avatar']) ?>" alt=""><?php else: ?><b aria-hidden="true"><?= htmlspecialchars(initials_from_name($person['name'])) ?></b><?php endif; ?></div>
    <h2 class="mp-handle"><?= htmlspecialchars($person['handle'] !== '' ? $person['handle'] : $person['name']) ?></h2>
    <?php if ($followsYou): ?><p class="sx-note">Follows you</p><?php endif; ?>

    <div class="mp-stats" style="grid-template-columns:repeat(2,1fr); max-width:300px">
      <a class="mp-stat-link" href="/follows.php?id=<?= $id ?>&amp;tab=followers"><b data-follower-count="<?= $id ?>"><?= htmlspecialchars($compact($counts['followers'])) ?></b><span>Followers</span></a>
      <a class="mp-stat-link" href="/follows.php?id=<?= $id ?>&amp;tab=following"><b><?= htmlspecialchars($compact($counts['following'])) ?></b><span>Following</span></a>
    </div>

    <?php if ($iBlocked): ?>
      <p class="sx-note">You blocked this person. Unblock them from the menu to follow or message.</p>
    <?php else: ?>
      <div class="mp-actions">
        <button type="button" class="mp-edit mp-follow" data-follow-user="<?= $id ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Following' : ($followsYou ? 'Follow back' : 'Follow') ?></button>
        <button type="button" class="mp-sq" data-message-user="<?= $id ?>" aria-label="Message <?= htmlspecialchars($person['name']) ?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg></button>
      </div>
    <?php endif; ?>
    <?php if ($about['bio'] !== ''): ?><p class="mp-bio"><?= nl2br(htmlspecialchars($about['bio'])) ?></p><?php endif; ?>
  </div>

  <?php if (!$iBlocked): ?>
  <div class="mp-tabs" style="grid-template-columns:1fr" role="presentation"><button type="button" aria-selected="true" tabindex="-1">Boosts</button></div>
  <section class="mp-panel" aria-label="Boosts">
    <?php if ($boosted): ?>
      <div class="mp-grid">
        <?php foreach ($boosted as $p):
            $thumb = (string) ($p['media_type'] === 'VIDEO' ? ($p['poster_path'] ?: '') : $p['media_path']);
            $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $thumb) ? $thumb : '';
            $views = (int) $p['view_count']; ?>
          <a class="mp-tile" href="/socials.php?v=<?= (int) $p['id'] ?>" aria-label="Boosted <?= $p['media_type'] === 'VIDEO' ? 'video' : 'post' ?>">
            <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars((string) $p['media_path']) ?>#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video><?php endif; ?>
            <span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round" aria-hidden="true"><path d="M7 4.5v15l12-7.5z"/></svg><?= htmlspecialchars($compact($views)) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="mp-empty"><b>No boosts yet</b><span>Videos <?= htmlspecialchars($person['name']) ?> boosts will show up here.</span></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<style>
  body.sx-page a.mp-stat-link{display:grid; gap:3px; color:#fff}
  body.sx-page a.mp-stat-link:hover{color:#fff; opacity:.85}
  .mp-follow{border:0; cursor:pointer; font:800 16px 'Plus Jakarta Sans',sans-serif; height:48px; border-radius:12px; flex:1; background:var(--ad-red, #F0313F); color:#fff}
  .mp-follow[aria-pressed="true"]{background:#2A2426; box-shadow:inset 0 0 0 1px rgba(255,255,255,.08)}
  button.mp-sq{border:0; cursor:pointer; flex:none; width:56px; height:48px; border-radius:12px; background:#2A2426; color:#fff; display:grid; place-items:center}
  .mp-top .cx-menu{justify-self:end}
</style>
<script src="/assets/js/social.js?v=<?= @filemtime(__DIR__ . '/assets/js/social.js') ?: time() ?>" defer></script>
<script src="/assets/js/chat-menu.js?v=<?= @filemtime(__DIR__ . '/assets/js/chat-menu.js') ?: time() ?>" defer></script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
