/* Event Social > Moments: pick a video or photo, see exactly how it will be cropped to 9:16,
   choose what stays in frame and a cover frame, then upload with a progress bar.
   The server re-checks everything (api/moment-upload.php); this only makes the choices easy. */
(function () {
  'use strict';
  var box = document.getElementById('mu');
  if (!box) return;

  var TARGET = 9 / 16;
  var frame = document.getElementById('muFrame');
  var hint = document.getElementById('muHint');
  var guidesBox = document.getElementById('muGuides');
  var fileInput = document.getElementById('muFile');
  var facts = document.getElementById('muFacts');
  var fitRow = document.getElementById('muFit');
  var coverRow = document.getElementById('muCover');
  var coverRange = document.getElementById('muCoverRange');
  var caption = document.getElementById('muCaption');
  var count = document.getElementById('muCount');
  var bar = document.getElementById('muBar');
  var stateEl = document.getElementById('muState');
  var publish = document.getElementById('muPublish');
  var maxVideo = parseInt(box.getAttribute('data-max-video'), 10);
  var maxImage = parseInt(box.getAttribute('data-max-image'), 10);
  var maxSeconds = parseInt(box.getAttribute('data-max-seconds'), 10);

  var S = { file: null, kind: null, url: null, media: null, nw: 0, nh: 0, duration: 0, focus: 50, fit: 'FILL', ok: false };

  function mb(n) { return (n / 1048576).toFixed(n > 10485760 ? 0 : 1) + ' MB'; }
  function clock(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
  function say(msg, isErr) { stateEl.textContent = msg; stateEl.classList.toggle('err', !!isErr); }
  function fact(text, cls) { var s = document.createElement('span'); s.className = 'mu-fact' + (cls ? ' ' + cls : ''); s.textContent = text; facts.appendChild(s); }

  caption.addEventListener('input', function () { count.textContent = caption.value.length; });
  guidesBox.addEventListener('change', function () { var g = frame.querySelector('.mu-guides'); if (g) g.classList.toggle('on', guidesBox.checked); });

  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    if (f) choose(f);
  });

  function reset() {
    if (S.url) URL.revokeObjectURL(S.url);
    S = { file: null, kind: null, url: null, media: null, nw: 0, nh: 0, duration: 0, focus: 50, fit: 'FILL', ok: false };
    facts.innerHTML = ''; fitRow.hidden = true; coverRow.hidden = true; hint.hidden = true; publish.disabled = true;
  }

  function choose(file) {
    reset();
    var isVideo = /^video\/(mp4|webm)$/.test(file.type);
    var isImage = /^image\/(jpeg|png|webp)$/.test(file.type);
    if (!isVideo && !isImage) { say('Please choose an MP4 or WebM video, or a JPG, PNG or WebP photo.', true); frame.className = 'mu-frame is-empty'; frame.textContent = 'Choose a video or photo to see how it will look'; return; }
    S.file = file; S.kind = isVideo ? 'video' : 'image'; S.url = URL.createObjectURL(file);
    fact(mb(file.size));
    if (isVideo && file.size > maxVideo) { fact('Over the ' + mb(maxVideo) + ' limit', 'bad'); say('That video is ' + mb(file.size) + '. This server accepts up to ' + mb(maxVideo) + '. Try a shorter or lower-quality export.', true); }
    else if (isImage && file.size > maxImage) { fact('Over the 5 MB limit', 'bad'); say('Photos must be under 5 MB.', true); }

    frame.className = 'mu-frame';
    frame.innerHTML = '<div class="mu-bg"></div>';
    var m;
    if (isVideo) {
      m = document.createElement('video');
      m.muted = true; m.loop = true; m.autoplay = true; m.playsInline = true; m.preload = 'auto';
      m.addEventListener('loadedmetadata', function () { S.nw = m.videoWidth; S.nh = m.videoHeight; S.duration = m.duration || 0; ready(); });
      m.addEventListener('loadeddata', function () { var bg = frame.querySelector('.mu-bg'); try { var cv = document.createElement('canvas'); cv.width = 54; cv.height = 96; cv.getContext('2d').drawImage(m, 0, 0, 54, 96); bg.style.backgroundImage = 'url(' + cv.toDataURL('image/jpeg', 0.6) + ')'; } catch (e) { /* plain dark backdrop */ } });
    } else {
      m = document.createElement('img'); m.alt = '';
      m.addEventListener('load', function () { S.nw = m.naturalWidth; S.nh = m.naturalHeight; frame.querySelector('.mu-bg').style.backgroundImage = 'url(' + S.url + ')'; ready(); });
    }
    m.src = S.url;
    m.style.objectPosition = '50% 50%';
    frame.appendChild(m);
    S.media = m;
    var g = document.createElement('div');
    g.className = 'mu-guides' + (guidesBox.checked ? ' on' : '');
    g.innerHTML = '<div class="g-top">Sound &middot; title</div><div class="g-rl">Buttons</div><div class="g-cap">Caption</div>';
    frame.appendChild(g);
  }

  function ready() {
    var ratio = S.nw / S.nh, bad = false;
    facts.innerHTML = '';
    fact(S.nw + ' × ' + S.nh);
    if (S.kind === 'video') fact(clock(S.duration));
    fact(mb(S.file.size), (S.kind === 'video' && S.file.size > maxVideo) || (S.kind === 'image' && S.file.size > maxImage) ? 'bad' : '');
    if (S.kind === 'video' && S.file.size > maxVideo) bad = true;
    if (S.kind === 'image' && S.file.size > maxImage) bad = true;
    if (S.kind === 'video' && S.duration > maxSeconds + 0.5) { fact('Longer than ' + maxSeconds + ' seconds', 'bad'); say('Videos can be up to ' + maxSeconds + ' seconds. This one is ' + clock(S.duration) + '.', true); bad = true; }
    var visible = Math.round(TARGET / ratio * 100);
    if (ratio > TARGET * 1.03) fact('Wider than 9:16: showing ' + Math.min(100, visible) + '% of the width', 'warn');
    else if (ratio < TARGET * 0.97) fact('Taller than 9:16: top and bottom are trimmed', 'warn');
    else fact('Fits 9:16');
    var wide = ratio > TARGET * 1.03;
    fitRow.hidden = !wide;
    hint.hidden = !wide;
    frame.classList.toggle('is-fit', false);
    S.fit = 'FILL';
    Array.prototype.forEach.call(fitRow.querySelectorAll('button'), function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-fit') === 'FILL' ? 'true' : 'false'); });
    if (S.kind === 'video') { coverRow.hidden = false; coverRange.value = 0; }
    S.ok = !bad;
    publish.disabled = bad;
    if (!bad) say('Ready to publish.', false);
  }

  // choose what stays in frame by dragging sideways
  var drag = null;
  frame.addEventListener('pointerdown', function (e) {
    if (!S.media || S.fit === 'FIT' || S.nw / S.nh <= TARGET * 1.03) return;
    drag = { x: e.clientX, f: S.focus };
    frame.setPointerCapture(e.pointerId);
  });
  frame.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var fw = frame.clientWidth, fh = frame.clientHeight, scaledW = fh * (S.nw / S.nh), range = Math.max(1, scaledW - fw);
    S.focus = Math.max(0, Math.min(100, drag.f - (e.clientX - drag.x) / range * 100));
    S.media.style.objectPosition = S.focus.toFixed(1) + '% 50%';
  });
  frame.addEventListener('pointerup', function () { drag = null; });
  frame.addEventListener('pointercancel', function () { drag = null; });

  Array.prototype.forEach.call(fitRow.querySelectorAll('button'), function (b) {
    b.addEventListener('click', function () {
      S.fit = b.getAttribute('data-fit');
      Array.prototype.forEach.call(fitRow.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      frame.classList.toggle('is-fit', S.fit === 'FIT');
      hint.hidden = S.fit === 'FIT';
    });
  });

  coverRange.addEventListener('input', function () {
    if (S.kind !== 'video' || !S.duration) return;
    S.media.pause();
    S.media.currentTime = Math.min(S.duration - 0.05, S.duration * coverRange.value / 100);
  });

  function captureCover() {
    return new Promise(function (resolve) {
      if (S.kind !== 'video') return resolve(null);
      var v = S.media, t = Math.min(Math.max(0, S.duration - 0.05), S.duration * coverRange.value / 100);
      var draw = function () {
        try {
          var cv = document.createElement('canvas'); cv.width = 540; cv.height = 960;
          var cx = cv.getContext('2d');
          cx.fillStyle = '#000'; cx.fillRect(0, 0, 540, 960);
          var s = (S.fit === 'FIT' ? Math.min : Math.max)(540 / S.nw, 960 / S.nh), dw = S.nw * s, dh = S.nh * s;
          var dx = S.fit === 'FIT' ? (540 - dw) / 2 : -(dw - 540) * S.focus / 100, dy = (960 - dh) / 2;
          cx.drawImage(v, dx, dy, dw, dh);
          cv.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.82);
        } catch (e) { resolve(null); }
      };
      if (Math.abs(v.currentTime - t) < 0.05) { draw(); return; }
      v.pause();
      v.addEventListener('seeked', function once() { v.removeEventListener('seeked', once); draw(); });
      v.currentTime = t;
    });
  }

  publish.addEventListener('click', function () {
    if (!S.file || !S.ok) return;
    publish.disabled = true;
    bar.hidden = false; bar.firstChild.style.width = '0%';
    say('Preparing…', false);
    captureCover().then(function (poster) {
      var fd = new FormData();
      fd.append('csrf_token', box.getAttribute('data-csrf'));
      fd.append('event_id', box.getAttribute('data-event-id'));
      fd.append('caption', caption.value);
      fd.append('focus_x', String(Math.round(S.focus)));
      fd.append('fit', S.fit);
      if (S.kind === 'video') fd.append('duration', String(Math.round(S.duration)));
      fd.append('file', S.file, S.file.name);
      if (poster) fd.append('poster', poster, 'cover.jpg');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', box.getAttribute('data-endpoint'));
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var p = Math.round(e.loaded / e.total * 100);
        bar.firstChild.style.width = p + '%';
        say(p < 100 ? 'Uploading… ' + p + '%' : 'Finishing…', false);
      };
      xhr.onload = function () {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
        if (xhr.status >= 200 && xhr.status < 300 && res && res.ok) {
          say('Published. Opening your moments…', false);
          location.href = '/org-social.php?event=' + encodeURIComponent(box.getAttribute('data-event-id')) + '&done=moment#moments-admin';
          return;
        }
        say((res && res.error) || (xhr.status === 413 ? 'That file is too large for this server.' : 'Something went wrong. Please try again.'), true);
        bar.hidden = true; publish.disabled = false;
      };
      xhr.onerror = function () { say('Check your connection and try again.', true); bar.hidden = true; publish.disabled = false; };
      xhr.send(fd);
    });
  });
})();
