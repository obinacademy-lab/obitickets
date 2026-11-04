<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/past-events.php';

// One finished event: story, highlights, photos and videos. Only events that are finished, published and
// not hidden by an admin are shown here; everything else gets a polite 404.

$slug = is_string($_GET['slug'] ?? null) ? trim($_GET['slug']) : '';
$event = $slug !== '' ? get_past_event($slug) : null;

if (!$event) {
    http_response_code(404);
    $pageTitle = 'Event not found | Obitickets';
    $bodyClass = 'classic-page past-events-page';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap pe-main">
      <section class="pe-empty">
        <h1 class="pe-h1-plain">We couldn't find that event</h1>
        <p>It may have been removed, or it hasn't finished yet. You can look through all the events that have already taken place.</p>
        <a class="pe-btn pe-btn-solid" href="/past-events.php">See all past events <svg width="15" height="15" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
      </section>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$eventId = (int) $event['id'];
$media = get_event_media($eventId);
$photos = array_values(array_filter($media, static fn ($m) => $m['media_type'] !== 'VIDEO'));
$videos = array_values(array_filter($media, static fn ($m) => $m['media_type'] === 'VIDEO'));
$highlights = past_highlights_list($event['highlights'] ?? '');
$organizerSlug = get_or_create_organizer_slug((int) $event['organizer_id']);
$verified = (int) ($event['organizer_verified'] ?? 0) === 1;
$mode = $event['attend_mode'] ?? 'IN_PERSON';
$where = $mode === 'ONLINE' ? 'Online event' : ($mode === 'HYBRID' ? $event['venue_name'] . ' + online' : $event['venue_name']);
$poster = trim((string) ($event['banner_image'] ?? ''));
$url = APP_URL . past_event_url($event['slug']);
$descText = trim((string) $event['description']);
$metaDesc = past_excerpt($descText !== '' ? $descText : $event['title'] . ' took place at ' . $event['venue_name'] . '. See the highlights on Obitickets.', 158);

// a few more finished events from the same filter, if there are any
$more = [];
$map = past_group_map();
if (isset($map[$event['category']])) {
    $cats = past_categories_for_group($map[$event['category']]);
    if ($cats) {
        $sameGroup = past_events_list('', $map[$event['category']], 4, 0);
        $more = array_slice(array_values(array_filter($sameGroup['events'], static fn ($e) => (int) $e['id'] !== $eventId)), 0, 3);
    }
}

$pageTitle = $event['title'] . ' | Past Event Highlights | Obitickets';
$pageDescription = $metaDesc;
$bodyClass = 'classic-page past-events-page';
include __DIR__ . '/includes/header.php';

$ld = [
    '@context' => 'https://schema.org',
    '@type' => 'Event',
    'name' => $event['title'],
    'startDate' => date('c', strtotime($event['starts_at'])),
    'endDate' => date('c', strtotime($event['ends_at'])),
    'eventStatus' => 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => $mode === 'ONLINE' ? 'https://schema.org/OnlineEventAttendanceMode' : ($mode === 'HYBRID' ? 'https://schema.org/MixedEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode'),
    'location' => $mode === 'ONLINE'
        ? ['@type' => 'VirtualLocation', 'url' => $url]
        : ['@type' => 'Place', 'name' => $event['venue_name'], 'address' => (string) ($event['venue_address'] ?: $event['venue_name'])],
    'organizer' => ['@type' => 'Organization', 'name' => $event['organizer_name'], 'url' => APP_URL . '/organizer.php?slug=' . rawurlencode((string) $organizerSlug)],
    'url' => $url,
];
if ($descText !== '') {
    $ld['description'] = past_excerpt($descText, 300);
}
$images = array_map(static fn ($m) => APP_URL . $m['file_path'], array_slice($photos, 0, 5));
if ($poster !== '') {
    array_unshift($images, APP_URL . $poster);
}
if ($images) {
    $ld['image'] = $images;
}
?>

<?php
// the single photo of an event is not worth a gallery of its own; a gallery appears from two photos up
$showPhotos = count($photos) >= 2;
$photoBase = $showPhotos ? count($photos) : 0;
$dateText = format_event_date_range($event['starts_at'], $event['ends_at']);
$ticker = array_values(array_filter(['Past event', (string) $event['category'], $dateText, $mode === 'ONLINE' ? 'Online event' : (string) $event['venue_name'], (string) $event['organizer_name']], static fn ($t) => trim($t) !== ''));
$titleWords = preg_split('/\s+/u', trim((string) $event['title'])) ?: [];
$nameParts = preg_split('/\s+/u', trim((string) $event['organizer_name'])) ?: [];
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$sealSvg = '<span class="pe-seal" role="img" aria-label="Verified organizer" title="Verified organizer"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22.25 12c0-1.43-.88-2.67-2.19-3.34.46-1.39.2-2.9-.81-3.91s-2.52-1.27-3.91-.81c-.66-1.31-1.91-2.19-3.34-2.19s-2.67.88-3.33 2.19c-1.4-.46-2.91-.2-3.92.81s-1.26 2.52-.8 3.91C2.87 9.33 2 10.57 2 12s.87 2.67 2.19 3.34c-.46 1.39-.21 2.9.8 3.91s2.52 1.26 3.91.81c.67 1.31 1.91 2.19 3.34 2.19s2.68-.88 3.34-2.19c1.39.45 2.9.2 3.91-.81s1.27-2.52.81-3.91C21.37 14.67 22.25 13.43 22.25 12z"/><path fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M8.6 12.3l2.5 2.5 4.4-4.8"/></svg></span>';
?>

<main class="pd">
  <span class="pd-glow" aria-hidden="true"></span><span class="pd-glow pd-glow-b" aria-hidden="true"></span>

  <section class="pd-hero">
    <div class="wrap pd-in">
      <nav class="pe-crumb pd-crumb" aria-label="Breadcrumb"><a href="/">obitickets</a><span aria-hidden="true">&rsaquo;</span><a href="/past-events.php">Past events</a><span aria-hidden="true">&rsaquo;</span><span><?= htmlspecialchars($event['title']) ?></span></nav>

      <div class="pd-grid">
        <div class="pd-info">
          <div class="pd-tags"><span class="pd-pill">Past event</span><span class="pd-cat"><?= htmlspecialchars($event['category']) ?></span></div>
          <h1 class="pd-h1"><?php foreach ($titleWords as $i => $word): ?><span class="pd-w"><span style="--i:<?= $i ?>"><?= htmlspecialchars($word) ?></span></span> <?php endforeach; ?></h1>
          <ul class="pd-tiles">
            <li><span class="pd-ic"><svg width="17" height="17" aria-hidden="true"><use href="#ic-cal"/></svg></span><small>Date</small><b><?= htmlspecialchars($dateText) ?></b></li>
            <li><span class="pd-ic"><svg width="17" height="17" aria-hidden="true"><use href="#ic-pin"/></svg></span><small><?= $mode === 'ONLINE' ? 'Location' : 'Venue' ?></small><b><?= htmlspecialchars($where) ?></b><?php if ($mode !== 'ONLINE' && !empty($event['venue_address'])): ?><em><?= htmlspecialchars($event['venue_address']) ?></em><?php endif; ?></li>
            <li><span class="pd-ic"><svg width="17" height="17" aria-hidden="true"><use href="#ic-user"/></svg></span><small>Organizer</small><b><?php if ($organizerSlug): ?><a href="/organizer.php?slug=<?= urlencode((string) $organizerSlug) ?>"><?= htmlspecialchars($event['organizer_name']) ?></a><?php else: ?><?= htmlspecialchars($event['organizer_name']) ?><?php endif; ?><?php if ($verified): ?> <?= $sealSvg ?><?php endif; ?></b></li>
          </ul>
        </div>

        <div class="pd-art">
          <?php if ($poster !== ''): ?>
            <button type="button" class="pd-ring pe-poster-btn" data-lightbox-src="<?= htmlspecialchars($poster) ?>" aria-label="View the poster full size">
              <img src="<?= htmlspecialchars($poster) ?>" alt="Poster for <?= htmlspecialchars($event['title'], ENT_QUOTES) ?>" fetchpriority="high">
            </button>
          <?php else: ?>
            <div class="pd-ring"><span class="pd-ring-fallback" role="img" aria-label="<?= htmlspecialchars($event['title'], ENT_QUOTES) ?>"><?= htmlspecialchars($event['banner_emoji']) ?></span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="pd-ticker" aria-hidden="true"><div><?php for ($r = 0; $r < 4; $r++): foreach ($ticker as $t): ?><span><?= htmlspecialchars($t) ?></span><?php endforeach; endfor; ?></div></div>
  </section>

  <div class="wrap pd-body">
    <div class="pd-main reveal">
      <?php if ($descText !== ''): ?>
        <div class="pe-about">
          <h2>About this event</h2>
          <?php foreach (preg_split('/\R{2,}/u', $descText) ?: [] as $para): ?>
            <p><?= nl2br(htmlspecialchars(trim($para))) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($highlights): ?>
        <aside class="pe-highlights" aria-labelledby="peHlH">
          <h2 id="peHlH">Event highlights</h2>
          <ul>
            <?php foreach ($highlights as $h): ?><li><svg width="16" height="16" aria-hidden="true"><use href="#ic-check"/></svg><span><?= htmlspecialchars($h) ?></span></li><?php endforeach; ?>
          </ul>
        </aside>
      <?php endif; ?>
      <p class="pd-ended"><i aria-hidden="true"></i>This event has ended.</p>
    </div>

    <aside class="pd-org reveal reveal-delay-1" aria-label="Organizer">
      <div class="pd-org-top">
        <span class="pd-av" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
        <div><small>Organized by</small><b><?= htmlspecialchars($event['organizer_name']) ?><?php if ($verified): ?> <?= $sealSvg ?><?php endif; ?></b></div>
      </div>
      <a class="pd-btn" href="/search.php">Browse upcoming events <svg width="15" height="15" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
      <?php if ($organizerSlug): ?><a class="pd-link" href="/organizer.php?slug=<?= urlencode((string) $organizerSlug) ?>">See all events by this organizer &rarr;</a><?php endif; ?>
    </aside>
  </div>

  <?php if ($showPhotos): ?>
  <section class="pe-gallery reveal" aria-labelledby="peGalH"><div class="wrap">
    <div class="pe-head"><h2 id="peGalH">Photo gallery</h2><p class="pe-count"><?= count($photos) ?> photos</p></div>
    <div class="pe-photos-grid">
      <?php foreach ($photos as $i => $m): ?>
        <button type="button" class="pe-photo<?= $i === 0 && count($photos) > 2 ? ' is-big' : '' ?>" data-lightbox-src="<?= htmlspecialchars($m['file_path']) ?>" data-gallery-index="<?= $i ?>" data-gallery-type="photo" aria-label="Open photo <?= $i + 1 ?> of <?= count($photos) ?>">
          <img src="<?= htmlspecialchars($m['file_path']) ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES) ?>, photo <?= $i + 1 ?>" loading="lazy">
        </button>
      <?php endforeach; ?>
    </div>
  </div></section>
  <?php endif; ?>

  <?php if ($videos): ?>
  <section class="pe-gallery reveal" aria-labelledby="peVidH"><div class="wrap">
    <div class="pe-head"><h2 id="peVidH">Video highlights</h2><p class="pe-count"><?= count($videos) ?> video<?= count($videos) === 1 ? '' : 's' ?></p></div>
    <div class="pe-videos-grid">
      <?php foreach ($videos as $v => $m): $idx = $photoBase + $v; ?>
        <button type="button" class="pe-video" data-lightbox-src="<?= htmlspecialchars($m['file_path']) ?>" data-gallery-index="<?= $idx ?>" data-gallery-type="video" aria-label="Play video <?= $v + 1 ?>">
          <video src="<?= htmlspecialchars($m['file_path']) ?>" preload="metadata" muted playsinline></video>
          <span class="pe-play"><svg width="22" height="22" aria-hidden="true"><use href="#ic-play"/></svg></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div></section>
  <?php endif; ?>

  <?php if ($more): ?>
  <section class="pe-section pd-more reveal" aria-labelledby="peMoreH"><div class="wrap">
    <div class="pe-head"><h2 id="peMoreH">More past events</h2><a class="pe-all" href="/past-events.php">See all &rarr;</a></div>
    <div class="pe-grid">
      <?php
      foreach ($more as $e): render_past_card($e); endforeach; ?>
    </div>
  </div></section>
  <?php endif; ?>
</main>
<section class="pe-cta pd-cta" aria-labelledby="peCtaH">
  <div class="wrap pe-cta-in">
    <div>
      <p class="pe-cta-kicker">OBITICKETS &mdash; DISCOVER. BOOK. ATTEND.</p>
      <h2 id="peCtaH">YOUR NEXT EVENT COULD BE HERE!</h2>
      <p>Give your event the visibility it deserves. Publish your event on Obitickets, reach potential attendees and simplify your ticketing experience.</p>
    </div>
    <a class="pe-cta-btn" href="/event-create.php">PUBLISH YOUR EVENT <svg width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg></a>
  </div>
</section>

<?php if ($photos || $videos || $poster !== ''): ?>
<div class="lightbox-overlay" id="heroLightbox">
  <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close"><svg width="20" height="20"><use href="#ic-x"/></svg></button>
  <button class="lightbox-nav lightbox-prev" id="lightboxPrev" type="button" aria-label="Previous"><svg width="20" height="20"><use href="#ic-chev" transform="rotate(180 12 12)"/></svg></button>
  <img id="lightboxImg" src="" alt="">
  <video id="lightboxVideo" controls playsinline hidden></video>
  <button class="lightbox-nav lightbox-next" id="lightboxNext" type="button" aria-label="Next"><svg width="20" height="20"><use href="#ic-chev"/></svg></button>
  <div class="lightbox-counter" id="lightboxCounter"></div>
</div>
<?php endif; ?>

<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/assets/js/cinematic.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
