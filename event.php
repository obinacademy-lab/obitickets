<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$event = is_string($slug) && $slug !== '' ? get_event_by_slug($slug) : null;

if (!$event) {
    $pageTitle = 'Event not found — obitickets';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <section class="auth-section">
        <div class="auth-card">
          <h1>Event not found</h1>
          <p class="sub">This event may have been removed, or the link is incorrect.</p>
          <a class="btn btn-purple btn-block btn-lg" href="/" style="margin-top:24px">Back to homepage</a>
        </div>
      </section>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$tiers = get_ticket_types_for_event((int) $event['id']);
$similarEvents = get_similar_events((int) $event['id'], $event['category'], 3);
$eventMedia = get_event_media((int) $event['id']);
$currency = $tiers[0]['currency'] ?? 'UGX';

$currentUser = current_user();
$likeUserId = $currentUser ? (int) $currentUser['id'] : null;
$likeSessionToken = $likeUserId ? null : session_id();
$userLikedEvent = has_liked_event((int) $event['id'], $likeUserId, $likeSessionToken);

$eventUrl = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($event['slug']);
$shareText = $event['title'] . ' — ' . format_event_date_range($event['starts_at'], $event['ends_at']) . ' at ' . $event['venue_name'];
$organizerSlug = get_or_create_organizer_slug((int) $event['organizer_id']);

// The join/leave buttons live inside the checkout <form> (nested forms aren't
// valid HTML), so they're submit buttons with their own formaction pointing
// here, named "waitlist" with a value like "join:12" / "leave:12".
$waitlistMessages = [
    'joined' => ['success', "You're on the waitlist — we'll email you if a ticket opens up."],
    'already' => ['success', "You're already on the waitlist for that ticket."],
    'left' => ['success', "You've been removed from the waitlist."],
    'available' => ['error', 'That ticket is available now — you can buy it straight away.'],
    'closed' => ['error', 'Sorry, the waitlist for that ticket is closed.'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['waitlist'])) {
    verify_csrf();
    $backUrl = '/event.php?slug=' . urlencode($event['slug']);
    if (!$currentUser) {
        header('Location: /login.php?next=' . urlencode($backUrl . '#buyPanel'));
        exit;
    }
    [$wlAction, $wlTierId] = array_pad(explode(':', (string) $_POST['waitlist'], 2), 2, '0');
    $wlTierId = (int) $wlTierId;

    // Only act on a tier that really belongs to this event — the id comes
    // straight from the form, so never trust it to match the page.
    $ownsTier = false;
    foreach ($tiers as $t) {
        if ((int) $t['id'] === $wlTierId) {
            $ownsTier = true;
        }
    }
    if (!$ownsTier) {
        $wlResult = 'closed';
    } elseif ($wlAction === 'leave') {
        leave_waitlist((int) $currentUser['id'], $wlTierId);
        $wlResult = 'left';
    } else {
        $wlResult = join_waitlist((int) $currentUser['id'], $wlTierId);
    }
    header('Location: ' . $backUrl . '&waitlist=' . $wlResult . '#buyPanel');
    exit;
}
$waitlistNotice = $waitlistMessages[$_GET['waitlist'] ?? ''] ?? null;
// The waitlist is a nice-to-have; it must never be able to take the ticket
// page down for a logged-in buyer (e.g. if its table hasn't been migrated yet).
$waitingTierIds = [];
if ($currentUser) {
    try {
        $waitingTierIds = get_waiting_tier_ids((int) $currentUser['id'], (int) $event['id']);
    } catch (Throwable $e) {
        error_log('[waitlist] could not load waiting tiers: ' . $e->getMessage());
    }
}

$reviewError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {
    verify_csrf();
    if (!$currentUser) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    [$reviewOk, $reviewError] = submit_event_review(
        (int) $currentUser['id'],
        (int) $event['id'],
        (int) ($_POST['rating'] ?? 0),
        $_POST['comment'] ?? null
    );
    if ($reviewOk) {
        // Relative on purpose — $eventUrl is built from APP_URL (for share
        // links/OG tags, which need an absolute URL), but an internal redirect
        // should land on whatever host the visitor is already on.
        header('Location: /event.php?slug=' . urlencode($event['slug']) . '#reviews');
        exit;
    }
}

$reviewSummary = get_event_rating_summary((int) $event['id']);
$reviews = get_reviews_for_event((int) $event['id']);
$myReview = $currentUser ? get_user_review_for_event((int) $currentUser['id'], (int) $event['id']) : null;
$eventHasEnded = strtotime($event['ends_at']) < time();
$canReview = $currentUser && $eventHasEnded && user_attended_event((int) $currentUser['id'], (int) $event['id']);


$orgName = $event['org_name'] ?? $event['organizer_user_name'];

// Event community. Wrapped so the page keeps working (without a community section)
// if the community tables haven't been created yet.
$communityReady = false;
$postError = null;
$postDraft = '';
$social = ['going' => 0, 'following' => 0, 'reactions' => 0, 'comments' => 0, 'posts' => 0];
$feed = [];
$organizerLinks = [];
$followingEvent = $followingOrganizer = false;
$organizerFollowers = 0;
$ctx = null;
try {
    $eventId = (int) $event['id'];
    $uid = $currentUser ? (int) $currentUser['id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_post') {
        verify_csrf();
        if (!$currentUser) {
            header('Location: /login.php?next=' . urlencode('/event.php?slug=' . $event['slug'] . '#community'));
            exit;
        }
        $postDraft = (string) ($_POST['body'] ?? '');
        $postType = ($_POST['post_type'] ?? '') === 'ORGANIZER_UPDATE' ? 'ORGANIZER_UPDATE' : 'TEXT';
        [$imageOk, $imagePath, $imageError] = handle_post_image_upload($_FILES['image'] ?? []);
        if (!$imageOk) {
            $postError = $imageError;
        } else {
            $created = create_event_post($event, $currentUser, $postType, $postDraft, $imagePath, !empty($_POST['pin']), true);
            if ($created['ok']) {
                header('Location: /event.php?slug=' . urlencode($event['slug']) . '&posted=1#community');
                exit;
            }
            delete_post_image($imagePath);
            $postError = $created['error'];
        }
    }

    $social = get_event_social_counts($eventId);
    $feed = get_event_feed($eventId, $uid, FEED_PAGE_SIZE);
    $organizerLinks = get_organizer_social_links((int) $event['organizer_id']);
    $followingEvent = is_following_event($eventId, $uid);
    $followingOrganizer = is_following_organizer((int) $event['organizer_id'], $uid);
    $organizerFollowers = count_organizer_followers((int) $event['organizer_id']);
    $ctx = community_ctx($event, $currentUser, $orgName);
    $communityReady = true;
} catch (Throwable $e) {
    error_log('[community] event page: ' . $e->getMessage());
}

// Reactions on the event itself live in their own table (migration 015); if it is
// missing, only this bar is hidden — the rest of the community keeps working.
$eventReactions = null;
if ($communityReady) {
    try {
        $eventReactions = get_event_reaction_summary((int) $event['id'], $currentUser ? (int) $currentUser['id'] : null);
    } catch (Throwable $e) {
        error_log('[community] event reactions: ' . $e->getMessage());
    }
}

$minPrice = null;
foreach ($tiers as $tier) {
    if ($minPrice === null || (float) $tier['price'] < $minPrice) {
        $minPrice = (float) $tier['price'];
    }
}

$pageTitle = $event['title'] . ' — obitickets';
$pageDescription = $event['title'] . ' — ' . format_event_date_range($event['starts_at'], $event['ends_at']) . ' at ' . $event['venue_name'] . '.';
if (!empty($event['banner_image'])) {
    $ogImage = rtrim(APP_URL, '/') . $event['banner_image'];
}
$bodyClass = 'event-page';
include __DIR__ . '/includes/header.php';
?>

<?php
$evWhen = format_event_date_range($event['starts_at'], $event['ends_at']);
$evWhere = $event['venue_name'] . ($event['venue_address'] ? ', ' . $event['venue_address'] : '');
$evOrgInitials = initials_from_name($orgName);
$evOrgVerified = (int) ($event['organizer_verified'] ?? 0) === 1;
?>
<div class="ev" id="evRoot"<?= $communityReady && !$currentUser ? ' data-login="' . htmlspecialchars('/login.php?next=' . urlencode('/event.php?slug=' . $event['slug'] . '#community'), ENT_QUOTES) . '"' : '' ?> data-event-id="<?= (int) $event['id'] ?>" data-event-url="<?= htmlspecialchars($eventUrl, ENT_QUOTES) ?>">

<div class="lightbox-overlay" id="heroLightbox">
  <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close"><svg width="20" height="20"><use href="#ic-x"/></svg></button>
  <button class="lightbox-nav lightbox-prev" id="lightboxPrev" type="button" aria-label="Previous"><svg width="20" height="20"><use href="#ic-chev" transform="rotate(180 12 12)"/></svg></button>
  <img id="lightboxImg" src="" alt="">
  <video id="lightboxVideo" controls playsinline hidden></video>
  <button class="lightbox-nav lightbox-next" id="lightboxNext" type="button" aria-label="Next"><svg width="20" height="20"><use href="#ic-chev"/></svg></button>
  <div class="lightbox-counter" id="lightboxCounter"></div>
</div>

<?php if ($minPrice !== null && !$eventHasEnded): ?>
<a class="mobile-buy-bar" href="#buyPanel">
  <span>
    <span class="lbl">From</span>
    <span class="amt"><?= htmlspecialchars(format_money($minPrice, $currency)) ?></span>
  </span>
  <span class="btn btn-purple">Get Tickets</span>
</a>
<?php endif; ?>

<div class="sheet-backdrop" id="sheetBackdrop"></div>

<!-- ===== hero ===== -->
<section class="ev-hero"<?= $eventHasEnded ? '' : ' data-countdown-target="' . htmlspecialchars($event['starts_at']) . '"' ?>>
  <div class="ev-wrap ev-hero-grid">

    <?php if (!empty($event['banner_image'])): ?>
      <div class="ev-cover" id="heroImgWrap" data-lightbox-src="<?= htmlspecialchars($event['banner_image']) ?>" role="button" tabindex="0" aria-label="View banner full size">
        <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
      </div>
    <?php else: ?>
      <div class="ev-cover ev-cover-fallback" aria-hidden="true"><?= htmlspecialchars($event['banner_emoji']) ?></div>
    <?php endif; ?>

    <div class="ev-hero-text">
      <div class="ev-eyebrow">
        <span class="ev-eyebrow-cat"><?= htmlspecialchars($event['category']) ?></span>
        <span class="ev-dot" aria-hidden="true"></span>
        <?php if ($eventHasEnded): ?>
          <span class="ev-eyebrow-state">Event ended</span>
        <?php else: ?>
          <span class="ev-eyebrow-state">Starts in <span id="cd-text">--</span></span>
        <?php endif; ?>
      </div>

      <h1 class="ev-title"><?= htmlspecialchars($event['title']) ?></h1>

      <ul class="ev-meta">
        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="5.5" width="16" height="14.5" rx="2.4"/><path d="M4 10h16M8 3.5v3.5M16 3.5v3.5"/></svg><?= htmlspecialchars($evWhen) ?></li>
        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><?= htmlspecialchars($evWhere) ?></li>
      </ul>

      <div class="ev-org-row">
        <span class="ev-avatar ev-avatar-lg" style="background:#DC2626; color:#fff" aria-hidden="true"><?= htmlspecialchars($evOrgInitials) ?></span>
        <div class="ev-org-info">
          <div class="ev-org-name">
            <?php if ($organizerSlug): ?><a href="/organizer.php?slug=<?= urlencode($organizerSlug) ?>"><?= htmlspecialchars($orgName) ?></a><?php else: ?><?= htmlspecialchars($orgName) ?><?php endif; ?>
            <?php if ($evOrgVerified): ?><span class="ev-chip ev-chip-official ev-chip-solid-soft"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Verified organizer</span><?php endif; ?>
          </div>
          <?php if ($communityReady): ?><div class="ev-org-sub"><span id="orgFollowers"><?= number_format($organizerFollowers) ?></span> follower<?= $organizerFollowers === 1 ? '' : 's' ?></div><?php endif; ?>
        </div>
        <?php if ($communityReady): ?>
          <button type="button" class="ev-btn ev-btn-outline ev-btn-sm" data-ev-follow="organizer" data-id="<?= (int) $event['organizer_id'] ?>" aria-pressed="<?= $followingOrganizer ? 'true' : 'false' ?>"><?= $followingOrganizer ? 'Following' : 'Follow' ?></button>
        <?php endif; ?>
      </div>

      <div class="ev-actions">
        <?php if ($eventHasEnded): ?>
          <a class="ev-btn ev-btn-primary" href="#community">Relive the experience</a>
        <?php endif; ?>
        <?php if ($communityReady): ?>
          <button type="button" class="ev-btn ev-btn-outline" data-ev-follow="event" data-id="<?= (int) $event['id'] ?>" aria-pressed="<?= $followingEvent ? 'true' : 'false' ?>">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 10a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 16 6 10z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg>
            <span class="ev-follow-label"><?= $followingEvent ? 'Following' : 'Follow event' ?></span>
          </button>
        <?php endif; ?>
        <?php if ($communityReady && $eventReactions !== null): $myEventReaction = $eventReactions['mine']; ?>
        <div class="ev-event-react">
          <div class="ev-react-wrap">
            <button type="button" class="ev-btn ev-btn-outline ev-react-btn<?= $myEventReaction ? ' is-on' : '' ?>" id="evEventReactBtn" data-act="react-open" data-my="<?= htmlspecialchars((string) $myEventReaction) ?>" aria-haspopup="true">
              <span class="ev-react-ico"><?= $myEventReaction && isset(REACTION_TYPES[$myEventReaction]) ? REACTION_TYPES[$myEventReaction] : '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 5.6a5.2 5.2 0 0 0-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 0 0-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 0 0 0-7.4z"/></svg>' ?></span>
              <span class="ev-react-label"><?= $myEventReaction ? htmlspecialchars(ucfirst(strtolower($myEventReaction))) : 'React to this event' ?></span>
            </button>
            <?= community_reaction_picker_html() ?>
          </div>
          <span id="evEventReactSum"><?= community_reaction_summary_html($eventReactions['breakdown'], $eventReactions['total']) ?></span>
        </div>
        <?php endif; ?>
        <span class="ev-grow" aria-hidden="true"></span>
        <div class="ev-icons">
        <button class="ev-icon-btn like-btn<?= $userLikedEvent ? ' liked' : '' ?>" id="likeBtn" type="button" aria-label="Like this event" aria-pressed="<?= $userLikedEvent ? 'true' : 'false' ?>" data-event-id="<?= (int) $event['id'] ?>">
          <svg width="18" height="18"><use href="#ic-heart"/></svg>
          <span id="likeCount"><?= (int) $event['likes_count'] ?></span>
        </button>
        <div class="share-wrap">
          <button class="ev-icon-btn" id="shareBtn" type="button" aria-label="Share" data-share-title="<?= htmlspecialchars($event['title']) ?>" data-share-text="<?= htmlspecialchars($shareText) ?>" data-share-url="<?= htmlspecialchars($eventUrl) ?>">
            <svg width="18" height="18"><use href="#ic-share"/></svg>
          </button>
          <div class="share-popover" id="sharePopover">
            <a class="share-option" href="https://wa.me/?text=<?= urlencode($shareText . ' ' . $eventUrl) ?>" target="_blank" rel="noopener">WhatsApp</a>
            <a class="share-option" href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>&url=<?= urlencode($eventUrl) ?>" target="_blank" rel="noopener">X (Twitter)</a>
            <a class="share-option" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($eventUrl) ?>" target="_blank" rel="noopener">Facebook</a>
            <button class="share-option" type="button" data-copy-link>Copy link</button>
          </div>
        </div>
        </div>
      </div>

      <?php if ($communityReady): ?>
      <div class="ev-pulse" aria-label="Event activity">
        <div class="ev-pulse-item"><b id="pulseGoing"><?= number_format($social['going']) ?></b><span><?= $eventHasEnded ? 'went' : 'going' ?></span></div>
        <div class="ev-pulse-item"><b id="pulseFollowing"><?= number_format($social['following']) ?></b><span>following</span></div>
        <div class="ev-pulse-item"><b id="pulseReactions"><?= number_format($social['reactions'] + ($eventReactions['total'] ?? 0)) ?></b><span>reactions</span></div>
        <div class="ev-pulse-item"><b><?= number_format($social['comments']) ?></b><span>comments</span></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ===== tabs ===== -->
<nav class="ev-tabs" aria-label="Sections">
  <div class="ev-wrap ev-tabs-row">
    <?php if ($communityReady): ?><a href="#community" class="is-active" data-tab="community">Community</a><?php endif; ?>
    <a href="#about" data-tab="about">About</a>
    <?php if (!$eventHasEnded): ?><a href="#buyPanel" data-tab="tickets">Tickets</a><?php endif; ?>
    <?php if ($eventMedia): ?><a href="#moments" data-tab="moments">Moments</a><?php endif; ?>
    <?php if ($eventHasEnded || $reviews): ?><a href="#reviews" data-tab="reviews">Reviews</a><?php endif; ?>
  </div>
</nav>

<div class="ev-wrap ev-grid">

  <main class="ev-main">

    <?php if ($communityReady): ?>
    <!-- ===== community ===== -->
    <section id="community" class="ev-section">
      <h2 class="ev-h2"><?= $eventHasEnded ? 'Relive the experience' : 'Community' ?></h2>

      <?php if ($postError): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($postError) ?></div><?php endif; ?>
      <?php if (isset($_GET['posted'])): ?><div class="alert alert-success" style="margin-bottom:16px">Posted.</div><?php endif; ?>

      <?php if ($ctx['can_post']): $composerHasTicket = user_has_ticket_for_event((int) $currentUser['id'], (int) $event['id']); ?>
        <form method="post" enctype="multipart/form-data" class="ev-composer" id="evComposer">
          <?= csrf_field() ?><input type="hidden" name="action" value="create_post">
          <div class="ev-composer-top">
            <?= community_avatar_html($ctx['is_mod'] ? $orgName : $currentUser['name'], (int) $currentUser['id'], $ctx['is_mod']) ?>
            <label class="sr-only" for="evPostBody">Share with the community</label>
            <textarea id="evPostBody" name="body" rows="2" maxlength="<?= POST_MAX_CHARS ?>" placeholder="<?= $eventHasEnded ? (($composerHasTicket || $ctx['is_mod']) ? 'You were there. Tell everyone how it was.' : 'Share your thoughts about this event.') : ($composerHasTicket || $ctx['is_mod'] ? 'Going to this event? Share your excitement.' : 'Share what you think about this event.') ?>"><?= htmlspecialchars($postDraft) ?></textarea>
          </div>
          <div class="ev-composer-bar">
            <label class="ev-file"><input type="file" name="image" accept="image/jpeg,image/png,image/webp"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 9"/></svg><span id="evFileName">Photo</span></label>
            <?php if ($ctx['is_mod']): ?>
              <label class="ev-check"><input type="checkbox" name="post_type" value="ORGANIZER_UPDATE" checked> Official update</label>
              <label class="ev-check"><input type="checkbox" name="pin" value="1"> Pin</label>
            <?php else: ?>
              <span class="ev-composer-note"><?= $composerHasTicket ? 'Posting as a verified attendee' : 'Posting as ' . htmlspecialchars(community_display_name((string) $currentUser['name'])) . ' &middot; links are for ticket holders' ?></span>
            <?php endif; ?>
            <span class="ev-grow"></span>
            <button type="submit" class="ev-btn ev-btn-dark ev-btn-sm" id="evPostBtn">Post</button>
          </div>
        </form>
      <?php else: ?>
        <div class="ev-note-card">
          <b>Join the conversation</b>
          <span>Log in to post, react, comment and follow this event. No ticket needed.</span>
          <a class="ev-btn ev-btn-dark ev-btn-sm" href="<?= htmlspecialchars($ctx['login_url']) ?>">Log in</a>
        </div>
      <?php endif; ?>

      <div class="ev-feed" id="evFeed" data-has-more="<?= count($feed) === FEED_PAGE_SIZE ? '1' : '0' ?>" data-last-id="<?= $feed ? (int) (end($feed)['id']) : 0 ?>">
        <?php foreach ($feed as $post) { echo community_post_html($post, $ctx); } ?>
        <?php if (!$feed): ?>
          <div class="ev-empty" id="evEmpty"><b>No posts yet</b><span>Be the first to share something about this event.</span></div>
        <?php endif; ?>
      </div>
      <?php if (count($feed) === FEED_PAGE_SIZE): ?>
        <div class="ev-more"><button type="button" class="ev-btn ev-btn-outline" id="evMore">Show more posts</button></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===== about ===== -->
    <section id="about" class="ev-section">
      <h2 class="ev-h2">About this event</h2>
      <div class="ev-about">
        <?php foreach (explode("\n\n", $event['description'] ?? '') as $paragraph): ?>
          <p class="about-text"><?= nl2br(htmlspecialchars($paragraph)) ?></p>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ===== hosted by + attendance (kept out of the sticky ticket column so nothing slides over the card) ===== -->
    <section class="ev-section ev-hosting" aria-label="Organizer and attendance">
      <h2 class="ev-h2">Hosted by</h2>
      <div class="ev-hosting-grid<?= $communityReady ? '' : ' is-single' ?>">
        <div class="ev-card ev-host-card">
          <div class="ev-card-head">
            <span class="ev-avatar ev-avatar-lg" style="background:#DC2626; color:#fff" aria-hidden="true"><?= htmlspecialchars($evOrgInitials) ?></span>
            <div class="ev-card-title">
              <?php if ($organizerSlug): ?><a href="/organizer.php?slug=<?= urlencode($organizerSlug) ?>"><?= htmlspecialchars($orgName) ?></a><?php else: ?><span><?= htmlspecialchars($orgName) ?></span><?php endif; ?>
              <?php if ($communityReady): ?><small><span class="orgFollowersMirror"><?= number_format($organizerFollowers) ?></span> follower<?= $organizerFollowers === 1 ? '' : 's' ?></small><?php endif; ?>
            </div>
            <?php if ($communityReady): ?>
              <button type="button" class="ev-btn ev-btn-dark ev-btn-sm" data-ev-follow="organizer" data-id="<?= (int) $event['organizer_id'] ?>" aria-pressed="<?= $followingOrganizer ? 'true' : 'false' ?>"><?= $followingOrganizer ? 'Following' : 'Follow' ?></button>
            <?php endif; ?>
          </div>
          <p class="ev-card-text"><?= htmlspecialchars($event['organizer_bio'] ?: 'Event organizer on obitickets.') ?></p>
          <?php if ($organizerLinks): ?>
            <div class="ev-card-sub">Follow them elsewhere</div>
            <div class="ev-social">
              <?php foreach ($organizerLinks as $link): ?>
                <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?= htmlspecialchars($link['label']) ?> (opens in a new tab)"><?= htmlspecialchars($link['short']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($communityReady): ?>
        <div class="ev-card ev-going-card">
          <div class="ev-going-num"><?= number_format($social['going']) ?></div>
          <div class="ev-card-title-lg"><?= $social['going'] === 1 ? 'person' : 'people' ?> <?= $eventHasEnded ? 'went' : ($social['going'] === 1 ? 'is going' : 'are going') ?></div>
          <?php if ($currentUser && user_has_ticket_for_event((int) $currentUser['id'], (int) $event['id'])): ?>
            <div class="ev-going-you"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg> You have a ticket</div>
          <?php endif; ?>
          <p class="ev-card-text">Counted from paid tickets. We never show who is coming without their say-so.</p>
        </div>
        <?php endif; ?>
      </div>
    </section>

      <?php if ($eventMedia):
        // Photos first, then videos — the combined data-gallery-index order
        // the lightbox's Prev/Next walks through matches this same order,
        // so browsing the lightbox mirrors browsing the page top-to-bottom.
        $eventPhotos = array_values(array_filter($eventMedia, static fn ($m) => $m['media_type'] !== 'VIDEO'));
        $eventVideos = array_values(array_filter($eventMedia, static fn ($m) => $m['media_type'] === 'VIDEO'));
      ?>
    <section id="moments" class="ev-section ev-gallery">
      <h2 class="ev-h2">Moments</h2>

      <?php if ($eventPhotos): ?>
      <div class="gallery-section reveal">
        <div class="gallery-section-head">
          <h3>Photos</h3>
          <span class="count"><?= str_pad((string) count($eventPhotos), 2, '0', STR_PAD_LEFT) ?></span>
        </div>
        <div class="photo-grid">
          <?php foreach ($eventPhotos as $idx => $media): ?>
            <button type="button" class="photo-item<?= $idx === 0 ? ' featured' : '' ?>" data-lightbox-src="<?= htmlspecialchars($media['file_path']) ?>" data-gallery-index="<?= $idx ?>" data-gallery-type="photo">
              <img src="<?= htmlspecialchars($media['file_path']) ?>" alt="">
              <span class="expand-ic"><svg width="12" height="12"><use href="#ic-expand"/></svg></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($eventVideos): ?>
      <div class="gallery-section reveal" style="margin-top:28px">
        <div class="gallery-section-head">
          <h3>Videos</h3>
          <span class="count"><?= str_pad((string) count($eventVideos), 2, '0', STR_PAD_LEFT) ?></span>
        </div>
        <div class="video-grid">
          <?php foreach ($eventVideos as $vIdx => $media):
              $idx = count($eventPhotos) + $vIdx;
          ?>
            <button type="button" class="video-item" data-lightbox-src="<?= htmlspecialchars($media['file_path']) ?>" data-gallery-index="<?= $idx ?>" data-gallery-type="video">
              <video src="<?= htmlspecialchars($media['file_path']) ?>" preload="metadata" muted playsinline></video>
              <span class="video-play"><svg width="16" height="16"><use href="#ic-play"/></svg></span>
              <span class="video-duration" data-video-duration-src="<?= htmlspecialchars($media['file_path']) ?>" hidden></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($eventHasEnded || $reviews): ?>
    <section id="reviews" class="ev-section ev-reviews">
      <h2 class="ev-h2">Reviews</h2>

      <div class="review-summary reveal">
        <?php if ($reviewSummary['count'] > 0): ?>
          <span class="avg"><?= number_format($reviewSummary['average'], 1) ?></span>
          <?= render_star_rating($reviewSummary['average']) ?>
          <span style="color:var(--muted-2); font-size:0.86rem;"><?= $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?></span>
        <?php else: ?>
          <span style="color:var(--muted-2);">No reviews yet.</span>
        <?php endif; ?>
      </div>

      <?php if ($reviewError): ?>
        <div class="alert alert-error" style="max-width:460px; margin-bottom:16px"><?= htmlspecialchars($reviewError) ?></div>
      <?php endif; ?>

      <?php if ($canReview): ?>
        <form method="post" class="review-form reveal">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="submit_review">
          <div style="font-weight:700; margin-bottom:10px"><?= $myReview ? 'Update your review' : 'How was it?' ?></div>
          <div class="star-picker" role="radiogroup" aria-label="Rating">
            <?php for ($s = 5; $s >= 1; $s--): ?>
              <input type="radio" id="rate<?= $s ?>" name="rating" value="<?= $s ?>" required<?= $myReview && (int) $myReview['rating'] === $s ? ' checked' : '' ?>>
              <label for="rate<?= $s ?>" title="<?= $s ?> star<?= $s === 1 ? '' : 's' ?>">&#9733;</label>
            <?php endfor; ?>
          </div>
          <div class="field" style="margin-top:14px">
            <textarea name="comment" rows="3" maxlength="1000" placeholder="Tell others what you thought (optional)" style="width:100%"><?= htmlspecialchars($myReview['comment'] ?? '') ?></textarea>
          </div>
          <button class="btn btn-purple" type="submit" style="margin-top:12px"><?= $myReview ? 'Update review' : 'Post review' ?></button>
        </form>
      <?php elseif ($eventHasEnded && !$currentUser): ?>
        <p class="reveal" style="color:var(--muted-2); font-size:0.9rem; margin-bottom:20px"><a href="/login.php?next=<?= urlencode('/event.php?slug=' . $event['slug'] . '#reviews') ?>" style="color:var(--purple-light); font-weight:700">Log in</a> if you attended to leave a review.</p>
      <?php endif; ?>

      <?php foreach ($reviews as $r): ?>
        <div class="review-row reveal">
          <div class="review-row-head">
            <span class="organizer-avatar" style="width:36px; height:36px; font-size:0.78rem"><?= htmlspecialchars(initials_from_name($r['reviewer_name'])) ?></span>
            <div>
              <div class="who"><?= htmlspecialchars($r['reviewer_name']) ?></div>
              <?= render_star_rating((float) $r['rating']) ?>
            </div>
            <span class="when"><?= htmlspecialchars(date('j M Y', strtotime($r['created_at']))) ?></span>
          </div>
          <?php if ($r['comment']): ?><p class="review-comment"><?= nl2br(htmlspecialchars($r['comment'])) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </section>
      <?php endif; ?>

  </main>

  <aside class="ev-side">
    <div class="ev-stub-wrap">
    <?php if ($eventHasEnded): ?>
    <aside class="ended-panel" id="endedPanel">
      <div class="ended-panel-badge"><svg width="26" height="26"><use href="#ic-cal"/></svg></div>
      <h3>This event has ended</h3>
      <p>Ticket sales are closed. Thanks to everyone who came along<?= $reviewSummary['count'] > 0 ? ' — here is what they thought.' : '.' ?></p>
      <?php if ($reviewSummary['count'] > 0): ?>
        <a class="ended-panel-rating" href="#reviews">
          <span class="avg"><?= number_format($reviewSummary['average'], 1) ?></span>
          <?= render_star_rating($reviewSummary['average']) ?>
          <span><?= $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?></span>
        </a>
      <?php endif; ?>
      <a class="btn btn-purple btn-block" href="/search.php">Browse upcoming events</a>
      <a class="ended-panel-link" href="/past-events.php">See more past events &rarr;</a>
    </aside>
    <?php else: ?>
    <aside class="buy-panel ev-stub" id="buyPanel">
      <span class="sheet-handle" aria-hidden="true"></span>
      <button class="sheet-close" id="sheetClose" type="button" aria-label="Close">
        <svg width="14" height="14"><use href="#ic-x"/></svg>
      </button>
      <div class="ev-stub-top">
        <div class="ev-stub-label">ADMIT ONE</div>
        <div class="ev-stub-title"><?= htmlspecialchars($event['title']) ?></div>
        <div class="ev-stub-when"><?= htmlspecialchars($evWhen) ?></div>
        <div class="ev-stub-where"><?= htmlspecialchars($event['venue_name']) ?></div>
      </div>

      <?php if ($waitlistNotice): ?>
        <div class="alert alert-<?= $waitlistNotice[0] ?>" style="margin:16px 22px 0"><?= htmlspecialchars($waitlistNotice[1]) ?></div>
      <?php endif; ?>

      <?php if (isset($_GET['error']) && $_GET['error'] === 'select_tickets'): ?>
        <div class="alert alert-error" style="margin:16px 22px 0">Please select at least one ticket.</div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'sold_out'): ?>
        <div class="alert alert-error" style="margin:16px 22px 0">Sorry, one of the tickets you selected sold out. Please pick another.</div>
      <?php endif; ?>

      <form method="post" action="/checkout.php" data-currency="<?= htmlspecialchars($currency) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="event_slug" value="<?= htmlspecialchars($event['slug']) ?>">

        <div class="poster-tiers">
          <?php foreach ($tiers as $tier):
              $available = max(0, (int) $tier['quantity_total'] - (int) $tier['quantity_sold']);
              $soldOut = $available === 0;
          ?>
            <div class="tier" data-price="<?= htmlspecialchars($tier['price']) ?>" data-max="<?= $available ?>">
              <div class="tier-row">
                <div>
                  <div class="tn"><?= htmlspecialchars($tier['name']) ?></div>
                  <?php if ($tier['description']): ?><div class="td"><?= htmlspecialchars($tier['description']) ?></div><?php endif; ?>
                  <div class="tp"><?= htmlspecialchars(format_money($tier['price'], $currency)) ?></div>
                </div>
                <?php if ($soldOut): ?>
                  <div class="waitlist-box">
                    <div class="tag-pill" style="background:var(--line); color:var(--muted); flex:none">Sold out</div>
                    <?php if (in_array((int) $tier['id'], $waitingTierIds, true)): ?>
                      <span class="waitlist-state">On the waitlist</span>
                      <button class="waitlist-btn" type="submit" name="waitlist" value="leave:<?= (int) $tier['id'] ?>" formaction="/event.php?slug=<?= urlencode($event['slug']) ?>" formnovalidate>Leave</button>
                    <?php elseif (!$eventHasEnded): ?>
                      <button class="waitlist-btn primary" type="submit" name="waitlist" value="join:<?= (int) $tier['id'] ?>" formaction="/event.php?slug=<?= urlencode($event['slug']) ?>" formnovalidate>Join waitlist</button>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="qty">
                    <button type="button">&minus;</button>
                    <span class="n">0</span>
                    <button type="button">+</button>
                    <input type="hidden" class="qty-input" name="qty[<?= (int) $tier['id'] ?>]" value="0">
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="poster-summary">
          <div class="r"><span id="subtotal-label">Subtotal (0 tickets)</span><span class="mono" id="subtotal-amount"><?= htmlspecialchars($currency) ?> 0</span></div>
          <div class="r"><span id="fee-label">Service fee</span><span class="mono" id="fee-amount"><?= htmlspecialchars($currency) ?> 0</span></div>
          <div class="r total"><span>Total</span><span class="v mono" id="total-amount"><?= htmlspecialchars($currency) ?> 0</span></div>
        </div>
        <div class="poster-foot">
          <button class="btn btn-purple btn-block btn-lg" type="submit">Get Tickets</button>
          <div class="secure-note"><svg width="13" height="13"><use href="#ic-shield"/></svg> Secure checkout &middot; instant QR ticket</div>
          <div class="pay-icons">
            <span class="pay-badge"><img class="pay-logo pay-logo-mtn" src="/assets/images/brands/mtn-logo.svg" alt="MTN MoMo"></span>
            <span class="pay-badge"><img class="pay-logo pay-logo-airtel" src="/assets/images/brands/airtel-logo.svg" alt="Airtel Money"></span>
          </div>
        </div>
      </form>
    </aside>
    <?php endif; ?>
    </div>
  </aside>
