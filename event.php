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
$similarEvents = get_similar_events((int) $event['id'], $event['category']);
$eventMedia = get_event_media((int) $event['id']);
$currency = $tiers[0]['currency'] ?? 'UGX';

$pageTitle = $event['title'] . ' — obitickets';
$pageDescription = $event['title'] . ' — ' . format_event_date_range($event['starts_at'], $event['ends_at']) . ' at ' . $event['venue_name'] . '.';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <div class="crumb"><a href="/">Home</a> <svg width="12" height="12"><use href="#ic-chev"/></svg> <a href="/search.php?category=<?= urlencode($event['category']) ?>"><?= htmlspecialchars($event['category']) ?></a> <svg width="12" height="12"><use href="#ic-chev"/></svg> <?= htmlspecialchars($event['title']) ?></div>
</div>

<div class="event-hero-full" id="eventHeroFull" data-countdown-target="<?= htmlspecialchars($event['starts_at']) ?>">
  <?php if (!empty($event['banner_image'])): ?>
    <div class="event-hero-media" id="heroImgWrap" data-lightbox-src="<?= htmlspecialchars($event['banner_image']) ?>" role="button" tabindex="0" aria-label="View banner full size">
      <img class="hero-bg-blur" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="" aria-hidden="true">
      <img id="heroBannerImg" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
    </div>
    <button class="hero-expand-btn" id="heroExpandBtn" type="button" aria-label="View banner full size" data-lightbox-src="<?= htmlspecialchars($event['banner_image']) ?>">
      <svg width="16" height="16"><use href="#ic-expand"/></svg><span>View full size</span>
    </button>
  <?php else: ?>
    <div class="event-hero-fallback"><?= htmlspecialchars($event['banner_emoji']) ?></div>
  <?php endif; ?>
  <div class="event-hero-count"><span class="tk">&#127917;</span> Starts in <span id="cd-text">--</span></div>
  <div class="wrap event-hero-content" id="eventHeroContent">
    <span class="event-hero-tag"><?= htmlspecialchars($event['banner_emoji']) ?> <?= htmlspecialchars($event['category']) ?></span>
    <h1><?= htmlspecialchars($event['title']) ?></h1>
    <div class="event-hero-meta">
      <span class="m"><svg width="15" height="15"><use href="#ic-cal"/></svg> <?= htmlspecialchars(format_event_date_range($event['starts_at'], $event['ends_at'])) ?></span>
      <span class="m"><svg width="15" height="15"><use href="#ic-pin"/></svg> <?= htmlspecialchars($event['venue_name']) ?></span>
    </div>
  </div>
</div>

<div class="lightbox-overlay" id="heroLightbox">
  <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close"><svg width="20" height="20"><use href="#ic-x"/></svg></button>
  <img id="lightboxImg" src="" alt="">
</div>

<div class="wrap">
  <div class="event-layout">

    <div class="event-content">

      <section class="reveal">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:20px">
          <h2>About this event</h2>
          <div style="display:flex; gap:2px; flex:none">
            <button class="icon-btn" type="button"><svg width="16" height="16"><use href="#ic-heart"/></svg></button>
            <button class="icon-btn" type="button"><svg width="16" height="16"><use href="#ic-share"/></svg></button>
          </div>
        </div>
        <?php foreach (explode("\n\n", $event['description'] ?? '') as $paragraph): ?>
          <p class="about-text"><?= nl2br(htmlspecialchars($paragraph)) ?></p>
        <?php endforeach; ?>
      </section>

      <?php if ($eventMedia): ?>
      <section class="reveal">
        <h2>Event gallery</h2>
        <div class="event-gallery">
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
      </section>
      <?php endif; ?>

      <section class="reveal">
        <h2>Venue</h2>
        <div class="plain-row">
          <div class="ic"><svg width="20" height="20"><use href="#ic-pin"/></svg></div>
          <div><h3><?= htmlspecialchars($event['venue_name']) ?></h3><p><?= htmlspecialchars($event['venue_address'] ?? '') ?></p></div>
          <a class="btn btn-line side" href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($event['venue_name'] . ', ' . ($event['venue_address'] ?? '')) ?>" target="_blank" rel="noopener">Directions</a>
        </div>
      </section>

      <section class="reveal">
        <h2>Organized by</h2>
        <div class="plain-row">
          <div class="avatar" style="width:40px;height:40px;background:var(--purple);color:#fff">
            <?= htmlspecialchars(initials_from_name($event['org_name'] ?? $event['organizer_user_name'])) ?>
          </div>
          <div>
            <h3><?= htmlspecialchars($event['org_name'] ?? $event['organizer_user_name']) ?></h3>
            <p><?= htmlspecialchars($event['organizer_bio'] ?? 'Event organizer on obitickets.') ?></p>
          </div>
          <a class="btn btn-line side" href="#">Follow</a>
        </div>
      </section>

      <?php if ($similarEvents): ?>
      <section class="reveal">
        <h2>Similar events</h2>
        <div class="similar-row">
          <?php foreach ($similarEvents as $tintIndex => $simEvent) { render_shelf_card($simEvent, $tintIndex); } ?>
        </div>
      </section>
      <?php endif; ?>

    </div>

    <aside class="buy-panel reveal">
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
                <div><div class="tn"><?= htmlspecialchars($tier['name']) ?></div><div class="td"><?= htmlspecialchars($tier['description'] ?? '') ?></div></div>
                <div class="tp"><?= htmlspecialchars(format_money($tier['price'], $currency)) ?></div>
              </div>
              <?php if ($soldOut): ?>
                <div class="tag-pill" style="margin-top:9px; background:var(--line); color:var(--muted)">Sold out</div>
              <?php else: ?>
                <div class="qty">
                  <button type="button">&minus;</button>
                  <span class="n">0</span>
                  <button type="button">+</button>
                  <input type="hidden" class="qty-input" name="qty[<?= (int) $tier['id'] ?>]" value="0">
                </div>
              <?php endif; ?>
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
            <span class="pay-badge pay-badge-text">Card</span>
          </div>
        </div>
      </form>
    </aside>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="/assets/js/cinematic.js"></script>
