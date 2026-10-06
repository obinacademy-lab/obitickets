<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Organizer "Promo studio": turns an event into a short 9:16 ad that ends on a Get tickets card. The video is
// drawn and recorded in the organizer's own browser (assets/js/promo-studio.js) and then published as a moment
// with the Get tickets bar switched on. Nothing is rendered on the server.

$ctx = require_organizer_access('social.view');
$organizerId = $ctx['organizer_id'];
$actor = current_user();
$canManage = organizer_can($ctx, 'social.manage');

$events = array_values(array_filter(get_events_for_organizer($organizerId), static fn ($e) => $e['status'] === 'PUBLISHED' && strtotime($e['ends_at']) >= time()));
$eventId = (int) ($_GET['event'] ?? 0);
$event = null;
foreach ($events as $e) {
    if ((int) $e['id'] === $eventId) {
        $event = $e;
    }
}
if (!$event && $events) {
    $event = $events[0];
}

$ready = moments_ready();
$studio = null;
if ($event && $ready) {
    $full = get_event_by_id((int) $event['id']);
    $tiers = get_ticket_types_for_event((int) $event['id']);
    $left = 0;
    $capacity = 0;
    $from = null;
    $currency = 'UGX';
    foreach ($tiers as $t) {
        $remaining = max(0, (int) $t['quantity_total'] - (int) $t['quantity_sold']);
        $capacity += (int) $t['quantity_total'];
        if (empty($t['sales_paused']) && $remaining > 0) {
            $left += $remaining;
            $from = $from === null ? (float) $t['price'] : min($from, (float) $t['price']);
            $currency = (string) $t['currency'];
        }
    }
    $starts = strtotime((string) $full['starts_at']);
    $days = $starts > time() ? (int) floor(($starts - time()) / 86400) : 0;

    // Photos the studio can use: the banner, gallery photos and photo moments, all on this site.
    $photos = [];
    if (!empty($full['banner_image'])) {
        $photos[] = $full['banner_image'];
    }
    foreach (get_event_media((int) $full['id']) as $m) {
        if ($m['media_type'] === 'IMAGE') {
            $photos[] = $m['file_path'];
        }
    }
    foreach (get_event_moments((int) $full['id'], null, 60) as $m) {
        if ($m['media_type'] === 'IMAGE') {
            $photos[] = $m['media_path'];
        } elseif ($m['media_type'] === 'SLIDESHOW') {
            foreach ($m['slides'] as $sp) {
                $photos[] = $sp;
            }
        }
    }
    $photos = array_slice(array_values(array_unique($photos)), 0, 12);

    $soundsOn = sounds_ready();
    $studio = [
        'eventId' => (int) $full['id'],
        'title' => (string) $full['title'],
        'when' => format_event_date_range($full['starts_at'], $full['ends_at']),
        'where' => (string) ($full['venue_name'] ?? ''),
        'currency' => $currency,
        'from' => $from,
        'left' => $left,
        'capacity' => $capacity,
        'days' => $days,
        'photos' => $photos,
        'sounds' => $soundsOn ? array_merge(array_map('sound_public', get_library_sounds()), array_map('sound_public', get_user_sounds((int) $actor['id']))) : [],
        'siteHost' => parse_url((string) APP_URL, PHP_URL_HOST) ?: 'obitickets.site',
        'canBuyBar' => moments_sales_ready(),
    ];
}

$pageTitle = 'Promo studio';
render_organizer_head('social', $ctx);
?>

<link rel="stylesheet" href="/assets/css/moments.css?v=<?= @filemtime(__DIR__ . '/assets/css/moments.css') ?: time() ?>">

<div class="admin-page-head">
  <div><h1>Promo studio</h1><p>Make a short video that ends on a Get tickets card. It is made in your browser, so it takes about as long as the video itself.</p></div>
  <a class="btn btn-line" href="/org-social.php<?= $event ? '?event=' . (int) $event['id'] : '' ?>#moments-admin">Back to Moments</a>
</div>

<?php if (!$events): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-heart"/></svg></div>
    <h3>No upcoming published events</h3>
    <p>Promo videos sell tickets, so they are made for events that are live and haven't finished.</p>
    <a class="btn btn-purple" href="/event-create.php">Create an event</a>
  </div>
<?php elseif (!$ready): ?>
  <div class="admin-empty"><h3>Moments aren't switched on yet</h3><p>Run <code>migration/017_moments.sql</code> to turn them on.</p></div>