</div>

<!-- shared report dialog -->
<div class="ev-modal" id="evReport" hidden>
  <div class="ev-modal-card" role="dialog" aria-modal="true" aria-labelledby="evReportTitle">
    <h3 id="evReportTitle">Report this</h3>
    <p class="ev-muted-note">Tell us what's wrong. Reports are private.</p>
    <fieldset class="ev-reasons">
      <legend class="sr-only">Reason</legend>
      <?php foreach (REPORT_REASONS as $key => $label): ?>
        <label><input type="radio" name="reason" value="<?= $key ?>"> <?= htmlspecialchars($label) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="sr-only" for="evReportDetails">More detail (optional)</label>
    <textarea id="evReportDetails" rows="2" maxlength="500" placeholder="More detail (optional)"></textarea>
    <div class="ev-modal-actions">
      <button type="button" class="ev-btn ev-btn-outline ev-btn-sm" data-modal="cancel">Cancel</button>
      <button type="button" class="ev-btn ev-btn-primary ev-btn-sm" data-modal="send">Send report</button>
    </div>
  </div>
</div>
<div class="ev-toast" id="evToast" role="status" aria-live="polite" hidden></div>

</div><!-- /.ev -->

<?php if ($similarEvents): ?>
<div class="wrap">
  <section class="reveal event-full-section" style="margin-top:0; padding:44px 0 10px;">
    <h2>Similar events</h2>
    <div class="poster-grid">
      <?php foreach ($similarEvents as $simEvent) render_poster_card($simEvent); ?>
    </div>
  </section>
</div>
<?php endif; ?>

<script src="/assets/js/community.js?v=<?= @filemtime(__DIR__ . '/assets/js/community.js') ?: time() ?>"></script>
<script src="/assets/js/event-motion.js?v=<?= @filemtime(__DIR__ . '/assets/js/event-motion.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="/assets/js/cinematic.js"></script>

