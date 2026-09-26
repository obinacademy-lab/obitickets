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
$similarEvents = get_similar_events((int) $event['id'], $event['category'], 4);
$eventMedia = get_event_media((int) $event['id']);
$currency = $tiers[0]['currency'] ?? 'UGX';

$currentUser = current_user();
$likeUserId = $currentUser ? (int) $currentUser['id'] : null;
$likeSessionToken = $likeUserId ? null : session_id();
$userLikedEvent = has_liked_event((int) $event['id'], $likeUserId, $likeSessionToken);

$eventUrl = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($event['slug']);
$shareText = $event['title'] . ' — ' . format_event_date_range($event['starts_at'], $event['ends_at']) . ' at ' . $event['venue_name'];

$minPrice = null;
foreach ($tiers as $tier) {
    if ($minPrice === null || (float) $tier['price'] < $minPrice) {
        $minPrice = (float) $tier['price'];
    }
}

$pageTitle = $event['title'] . ' — obitickets';
$pageDescription = $event['title'] . ' — ' . format_event_date_range($event['starts_at'], $event['ends_at']) . ' at ' . $event['venue_name'] . '.';
$bodyClass = 'event-page';
include __DIR__ . '/includes/header.php';
?>

<div class="event-hero-v2 reveal" id="eventHeroFull" data-countdown-target="<?= htmlspecialchars($event['starts_at']) ?>">
  <?php if (!empty($event['banner_image'])): ?>
    <div class="event-hero-v2-media" id="heroImgWrap" data-lightbox-src="<?= htmlspecialchars($event['banner_image']) ?>" role="button" tabindex="0" aria-label="View banner full size">
      <img class="hero-bg-blur" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="" aria-hidden="true">
      <img id="heroBannerImg" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
    </div>
  <?php else: ?>
    <div class="event-hero-v2-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
  <?php endif; ?>
  <div class="event-hero-v2-scrim"></div>

  <div class="event-hero-v2-top">
    <span class="event-hero-v2-tag"><?= htmlspecialchars($event['banner_emoji']) ?> <?= htmlspecialchars($event['category']) ?></span>
    <div class="event-hero-v2-icons">
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
  </div>

  <div class="event-hero-v2-content">
    <span class="event-hero-v2-org">Organized by <b><?= htmlspecialchars($event['org_name'] ?? $event['organizer_user_name']) ?></b></span>
    <h1><?= htmlspecialchars($event['title']) ?></h1>
    <div class="event-hero-v2-meta">
      <span><?= htmlspecialchars(format_event_date_range($event['starts_at'], $event['ends_at'])) ?></span>
      <span>&#128205; <?= htmlspecialchars($event['venue_name']) ?><?= $event['venue_address'] ? ', ' . htmlspecialchars($event['venue_address']) : '' ?></span>
    </div>
    <div class="event-hero-v2-badges">
      <span class="event-hero-v2-badge"><span class="tk">&#127917;</span> Starts in <span id="cd-text">--</span></span>
      <button class="event-hero-v2-badge like-btn<?= $userLikedEvent ? ' liked' : '' ?>" id="likeBtn" type="button" aria-label="Like this event" aria-pressed="<?= $userLikedEvent ? 'true' : 'false' ?>" data-event-id="<?= (int) $event['id'] ?>">
        <svg width="15" height="15"><use href="#ic-heart"/></svg>
        <span id="likeCount"><?= (int) $event['likes_count'] ?></span>
      </button>
    </div>
  </div>
</div>

<div class="lightbox-overlay" id="heroLightbox">
  <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close"><svg width="20" height="20"><use href="#ic-x"/></svg></button>
  <img id="lightboxImg" src="" alt="">
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
        <span class="organizer-avatar"><?= htmlspecialchars(initials_from_name($event['org_name'] ?? $event['organizer_user_name'])) ?></span>
        <div>
          <h3><?= htmlspecialchars($event['org_name'] ?? $event['organizer_user_name']) ?></h3>
          <p><?= htmlspecialchars($event['organizer_bio'] ?? 'Event organizer on obitickets.') ?></p>
        </div>
        <button class="follow-btn" type="button">+ Follow</button>
      </div>

      <?php if ($eventMedia): ?>
      <div class="divider"></div>
      <h2 class="reveal" style="margin-bottom:20px">Event gallery</h2>
      <div class="event-gallery reveal">
        <?php foreach ($eventMedia as $media): ?>
          <?php if ($media['media_type'] === 'VIDEO'): ?>
            <div class="event-gallery-item">
              <video src="<?= htmlspecialchars($media['file_path']) ?>" controls preload="metadata"></video>
            </div>
          <?php else: ?>
            <button type="button" class="event-gallery-item" data-lightbox-src="<?= htmlspecialchars($media['file_path']) ?>">
              <img src="<?= htmlspecialchars($media['file_path']) ?>" alt="">
            </button>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
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
                  <div class="tag-pill" style="background:var(--line); color:var(--muted); flex:none">Sold out</div>
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
