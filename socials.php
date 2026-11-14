<?php
// obitickets Socials: the event video feed. Open to everyone; liking, commenting and following need an account.
// Posts are Moments from every published event (includes/socials.php), shown one at a time, full height.
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$uid = $user ? (int) $user['id'] : null;
$tab = in_array($_GET['tab'] ?? '', ['following', 'trending'], true) ? (string) $_GET['tab'] : 'foryou';
$startId = (int) ($_GET['v'] ?? 0);
$ready = function_exists('socials_ready') && moments_ready();
// Two screens: the list (the Socials tab opens it), one card per organizer holding their posts; and the
// full-screen player. A card opens the player on that organizer's posts (?org=<id>, newest first, ?v= to start on
// one of them); a shared link opens one post in the main feed (?v=<id>); ?play=1 opens the main feed from the top.
$orgFilter = (int) ($_GET['org'] ?? 0);
$view = ($startId > 0 || $orgFilter > 0 || isset($_GET['play'])) ? 'player' : 'list';
$tabUrl = static function (string $t) use ($view): string {
    $q = array_filter(['play' => $view === 'player' ? '1' : null, 'tab' => $t !== 'foryou' ? $t : null]);
    return '/socials.php' . ($q ? '?' . http_build_query($q) : '');
};

$cards = [];
$orgInfo = null;
if (!$ready) {
    $rows = [];
} elseif ($view === 'list') {
    $cards = socials_organizer_cards($uid, $tab, 0, SOCIALS_FEED_PAGE);
    $rows = [];
} elseif ($orgFilter > 0) {
    // all of this organizer's posts, newest first; the player scrolls to ?v= if it is one of them
    $rows = socials_feed($uid, 'profile', 0, 24, null, $orgFilter);
    $orgCount = socials_profile_counts($orgFilter)['posts'];
} else {
    $rows = socials_feed($uid, $tab, 0, SOCIALS_FEED_PAGE, $startId ?: null);
}
$items = array_map('socials_item_public', $rows);
if ($orgFilter > 0 && $items) {
    $orgInfo = $items[0]['org'] + ['count' => $orgCount ?? count($items)];
}

$pageTitle = 'Socials: event videos | obitickets';
$pageDescription = 'Watch videos from events across Uganda, follow organizers and get your ticket straight from the video.';
// A shared link opens on that post, so the preview shows it.
$startRow = null;
foreach ($rows as $r) {
    if ($startId && (int) $r['id'] === $startId) {
        $startRow = $r;
        break;
    }
}
if ($startRow) {
    $first = $startRow;
    $pageTitle = $first['event_title'] . ' on obitickets Socials';
    $caption = trim((string) ($first['caption'] ?? ''));
    $pageDescription = $caption !== '' ? mb_substr($caption, 0, 180) : 'Watch this on obitickets Socials and get your ticket.';
    $shareImg = (string) ($first['poster_path'] ?: ($first['media_type'] === 'IMAGE' ? $first['media_path'] : ($first['event_banner'] ?? '')));
    if ($shareImg !== '' && preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $shareImg)) {
        $ogImage = rtrim(APP_URL, '/') . $shareImg;
        $ogImageW = 0;
    }
}
$bodyClass = 'classic-page socials-page' . ($view === 'list' ? ' socials-list' : '');
$hideWhatsApp = true; // the rail of buttons sits where the WhatsApp button would be
$ctx = $user ? resolve_organizer_context($user) : null;
$loginUrl = '/login.php?next=' . rawurlencode('/socials.php' . ($tab !== 'foryou' ? '?tab=' . $tab : ''));
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/socials.css?v=<?= @filemtime(__DIR__ . '/assets/css/socials.css') ?: time() ?>">

