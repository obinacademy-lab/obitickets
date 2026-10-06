<?php
require_once __DIR__ . '/includes/bootstrap.php';

// The sound library for Moments: tracks the platform has the rights to use, which organizers can put under
// their photos and videos. Also lists sounds organizers uploaded themselves, so one can be removed if it is
// reported. The beat (BPM and where the first beat falls) is found in this browser when a file is chosen.
$admin = require_admin_permission('events.manage');

$messages = ['added' => 'Track added to the library.', 'removed' => 'Sound removed. Moments that used it now play without sound.'];
$error = null;
$ready = sounds_ready();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_library') {
        $result = create_sound(
            $admin,
            $_FILES['audio'] ?? [],
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['artist'] ?? ''),
            (float) ($_POST['bpm'] ?? 100),
            (float) ($_POST['offset'] ?? 0),
            (int) round((float) ($_POST['duration'] ?? 0)),
            'LIBRARY',
            (string) ($_POST['license_note'] ?? ''),
            true
        );
        if ($result['ok']) {
            log_admin_action((int) $admin['id'], 'sound.add', 'sound', (int) $result['id']);
            header('Location: /admin-sounds.php?done=added');
            exit;
        }
        $error = $result['error'];
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $result = remove_sound($admin, $id);
        if ($result['ok']) {
            log_admin_action((int) $admin['id'], 'sound.remove', 'sound', $id);
            header('Location: /admin-sounds.php?done=removed');
            exit;
        }
        $error = $result['error'];
    }
}

$library = $ready ? get_library_sounds() : [];
$userSounds = [];
if ($ready) {
    $userSounds = db()->query("
        SELECT s.*, u.name AS owner_name,
               (SELECT COUNT(*) FROM event_moments m WHERE m.sound_id = s.id) AS uses
        FROM sounds s LEFT JOIN users u ON u.id = s.owner_user_id
        WHERE s.source = 'USER' AND s.status = 'ACTIVE' ORDER BY s.id DESC LIMIT 60")->fetchAll();
}

$pageTitle = 'Sound library';
render_admin_head('sounds');
?>

<div class="admin-page-head">
  <div><h1>Sound library</h1><p>Tracks organizers can add to their photos and videos. Only add music you have the rights to use.</p></div>
</div>

<?php if (isset($_GET['done'], $messages[$_GET['done']])): ?><span data-flash="<?= htmlspecialchars($messages[$_GET['done']], ENT_QUOTES) ?>" hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$ready): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-tag"/></svg></div>
    <h3>Sounds aren't switched on yet</h3>
    <p>Run <code>migration/018_sounds.sql</code> in phpMyAdmin to switch them on.</p>
  </div>
<?php else: ?>

