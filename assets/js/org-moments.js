/* Event Social > Moments: pick a video, a photo or up to 10 photos for a slideshow, add a sound (library,
   one of your own, or a new upload), see exactly how it will be cropped to 9:16 and hear the photos change
   on the beat, then upload with a progress bar. The server re-checks everything (api/moment-upload.php);
   this only makes the choices easy. Beat finding runs here, in the browser (assets/js/beat.js). */
(function () {
  'use strict';
  var box = document.getElementById('mu');
  if (!box) return;

  var TARGET = 9 / 16;
  var maxVideo = parseInt(box.getAttribute('data-max-video'), 10);
  var maxImage = parseInt(box.getAttribute('data-max-image'), 10);
  var maxSeconds = parseInt(box.getAttribute('data-max-seconds'), 10);
  var maxSlides = parseInt(box.getAttribute('data-max-slides'), 10) || 10;
  var maxSound = parseInt(box.getAttribute('data-max-sound'), 10);
  var soundsOn = box.getAttribute('data-sounds-on') === '1';
  var lists = { library: [], mine: [] };
  try { lists = JSON.parse(box.getAttribute('data-sounds') || '{}'); } catch (e) { /* no sounds */ }

  function $(id) { return document.getElementById(id); }
  var frame = $('muFrame'), hint = $('muHint'), guidesBox = $('muGuides'), ctrls = $('muCtrls');
  var facts = $('muFacts'), fitRow = $('muFit'), coverRow = $('muCover'), coverRange = $('muCoverRange');
  var caption = $('muCaption'), count = $('muCount'), bar = $('muBar'), stateEl = $('muState'), publish = $('muPublish');

  var S = {
    mode: 'single',
    file: null, kind: null, url: null, media: null, nw: 0, nh: 0, duration: 0, focus: 50, fit: 'FILL', fileOk: false,
    photos: [],
    srcMode: 'none', sound: null, start: 0, mix: 70, beats: 2, fx: 'ZOOM',
    previewMuted: false
  };
  var player = null, track = null, playing = false, shared = null;

  function mb(n) { return (n / 1048576).toFixed(n > 10485760 ? 0 : 1) + ' MB'; }
  function clock(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
  function say(msg, isErr) { stateEl.textContent = msg; stateEl.classList.toggle('err', !!isErr); }
  function fact(host, text, cls) { var s = document.createElement('span'); s.className = 'mu-fact' + (cls ? ' ' + cls : ''); s.textContent = text; host.appendChild(s); }
  function segPress(group, attr, value) {
    Array.prototype.forEach.call(group.querySelectorAll('button'), function (b) { b.setAttribute('aria-pressed', b.getAttribute(attr) === String(value) ? 'true' : 'false'); });
  }
  function bpm() { return S.sound ? S.sound.bpm : 0; }

  caption.addEventListener('input', function () { count.textContent = caption.value.length; });
  guidesBox.addEventListener('change', function () { var g = frame.querySelector('.mu-guides'); if (g) g.classList.toggle('on', guidesBox.checked); });

  /* ---------- mode: single file or slideshow ---------- */
  var modes = document.querySelector('.mu-modes');
  Array.prototype.forEach.call(modes.querySelectorAll('button'), function (b) {
    b.addEventListener('click', function () {
      stopPreview();
      S.mode = b.getAttribute('data-mode');
      segPress(modes, 'data-mode', S.mode);
      $('muSingleBox').hidden = S.mode !== 'single';
      $('muSlidesBox').hidden = S.mode !== 'slides';
      if ($('muBeatBox')) $('muBeatBox').hidden = S.mode !== 'slides';
      paintFrame();
      refreshAll();
    });
  });

  /* ---------- single video / photo ---------- */
  $('muFile').addEventListener('change', function () {
    var f = this.files && this.files[0];
    if (f) chooseSingle(f);
  });

  function resetSingle() {
    if (S.url) URL.revokeObjectURL(S.url);
    S.file = null; S.kind = null; S.url = null; S.media = null; S.nw = S.nh = 0; S.duration = 0; S.focus = 50; S.fit = 'FILL'; S.fileOk = false;
    facts.innerHTML = ''; fitRow.hidden = true; coverRow.hidden = true; hint.hidden = true;
  }

  function chooseSingle(file) {
    stopPreview();
    resetSingle();
    var isVideo = /^video\/(mp4|webm)$/.test(file.type);
    var isImage = /^image\/(jpeg|png|webp)$/.test(file.type);
    if (!isVideo && !isImage) { say('Please choose an MP4 or WebM video, or a JPG, PNG or WebP photo.', true); paintFrame(); refreshAll(); return; }
    S.file = file; S.kind = isVideo ? 'video' : 'image'; S.url = URL.createObjectURL(file);
    fact(facts, mb(file.size));
    paintFrame();
    var m = S.media;
    if (isVideo) {
      m.addEventListener('loadedmetadata', function () { S.nw = m.videoWidth; S.nh = m.videoHeight; S.duration = m.duration || 0; singleReady(); });
      m.addEventListener('loadeddata', function () { var bg = frame.querySelector('.mu-bg'); try { var cv = document.createElement('canvas'); cv.width = 54; cv.height = 96; cv.getContext('2d').drawImage(m, 0, 0, 54, 96); bg.style.backgroundImage = 'url(' + cv.toDataURL('image/jpeg', 0.6) + ')'; } catch (e) { /* plain dark backdrop */ } });
    } else {
      m.addEventListener('load', function () { S.nw = m.naturalWidth; S.nh = m.naturalHeight; frame.querySelector('.mu-bg').style.backgroundImage = 'url(' + S.url + ')'; singleReady(); });
    }
    m.src = S.url;
  }

  function singleReady() {
    var ratio = S.nw / S.nh, bad = false;
    facts.innerHTML = '';
    fact(facts, S.nw + ' × ' + S.nh);
    if (S.kind === 'video') fact(facts, clock(S.duration));
    var tooBig = (S.kind === 'video' && S.file.size > maxVideo) || (S.kind === 'image' && S.file.size > maxImage);
    fact(facts, mb(S.file.size), tooBig ? 'bad' : '');
    if (tooBig) { bad = true; say(S.kind === 'video' ? 'That video is ' + mb(S.file.size) + '. This server accepts up to ' + mb(maxVideo) + '. Try a shorter or lower-quality export.' : 'Photos must be under 5 MB.', true); }
    if (S.kind === 'video' && S.duration > maxSeconds + 0.5) { fact(facts, 'Longer than ' + maxSeconds + ' seconds', 'bad'); say('Videos can be up to ' + maxSeconds + ' seconds. This one is ' + clock(S.duration) + '.', true); bad = true; }
    var visible = Math.round(TARGET / ratio * 100);
    if (ratio > TARGET * 1.03) fact(facts, 'Wider than 9:16: showing ' + Math.min(100, visible) + '% of the width', 'warn');
    else if (ratio < TARGET * 0.97) fact(facts, 'Taller than 9:16: top and bottom are trimmed', 'warn');
    else fact(facts, 'Fits 9:16');
    var wide = ratio > TARGET * 1.03;
    fitRow.hidden = !wide; hint.hidden = !wide;
    frame.classList.remove('is-fit');
    S.fit = 'FILL';
    segPress(fitRow, 'data-fit', 'FILL');
    if (S.kind === 'video') { coverRow.hidden = false; coverRange.value = 0; }
    S.fileOk = !bad;
    refreshAll();
    if (!bad) say('Ready to publish.', false);
  }

  /* ---------- slideshow photos ---------- */
  $('muPhotos').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files || []);
    this.value = '';
    addPhotos(files);
  });

  function cropToBlob(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        try {
          var W = 1080, H = 1920, cv = document.createElement('canvas');
          cv.width = W; cv.height = H;
          var scale = Math.max(W / img.naturalWidth, H / img.naturalHeight), dw = img.naturalWidth * scale, dh = img.naturalHeight * scale;
          cv.getContext('2d').drawImage(img, (W - dw) / 2, (H - dh) / 2, dw, dh);
          cv.toBlob(function (b) { URL.revokeObjectURL(url); b ? resolve(b) : reject(new Error('crop')); }, 'image/jpeg', 0.85);
        } catch (e) { URL.revokeObjectURL(url); reject(e); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('read')); };
      img.src = url;
    });
  }

  function addPhotos(files) {
    var room = maxSlides - S.photos.length;
    if (room <= 0) { say('A slideshow can have up to ' + maxSlides + ' photos.', true); return; }
    var ok = files.filter(function (f) { return /^image\/(jpeg|png|webp)$/.test(f.type); });
    if (ok.length < files.length) say('Only JPG, PNG and WebP photos can be used.', true);
    if (ok.length > room) { ok = ok.slice(0, room); say('Only the first ' + room + ' photos fit. A slideshow can have up to ' + maxSlides + '.', true); }
    say('Preparing photos…', false);
    var chain = Promise.resolve();
    ok.forEach(function (f) {
      chain = chain.then(function () { return cropToBlob(f).then(function (b) { S.photos.push({ blob: b, url: URL.createObjectURL(b), name: f.name }); }, function () { say('"' + f.name + '" could not be read.', true); }); });
    });
    chain.then(function () { drawThumbs(); rebuildPlayer(); refreshAll(); if (ok.length) say(S.photos.length > 1 ? 'Ready to publish.' : 'Add one more photo to make a slideshow, or publish it as a single photo.', false); });
  }

  function drawThumbs() {
    var t = $('muThumbs'); t.innerHTML = '';
    S.photos.forEach(function (p, i) {
      var d = document.createElement('div');
      d.className = 'mu-th'; d.draggable = true;
      d.innerHTML = '<img alt=""><button class="x" type="button" aria-label="Remove photo ' + (i + 1) + '">&times;</button><span class="n">' + (i + 1) + '</span>';
      d.firstChild.src = p.url;
      d.querySelector('.x').addEventListener('click', function () { URL.revokeObjectURL(p.url); S.photos.splice(i, 1); drawThumbs(); rebuildPlayer(); refreshAll(); });
      d.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', String(i)); d.classList.add('drag'); });
      d.addEventListener('dragend', function () { d.classList.remove('drag'); });
      d.addEventListener('dragover', function (e) { e.preventDefault(); });
      d.addEventListener('drop', function (e) {
        e.preventDefault();
        var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (isNaN(from) || from === i) return;
        var m = S.photos.splice(from, 1)[0]; S.photos.splice(i, 0, m);
        drawThumbs(); rebuildPlayer();
      });
      t.appendChild(d);
    });
    if (S.photos.length < maxSlides) {
      var add = document.createElement('label'); add.className = 'mu-add'; add.setAttribute('for', 'muPhotos'); add.style.cssText = 'display:flex;align-items:center;justify-content:center;cursor:pointer'; add.textContent = '+'; add.setAttribute('aria-label', 'Add photos');
      t.appendChild(add);
    }
    $('muPcount').textContent = S.photos.length + ' of ' + maxSlides + ' photos' + (S.photos.length === 1 ? ' · add at least 2 for a slideshow' : '');
  }

  /* ---------- the preview frame ---------- */
  function paintFrame() {
    if (player) { player.destroy(); player = null; }
    if (track) { track.destroy(); track = null; }
    frame.classList.remove('is-fit', 'is-empty');
    S.media = null;
    if (S.mode === 'slides') {
      if (!S.photos.length) { frame.className = 'mu-frame is-empty'; frame.textContent = 'Add photos to see the slideshow'; ctrls.hidden = true; return; }
      frame.innerHTML = '<div class="mv-stack"></div><div class="mv-flash"></div><div class="mv-segs"></div>';
      addGuides();
      rebuildPlayer();
    } else {
      if (!S.file) { frame.className = 'mu-frame is-empty'; frame.textContent = 'Choose a video or photo to see how it will look'; ctrls.hidden = true; return; }
      frame.className = 'mu-frame';
      frame.innerHTML = '<div class="mu-bg"></div>';
      var m;
      if (S.kind === 'video') { m = document.createElement('video'); m.muted = true; m.loop = true; m.autoplay = true; m.playsInline = true; m.preload = 'auto'; }
      else { m = document.createElement('img'); m.alt = ''; }
      m.style.objectPosition = S.focus + '% 50%';
      frame.appendChild(m);
      S.media = m;
      if (S.url && S.nw) { m.src = S.url; } // repaint after a mode switch
      addGuides();
    }
    ctrls.hidden = false;
  }
  function addGuides() {
    var g = document.createElement('div');
    g.className = 'mu-guides' + (guidesBox.checked ? ' on' : '');
    g.innerHTML = '<div class="g-top">Sound &middot; title</div><div class="g-rl">Buttons</div><div class="g-cap">Caption</div>';
    frame.appendChild(g);
  }

  function slideUrls() { return S.photos.map(function (p) { return p.url; }); }
  function soundCfg() { return S.sound ? { src: S.sound.src, start: S.start, volume: 1 } : null; }
  function rebuildPlayer() {
    if (S.mode !== 'slides' || !S.photos.length) return;
    var was = playing;
    if (player) player.destroy();
    var host = frame;
    if (!host.querySelector('.mv-stack')) { paintFrame(); return; }
    player = SlideshowPlayer.create({ stack: host.querySelector('.mv-stack'), segs: host.querySelector('.mv-segs'), flash: host.querySelector('.mv-flash'), urls: slideUrls(), beats: S.beats, fx: S.fx.toLowerCase(), bpm: bpm(), sound: soundCfg() });
    if (was) player.play(S.previewMuted);
  }
  function updatePace() { if (player) player.update({ beats: S.beats, fx: S.fx.toLowerCase(), bpm: bpm() }); }
  function updateSound() { if (player) player.update({ bpm: bpm(), sound: soundCfg() }); }

  /* preview transport */
  function stopPreview() {
    playing = false;
    if (player) player.pause();
    if (track) track.pause();
    if (S.media && S.media.tagName === 'VIDEO') S.media.pause();
    if (shared) shared.pause();
    $('muPlay').textContent = 'Play preview';
  }
  function startPreview() {
    playing = true;
    if (S.mode === 'slides') { if (player) player.play(S.previewMuted); }
    else if (S.file) {
      if (S.sound) {
        if (track) track.destroy();
        track = SoundTrack.create({ src: S.sound.src, start: S.start, volume: S.kind === 'video' ? S.mix / 100 : 1, loopSeconds: 30 });
        if (S.kind === 'video') { track.attachVideo(S.media); S.media.volume = (100 - S.mix) / 100; S.media.muted = S.previewMuted; }
        track.play(S.previewMuted);
      }
      if (S.kind === 'video') { S.media.muted = S.previewMuted; S.media.play().catch(function () {}); }
    }
    $('muPlay').textContent = 'Stop preview';
  }
  $('muPlay').addEventListener('click', function () { playing ? stopPreview() : startPreview(); });
  $('muMute').addEventListener('click', function () {
    S.previewMuted = !S.previewMuted;
    this.setAttribute('aria-pressed', S.previewMuted ? 'true' : 'false');
    this.textContent = S.previewMuted ? 'Sound off' : 'Sound on';
    if (player) player.setMuted(S.previewMuted);
    if (track) track.setMuted(S.previewMuted);
    if (S.media && S.media.tagName === 'VIDEO') S.media.muted = S.previewMuted;
  });

  /* drag to choose what stays in frame (single wide video/photo) */
  var drag = null;
  frame.addEventListener('pointerdown', function (e) {
    if (S.mode !== 'single' || !S.media || S.fit === 'FIT' || S.nw / S.nh <= TARGET * 1.03) return;
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
      segPress(fitRow, 'data-fit', S.fit);
      frame.classList.toggle('is-fit', S.fit === 'FIT');
      hint.hidden = S.fit === 'FIT';
    });
  });
  coverRange.addEventListener('input', function () {
    if (S.kind !== 'video' || !S.duration || !S.media) return;
    stopPreview();
    S.media.pause();
    S.media.currentTime = Math.min(S.duration - 0.05, S.duration * coverRange.value / 100);
  });

  /* ---------- sound ---------- */
  if (soundsOn) {
    var srcGroup = document.querySelector('#muSoundBox .mu-seg');
    Array.prototype.forEach.call(srcGroup.querySelectorAll('button'), function (b) {
      b.addEventListener('click', function () {
        S.srcMode = b.getAttribute('data-src');
        segPress(srcGroup, 'data-src', S.srcMode);
        stopPreview();
        $('muTracks').hidden = S.srcMode !== 'lib' && S.srcMode !== 'mine';
        $('muNewBox').hidden = S.srcMode !== 'new';
        if (S.srcMode === 'none') setSound(null);
        else if (S.srcMode === 'new') setSound(newSound);
        else { drawTracks(); setSound(null); }
        refreshAll();
      });
    });

    var newSound = null; // {own: File, ...} after an upload has been analysed
    var audioInput = $('muAudio');
    audioInput.addEventListener('change', function () {
      var f = this.files && this.files[0];
      if (!f) return;
      if (f.size > maxSound) { say('That audio file is ' + mb(f.size) + '. The limit is ' + mb(maxSound) + '.', true); return; }
      say('Finding the beat…', false);
      BeatAnalyzer.analyze(f).then(function (r) {
        if (newSound && newSound.src) URL.revokeObjectURL(newSound.src);
        newSound = { own: f, src: URL.createObjectURL(f), title: f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim(), artist: '', bpm: r.bpm, offset: r.offset, duration: r.duration, peaks: r.peaks };
        if (!$('muOwnTitle').value) $('muOwnTitle').value = newSound.title;
        setSound(newSound);
        say('Beat found: ' + r.bpm + ' BPM.', false);
      }, function (err) { say(err.message || "That audio file couldn't be read.", true); });
    });
    $('muOwnTitle').addEventListener('input', function () { if (newSound) newSound.title = this.value; });
    $('muOwnArtist').addEventListener('input', function () { if (newSound) newSound.artist = this.value; });
    $('muHalf').addEventListener('click', function () { if (S.sound && S.sound.own) { S.sound.bpm = Math.round(S.sound.bpm / 2 * 10) / 10; afterSoundChange(); } });
    $('muDouble').addEventListener('click', function () { if (S.sound && S.sound.own) { S.sound.bpm = Math.round(S.sound.bpm * 2 * 10) / 10; afterSoundChange(); } });
    $('muRights').addEventListener('change', refreshAll);

    $('muMix').addEventListener('input', function () { S.mix = parseInt(this.value, 10); $('muMixV').textContent = 'Music ' + S.mix + '% · video ' + (100 - S.mix) + '%'; });

    var beatsGroup = document.querySelector('#muBeatBox [data-beats]').parentNode;
    Array.prototype.forEach.call(beatsGroup.querySelectorAll('button'), function (b) {
      b.addEventListener('click', function () { S.beats = parseInt(b.getAttribute('data-beats'), 10); segPress(beatsGroup, 'data-beats', S.beats); updatePace(); drawWave(); refreshAll(); });
    });
    var fxGroup = document.querySelector('#muBeatBox [data-fx]').parentNode;
    Array.prototype.forEach.call(fxGroup.querySelectorAll('button'), function (b) {
      b.addEventListener('click', function () { S.fx = b.getAttribute('data-fx'); segPress(fxGroup, 'data-fx', S.fx); updatePace(); });
    });
  }

  function drawTracks() {
    var host = $('muTracks'); host.innerHTML = '';
    var list = S.srcMode === 'mine' ? lists.mine : lists.library;
    if (!list || !list.length) {
      var p = document.createElement('div'); p.className = 'muted'; p.style.cssText = 'font-size:0.88rem; padding:6px 2px';
      p.textContent = S.srcMode === 'mine' ? 'You have not uploaded any sounds yet. Use "Upload new" to add one.' : 'The library is empty. An admin can add tracks, or use "Upload new".';
      host.appendChild(p); return;
    }
    list.forEach(function (t) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'mu-trk' + (S.sound && S.sound.id === t.id ? ' on' : '');
      b.innerHTML = '<span class="tx"><b></b><span></span></span><span class="bpm"></span><span class="pv" role="button" tabindex="0" aria-label="Hear a few seconds">▶</span>';
      b.querySelector('b').textContent = t.title;
      b.querySelector('.tx > span').textContent = (t.artist ? t.artist + ' · ' : '') + clock(t.duration);
      b.querySelector('.bpm').textContent = Math.round(t.bpm) + ' BPM';
      b.querySelector('.pv').addEventListener('click', function (e) { e.stopPropagation(); hear(t); });
      b.addEventListener('click', function () { setSound({ id: t.id, src: t.src, title: t.title, artist: t.artist, bpm: t.bpm, offset: t.offset, duration: t.duration, peaks: null }); drawTracks(); });
      host.appendChild(b);
    });
  }
  function hear(t) {
    stopPreview();
    if (!shared) shared = new Audio();
    shared.src = t.src; shared.currentTime = Math.max(0, t.offset || 0); shared.play().catch(function () {});
    setTimeout(function () { if (shared) shared.pause(); }, 8000);
  }

  function setSound(snd) {
    stopPreview();
    S.sound = snd;
    S.start = snd ? snd.offset : 0;
    if (snd && !snd.peaks) {
      // library / own sounds: fetch the file once to draw its waveform
      fetch(snd.src).then(function (r) { return r.arrayBuffer(); }).then(function (ab) { return BeatAnalyzer.analyze(ab); }).then(function (r) { if (S.sound === snd) { snd.peaks = r.peaks; snd.duration = snd.duration || r.duration; drawWave(); } }).catch(function () { /* waveform is optional */ });
    }
    afterSoundChange();
  }
  function afterSoundChange() {
    var has = !!S.sound;
    $('muSoundCtl').hidden = !has;
    $('muBpmFix').hidden = !(has && S.sound.own);
    $('muMixRow').hidden = !(has && S.mode === 'single' && S.kind === 'video');
    updateSound();
    drawWave();
    refreshAll();
  }

  /* waveform with beat lines and a draggable window that snaps to the beat */
  var wave = $('muWave'), wcv = $('muWcv'), win = $('muWin');
  function winSeconds() {
    var spb = 60 / (bpm() || 100);
    if (S.mode === 'slides') return Math.max(4, Math.max(1, S.photos.length) * S.beats * spb);
    if (S.kind === 'video') return Math.min(S.duration || 30, 60);
    return 30;
  }
  function drawWave() {
    if (!S.sound || $('muSoundCtl').hidden) return;
    var w = wave.clientWidth || 600, h = 96, dpr = Math.min(2, window.devicePixelRatio || 1);
    wcv.width = w * dpr; wcv.height = h * dpr;
    var c = wcv.getContext('2d'); c.scale(dpr, dpr); c.clearRect(0, 0, w, h);
    var total = Math.max(1, S.sound.duration || 180), peaks = S.sound.peaks, spb = 60 / S.sound.bpm, px = w / total;
    if (peaks) {
      var bars = Math.floor(w / 3);
      for (var i = 0; i < bars; i++) {
        var v = peaks[Math.min(peaks.length - 1, Math.floor(i / bars * peaks.length))] * h * 0.9;
        c.fillStyle = 'rgba(255,255,255,.4)'; c.fillRect(i * 3, (h - v) / 2, 2, Math.max(2, v));
      }
    }
    c.strokeStyle = 'rgba(255,77,154,.6)'; c.lineWidth = 1;
    for (var t = S.sound.offset, k = 0; t < total; t += spb, k++) { var x = t * px; if (spb * px < 3) break; c.beginPath(); c.moveTo(x, 0); c.lineTo(x, k % 4 === 0 ? h : h * 0.25); c.stroke(); }
    var ws = winSeconds(), ww = Math.min(w, ws * px);
    win.style.width = ww + 'px';
    win.style.left = Math.min(w - ww, S.start * px) + 'px';
    $('muWstart').textContent = 'Starts at ' + clock(S.start);
    $('muWlen').textContent = (S.mode === 'slides' ? 'Slideshow plays ' : 'Plays ') + Math.round(ws) + ' s' + (S.mode === 'slides' ? ', then loops' : '');
    var f = $('muSfacts'); f.innerHTML = '';
    fact(f, 'Beat found: ' + S.sound.bpm + ' BPM', 'good');
    fact(f, Math.round(spb * 1000) + ' ms per beat');
  }
  var dragW = false;
  wave.addEventListener('pointerdown', function (e) { dragW = true; wave.setPointerCapture(e.pointerId); moveWindow(e); });
  wave.addEventListener('pointermove', function (e) { if (dragW) moveWindow(e); });
  wave.addEventListener('pointerup', function () { dragW = false; if (S.mode === 'slides') updateSound(); });
  function moveWindow(e) {
    if (!S.sound) return;
    var r = wave.getBoundingClientRect(), total = Math.max(1, S.sound.duration || 180), ws = winSeconds();
    var raw = Math.max(0, Math.min(total - ws, (e.clientX - r.left) / r.width * total - ws / 2));
    var spb = 60 / S.sound.bpm, off = S.sound.offset;
    S.start = raw <= off ? off : off + Math.round((raw - off) / spb) * spb; // always start on a beat
    drawWave();
  }
  window.addEventListener('resize', drawWave);

  /* ---------- summary / readiness ---------- */
  function refreshAll() {
    var tf = $('muTiming');
    if (tf) {
      tf.innerHTML = '';
      if (S.mode === 'slides' && S.photos.length) {
        var sec = S.sound ? S.beats * 60 / S.sound.bpm : 2.5;
        fact(tf, '1 photo = ' + (S.sound ? S.beats + ' beat' + (S.beats > 1 ? 's' : '') + ' = ' : '') + sec.toFixed(2) + ' s');
        fact(tf, S.photos.length + ' photos = ' + (sec * S.photos.length).toFixed(1) + ' s, then loops');
        if (!S.sound) fact(tf, 'No sound: photos change every 2.5 s', 'warn');
      }
    }
    var ready = S.mode === 'slides' ? S.photos.length >= 1 : (S.file && S.fileOk);
    var why = '';
    if (S.srcMode === 'new') {
      if (!S.sound) { ready = false; why = 'Choose an audio file, or switch to "No sound".'; }
      else if (!$('muRights').checked) { ready = false; why = 'Tick the box to confirm you may use this sound.'; }
      else if (!($('muOwnTitle').value || '').trim()) { ready = false; why = 'Give the sound a title.'; }
    } else if ((S.srcMode === 'lib' || S.srcMode === 'mine') && !S.sound) { ready = false; why = 'Pick a track, or switch to "No sound".'; }
    publish.disabled = !ready;
    if (why) say(why, false);
  }

  /* ---------- publish ---------- */
  function captureCover() {
    return new Promise(function (resolve) {
      if (S.mode !== 'single' || S.kind !== 'video') return resolve(null);
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
    stopPreview();
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
      if (S.mode === 'slides') {
        S.photos.forEach(function (p, i) { fd.append('photos[]', p.blob, (i + 1) + '.jpg'); });
      } else {
        if (S.kind === 'video') fd.append('duration', String(Math.round(S.duration)));
        fd.append('file', S.file, S.file.name);
        if (poster) fd.append('poster', poster, 'cover.jpg');
      }
      if (S.sound) {
        if (S.sound.own) {
          fd.append('own_audio', S.sound.own, S.sound.own.name);
          fd.append('own_title', $('muOwnTitle').value);
          fd.append('own_artist', $('muOwnArtist').value);
          fd.append('own_bpm', String(S.sound.bpm));
          fd.append('own_offset', String(S.sound.offset));
          fd.append('own_duration', String(Math.round(S.sound.duration)));
          fd.append('rights', $('muRights').checked ? '1' : '');
        } else {
          fd.append('sound_id', String(S.sound.id));
        }
        fd.append('sound_start', String(S.start));
        fd.append('sound_mix', String(S.mode === 'single' && S.kind === 'video' ? S.mix : 100));
      }
      fd.append('slide_beats', String(S.beats));
      fd.append('slide_fx', S.fx);
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
        say((res && res.error) || (xhr.status === 413 ? 'That is too large for this server. Try fewer photos or a shorter video.' : 'Something went wrong. Please try again.'), true);
        bar.hidden = true; publish.disabled = false;
      };
      xhr.onerror = function () { say('Check your connection and try again.', true); bar.hidden = true; publish.disabled = false; };
      xhr.send(fd);
    });
  });

  paintFrame();
})();