<main class="so-app" id="soApp">
  <?php if ($view === 'list'): ?>
  <header class="sl-head">
    <button type="button" class="sl-icon" id="slMenu" aria-label="Open menu" aria-controls="navDrawer" aria-expanded="false"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
    <a class="sl-logo" href="/" aria-label="obitickets home">
      <svg viewBox="0 0 34 34" width="30" height="30" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="#DC2626"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="#DC2626" stroke="#fff" stroke-width="1.6"/></svg>
      <span><b>obi</b>tickets</span>
    </a>
    <?php if ($ctx): $slUnread = count_unread_notifications((int) $user['id']); ?>
      <a class="sl-icon sl-bell" href="/org-notifications.php" aria-label="Notifications<?= $slUnread ? ', ' . (int) $slUnread . ' unread' : '' ?>"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7"/><path d="M10 20a2 2 0 0 0 4 0"/></svg><?php if ($slUnread): ?><i></i><?php endif; ?></a>
    <?php else: ?>
      <a class="sl-icon" href="/search.php" aria-label="Search events"><svg width="23" height="23" aria-hidden="true"><use href="#ic-search"/></svg></a>
    <?php endif; ?>
  </header>
  <section class="pc-intro">
    <h1>Socials</h1>
    <p>Videos from the events everyone is talking about. Watch, follow your favourite organizers and get your ticket in a tap.</p>
  </section>
  <?php endif; ?>

  <?php if ($orgInfo): ?>
  <div class="so-top so-orgtop">
    <a class="so-back" href="/socials.php" aria-label="Back to Socials"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <div class="so-orgname">
      <b><?= htmlspecialchars($orgInfo['name']) ?><?php if (!empty($orgInfo['verified'])): ?> <span class="sl-ver" title="Verified organizer"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><?php endif; ?></b>
      <small><?= (int) $orgInfo['count'] ?> <?= (int) $orgInfo['count'] === 1 ? 'video' : 'videos' ?></small>
    </div>
    <?php if (!empty($orgInfo['url'])): ?><a class="so-search" href="<?= htmlspecialchars($orgInfo['url']) ?>" aria-label="<?= htmlspecialchars($orgInfo['name']) ?> profile"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></a><?php else: ?><span class="so-top-gap" aria-hidden="true"></span><?php endif; ?>
  </div>
  <?php else: ?>
  <div class="so-top<?= $view === 'list' ? ' sl-top' : '' ?>">
    <?php if ($view === 'player'): ?><span class="so-top-gap" aria-hidden="true"></span><?php endif; ?>
    <nav class="so-tabs" aria-label="Socials">
      <a href="<?= htmlspecialchars($tabUrl('foryou')) ?>"<?= $tab === 'foryou' ? ' class="on" aria-current="page"' : '' ?>>For You</a>
      <a href="<?= htmlspecialchars($tabUrl('following')) ?>"<?= $tab === 'following' ? ' class="on" aria-current="page"' : '' ?>>Following</a>
      <a href="<?= htmlspecialchars($tabUrl('trending')) ?>"<?= $tab === 'trending' ? ' class="on" aria-current="page"' : '' ?>>Trending</a>
    </nav>
    <a class="so-search" href="/search.php" aria-label="Search events"><svg width="22" height="22" aria-hidden="true"><use href="#ic-search"/></svg></a>
  </div>
  <?php endif; ?>

  <?php if ($cards): ?>
    <div class="sl-list sc-list" id="slList" data-signed-in="<?= $user ? '1' : '0' ?>" data-login="<?= htmlspecialchars($loginUrl) ?>" data-tab="<?= htmlspecialchars($tab) ?>" data-offset="<?= count($cards) ?>" data-more="<?= count($cards) === SOCIALS_FEED_PAGE ? '1' : '0' ?>">
      <?php foreach ($cards as $card) { echo socials_org_card_html($card); } ?>
    </div>
    <p class="sl-more" id="slMore" hidden>Loading more&hellip;</p>
  <?php elseif ($items): ?>
    <div class="so-feed" id="soFeed" tabindex="0" aria-label="Socials feed. Swipe or use the arrow keys to move between videos."></div>
  <?php else: ?>
    <div class="so-empty">
      <i><svg width="30" height="30" aria-hidden="true"><use href="#ic-play"/></svg></i>
      <?php if (!$ready): ?>
        <h1>Socials is coming soon</h1>
        <p>Event videos from organizers across Uganda will show up here.</p>
        <a class="so-btn" href="/search.php">Browse events</a>
      <?php elseif ($tab === 'following' && !$user): ?>
        <h1>Follow your favourite organizers</h1>
        <p>Log in to follow organizers and see their videos here.</p>
        <a class="so-btn" href="<?= htmlspecialchars($loginUrl) ?>">Log in</a>
      <?php elseif ($tab === 'trending'): ?>
        <h1>Nothing trending yet</h1>
        <p>The most-liked, most-shared posts of the last two weeks will show up here.</p>
        <a class="so-btn" href="/socials.php">Open For You</a>
      <?php elseif ($tab === 'following'): ?>
        <h1>Nothing from the organizers you follow yet</h1>
        <p>Follow an organizer from any video and their posts will appear here.</p>
        <a class="so-btn" href="/socials.php">Open For You</a>
      <?php else: ?>
        <h1>Socials is just getting started</h1>
        <p>Event videos from organizers will show up here. Check back soon.</p>
        <?php if ($ctx): ?><a class="so-btn" href="/org-socials.php">Post your first video</a><?php else: ?><a class="so-btn" href="/search.php">Browse events</a><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<?php if ($cards): ?>
<script src="/assets/js/socials-list.js?v=<?= @filemtime(__DIR__ . '/assets/js/socials-list.js') ?: time() ?>" defer></script>
<?php endif; ?>

<?php if ($items && $view === 'player'): ?>
<div class="so-shade" id="soShade" hidden></div>

