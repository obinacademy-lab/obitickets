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
$waitingTierIds = $currentUser ? get_waiting_tier_ids((int) $currentUser['id'], (int) $event['id']) : [];

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

<div class="event-hero-compact reveal" data-countdown-target="<?= htmlspecialchars($event['starts_at']) ?>">
  <div class="wrap">
    <div class="event-hero-compact-top">
      <span class="event-hero-compact-tag"><?= htmlspecialchars($event['banner_emoji']) ?> <?= htmlspecialchars($event['category']) ?></span>
    </div>

    <div class="event-hero-compact-row">
      <?php if (!empty($event['banner_image'])): ?>
        <div class="event-hero-compact-media" id="heroImgWrap" data-lightbox-src="<?= htmlspecialchars($event['banner_image']) ?>" role="button" tabindex="0" aria-label="View banner full size">
          <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
        </div>
      <?php else: ?>
        <div class="event-hero-compact-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
      <?php endif; ?>

      <div class="event-hero-compact-text">
        <div class="event-title-row">
          <h1><?= htmlspecialchars($event['title']) ?></h1>
          <div class="share-wrap">
            <button class="icon-btn" id="shareBtn" type="button" aria-label="Share" data-share-title="<?= htmlspecialchars($event['title']) ?>" data-share-text="<?= htmlspecialchars($shareText) ?>" data-share-url="<?= htmlspecialchars($eventUrl) ?>">
              <svg width="16" height="16"><use href="#ic-share"/></svg>
            </button>
            <div class="share-popover" id="sharePopover">
              <a class="share-option" href="https://wa.me/?text=<?= urlencode($shareText . ' ' . $eventUrl) ?>" target="_blank" rel="noopener">WhatsApp</a>
              <a class="share-option" href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>&url=<?= urlencode($eventUrl) ?>" target="_blank" rel="noopener">X (Twitter)</a>
              <a class="share-option" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($eventUrl) ?>" target="_blank" rel="noopener">Facebook</a>
              <button class="share-option" type="button" data-copy-link>Copy link</button>
            </div>
          </div>
        </div>
        <span class="event-hero-compact-org">Organized by:<b><?= htmlspecialchars($event['org_name'] ?? $event['organizer_user_name']) ?></b></span>
        <div class="event-hero-compact-date"><?= htmlspecialchars(format_event_date_range($event['starts_at'], $event['ends_at'])) ?></div>
        <div class="event-hero-compact-venue">&#128205; <?= htmlspecialchars($event['venue_name']) ?><?= $event['venue_address'] ? ', ' . htmlspecialchars($event['venue_address']) : '' ?></div>
        <div class="event-hero-compact-badges">
          <span class="event-hero-compact-badge"><span class="tk">&#127917;</span> Starts in <span id="cd-text">--</span></span>
          <button class="event-hero-compact-badge like-btn<?= $userLikedEvent ? ' liked' : '' ?>" id="likeBtn" type="button" aria-label="Like this event" aria-pressed="<?= $userLikedEvent ? 'true' : 'false' ?>" data-event-id="<?= (int) $event['id'] ?>">
            <svg width="15" height="15"><use href="#ic-heart"/></svg>
            <span id="likeCount"><?= (int) $event['likes_count'] ?></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="lightbox-overlay" id="heroLightbox">
  <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close"><svg width="20" height="20"><use href="#ic-x"/></svg></button>
  <button class="lightbox-nav lightbox-prev" id="lightboxPrev" type="button" aria-label="Previous"><svg width="20" height="20"><use href="#ic-chev" transform="rotate(180 12 12)"/></svg></button>
  <img id="lightboxImg" src="" alt="">
  <video id="lightboxVideo" controls playsinline hidden></video>
  <button class="lightbox-nav lightbox-next" id="lightboxNext" type="button" aria-label="Next"><svg width="20" height="20"><use href="#ic-chev"/></svg></button>
  <div class="lightbox-counter" id="lightboxCounter"></div>
</div>

<?php if ($minPrice !== null): ?>
<a class="mobile-buy-bar" href="#buyPanel">
  <span>
    <span class="lbl">From</span>
    <span class="amt"><?= htmlspecialchars(format_money($minPrice, $currency)) ?></span>
  </span>
  <span class="btn btn-purple">Get Tickets</span>
</a>
<?php endif; ?>

<div class="sheet-backdrop" id="sheetBackdrop"></div>

<div class="event-dark-shell">
<div class="wrap">
  <div class="event-layout">

    <div class="event-content">

      <h2 class="reveal">About this event</h2>
      <div class="reveal">
        <?php foreach (explode("\n\n", $event['description'] ?? '') as $paragraph): ?>
          <p class="about-text"><?= nl2br(htmlspecialchars($paragraph)) ?></p>
        <?php endforeach; ?>
      </div>

      <div class="divider"></div>

      <h2 class="reveal" style="margin-bottom:20px">Organized by</h2>
      <div class="organizer-row reveal">
        <?php if ($organizerSlug): ?>
          <a class="organizer-link" href="/organizer.php?slug=<?= urlencode($organizerSlug) ?>" style="display:contents">
            <span class="organizer-avatar"><?= htmlspecialchars(initials_from_name($event['org_name'] ?? $event['organizer_user_name'])) ?></span>
            <div>
              <h3><?= htmlspecialchars($event['org_name'] ?? $event['organizer_user_name']) ?></h3>
              <p><?= htmlspecialchars($event['organizer_bio'] ?? 'Event organizer on obitickets.') ?></p>
            </div>
          </a>
        <?php else: ?>
          <span class="organizer-avatar"><?= htmlspecialchars(initials_from_name($event['org_name'] ?? $event['organizer_user_name'])) ?></span>
          <div>
            <h3><?= htmlspecialchars($event['org_name'] ?? $event['organizer_user_name']) ?></h3>
            <p><?= htmlspecialchars($event['organizer_bio'] ?? 'Event organizer on obitickets.') ?></p>
          </div>
        <?php endif; ?>
        <button class="follow-btn" type="button">+ Follow</button>
      </div>

      <?php if ($eventMedia):
        // Photos first, then videos — the combined data-gallery-index order
        // the lightbox's Prev/Next walks through matches this same order,
        // so browsing the lightbox mirrors browsing the page top-to-bottom.
        $eventPhotos = array_values(array_filter($eventMedia, static fn ($m) => $m['media_type'] !== 'VIDEO'));
        $eventVideos = array_values(array_filter($eventMedia, static fn ($m) => $m['media_type'] === 'VIDEO'));
      ?>
      <div class="divider"></div>
      <h2 class="reveal" style="margin-bottom:20px">Event gallery</h2>

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
      <?php endif; ?>

      <?php if ($eventHasEnded || $reviews): ?>
      <div class="divider"></div>
      <h2 class="reveal" id="reviews" style="margin-bottom:16px">Reviews</h2>

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
      <?php endif; ?>

    </div>

    <aside class="buy-panel reveal" id="buyPanel">
      <span class="sheet-handle" aria-hidden="true"></span>
      <button class="sheet-close" id="sheetClose" type="button" aria-label="Close">
        <svg width="14" height="14"><use href="#ic-x"/></svg>
      </button>
      <div class="buy-head">
        <div class="lbl">SELECT TICKETS</div>
        <h3><?= htmlspecialchars($event['title']) ?></h3>
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

  </div>
</div>
</div>

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

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="/assets/js/cinematic.js"></script>
