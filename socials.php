<?php
// obitickets Socials: the event video feed. Open to everyone; liking, commenting and following need an account.
// Posts are Moments from every published event (includes/socials.php), shown one at a time, full height.
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$uid = $user ? (int) $user['id'] : null;
$tab = ($_GET['tab'] ?? '') === 'following' ? 'following' : 'foryou';
$startId = (int) ($_GET['v'] ?? 0);
$ready = function_exists('socials_ready') && moments_ready();

$rows = $ready ? socials_feed($uid, $tab, 0, SOCIALS_FEED_PAGE, $startId ?: null) : [];
$items = array_map('socials_item_public', $rows);

$pageTitle = 'Socials: event videos | obitickets';
$pageDescription = 'Watch videos from events across Uganda, follow organizers and get your ticket straight from the video.';
// A shared link opens on that post, so the preview shows it.
if ($startId && $rows && (int) $rows[0]['id'] === $startId) {
    $first = $rows[0];
    $pageTitle = $first['event_title'] . ' on obitickets Socials';
    $caption = trim((string) ($first['caption'] ?? ''));
    $pageDescription = $caption !== '' ? mb_substr($caption, 0, 180) : 'Watch this on obitickets Socials and get your ticket.';
    $shareImg = (string) ($first['poster_path'] ?: ($first['media_type'] === 'IMAGE' ? $first['media_path'] : ($first['event_banner'] ?? '')));
    if ($shareImg !== '' && preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $shareImg)) {
        $ogImage = rtrim(APP_URL, '/') . $shareImg;
        $ogImageW = 0;
    }
}
$bodyClass = 'classic-page socials-page';
$hideWhatsApp = true; // the rail of buttons sits where the WhatsApp button would be
$ctx = $user ? resolve_organizer_context($user) : null;
$loginUrl = '/login.php?next=' . rawurlencode('/socials.php' . ($tab === 'following' ? '?tab=following' : ''));
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/socials.css?v=<?= @filemtime(__DIR__ . '/assets/css/socials.css') ?: time() ?>">

<main class="so-app" id="soApp">
  <div class="so-top">
    <a class="so-home" href="/" aria-label="obitickets home">
      <svg viewBox="0 0 34 34" width="30" height="30" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="#DC2626"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="#DC2626" stroke="#fff" stroke-width="1.6"/></svg>
    </a>
    <nav class="so-tabs" aria-label="Socials">
      <a href="/socials.php"<?= $tab === 'foryou' ? ' class="on" aria-current="page"' : '' ?>>For You</a>
      <a href="/socials.php?tab=following"<?= $tab === 'following' ? ' class="on" aria-current="page"' : '' ?>>Following</a>
    </nav>
    <a class="so-search" href="/search.php" aria-label="Search events"><svg width="22" height="22" aria-hidden="true"><use href="#ic-search"/></svg></a>
  </div>

  <?php if ($items): ?>
    <div class="so-feed" id="soFeed" tabindex="0" aria-label="Socials feed. Swipe or use the arrow keys to move between videos."></div>
    <button type="button" class="so-sound" id="soSound" aria-pressed="false">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M16 9l5 6M21 9l-5 6"/></svg>
      <span>Tap for sound</span>
    </button>
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

<?php if ($items): ?>
<div class="so-shade" id="soShade" hidden></div>

<section class="so-sheet" id="soComments" role="dialog" aria-modal="true" aria-labelledby="soCmTitle" hidden>
  <header><h2 id="soCmTitle">Comments</h2><button type="button" class="so-x" data-so-close aria-label="Close comments"><svg width="18" height="18" aria-hidden="true"><use href="#ic-x"/></svg></button></header>
  <div class="so-cm-list" id="soCmList" aria-live="polite"></div>
  <div class="so-cm-foot">
    <div class="so-replying" id="soReplying" hidden><span></span><button type="button" id="soReplyCancel" aria-label="Cancel reply">&times;</button></div>
    <div class="so-emojis" role="group" aria-label="Add an emoji">
      <?php foreach (["\u{2764}\u{FE0F}", "\u{1F525}", "\u{1F44F}", "\u{1F602}", "\u{1F60D}", "\u{1F62E}", "\u{1F389}", "\u{1F64C}"] as $emoji): ?>
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
    'hasMore' => count($items) === SOCIALS_FEED_PAGE,
    'tab' => $tab,
    'start' => $startId ?: null,
    'signedIn' => (bool) $user,
    'loginUrl' => $loginUrl,
    'me' => $uid,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/socials.js?v=<?= @filemtime(__DIR__ . '/assets/js/socials.js') ?: time() ?>" defer></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