<section class="so-sheet" id="soComments" role="dialog" aria-modal="true" aria-labelledby="soCmTitle" hidden>
  <header><h2 id="soCmTitle">Comments</h2><button type="button" class="so-x" data-so-close aria-label="Close comments"><svg width="18" height="18" aria-hidden="true"><use href="#ic-x"/></svg></button></header>
  <div class="so-cm-list" id="soCmList" aria-live="polite"></div>
  <div class="so-cm-foot">
    <div class="so-replying" id="soReplying" hidden><span></span><button type="button" id="soReplyCancel" aria-label="Cancel reply">&times;</button></div>
    <div class="so-stickers" id="soStickers" role="group" aria-label="Send a sticker" hidden>
      <?php foreach (SOCIALS_STICKERS as $stName => $stLabel): ?>
        <button type="button" data-so-sticker="<?= htmlspecialchars($stName) ?>" aria-label="Send the <?= htmlspecialchars($stLabel) ?> sticker"><img src="/assets/stickers/<?= htmlspecialchars($stName) ?>.svg" alt="" width="64" height="64" loading="lazy"></button>
      <?php endforeach; ?>
    </div>
    <div class="so-emojis" role="group" aria-label="Add an emoji">
      <button type="button" class="so-st-toggle" id="soStToggle" aria-expanded="false" aria-controls="soStickers" aria-label="Stickers"><img src="/assets/stickers/lets-go.svg" alt="" width="30" height="30"></button>
      <?php foreach (["\u{2764}\u{FE0F}", "\u{1F525}", "\u{1F44F}", "\u{1F602}", "\u{1F60D}", "\u{1F62E}", "\u{1F389}"] as $emoji): ?>
        <button type="button" data-so-emoji="<?= $emoji ?>"><?= $emoji ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($user): ?>
      <form class="so-cm-form" id="soCmForm">
        <label class="sr-only" for="soCmInput">Add a comment</label>
        <input id="soCmInput" type="text" maxlength="<?= (int) COMMENT_MAX_CHARS ?>" placeholder="Add a comment..." autocomplete="off">
        <button type="submit" aria-label="Send comment"><svg width="18" height="18" aria-hidden="true"><use href="#ic-arrow"/></svg></button>
      </form>
    <?php else: ?>
      <a class="so-btn so-btn-wide" href="<?= htmlspecialchars($loginUrl) ?>">Log in to comment</a>
    <?php endif; ?>
  </div>
</section>

<section class="so-sheet so-sheet-sm" id="soShare" role="dialog" aria-modal="true" aria-labelledby="soShTitle" hidden>
  <header><h2 id="soShTitle">Share to</h2><button type="button" class="so-x" data-so-close aria-label="Close sharing"><svg width="18" height="18" aria-hidden="true"><use href="#ic-x"/></svg></button></header>
  <div class="so-share-grid">
    <a data-so-share="whatsapp" target="_blank" rel="noopener"><i class="wa"><svg width="24" height="24" aria-hidden="true"><use href="#ic-whatsapp"/></svg></i>WhatsApp</a>
    <a data-so-share="facebook" target="_blank" rel="noopener"><i class="fb">f</i>Facebook</a>
    <a data-so-share="x" target="_blank" rel="noopener"><i class="xx">X</i>X</a>
    <a data-so-share="telegram" target="_blank" rel="noopener"><i class="tg"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.5 4.5 2.9 11.6c-.9.4-.9 1.1-.2 1.300l4.700 1.500 1.800 5.600c.2.600.4.800.900.800s.600-.2.900-.500l2.300-2.200 4.700 3.500c.9.500 1.500.2 1.700-.800l3.100-14.700c.3-1.300-.5-1.900-1.300-1.600zM8.300 14.100l9.300-5.900c.4-.3.800-.1.500.2l-7.700 7-.3 3.300z"/></svg></i>Telegram</a>
    <button type="button" data-so-share="copy"><i><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1"/><path d="M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1"/></svg></i><span>Copy link</span></button>
    <button type="button" data-so-share="more"><i><svg width="22" height="22" aria-hidden="true"><use href="#ic-more"/></svg></i>More</button>
  </div>
</section>

<section class="so-sheet so-sheet-sm" id="soMore" role="dialog" aria-modal="true" aria-labelledby="soMoTitle" hidden>
  <header><h2 id="soMoTitle">Report this video</h2><button type="button" class="so-x" data-so-close aria-label="Close"><svg width="18" height="18" aria-hidden="true"><use href="#ic-x"/></svg></button></header>
  <div class="so-reasons">
    <?php foreach (REPORT_REASONS as $code => $label): ?>
      <button type="button" data-so-report="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></button>
    <?php endforeach; ?>
  </div>
</section>

<div class="so-toast" id="soToast" role="status" aria-live="polite"></div>

<script type="application/json" id="soData"><?= json_encode([
    'items' => $items,
    'hasMore' => count($items) === ($orgFilter ? 24 : SOCIALS_FEED_PAGE),
    'tab' => $tab,
    'start' => $startId ?: null,
    'org' => $orgFilter ?: null,
    'signedIn' => (bool) $user,
    'loginUrl' => $loginUrl,
    'me' => $uid,
    'stickers' => SOCIALS_STICKERS,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/socials.js?v=<?= @filemtime(__DIR__ . '/assets/js/socials.js') ?: time() ?>" defer></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
