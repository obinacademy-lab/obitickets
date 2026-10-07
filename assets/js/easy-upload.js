/* Easy upload for Moments: pick (or drop) a video or photos and the page does the rest. It shows the picture
   straight away, crops photos to 9:16, shrinks or trims a video that is too big or too long (in the browser,
   no server work), and uploads in the background as a draft while the organizer writes a caption. One Post
   button finishes it. The server re-checks everything (api/moment-upload.php, api/moment-finish.php). */
(function () {
  'use strict';
  var box = document.getElementById('eu');
  if (!box) return;

  function attr(n) { return box.getAttribute('data-' + n); }
  var cfg = {
    upload: attr('upload'), finish: attr('finish'), eventId: attr('event-id'), csrf: attr('csrf'),
    maxVideo: parseInt(attr('max-video'), 10), maxSeconds: parseInt(attr('max-seconds'), 10) || 90, maxSlides: parseInt(attr('max-slides'), 10) || 10,
    draftReady: attr('draft-ready') === '1', canBuy: attr('can-buy') === '1', eventUrl: attr('event-url'), when: attr('when') || ''
  };
  var sounds = [], drafts = [];
  try { sounds = JSON.parse(attr('sounds') || '[]'); } catch (e) { /* none */ }
  try { drafts = JSON.parse(attr('drafts') || '[]'); } catch (e) { /* none */ }

  function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }
  function mb(n) { return (n / 1048576).toFixed(n > 10485760 ? 0 : 1) + ' MB'; }
  var canShrink = !!(window.MediaRecorder && HTMLCanvasElement.prototype.captureStream);

  /* ---------- entry points: the button, the whole page as a drop target, the round + on phones ---------- */
  var fileInput = document.getElementById('euFile');
  fileInput.addEventListener('change', function () { var f = Array.prototype.slice.call(fileInput.files || []); fileInput.value = ''; if (f.length) start(f); });
  var fab = document.getElementById('euFab');
  if (fab) fab.addEventListener('click', function () { fileInput.click(); });

  var overlay = el('div', 'eu-overlay', '<div><b>Drop to upload</b><span>A video, or photos for a slideshow</span></div>');
  overlay.hidden = true; document.body.appendChild(overlay);
  var dragDepth = 0;
  function hasFiles(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') > -1; }
  document.addEventListener('dragenter', function (e) { if (!hasFiles(e)) return; dragDepth++; overlay.hidden = false; });
  document.addEventListener('dragleave', function (e) { if (!hasFiles(e)) return; dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) overlay.hidden = true; });
  document.addEventListener('dragover', function (e) { if (hasFiles(e)) e.preventDefault(); });
  document.addEventListener('drop', function (e) {
    if (!hasFiles(e)) return;
    e.preventDefault(); dragDepth = 0; overlay.hidden = true;
    var f = Array.prototype.slice.call(e.dataTransfer.files || []);
    if (f.length) start(f);
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-eu-draft]'), function (b) {
    b.addEventListener('click', function () {
      var d = drafts.filter(function (x) { return String(x.id) === b.getAttribute('data-eu-draft'); })[0];
      if (d) openSheet({ resume: d });
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-eu-discard]'), function (b) {
    b.addEventListener('click', function () {
      if (!window.confirm('Discard this draft?')) return;
      post(cfg.finish, { action: 'discard', id: parseInt(b.getAttribute('data-eu-discard'), 10) }).then(function () { var row = b.closest('.eu-draft'); if (row) row.remove(); });
    });
  });
  var adv = document.getElementById('euAdvanced');

  function post(url, body) {
    body.csrf_token = cfg.csrf;
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (d) { d._status = r.status; return d; }).catch(function () { return { error: 'Something went wrong. Please try again.' }; }); })
      .catch(function () { return { error: 'Check your connection and try again.' }; });
  }

  /* ---------- what was chosen ---------- */
  function start(files) {
    var videos = files.filter(function (f) { return /^video\//.test(f.type) || /\.(mp4|webm|mov|m4v)$/i.test(f.name); });
    var photos = files.filter(function (f) { return /^image\//.test(f.type) && !/heic|heif/i.test(f.type + f.name); });
    var heic = files.some(function (f) { return /heic|heif/i.test(f.type + f.name); });
    if (!videos.length && !photos.length) {
      openSheet({ error: heic ? "iPhone HEIC photos can't be used here yet. In your phone's camera settings choose Most Compatible, or share the photo as a JPG." : 'Please choose a video, or JPG, PNG or WebP photos.' });
      return;
    }
    var notes = [];
    if (videos.length) {
      if (videos.length > 1 || photos.length) notes.push('Only the first video is used. Post photos separately.');
      openSheet({ kind: 'video', file: videos[0], notes: notes });
      return;
    }
    if (photos.length > cfg.maxSlides) { notes.push('A slideshow can have up to ' + cfg.maxSlides + ' photos, so the first ' + cfg.maxSlides + ' are used.'); photos = photos.slice(0, cfg.maxSlides); }
    openSheet({ kind: photos.length > 1 ? 'slides' : 'photo', files: photos, notes: notes });
  }

  /* ---------- the sheet ---------- */
  var sheetEl = null, S = null, sample = null;

  function closeSheet() {
    if (S) { S.dead = true; if (S.xhr) try { S.xhr.abort(); } catch (e) { /* ignore */ } if (S.shrinker) S.shrinker.cancel(); if (S.cycle) clearInterval(S.cycle); (S.urls || []).forEach(function (u) { URL.revokeObjectURL(u); }); }
    if (sample) { sample.pause(); sample = null; }
    if (sheetEl) { sheetEl.remove(); sheetEl = null; }
    document.body.classList.remove('eu-open');
    S = null;
  }

  function openSheet(opt) {
    closeSheet();
    S = { kind: opt.kind || (opt.resume && opt.resume.type === 'slideshow' ? 'slides' : opt.resume && opt.resume.type === 'image' ? 'photo' : 'video'), buy: cfg.canBuy, comments: true, soundId: null,
          prep: 0, up: 0, ready: false, draftId: opt.resume ? opt.resume.id : null, urls: [], dead: false, caption: '', error: null, posting: false };
    document.body.classList.add('eu-open');
    sheetEl = el('div', 'eu-back');
    sheetEl.setAttribute('role', 'dialog'); sheetEl.setAttribute('aria-modal', 'true'); sheetEl.setAttribute('aria-label', 'Post a moment');
    sheetEl.innerHTML =
      '<div class="eu-sheet">' +
        '<button type="button" class="eu-x" aria-label="Close">&times;</button>' +
        '<div class="eu-pvcol"><div class="eu-pv"><div class="eu-pvmedia"></div><div class="eu-tags"><span class="eu-tag">9:16</span><span class="eu-tag eu-tag-n" hidden></span><span class="eu-tag eu-tag-m" hidden></span></div><div class="eu-pcap"></div><div class="eu-ptix" hidden><b>Get tickets</b><em>Buy</em></div></div></div>' +
        '<div class="eu-form">' +
          '<h3 class="eu-h"></h3><p class="eu-sub"></p>' +
          '<div class="eu-notice" hidden></div>' +
          '<div class="eu-status"></div>' +
          '<div class="eu-fld"><label for="euCap">Caption <span>(optional)</span></label><textarea id="euCap" maxlength="300" placeholder="Say something about it"></textarea><div class="eu-chips"></div></div>' +
          '<div class="eu-row" id="euBuyRow" hidden><div><b>Show a Get tickets button</b><span class="s">People can buy while they watch</span></div><button class="eu-sw" id="euBuy" type="button" role="switch" aria-checked="true" aria-label="Show a Get tickets button"></button></div>' +
          '<div class="eu-row eu-musicrow" id="euMusicRow" hidden><b>Add music</b><span class="s">Optional. Tap one to hear a few seconds.</span><div class="eu-music"></div></div>' +
          '<button class="eu-more" type="button" aria-expanded="false">More options <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>' +
          '<div class="eu-opts"><div class="eu-row"><div><b>Allow comments</b></div><button class="eu-sw" id="euCom" type="button" role="switch" aria-checked="true" aria-label="Allow comments"></button></div>' +
            (adv ? '<div class="eu-row"><div><b>Need more control?</b><span class="s">Cropping, your own sounds, a cover frame</span></div><button class="eu-btn sm" type="button" id="euAdvBtn">Open advanced editor</button></div>' : '') + '</div>' +
          '<div class="eu-actions"><button class="eu-btn" type="button" id="euLater">Save for later</button><span class="grow"></span><button class="eu-btn red big" type="button" id="euPost" disabled>Post</button></div>' +
          '<div class="eu-hint" role="status"></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(sheetEl);
    var $ = function (sel) { return sheetEl.querySelector(sel); };

    $('.eu-x').addEventListener('click', tryClose);
    sheetEl.addEventListener('keydown', function (e) { if (e.key === 'Escape') tryClose(); });
    $('.eu-more').addEventListener('click', function () { var o = this.getAttribute('aria-expanded') !== 'true'; this.setAttribute('aria-expanded', o ? 'true' : 'false'); $('.eu-opts').classList.toggle('open', o); });
    $('#euCom').addEventListener('click', function () { S.comments = !S.comments; this.setAttribute('aria-checked', S.comments ? 'true' : 'false'); });
    if (cfg.canBuy) { $('#euBuyRow').hidden = false; $('#euBuy').addEventListener('click', function () { S.buy = !S.buy; this.setAttribute('aria-checked', S.buy ? 'true' : 'false'); paintPreview(); }); }
    if (adv && $('#euAdvBtn')) $('#euAdvBtn').addEventListener('click', function () { closeSheet(); adv.open = true; adv.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    var cap = $('#euCap');
    cap.addEventListener('input', function () { S.caption = cap.value; paintPreview(); });
    var chips = $('.eu-chips');
    ['Tickets on sale now', 'Tag your friends', 'See you there'].concat(cfg.when ? ['Happening ' + cfg.when] : []).forEach(function (t) {
      var b = el('button'); b.type = 'button'; b.textContent = t;
      b.addEventListener('click', function () { cap.value = (cap.value ? cap.value.replace(/\s+$/, '') + ' ' : '') + t; S.caption = cap.value; cap.focus(); paintPreview(); });
      chips.appendChild(b);
    });
    if (sounds.length) {
      $('#euMusicRow').hidden = false;
      var mu = $('.eu-music');
      var none = el('button', null, 'No music'); none.type = 'button'; none.setAttribute('aria-pressed', 'true'); mu.appendChild(none);
      none.addEventListener('click', function () { pickSound(null, none); });
      sounds.slice(0, 8).forEach(function (s) {
        var b = el('button', null, '<i></i>'); b.type = 'button'; b.setAttribute('aria-pressed', 'false');
        b.appendChild(document.createTextNode(s.title)); mu.appendChild(b);
        b.addEventListener('click', function () { pickSound(s, b); });
      });
    }
    function pickSound(s, btn) {
      Array.prototype.forEach.call(sheetEl.querySelectorAll('.eu-music button'), function (x) { x.setAttribute('aria-pressed', x === btn ? 'true' : 'false'); });
      S.soundId = s ? s.id : null; S.soundTitle = s ? s.title : null;
      if (sample) { sample.pause(); sample = null; }
      if (s) { sample = new Audio(s.src); try { sample.currentTime = s.offset || 0; } catch (e) { /* ignore */ } sample.play().catch(function () {}); setTimeout(function () { if (sample) sample.pause(); }, 6000); }
      paintPreview();
    }
    $('#euLater').addEventListener('click', function () { if (S.draftId) { location.href = location.pathname + location.search.replace(/&?done=[^&]*/, '') + (location.search ? '&' : '?') + 'done=draft#moments-admin'; } });
    $('#euPost').addEventListener('click', onPost);

    function tryClose() {
      if (S && S.finished) { location.reload(); return; }
      if (S && !S.posting && S.draftId && cfg.draftReady) { $('#euLater').click(); return; } // already safe in Drafts, nothing to lose
      if (S && !S.posting && (S.up > 0 || S.prep > 0)) {
        if (!window.confirm('Close and lose this upload?')) return;
      }
      closeSheet();
    }

    function paintPreview() {
      if (!sheetEl) return;
      $('.eu-pcap').textContent = S.caption.trim();
      var pv = $('.eu-pv'); pv.classList.toggle('hasbar', cfg.canBuy && S.buy);
      $('.eu-ptix').hidden = !(cfg.canBuy && S.buy);
      var m = $('.eu-tag-m'); if (S.soundTitle) { m.hidden = false; m.textContent = '♫ ' + S.soundTitle; } else m.hidden = true;
      paintStatus();
    }
    S.paint = paintPreview;

    function row(cls, label, pct) { return '<div class="eu-step ' + cls + '"><span class="dot"></span><span class="lbl">' + label + '</span><span class="bar"><i style="width:' + pct + '%"></i></span><span class="pc">' + (cls === 'wait' ? '' : pct + '%') + '</span></div>'; }
    function paintStatus() {
      if (!sheetEl) return;
      var h = '';
      if (S.error) { $('.eu-status').innerHTML = ''; $('.eu-hint').textContent = ''; var n = $('.eu-notice'); n.hidden = false; n.className = 'eu-notice bad'; n.textContent = S.error; $('#euPost').disabled = true; return; }
      if (S.needsPrep) h += row(S.prep >= 100 ? 'done' : 'run', S.kind === 'video' ? 'Shrinking for upload' : 'Cropping photos', Math.round(S.prep));
      if (!S.resumed) h += row(S.up >= 100 ? 'done' : (S.prep >= 100 || !S.needsPrep ? 'run' : 'wait'), cfg.draftReady ? 'Uploading' : 'Getting ready', Math.round(S.up));
      $('.eu-status').innerHTML = h;
      $('#euPost').disabled = !S.ready || S.posting;
      $('#euLater').disabled = !S.ready || !cfg.draftReady || S.posting;
      $('.eu-hint').textContent = S.posting ? 'Posting…' : (S.ready ? 'Ready. Press Post when you are happy.' : 'You can keep writing. Post unlocks when this finishes. Keep this tab open.');
    }

    function showError(msg) { S.error = msg; paintStatus(); }
    function notice(msg) { var n = $('.eu-notice'); n.hidden = false; n.className = 'eu-notice'; n.innerHTML = '<span>&#9888;&#65039;</span><div></div>'; n.lastChild.textContent = msg; }

    /* ---- fill the sheet for what was chosen ---- */
    var pvm = $('.eu-pvmedia');
    if (opt.error) { $('.eu-h').textContent = 'That file can’t be used'; showError(opt.error); $('.eu-form .eu-fld').hidden = true; $('.eu-actions').hidden = true; $('.eu-more').hidden = true; return; }
    $('.eu-h').textContent = S.kind === 'video' ? 'Your video' : S.kind === 'slides' ? 'Your slideshow' : 'Your photo';
    $('.eu-sub').textContent = 'We crop it to fit the phone screen for you.';
    (opt.notes || []).forEach(function (t) { notice(t); });
    paintPreview();

    if (opt.resume) { resumeDraft(opt.resume); return; }
    if (S.kind === 'video') runVideo(opt.file); else runPhotos(opt.files);

    /* ---- resume a draft ---- */
    function resumeDraft(d) {
      S.resumed = true; S.ready = true; S.up = 100; S.needsPrep = false;
      var src = d.type === 'video' ? d.poster : (d.slides && d.slides[0]) || d.src;
      if (src) { var im = el('img'); im.alt = ''; im.src = src; pvm.appendChild(im); }
      else { var v = el('video'); v.src = d.src + '#t=0.5'; v.muted = true; v.playsInline = true; v.preload = 'metadata'; pvm.appendChild(v); }
      if (d.slides && d.slides.length > 1) { var t = $('.eu-tag-n'); t.hidden = false; t.textContent = d.slides.length + ' photos'; }
      if (d.caption) { cap.value = d.caption; S.caption = d.caption; }
      paintPreview();
    }

    /* ---- photos: crop each to 9:16, then upload ---- */
    function cropToBlob(file) {
      return new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(file), img = new Image();
        img.onload = function () {
          try {
            var W = 1080, H = 1920, cv = document.createElement('canvas'); cv.width = W; cv.height = H;
            var sc = Math.max(W / img.naturalWidth, H / img.naturalHeight), dw = img.naturalWidth * sc, dh = img.naturalHeight * sc;
            cv.getContext('2d').drawImage(img, (W - dw) / 2, (H - dh) / 2, dw, dh);
            cv.toBlob(function (b) { URL.revokeObjectURL(url); b ? resolve(b) : reject(new Error('crop')); }, 'image/jpeg', 0.85);
          } catch (e) { URL.revokeObjectURL(url); reject(e); }
        };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('read')); };
        img.src = url;
      });
    }
    function runPhotos(files) {
      S.needsPrep = true; S.prep = 0; paintStatus();
      var blobs = [], chain = Promise.resolve(), failed = 0;
      files.forEach(function (f, i) {
        chain = chain.then(function () {
          if (S.dead) return;
          return cropToBlob(f).then(function (b) { blobs.push(b); var u = URL.createObjectURL(b); S.urls.push(u); S.prep = Math.round((i + 1) / files.length * 100); if (blobs.length === 1) paintFirstPhoto(u); paintStatus(); }, function () { failed++; });
        });
      });
      chain.then(function () {
        if (S.dead) return;
        if (!blobs.length) { showError("Those photos couldn't be read. Try JPG or PNG files."); return; }
        if (failed) notice(failed + ' photo' + (failed > 1 ? 's' : '') + " couldn't be read and " + (failed > 1 ? 'were' : 'was') + ' skipped.');
        S.prep = 100;
        if (blobs.length > 1) { var t = $('.eu-tag-n'); t.hidden = false; t.textContent = blobs.length + ' photos'; startCycle(); }
        S.kind = blobs.length > 1 ? 'slides' : 'photo';
        $('.eu-h').textContent = S.kind === 'slides' ? 'Your slideshow' : 'Your photo';
        sendFiles(blobs.map(function (b, i) { return ['photos[]', b, (i + 1) + '.jpg']; }), null, null);
      });
    }
    function paintFirstPhoto(u) { var im = el('img'); im.alt = ''; im.src = u; pvm.innerHTML = ''; pvm.appendChild(im); }
    function startCycle() {
      var imgs = S.urls.map(function (u, i) { var im = el('img'); im.alt = ''; im.src = u; im.style.opacity = i === 0 ? 1 : 0; return im; });
      pvm.innerHTML = ''; imgs.forEach(function (im) { pvm.appendChild(im); });
      var cur = 0; S.cycle = setInterval(function () { imgs[cur].style.opacity = 0; cur = (cur + 1) % imgs.length; imgs[cur].style.opacity = 1; }, 1600);
    }

    /* ---- video: shrink or trim only when needed, then upload ---- */
    function runVideo(file) {
      var url = URL.createObjectURL(file); S.urls.push(url);
      var v = el('video'); v.muted = true; v.loop = true; v.autoplay = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
      pvm.appendChild(v);
      var settled = false;
      v.addEventListener('error', function () { if (!settled) { settled = true; showError("This video format can't be read by your browser. Try an MP4 (H.264) file."); } });
      v.addEventListener('loadedmetadata', function () {
        if (settled || S.dead) return; settled = true;
        S.duration = v.duration || 0;
        var tooBig = file.size > cfg.maxVideo, tooLong = S.duration > cfg.maxSeconds + 0.5;
        S.needsPrep = tooBig || tooLong;
        if (tooLong) notice('This video is ' + Math.floor(S.duration / 60) + ':' + ('0' + Math.round(S.duration % 60)).slice(-2) + ' and moments can be up to ' + Math.floor(cfg.maxSeconds / 60) + ':' + ('0' + (cfg.maxSeconds % 60)).slice(-2) + '. We use the first ' + cfg.maxSeconds + ' seconds.');
        else if (tooBig) notice('This video is ' + mb(file.size) + '. We shrink it so it uploads fast. It takes about as long as the video.');
        if (S.needsPrep && !canShrink) { showError('This video is ' + mb(file.size) + ' (limit ' + mb(cfg.maxVideo) + ') and this browser can’t shrink it. Use Chrome on a computer or Android phone, or trim it in your gallery first.'); return; }
        paintStatus();
        if (S.needsPrep) {
          S.shrinker = shrinkVideo(url, Math.min(S.duration, cfg.maxSeconds), function (p) { S.prep = Math.round(p * 100); paintStatus(); });
          S.shrinker.promise.then(function (r) {
            if (S.dead) return; S.prep = 100; S.duration = r.duration; paintStatus();
            poster(v, function (pb) { sendFiles([['file', r.blob, 'video.' + (r.mime.indexOf('mp4') > -1 ? 'mp4' : 'webm')]], pb, r.duration); });
          }, function (err) { if (!S.dead) showError(err && err.message ? err.message : "We couldn't shrink this video. Try a shorter one."); });
        } else {
          poster(v, function (pb) { sendFiles([['file', file, file.name]], pb, S.duration); });
        }
      });
    }
    function poster(v, done) {
      var t = Math.min(1, Math.max(0, (v.duration || 1) * 0.2));
      var draw = function () {
        try {
          var cv = document.createElement('canvas'); cv.width = 540; cv.height = 960;
          var sc = Math.max(540 / v.videoWidth, 960 / v.videoHeight), dw = v.videoWidth * sc, dh = v.videoHeight * sc;
          cv.getContext('2d').drawImage(v, (540 - dw) / 2, (960 - dh) / 2, dw, dh);
          cv.toBlob(function (b) { done(b); }, 'image/jpeg', 0.82);
        } catch (e) { done(null); }
      };
      var called = false, once = function () { if (called) return; called = true; draw(); };
      v.addEventListener('seeked', once, { once: true });
      try { v.currentTime = t; } catch (e) { once(); }
      setTimeout(once, 1500);
    }

    /* ---- upload (as a draft when the server supports it) ---- */
    function sendFiles(parts, posterBlob, duration) {
      var fd = new FormData();
      fd.append('csrf_token', cfg.csrf); fd.append('event_id', cfg.eventId);
      if (cfg.draftReady) fd.append('draft', '1');
      if (duration != null) fd.append('duration', String(Math.round(duration)));
      parts.forEach(function (p) { fd.append(p[0], p[1], p[2]); });
      if (posterBlob) fd.append('poster', posterBlob, 'cover.jpg');
      S.pending = { parts: parts, poster: posterBlob, duration: duration };
      if (!cfg.draftReady) { S.ready = true; S.up = 100; paintStatus(); return; } // without drafts the upload happens when Post is pressed
      var xhr = new XMLHttpRequest(); S.xhr = xhr;
      xhr.open('POST', cfg.upload); xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) { if (e.lengthComputable && !S.dead) { S.up = Math.round(e.loaded / e.total * 100); paintStatus(); } };
      xhr.onload = function () {
        if (S.dead) return;
        var res = null; try { res = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
        if (xhr.status >= 200 && xhr.status < 300 && res && res.ok) { S.draftId = res.id; S.up = 100; S.ready = true; paintStatus(); return; }
        showError((res && res.error) || (xhr.status === 413 ? 'That is too large for this server. Try a shorter video or fewer photos.' : 'The upload failed. Please try again.'));
      };
      xhr.onerror = function () { if (!S.dead) showError('Check your connection and try again.'); };
      xhr.send(fd);
    }

    /* ---- Post ---- */
    function onPost() {
      if (!S.ready || S.posting) return;
      S.posting = true; paintStatus();
      var sound = S.soundId ? sounds.filter(function (s) { return s.id === S.soundId; })[0] : null;
      var common = { caption: S.caption, comments: S.comments, buy_bar: cfg.canBuy ? S.buy : false };
      if (sound) { common.sound_id = sound.id; common.sound_start = sound.offset || 0; common.sound_mix = S.kind === 'video' ? 70 : 100; common.slide_beats = 2; common.slide_fx = 'ZOOM'; }
      if (cfg.draftReady && S.draftId) {
        var body = { action: 'publish', id: S.draftId }; for (var k in common) body[k] = common[k];
        post(cfg.finish, body).then(done);
      } else if (S.pending) {
        // no drafts available: upload now with everything attached
        var fd = new FormData();
        fd.append('csrf_token', cfg.csrf); fd.append('event_id', cfg.eventId); fd.append('caption', S.caption);
        if (S.pending.duration != null) fd.append('duration', String(Math.round(S.pending.duration)));
        S.pending.parts.forEach(function (p) { fd.append(p[0], p[1], p[2]); });
        if (S.pending.poster) fd.append('poster', S.pending.poster, 'cover.jpg');
        fd.append('buy_bar', common.buy_bar ? '1' : '0');
        if (sound) { fd.append('sound_id', String(sound.id)); fd.append('sound_start', String(common.sound_start)); fd.append('sound_mix', String(common.sound_mix)); fd.append('slide_beats', '2'); fd.append('slide_fx', 'ZOOM'); }
        var xhr = new XMLHttpRequest(); xhr.open('POST', cfg.upload); xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = function (e) { if (e.lengthComputable) $('.eu-hint').textContent = 'Uploading… ' + Math.round(e.loaded / e.total * 100) + '%'; };
        xhr.onload = function () { var res = null; try { res = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ } res = res || { error: 'Something went wrong. Please try again.' }; done(res); };
        xhr.onerror = function () { done({ error: 'Check your connection and try again.' }); };
        xhr.send(fd);
      }
      function done(res) {
        if (!res || !res.ok) { S.posting = false; var n = $('.eu-notice'); n.hidden = false; n.className = 'eu-notice bad'; n.textContent = (res && res.error) || 'Something went wrong. Please try again.'; paintStatus(); return; }
        showDone(res.id, sound);
      }
    }

    function showDone(id, sound) {
      S.finished = true;
      var url = cfg.eventUrl + (cfg.eventUrl.indexOf('?') > -1 ? '&' : '?') + 'moment=' + id;
      var bar = cfg.canBuy && S.buy;
      sheetEl.querySelector('.eu-sheet').innerHTML = '<button type="button" class="eu-x" aria-label="Close">&times;</button><div class="eu-done"><div class="ok">&#10003;</div><h3>Posted</h3><p></p><div class="eu-link"><span></span><button class="eu-btn sm" type="button" id="euCopy">Copy link</button></div><div class="eu-acts"><a class="eu-btn red" target="_blank" rel="noopener">View it</a><a class="eu-btn" target="_blank" rel="noopener" id="euWa">Share on WhatsApp</a><button class="eu-btn" type="button" id="euAgain">Upload another</button></div></div>';
      sheetEl.querySelector('.eu-done p').textContent = 'It is live on your event page' + (bar ? ' with a Get tickets button' : '') + (sound ? ' and plays with “' + sound.title + '”' : '') + '. Your followers are being told.';
      sheetEl.querySelector('.eu-link span').textContent = url.replace(/^https?:\/\//, '');
      sheetEl.querySelector('.eu-acts a.red').href = url;
      sheetEl.querySelector('#euWa').href = 'https://wa.me/?text=' + encodeURIComponent((S.caption || 'Watch this') + ' ' + url);
      sheetEl.querySelector('#euCopy').addEventListener('click', function () { var b = this; if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { b.textContent = 'Copied'; }); else b.textContent = url; });
      sheetEl.querySelector('#euAgain').addEventListener('click', function () { location.href = location.pathname + location.search.replace(/&?done=[^&]*/, '') + '#moments-admin'; location.reload(); });
      sheetEl.querySelector('.eu-x').addEventListener('click', function () { location.reload(); });
    }
  }

  /* ---------- shrink / trim a video in the browser (720 x 1280, real time, keeps the sound) ---------- */
  function shrinkVideo(url, seconds, onProgress) {
    var cancelled = false, rec = null, v = null, ac = null, timer = null;
    var handle = { cancel: function () { cancelled = true; clearInterval(timer); try { if (rec && rec.state !== 'inactive') rec.stop(); } catch (e) { /* ignore */ } if (v) v.pause(); try { if (ac) ac.close(); } catch (e) { /* ignore */ } } };
    handle.promise = new Promise(function (resolve, reject) {
      v = document.createElement('video'); v.playsInline = true; v.preload = 'auto'; v.src = url;
      v.addEventListener('error', function () { reject(new Error("This video can't be read by your browser.")); });
      v.addEventListener('loadedmetadata', function () {
        var W = 720, H = 1280, cv = document.createElement('canvas'); cv.width = W; cv.height = H;
        var cx = cv.getContext('2d'), stream = cv.captureStream(30);
        try {
          var AC = window.AudioContext || window.webkitAudioContext;
          ac = new AC(); var srcNode = ac.createMediaElementSource(v), dest = ac.createMediaStreamDestination();
          srcNode.connect(dest); // the sound goes into the recording, not the speakers
          dest.stream.getAudioTracks().forEach(function (t) { stream.addTrack(t); });
        } catch (e) { /* silent video if the browser refuses */ }
        var type = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'].filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0] || '';
        var chunks = [];
        try { rec = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: 2500000 } : undefined); } catch (e) { reject(new Error("This browser couldn't start recording.")); return; }
        rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = function () { if (cancelled) return; clearInterval(timer); resolve({ blob: new Blob(chunks, { type: rec.mimeType || type || 'video/webm' }), mime: rec.mimeType || type || 'video/webm', duration: Math.min(seconds, v.duration || seconds) }); };
        var draw = function () {
          var vw = v.videoWidth, vh = v.videoHeight; if (!vw) return;
          var sc = Math.max(W / vw, H / vh), dw = vw * sc, dh = vh * sc;
          cx.drawImage(v, (W - dw) / 2, (H - dh) / 2, dw, dh);
        };
        var finish = function () { if (rec.state !== 'inactive') { v.pause(); setTimeout(function () { if (rec.state !== 'inactive') rec.stop(); }, 150); } };
        rec.start(500);
        v.currentTime = 0;
        var p = v.play(); if (p && p.catch) p.catch(function () { reject(new Error('Your browser would not play this video to shrink it.')); });
        timer = setInterval(function () {
          if (cancelled) return;
          draw();
          onProgress(Math.min(1, v.currentTime / seconds));
          if (v.ended || v.currentTime >= seconds - 0.05) { clearInterval(timer); onProgress(1); finish(); }
        }, 33);
      });
    });
    return { promise: handle.promise, cancel: handle.cancel };
  }
})();
