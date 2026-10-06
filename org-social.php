<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Organizer "Event Social": post to your event's community, run its moderation
// queue, and manage the social links shown on your events.

$ctx = require_organizer_access('social.view');
$organizerId = $ctx['organizer_id'];
$actor = current_user();
$canManage = organizer_can($ctx, 'social.manage');

$events = array_values(array_filter(get_events_for_organizer($organizerId), static fn ($e) => $e['status'] === 'PUBLISHED'));
$eventId = (int) ($_POST['event_id'] ?? $_GET['event'] ?? 0);
$event = null;
foreach ($events as $e) {
    if ((int) $e['id'] === $eventId) {
        $event = $e;
    }
}
if (!$event && $events) {
    // default: the soonest upcoming event, else the most recent one
    $upcoming = array_filter($events, static fn ($e) => strtotime($e['ends_at']) >= time());
    $event = $upcoming ? end($upcoming) : $events[0];
}

$error = null;
$messages = [
    'posted' => 'Posted.', 'updated' => 'Saved.', 'hidden' => 'Hidden from the community. You can restore it below.',
    'restored' => 'Restored.', 'deleted' => 'Deleted.', 'dismissed' => 'Kept. The reports were closed.', 'links' => 'Social links saved.',
    'moment' => 'Moment published. Your followers have been told.', 'moment_saved' => 'Moment updated.', 'moment_deleted' => 'Moment deleted.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage event social.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $back = static function (string $code) use ($event): never {
        header('Location: /org-social.php' . ($event ? '?event=' . (int) $event['id'] . '&' : '?') . 'done=' . $code);
        exit;
    };

    if ($action === 'save_links') {
        $input = [];
        foreach (array_keys(SOCIAL_PLATFORMS) as $key) {
            $input[$key] = ['url' => (string) ($_POST['link_url'][$key] ?? ''), 'enabled' => isset($_POST['link_on'][$key])];
        }
        $errors = save_organizer_social_links($organizerId, $input);
        if ($errors) {
            $error = implode(' ', $errors);
        } else {
            $back('links');
        }
    } elseif ($event) {
        $fullEvent = get_event_by_id((int) $event['id']);
        if ($action === 'publish') {
            $type = ($_POST['post_type'] ?? '') === 'ORGANIZER_UPDATE' ? 'ORGANIZER_UPDATE' : 'TEXT';
            [$ok, $imagePath, $imageError] = handle_post_image_upload($_FILES['image'] ?? []);
            if (!$ok) {
                $error = $imageError;
            } else {
                $result = create_event_post($fullEvent, $actor, $type, (string) ($_POST['body'] ?? ''), $imagePath, !empty($_POST['pin']), !empty($_POST['comments_enabled']));
                if ($result['ok']) {
                    $back('posted');
                }
                delete_post_image($imagePath);
                $error = $result['error'];
            }
        } elseif ($action === 'moment_delete') {
            $result = delete_moment($actor, (int) ($_POST['id'] ?? 0));
            $result['ok'] ? $back('moment_deleted') : $error = $result['error'];
        } elseif ($action === 'moment_update') {
            $soundOpts = [];
            if (isset($_POST['sound_id'])) {
                $newSound = (int) $_POST['sound_id'];
                $soundOpts = ['sound_id' => $newSound, 'sound_start' => $newSound === (int) ($_POST['sound_prev'] ?? 0) ? (float) ($_POST['sound_start'] ?? 0) : 0, 'sound_mix' => (int) ($_POST['sound_mix'] ?? 70), 'slide_beats' => (int) ($_POST['slide_beats'] ?? 2), 'slide_fx' => (string) ($_POST['slide_fx'] ?? 'ZOOM')];
            }
            $result = update_moment($actor, (int) ($_POST['id'] ?? 0), (string) ($_POST['caption'] ?? ''), (int) ($_POST['focus_x'] ?? 50), ($_POST['fit'] ?? '') === 'FIT' ? 'FIT' : 'FILL', !empty($_POST['comments_enabled']), $soundOpts);
            $result['ok'] ? $back('moment_saved') : $error = $result['error'];
        } elseif (in_array($action, ['pin', 'unpin'], true)) {
            $result = set_post_pinned($actor, (int) ($_POST['id'] ?? 0), $action === 'pin');
            $result['ok'] ? $back('updated') : $error = $result['error'];
        } elseif (in_array($action, ['comments_on', 'comments_off'], true)) {
            $result = set_post_comments_enabled($actor, (int) ($_POST['id'] ?? 0), $action === 'comments_on');
            $result['ok'] ? $back('updated') : $error = $result['error'];
        } elseif (in_array($action, ['hide', 'restore', 'delete'], true)) {
            $type = ($_POST['type'] ?? '') === 'COMMENT' ? 'COMMENT' : 'POST';
            $result = moderate_content($actor, $type, (int) ($_POST['id'] ?? 0), strtoupper($action));
            $result['ok'] ? $back($action === 'hide' ? 'hidden' : ($action === 'restore' ? 'restored' : 'deleted')) : $error = $result['error'];
        } elseif ($action === 'dismiss') {
            $type = ($_POST['type'] ?? '') === 'COMMENT' ? 'COMMENT' : 'POST';
            $result = dismiss_reports($actor, $type, (int) ($_POST['id'] ?? 0));
            $result['ok'] ? $back('dismissed') : $error = $result['error'];
        }
    }
}