<div class="admin-grid-2">
  <div style="display:flex; flex-direction:column; gap:20px; min-width:0">
    <div class="admin-card">
      <h3 style="margin-bottom:6px">Library <span class="muted" style="font-weight:600; font-size:0.85rem">(<?= count($library) ?>)</span></h3>
      <?php if (!$library): ?><div class="muted" style="padding:14px 0; text-align:center">No tracks yet. Add the first one on the right.</div><?php endif; ?>
      <?php foreach ($library as $t): ?>
        <div class="social-hidden" style="align-items:center; gap:12px">
          <div style="flex:1; min-width:0">
            <b><?= htmlspecialchars($t['title']) ?></b><?= $t['artist'] !== '' ? ' <span class="muted">&middot; ' . htmlspecialchars($t['artist']) . '</span>' : '' ?>
            <div class="muted" style="font-size:0.82rem"><?= (int) round((float) $t['bpm']) ?> BPM &middot; <?= htmlspecialchars(moment_clock((int) $t['duration_seconds'])) ?><?= $t['license_note'] ? ' &middot; ' . htmlspecialchars($t['license_note']) : '' ?></div>
            <audio controls preload="none" src="<?= htmlspecialchars($t['file_path']) ?>" style="width:100%; margin-top:6px; height:34px"></audio>
          </div>
          <form method="post" data-confirm="Remove this track? Moments using it will play without sound." data-danger>
            <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="social-btn danger">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:6px">Sounds organizers uploaded <span class="muted" style="font-weight:600; font-size:0.85rem">(<?= count($userSounds) ?>)</span></h3>
      <p class="muted" style="font-size:0.86rem; margin:0 0 10px">Organizers confirm they have permission when they upload. Remove a sound if it is reported.</p>
      <?php if (!$userSounds): ?><div class="muted" style="padding:14px 0; text-align:center">None yet.</div><?php endif; ?>
      <?php foreach ($userSounds as $t): ?>
        <div class="social-hidden" style="align-items:center; gap:12px">
          <div style="flex:1; min-width:0">
            <b><?= htmlspecialchars($t['title']) ?></b><?= $t['artist'] !== '' ? ' <span class="muted">&middot; ' . htmlspecialchars($t['artist']) . '</span>' : '' ?>
            <div class="muted" style="font-size:0.82rem">by <?= htmlspecialchars((string) ($t['owner_name'] ?? 'a removed user')) ?> &middot; used in <?= (int) $t['uses'] ?> moment<?= (int) $t['uses'] === 1 ? '' : 's' ?> &middot; <?= htmlspecialchars(date('j M Y', strtotime($t['created_at']))) ?></div>
            <audio controls preload="none" src="<?= htmlspecialchars($t['file_path']) ?>" style="width:100%; margin-top:6px; height:34px"></audio>
          </div>
          <form method="post" data-confirm="Remove this sound? Moments using it will play without sound." data-danger>
            <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="social-btn danger">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="admin-card" style="align-self:start">
    <h3 style="margin-bottom:4px">Add a track</h3>
    <p class="muted" style="font-size:0.86rem; margin:0 0 14px">MP3, M4A, WAV or OGG up to <?= (int) round(SOUND_MAX_BYTES / 1048576) ?> MB. The beat is found automatically when you choose the file.</p>
    <form method="post" enctype="multipart/form-data" id="soundForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_library">
      <input type="hidden" name="bpm" id="sBpm" value="100"><input type="hidden" name="offset" id="sOffset" value="0"><input type="hidden" name="duration" id="sDuration" value="0">
      <div class="admin-form-row"><label for="sFile">Audio file</label><input id="sFile" type="file" name="audio" accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg,audio/aac,.mp3,.m4a,.wav,.ogg" required></div>
      <div class="admin-form-row"><label for="sTitle">Title</label><input id="sTitle" type="text" name="title" maxlength="120" required style="width:100%"></div>
      <div class="admin-form-row"><label for="sArtist">Artist</label><input id="sArtist" type="text" name="artist" maxlength="120" style="width:100%"></div>
      <div class="admin-form-row"><label for="sLicense">Licence note</label><input id="sLicense" type="text" name="license_note" maxlength="255" placeholder="Royalty-free, bought 2026, receipt #..." style="width:100%"></div>
      <div class="muted" id="sResult" style="font-size:0.86rem; margin:6px 0 12px">Choose a file to find its beat.</div>
      <button class="btn btn-purple" type="submit" id="sSubmit" disabled>Add to library</button>
    </form>
  </div>
</div>

<script src="/assets/js/beat.js?v=<?= @filemtime(__DIR__ . '/assets/js/beat.js') ?: time() ?>"></script>
<script>
(function () {
  var f = document.getElementById('sFile'), out = document.getElementById('sResult'), btn = document.getElementById('sSubmit');
  f.addEventListener('change', function () {
    var file = f.files && f.files[0];
    btn.disabled = true;
    if (!file) { out.textContent = 'Choose a file to find its beat.'; return; }
    out.textContent = 'Finding the beat…';
    var t = document.getElementById('sTitle');
    if (!t.value) t.value = file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
    BeatAnalyzer.analyze(file).then(function (r) {
      document.getElementById('sBpm').value = r.bpm;
      document.getElementById('sOffset').value = r.offset;
      document.getElementById('sDuration').value = Math.round(r.duration);
      out.textContent = 'Beat found: ' + r.bpm + ' BPM, ' + Math.floor(r.duration / 60) + ':' + ('0' + Math.round(r.duration % 60)).slice(-2) + ' long.';
      btn.disabled = false;
    }, function (e) { out.textContent = e.message || "That audio file couldn't be read."; });
  });
})();
</script>

<?php endif; ?>

<?php render_admin_foot(); ?>