<?php elseif (!$canManage): ?>
  <div class="admin-empty"><h3>You can look but not publish</h3><p>Your role doesn't include managing event social.</p></div>
<?php else: ?>

<form method="get" class="social-event-picker">
  <label for="eventPick">Event</label>
  <select id="eventPick" name="event" onchange="this.form.submit()">
    <?php foreach ($events as $e): ?>
      <option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === (int) $event['id'] ? ' selected' : '' ?>><?= htmlspecialchars($e['title']) ?> &middot; <?= htmlspecialchars(date('j M Y', strtotime($e['starts_at']))) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="admin-card" style="margin-top:16px" id="promoStudio" data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>" data-endpoint="/api/moment-upload.php"
     data-studio="<?= htmlspecialchars(json_encode($studio, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
  <div id="prUnsupported" class="muted" hidden style="padding:8px 0 14px">This browser can't record video. Use Chrome on a computer or an Android phone, or make a <a class="link" href="/org-social.php?event=<?= (int) $event['id'] ?>#moments-admin">photo slideshow with music</a> instead.</div>
  <div class="pr">
    <div>
      <h3 style="margin-bottom:4px">1. Choose a style</h3>
      <p class="muted" style="font-size:0.86rem; margin:0 0 12px">Each one is built to end in a sale.</p>
      <div class="pr-tpls" id="prTpls"></div>

      <h3 style="margin:22px 0 4px">2. Check the details</h3>
      <div class="social-link-row" style="display:block">
        <div class="admin-form-row"><label for="prHead">Headline</label><input id="prHead" type="text" maxlength="40" style="width:100%"></div>
        <div class="admin-form-row"><label for="prSub">Second line</label><input id="prSub" type="text" maxlength="60" style="width:100%"></div>
        <div class="admin-form-row"><label for="prWhen">When and where</label><input id="prWhen" type="text" maxlength="60" style="width:100%"></div>
        <div class="admin-form-row"><label for="prPrice">Price line</label><input id="prPrice" type="text" maxlength="40" style="width:100%"></div>
        <div class="admin-form-row"><label for="prCta">Button on the last card</label><input id="prCta" type="text" maxlength="24" value="Get tickets" style="width:100%"></div>
      </div>

      <h3 style="margin:22px 0 4px">3. Photos, length and sound</h3>
      <p class="muted" style="font-size:0.86rem; margin:0 0 10px">Tap photos to use them (the first ones play first). Add your own from your device.</p>
      <div class="pr-photos" id="prPhotos"></div>
      <input type="file" id="prAdd" accept="image/jpeg,image/png,image/webp" multiple hidden>
      <div class="mu-seg" id="prLen" style="margin-top:14px" role="group" aria-label="Length"><button type="button" data-l="8" aria-pressed="false">8 s</button><button type="button" data-l="12" aria-pressed="true">12 s</button><button type="button" data-l="16" aria-pressed="false">16 s</button></div>
      <div style="margin-top:12px">
        <label for="prSound" style="font-weight:800; font-size:0.86rem; display:block; margin-bottom:6px">Sound</label>
        <select id="prSound" style="width:100%; max-width:420px"><option value="">No sound</option></select>
        <div class="muted" style="font-size:0.82rem; margin-top:4px" id="prSoundNote"></div>
      </div>
    </div>

    <div>
      <div class="pr-pv"><canvas id="prCanvas" width="720" height="1280"></canvas></div>
      <div class="pr-tl" id="prTl"><b id="prHeadMark"></b></div>
      <div class="mu-ctrls"><button class="btn btn-line" type="button" id="prPlay" style="width:auto; margin:0">Play</button><button class="btn btn-line" type="button" id="prMute" aria-pressed="false" style="width:auto; margin:0">Sound on</button><button class="btn btn-purple" type="button" id="prRender" style="width:auto; margin:0">Render video</button></div>
      <div class="mu-bar" id="prBar" hidden style="max-width:300px; margin-left:auto; margin-right:auto"><i></i></div>
      <div class="mu-state" id="prState" role="status" style="text-align:center; margin-top:8px"></div>
      <div class="pr-result" id="prResult" hidden></div>
    </div>
  </div>
</div>

<script src="/assets/js/promo-studio.js?v=<?= @filemtime(__DIR__ . '/assets/js/promo-studio.js') ?: time() ?>"></script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