$counts = $event ? get_event_social_counts((int) $event['id']) : null;
$reports = $event ? get_event_reports((int) $event['id']) : [];
$hidden = $event ? get_hidden_content((int) $event['id']) : [];
$posts = $event ? get_event_feed((int) $event['id'], null, 20) : [];
$momentsReady = false;
$orgMoments = [];
$soundsReady = false;
$libSounds = [];
$mySounds = [];
if ($event) {
    try {
        if (moments_ready()) {
            $momentsReady = true;
            $orgMoments = get_event_moments((int) $event['id'], null, 100);
            if (sounds_ready()) {
                $soundsReady = true;
                $libSounds = array_map('sound_public', get_library_sounds());
                $mySounds = array_map('sound_public', get_user_sounds((int) $actor['id']));
            }
        }
    } catch (Throwable $e) {
        error_log('[moments] org page: ' . $e->getMessage());
    }
}
$links = get_organizer_social_links($organizerId, false);
$linksByPlatform = array_column($links, null, 'platform');

$pageTitle = 'Event Social';
render_organizer_head('social', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Event Social</h1><p>Talk to the people coming to your events and keep the conversation healthy.</p></div>
  <?php if ($event): ?>
    <a class="btn btn-line" href="/event.php?slug=<?= urlencode($event['slug']) ?>#community" target="_blank" rel="noopener">View community &rarr;</a>
  <?php endif; ?>
</div>

<?php if (isset($_GET['done'], $messages[$_GET['done']])): ?><span data-flash="<?= htmlspecialchars($messages[$_GET['done']], ENT_QUOTES) ?>" hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$events): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-heart"/></svg></div>
    <h3>No published events yet</h3>
    <p>Once an event is live, its community opens here: post updates, answer questions and moderate comments.</p>
    <a class="btn btn-purple" href="/event-create.php">Create an event</a>
  </div>
<?php else: ?>

<form method="get" class="social-event-picker">
  <label for="eventPick">Event</label>
  <select id="eventPick" name="event" onchange="this.form.submit()">
    <?php foreach ($events as $e): ?>
      <option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === (int) $event['id'] ? ' selected' : '' ?>><?= htmlspecialchars($e['title']) ?> &middot; <?= htmlspecialchars(date('j M Y', strtotime($e['starts_at']))) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn btn-line" type="submit">Switch</button></noscript>
</form>

<div class="admin-mini-stat-row">
  <div class="admin-mini-stat"><span class="n"><?= $counts['following'] ?></span><span class="l">Following the event</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $counts['reactions'] ?></span><span class="l">Reactions</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $counts['comments'] ?></span><span class="l">Comments</span></div>
  <div class="admin-mini-stat"><span class="n"<?= $reports ? ' style="color:var(--danger)"' : '' ?>><?= count($reports) ?></span><span class="l">Reports waiting</span></div>
</div>

<div class="admin-grid-2">
  <div style="display:flex; flex-direction:column; gap:20px; min-width:0">

    <?php if ($canManage): ?>
    <!-- composer -->
    <div class="admin-card">
      <h3 style="margin-bottom:12px">Post to your community</h3>
      <form method="post" enctype="multipart/form-data" class="social-composer">
        <?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <div class="social-seg" role="radiogroup" aria-label="Post type">
          <label><input type="radio" name="post_type" value="ORGANIZER_UPDATE" checked><span>Official announcement</span></label>
          <label><input type="radio" name="post_type" value="TEXT"><span>Regular post</span></label>
        </div>
        <div class="admin-form-row" style="margin-top:12px">
          <label for="postBody" class="sr-only">Message</label>
          <textarea id="postBody" name="body" rows="4" maxlength="<?= POST_MAX_CHARS ?>" placeholder="Doors open at 8 PM. Dress code is all black&hellip;" style="width:100%"></textarea>
        </div>
        <div class="social-composer-row">
          <label class="social-file"><input type="file" name="image" accept="image/jpeg,image/png,image/webp"> <span>Add a photo (optional)</span></label>
          <label class="social-check"><input type="checkbox" name="pin" value="1" checked> Pin to top <small>(max <?= MAX_PINNED_POSTS ?>, announcements only)</small></label>
          <label class="social-check"><input type="checkbox" name="comments_enabled" value="1" checked> Allow comments</label>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:14px"><button class="btn btn-purple" type="submit">Publish</button></div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <!-- moments -->
    <div class="admin-card" id="moments-admin">
      <link rel="stylesheet" href="/assets/css/moments.css?v=<?= @filemtime(__DIR__ . '/assets/css/moments.css') ?: time() ?>">
      <h3 style="margin-bottom:4px">Moments <span class="muted" style="font-weight:600; font-size:0.85rem">9:16 photos and videos</span></h3>
      <p class="muted" style="font-size:0.86rem; margin:0 0 16px">Short vertical clips and photos from your event. Anyone can watch; people with an account can react, comment and share. Vertical video (1080 &times; 1920) looks best.</p>
      <?php if (!$momentsReady): ?>
        <div class="muted" style="padding:14px 0">Moments are not switched on yet. Run <code>migration/017_moments.sql</code> to turn them on.</div>
      <?php else: ?>
      <div class="mu" id="mu" data-endpoint="/api/moment-upload.php" data-event-id="<?= (int) $event['id'] ?>" data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"
           data-max-video="<?= moment_video_max_bytes() ?>" data-max-image="<?= MOMENT_MAX_IMAGE_BYTES ?>" data-max-seconds="<?= MOMENT_MAX_SECONDS ?>" data-max-slides="<?= MOMENT_MAX_SLIDES ?>"
           data-max-sound="<?= SOUND_MAX_BYTES ?>" data-sounds-on="<?= $soundsReady ? '1' : '0' ?>"
           data-sounds="<?= htmlspecialchars(json_encode(['library' => $libSounds, 'mine' => $mySounds], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
        <div>
          <div class="mu-frame is-empty" id="muFrame">Choose a video or photos to see how they will look</div>
          <div class="mu-hint" id="muHint" hidden>&larr; Drag sideways to choose what stays in frame &rarr;</div>
          <div class="mu-ctrls" id="muCtrls" hidden>
            <button class="btn btn-line" type="button" id="muPlay" style="width:auto; margin:0">Play preview</button>
            <button class="btn btn-line" type="button" id="muMute" aria-pressed="false" style="width:auto; margin:0">Sound on</button>
          </div>
          <label class="social-check" style="justify-content:center; margin-top:10px"><input type="checkbox" id="muGuides" checked> Show where buttons and caption appear</label>
        </div>
        <div>
          <div class="mu-seg mu-modes" role="group" aria-label="What are you posting"><button type="button" data-mode="single" aria-pressed="true">Video or photo</button><button type="button" data-mode="slides" aria-pressed="false">Photo slideshow (up to <?= MOMENT_MAX_SLIDES ?>)</button></div>

          <div id="muSingleBox">
            <div class="mu-row" style="margin-top:0">
              <label class="btn btn-line" for="muFile" style="display:inline-flex; width:auto; margin:0; cursor:pointer">Choose video or photo</label>
              <input type="file" id="muFile" accept="video/mp4,video/webm,image/jpeg,image/png,image/webp" hidden>
              <span class="muted" style="font-size:0.84rem; margin-left:8px">MP4 or WebM up to <?= (int) round(moment_video_max_bytes() / 1048576) ?> MB and <?= MOMENT_MAX_SECONDS ?> seconds &middot; JPG, PNG or WebP up to 5 MB</span>
            </div>
            <div class="mu-facts" id="muFacts" style="margin-top:12px"></div>
            <div id="muFit" hidden>
              <div class="mu-seg" role="group" aria-label="How to fit"><button type="button" data-fit="FILL" aria-pressed="true">Fill the frame</button><button type="button" data-fit="FIT" aria-pressed="false">Fit with blurred sides</button></div>
            </div>
            <div class="mu-row" id="muCover" hidden>
              <label for="muCoverRange">Cover frame <span class="muted" style="font-weight:600">(what people see before they press play)</span></label>
              <input type="range" id="muCoverRange" min="0" max="100" value="0" step="1">
            </div>
          </div>

          <div id="muSlidesBox" hidden>
            <div class="mu-row" style="margin-top:0">
              <label class="btn btn-line" for="muPhotos" style="display:inline-flex; width:auto; margin:0; cursor:pointer">Add photos</label>
              <input type="file" id="muPhotos" accept="image/jpeg,image/png,image/webp" multiple hidden>
              <span class="muted" style="font-size:0.84rem; margin-left:8px">Up to <?= MOMENT_MAX_SLIDES ?> photos. Each is cropped to 9:16 from the middle. Drag to reorder.</span>
            </div>
            <div class="mu-thumbs" id="muThumbs"></div>
            <div class="muted" style="font-size:0.84rem" id="muPcount">0 of <?= MOMENT_MAX_SLIDES ?> photos</div>
          </div>

          <?php if ($soundsReady): ?>
          <div class="mu-row" id="muSoundBox">
            <label>Sound</label>
            <div class="mu-seg" role="group" aria-label="Sound source"><button type="button" data-src="none" aria-pressed="true">No sound</button><button type="button" data-src="lib" aria-pressed="false">Library</button><button type="button" data-src="mine" aria-pressed="false">My sounds</button><button type="button" data-src="new" aria-pressed="false">Upload new</button></div>
            <div class="mu-tracks" id="muTracks" hidden></div>
            <div id="muNewBox" hidden>
              <div class="mu-own">
                <b style="font-family:var(--font-display,serif); font-size:1.05rem; display:block">Add an audio file</b>
                <span class="muted" style="display:block; font-size:0.84rem; margin:4px 0 10px">MP3, M4A, WAV or OGG up to <?= (int) round(SOUND_MAX_BYTES / 1048576) ?> MB. The beat is found automatically.</span>
                <label class="btn btn-line" for="muAudio" style="display:inline-flex; width:auto; margin:0; cursor:pointer">Choose audio</label>
                <input type="file" id="muAudio" accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg,audio/aac,.mp3,.m4a,.wav,.ogg" hidden>
              </div>
              <div class="mu-row"><label for="muOwnTitle">Title</label><input type="text" id="muOwnTitle" maxlength="120" style="width:100%"></div>
              <div class="mu-row"><label for="muOwnArtist">Artist (optional)</label><input type="text" id="muOwnArtist" maxlength="120" style="width:100%"></div>
              <label class="social-check" style="margin-top:12px; align-items:flex-start"><input type="checkbox" id="muRights"> <span>I own this sound or have permission to use it publicly. Reported sounds are removed.</span></label>
            </div>
            <div id="muSoundCtl" hidden>
              <div class="mu-wave" id="muWave"><canvas id="muWcv"></canvas><div class="mu-win" id="muWin"></div></div>
              <div class="mu-wlab"><span id="muWstart">Starts at 0:00</span><span id="muWlen"></span></div>
              <div class="mu-facts" id="muSfacts" style="margin-top:10px"></div>
              <div class="mu-btnrow" id="muBpmFix" hidden><button class="btn btn-line" type="button" id="muHalf">Half speed</button><button class="btn btn-line" type="button" id="muDouble">Double speed</button></div>
              <div class="mu-row" id="muMixRow" hidden><label for="muMix">Sound mix: <span id="muMixV">Music 70% &middot; video 30%</span></label><input type="range" id="muMix" min="0" max="100" value="70"></div>
            </div>
          </div>

          <div class="mu-row" id="muBeatBox" hidden>
            <label>Change photo every</label>
            <div class="mu-seg" role="group" aria-label="Beats per photo"><button type="button" data-beats="1" aria-pressed="false">Beat</button><button type="button" data-beats="2" aria-pressed="true">2 beats</button><button type="button" data-beats="4" aria-pressed="false">Bar (4)</button><button type="button" data-beats="8" aria-pressed="false">2 bars</button></div>
            <label style="margin-top:12px">Transition</label>
            <div class="mu-seg" role="group" aria-label="Transition"><button type="button" data-fx="CUT" aria-pressed="false">Cut</button><button type="button" data-fx="FADE" aria-pressed="false">Fade</button><button type="button" data-fx="ZOOM" aria-pressed="true">Zoom pulse</button><button type="button" data-fx="FLASH" aria-pressed="false">Flash</button></div>
            <div class="mu-facts" id="muTiming" style="margin-top:10px"></div>
          </div>
          <?php endif; ?>

          <div class="mu-row">
            <label for="muCaption">Caption</label>
            <textarea id="muCaption" maxlength="<?= MOMENT_CAPTION_MAX ?>" placeholder="Doors open at 8. Dress code: all black."></textarea>
            <div class="muted" style="font-size:0.8rem; margin-top:4px"><span id="muCount">0</span>/<?= MOMENT_CAPTION_MAX ?></div>
          </div>
          <div class="mu-bar" id="muBar" hidden><i></i></div>
          <div class="mu-actions"><span class="mu-state" id="muState" role="status">Nothing selected yet.</span><button class="btn btn-purple" type="button" id="muPublish" disabled>Publish moment</button></div>
        </div>
      </div>

      <?php if ($orgMoments): ?>
      <div class="mu-list">
        <?php foreach ($orgMoments as $m): $isVideo = $m['media_type'] === 'VIDEO'; $thumb = $isVideo ? $m['poster_path'] : $m['media_path']; ?>
          <div class="mu-item">
            <div class="mu-thumb">
              <?php if ($thumb): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy" style="object-position:<?= (int) $m['focus_x'] ?>% 50%"><?php else: ?><video src="<?= htmlspecialchars($m['media_path']) ?>#t=0.5" muted playsinline preload="metadata" style="object-position:<?= (int) $m['focus_x'] ?>% 50%"></video><?php endif; ?>
              <span class="mu-badge"><?= $isVideo ? 'Video' . ($m['duration_seconds'] !== null ? ' ' . moment_clock((int) $m['duration_seconds']) : '') : ($m['media_type'] === 'SLIDESHOW' ? count($m['slides'] ?? []) . ' photos' : 'Photo') ?><?= !empty($m['sound_id']) && ($m['sound_status'] ?? '') === 'ACTIVE' ? ' &#9835;' : '' ?></span>
              <span class="mu-meta"><?= htmlspecialchars(moment_compact((int) $m['view_count'])) ?> views &middot; <?= (int) $m['reaction_count'] ?> &#10084;&#65039; &middot; <?= (int) $m['comment_count'] ?> &#128172;</span>
            </div>
            <div class="mu-cap"><?= $m['caption'] ? htmlspecialchars($m['caption']) : '<span class="muted">No caption</span>' ?></div>
            <details>
              <summary>Edit</summary>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="moment_update"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <textarea name="caption" rows="3" maxlength="<?= MOMENT_CAPTION_MAX ?>" aria-label="Caption" style="width:100%"><?= htmlspecialchars((string) $m['caption']) ?></textarea>
                <label class="social-check">Framing <input type="range" name="focus_x" min="0" max="100" value="<?= (int) $m['focus_x'] ?>" style="flex:1"></label>
                <label class="social-check"><input type="radio" name="fit" value="FILL"<?= $m['fit_mode'] === 'FILL' ? ' checked' : '' ?>> Fill</label>
                <label class="social-check"><input type="radio" name="fit" value="FIT"<?= $m['fit_mode'] === 'FIT' ? ' checked' : '' ?>> Fit with blurred sides</label>
                <label class="social-check"><input type="checkbox" name="comments_enabled" value="1"<?= (int) $m['comments_enabled'] === 1 ? ' checked' : '' ?>> Allow comments</label>
                <?php if ($soundsReady): ?>
                  <input type="hidden" name="sound_start" value="<?= htmlspecialchars((string) ($m['sound_start'] ?? 0)) ?>"><input type="hidden" name="sound_prev" value="<?= (int) ($m['sound_id'] ?? 0) ?>">
                  <label class="social-check">Sound
                    <select name="sound_id" aria-label="Sound" style="flex:1; min-width:0">
                      <option value="0">No sound</option>
                      <?php foreach ([['Library', $libSounds], ['My sounds', $mySounds]] as [$grp, $list]): if (!$list) { continue; } ?>
                        <optgroup label="<?= $grp ?>"><?php foreach ($list as $snd): ?><option value="<?= (int) $snd['id'] ?>"<?= (int) ($m['sound_id'] ?? 0) === $snd['id'] ? ' selected' : '' ?>><?= htmlspecialchars($snd['title'] . ($snd['artist'] !== '' ? ' - ' . $snd['artist'] : '')) ?></option><?php endforeach; ?></optgroup>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="social-check">Music volume <input type="range" name="sound_mix" min="0" max="100" value="<?= (int) ($m['sound_mix'] ?? 70) ?>" style="flex:1"></label>
                  <?php if ($m['media_type'] === 'SLIDESHOW'): ?>
                  <label class="social-check">Photo changes every
                    <select name="slide_beats" aria-label="Beats per photo"><?php foreach (MOMENT_SLIDE_BEATS as $bt): ?><option value="<?= $bt ?>"<?= (int) ($m['slide_beats'] ?? 2) === $bt ? ' selected' : '' ?>><?= $bt === 1 ? 'beat' : $bt . ' beats' ?></option><?php endforeach; ?></select>
                  </label>
                  <label class="social-check">Transition
                    <select name="slide_fx" aria-label="Transition"><?php foreach (MOMENT_SLIDE_FX as $fx): ?><option value="<?= $fx ?>"<?= strtoupper((string) ($m['slide_fx'] ?? 'ZOOM')) === $fx ? ' selected' : '' ?>><?= ucfirst(strtolower($fx)) ?></option><?php endforeach; ?></select>
                  </label>
                  <?php endif; ?>
                <?php endif; ?>
                <button class="social-btn plain" type="submit">Save</button>
              </form>
            </details>
            <div class="social-actions">
              <form method="post" data-confirm="Delete this moment, its comments and reactions for good?" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="moment_delete"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button type="submit" class="social-btn danger">Delete</button>
              </form>
              <a class="social-btn plain" href="/event.php?slug=<?= urlencode($event['slug']) ?>&moment=<?= (int) $m['id'] ?>" target="_blank" rel="noopener">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <script src="/assets/js/beat.js?v=<?= @filemtime(__DIR__ . '/assets/js/beat.js') ?: time() ?>"></script>
      <script src="/assets/js/slideshow.js?v=<?= @filemtime(__DIR__ . '/assets/js/slideshow.js') ?: time() ?>"></script>
      <script src="/assets/js/org-moments.js?v=<?= @filemtime(__DIR__ . '/assets/js/org-moments.js') ?: time() ?>"></script>
      <?php endif; ?>
    </div>

    <?php endif; ?>

    <!-- reports -->
    <div class="admin-card" id="reports">
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px">
        <h3 style="flex:1; margin:0">Reports on this event</h3>
        <?php if ($reports): ?><span class="admin-badge admin-badge-danger"><?= count($reports) ?> waiting</span><?php endif; ?>
      </div>
      <p class="muted" style="font-size:0.86rem; margin:0 0 14px">You can hide or delete content on your own event. Platform admins can overrule any decision.</p>
      <?php if (!$reports): ?>
        <div class="muted" style="padding:18px 0; text-align:center">No reports. All quiet.</div>
      <?php endif; ?>
      <?php foreach ($reports as $r): $isPost = $r['content_type'] === 'POST'; ?>
        <div class="social-report">
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
            <b><?= $isPost ? 'Post' : 'Comment' ?> by <?= htmlspecialchars($r['author_name']) ?></b>
            <?php foreach ($r['reasons'] as $reason): ?><span class="admin-badge admin-badge-muted"><?= htmlspecialchars(REPORT_REASONS[$reason] ?? $reason) ?></span><?php endforeach; ?>
            <span class="muted" style="font-size:0.82rem">reported by <?= (int) $r['report_count'] ?> <?= (int) $r['report_count'] === 1 ? 'person' : 'people' ?> &middot; <?= htmlspecialchars(date('j M, H:i', strtotime($r['last_reported']))) ?></span>
          </div>
          <div class="social-quote"><?= $r['snippet'] !== '' ? nl2br(htmlspecialchars($r['snippet'])) : '<span class="muted">(photo only)</span>' ?></div>
          <?php if ($canManage): ?>
          <div class="social-actions">
            <?php foreach ([['hide', 'Hide', ''], ['delete', 'Delete', 'danger'], ['dismiss', 'Keep it', 'plain']] as [$act, $label, $kind]): ?>
              <form method="post"<?= $act === 'delete' ? ' data-confirm="Delete this ' . ($isPost ? 'post' : 'comment') . ' for good?" data-danger' : '' ?>>
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="type" value="<?= $r['content_type'] ?>"><input type="hidden" name="id" value="<?= (int) $r['content_id'] ?>">
                <button type="submit" class="social-btn <?= $kind ?>"><?= $label ?></button>
              </form>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($hidden): ?>
        <div class="social-hidden-head">Hidden by you or an admin</div>
        <?php foreach ($hidden as $h): ?>
          <div class="social-hidden">
            <div style="flex:1; min-width:0"><b><?= $h['content_type'] === 'POST' ? 'Post' : 'Comment' ?> by <?= htmlspecialchars($h['author_name']) ?></b><div class="muted" style="font-size:0.84rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= htmlspecialchars($h['body'] !== '' ? $h['body'] : '(photo only)') ?></div></div>
            <?php if ($canManage): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="type" value="<?= $h['content_type'] ?>"><input type="hidden" name="id" value="<?= (int) $h['content_id'] ?>"><button type="submit" class="social-btn plain">Restore</button></form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- recent posts -->
    <div class="admin-card">
      <h3 style="margin-bottom:12px">Recent posts</h3>
      <?php if (!$posts): ?>
        <div class="muted" style="padding:14px 0; text-align:center">Nothing posted yet. Your first announcement will show here and on the event page.</div>
      <?php endif; ?>
      <?php foreach ($posts as $p): $official = $p['post_type'] === 'ORGANIZER_UPDATE'; ?>
        <div class="social-post">
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
            <b><?= $official ? 'Official update' : htmlspecialchars(community_display_name($p['author_name'])) ?></b>
            <?php if ($official): ?><span class="admin-badge admin-badge-success">Official</span><?php endif; ?>
            <?php if ((int) $p['is_pinned'] === 1): ?><span class="admin-badge admin-badge-muted">Pinned</span><?php endif; ?>
            <?php if (!$official && (int) $p['is_verified_attendee'] === 1): ?><span class="admin-badge admin-badge-muted">Verified attendee</span><?php endif; ?>
            <span class="muted" style="font-size:0.82rem"><?= htmlspecialchars(date('j M, H:i', strtotime($p['created_at']))) ?> &middot; <?= (int) $p['reaction_count'] ?> reactions &middot; <?= (int) $p['comment_count'] ?> comments</span>
          </div>
          <?php if ($p['image_path']): ?><img class="social-thumb" src="<?= htmlspecialchars($p['image_path']) ?>" alt="Photo attached to this post" loading="lazy"><?php endif; ?>
          <?php if ($p['body']): ?><div class="social-quote"><?= nl2br(htmlspecialchars(mb_substr($p['body'], 0, 400))) ?></div><?php endif; ?>
          <?php if ($canManage): ?>
          <div class="social-actions">
            <?php
              $buttons = [];
              if ($official) {
                  $buttons[] = [(int) $p['is_pinned'] === 1 ? 'unpin' : 'pin', (int) $p['is_pinned'] === 1 ? 'Unpin' : 'Pin', 'plain', false];
              }
              $buttons[] = [(int) $p['comments_enabled'] === 1 ? 'comments_off' : 'comments_on', (int) $p['comments_enabled'] === 1 ? 'Turn comments off' : 'Turn comments on', 'plain', false];
              $buttons[] = ['hide', 'Hide', '', false];
              $buttons[] = ['delete', 'Delete', 'danger', true];
            ?>
            <?php foreach ($buttons as [$act, $label, $kind, $confirm]): ?>
              <form method="post"<?= $confirm ? ' data-confirm="Delete this post for good?" data-danger' : '' ?>>
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="type" value="POST"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="social-btn <?= $kind ?>"><?= $label ?></button>
              </form>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- social links -->
  <div style="display:flex; flex-direction:column; gap:20px; min-width:0">
    <div class="admin-card">
      <h3 style="margin-bottom:4px">Your social media</h3>
      <p class="muted" style="font-size:0.86rem; margin:0 0 14px">All optional. Only links you switch on appear on your events and profile. Only <b>https://</b> links are accepted.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_links"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <?php foreach (SOCIAL_PLATFORMS as $key => $meta): $row = $linksByPlatform[$key] ?? null; ?>
          <div class="social-link-row">
            <span class="social-link-ic" aria-hidden="true"><?= htmlspecialchars($meta['short']) ?></span>
            <label class="sr-only" for="link_<?= $key ?>"><?= htmlspecialchars($meta['label']) ?> link</label>
            <input id="link_<?= $key ?>" type="text" name="link_url[<?= $key ?>]" value="<?= htmlspecialchars($row['url'] ?? '') ?>" placeholder="<?= $key === 'WHATSAPP' ? 'Phone number or wa.me link' : 'Paste your ' . htmlspecialchars($meta['label']) . ' link' ?>" autocomplete="off" <?= $canManage ? '' : 'disabled' ?>>
            <label class="social-switch" title="Show on my events"><input type="checkbox" name="link_on[<?= $key ?>]" value="1"<?= !$row || $row['is_enabled'] ? ' checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>><span></span><span class="sr-only">Show <?= htmlspecialchars($meta['label']) ?> on my events</span></label>
          </div>
        <?php endforeach; ?>
        <?php if ($canManage): ?><div style="display:flex; justify-content:flex-end; margin-top:14px"><button class="btn btn-purple" type="submit">Save links</button></div><?php endif; ?>
      </form>
    </div>

    <div class="social-preview">
      <div class="social-preview-label">This is how attendees will see it</div>
      <div style="font-weight:800; margin-top:10px">Follow them elsewhere</div>
      <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap">
        <?php $shown = array_filter($links, static fn ($l) => $l['is_enabled']); ?>
        <?php foreach ($shown as $l): ?>
          <span class="social-chip" title="<?= htmlspecialchars($l['label']) ?>"><?= htmlspecialchars($l['short']) ?></span>
        <?php endforeach; ?>
        <?php if (!$shown): ?><span style="opacity:.7; font-size:0.86rem">No links switched on yet.</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php render_organizer_foot(); ?>
