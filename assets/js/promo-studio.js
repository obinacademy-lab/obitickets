/* Promo studio: draws a short 9:16 ad for an event on a canvas, timed to a beat, and records it to a real
   video file in the organizer's own browser (canvas.captureStream + MediaRecorder). Nothing is rendered on
   the server. The finished file is published as a moment with the Get tickets bar switched on.

   The scenes are written for a 540 x 960 drawing space and scaled up to the 720 x 1280 canvas. */
(function () {
  'use strict';
  var root = document.getElementById('promoStudio');
  if (!root) return;
  var cfg;
  try { cfg = JSON.parse(root.getAttribute('data-studio')); } catch (e) { return; }

  var W = 540, H = 960, SCALE = 4 / 3;
  var cv = document.getElementById('prCanvas'), cx = cv.getContext('2d');
  function $(id) { return document.getElementById(id); }

  if (!window.MediaRecorder || !cv.captureStream) { $('prUnsupported').hidden = false; $('prRender').disabled = true; }

  /* ---------- details, from the event ---------- */
  function money(n) { return cfg.currency + ' ' + Math.round(n).toLocaleString('en-US'); }
  var D = {
    head: (cfg.title || '').toUpperCase().slice(0, 30),
    sub: cfg.where || '',
    when: cfg.when || '',
    price: cfg.from != null ? 'From ' + money(cfg.from) : '',
    cta: 'Get tickets',
    left: cfg.left,
    days: cfg.days
  };
  var fields = { prHead: 'head', prSub: 'sub', prWhen: 'when', prPrice: 'price', prCta: 'cta' };
  Object.keys(fields).forEach(function (id) {
    var el = $(id);
    if (D[fields[id]] != null) el.value = D[fields[id]];
    el.addEventListener('input', function () { D[fields[id]] = el.value; if (!playing) frame(); });
  });

  /* ---------- photos ---------- */
  var pool = [];   // {img, url, on}
  function addPhoto(url, on) {
    var im = new Image();
    var p = { img: im, url: url, on: !!on, ok: false };
    im.onload = function () { p.ok = true; if (!playing) frame(); };
    im.src = url;
    pool.push(p);
  }
  (cfg.photos || []).forEach(function (u, i) { addPhoto(u, i < 4); });
  function drawPool() {
    var host = $('prPhotos'); host.innerHTML = '';
    pool.forEach(function (p) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'pr-ph'; b.setAttribute('aria-pressed', p.on ? 'true' : 'false'); b.setAttribute('aria-label', 'Use this photo');
      var im = document.createElement('img'); im.alt = ''; im.src = p.url; b.appendChild(im);
      var n = document.createElement('span'); n.className = 'n'; b.appendChild(n);
      b.addEventListener('click', function () { p.on = !p.on; drawPool(); if (!playing) frame(); });
      host.appendChild(b);
    });
    var k = 0;
    Array.prototype.forEach.call(host.querySelectorAll('.pr-ph'), function (b, i) { if (pool[i].on) { k++; b.querySelector('.n').textContent = k; } });
    var add = document.createElement('label'); add.className = 'pr-ph add'; add.setAttribute('for', 'prAdd'); add.textContent = '+'; add.setAttribute('aria-label', 'Add photos from your device'); host.appendChild(add);
  }
  $('prAdd').addEventListener('change', function () {
    Array.prototype.slice.call(this.files || []).slice(0, 12 - pool.length).forEach(function (f) { if (/^image\//.test(f.type)) addPhoto(URL.createObjectURL(f), true); });
    this.value = '';
    drawPool(); if (!playing) frame();
  });
  drawPool();
  function chosen() { var l = pool.filter(function (p) { return p.on && p.ok; }); return l; }

  /* ---------- drawing helpers ---------- */
  var BRAND = [[330, 60], [200, 60], [20, 60], [280, 60], [160, 60]];
  function ease(t) { return 1 - Math.pow(1 - Math.min(1, Math.max(0, t)), 3); }
  function photo(c, idx, t, zoom) {
    var list = chosen();
    if (list.length) {
      var p = list[idx % list.length], im = p.img, iw = im.naturalWidth, ih = im.naturalHeight;
      var s = Math.max(W / iw, H / ih) * zoom, dw = iw * s, dh = ih * s;
      c.drawImage(im, (W - dw) / 2, (H - dh) / 2, dw, dh);
    } else {
      var h = BRAND[idx % BRAND.length][0], g = c.createLinearGradient(0, 0, 0, H);
      g.addColorStop(0, 'hsl(' + h + ',60%,24%)'); g.addColorStop(1, 'hsl(' + h + ',65%,6%)');
      c.fillStyle = g; c.fillRect(0, 0, W, H);
      var rg = c.createRadialGradient(W * .3, H * .3, 0, W * .3, H * .3, W * .5);
      rg.addColorStop(0, 'hsla(' + h + ',90%,62%,.85)'); rg.addColorStop(1, 'hsla(' + h + ',90%,62%,0)');
      c.fillStyle = rg; c.fillRect(0, 0, W, H);
    }
    var sh = c.createLinearGradient(0, H * .35, 0, H); sh.addColorStop(0, 'rgba(0,0,0,0)'); sh.addColorStop(1, 'rgba(0,0,0,.78)');
    c.fillStyle = sh; c.fillRect(0, H * .3, W, H * .7);
  }
  function txt(c, s, x, y, size, o) {
    o = o || {};
    c.save(); c.globalAlpha = o.a == null ? 1 : o.a;
    c.font = (o.w || 800) + ' ' + size + 'px ' + (o.f || 'Manrope, system-ui, sans-serif');
    c.textAlign = o.al || 'left'; c.fillStyle = o.col || '#fff';
    c.shadowColor = 'rgba(0,0,0,.5)'; c.shadowBlur = o.sh == null ? 18 : o.sh;
    c.fillText(s, x, y); c.restore();
  }
  function wrap(c, s, x, y, size, maxW, lh, o) {
    var words = String(s).split(' '), line = '', yy = y;
    c.save(); c.font = ((o && o.w) || 800) + ' ' + size + 'px ' + ((o && o.f) || 'Manrope, system-ui, sans-serif');
    var lines = [];
    words.forEach(function (w) { var test = line ? line + ' ' + w : w; if (c.measureText(test).width > maxW && line) { lines.push(line); line = w; } else line = test; });
    lines.push(line); c.restore();
    lines.slice(0, 3).forEach(function (l) { txt(c, l, x, yy, size, o); yy += lh; });
    return yy;
  }
  function button(c, label, cx0, cy0, pulse) {
    var w = 380, h = 104, sc = 1 + pulse * .05, r = h / 2;
    c.save(); c.translate(cx0, cy0); c.scale(sc, sc);
    c.shadowColor = 'rgba(220,38,38,.6)'; c.shadowBlur = 40; c.fillStyle = '#DC2626';
    c.beginPath(); c.moveTo(-w / 2 + r, -h / 2); c.lineTo(w / 2 - r, -h / 2); c.arc(w / 2 - r, 0, r, -Math.PI / 2, Math.PI / 2); c.lineTo(-w / 2 + r, h / 2); c.arc(-w / 2 + r, 0, r, Math.PI / 2, Math.PI * 1.5); c.fill();
    c.shadowBlur = 0; c.fillStyle = '#fff'; c.font = '800 44px Manrope, system-ui, sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle';
    c.fillText(label, 0, 3); c.restore();
  }
  function endCard(c, t, i, lines) {
    photo(c, i, t, 1.05); c.fillStyle = 'rgba(0,0,0,.5)'; c.fillRect(0, 0, W, H);
    lines(c, t);
    button(c, D.cta || 'Get tickets', W / 2, H * .62, Math.max(0, Math.sin(t * 5)) * ease(t * 3));
    txt(c, cfg.siteHost, W / 2, H * .62 + 120, 32, { al: 'center', a: ease((t - .5) * 3), w: 700, sh: 0, col: 'rgba(255,255,255,.85)' });
  }
  var SERIF = 'Fraunces, Georgia, serif';

  /* ---------- the templates: every scene is a whole number of beats ---------- */
  var TPLS = [
    { id: 'hype', name: 'Hype reel', desc: 'Big title, date, price, button.', c: ['#ec4899', '#7c3aff'], ok: function () { return true; }, scenes: function () { return [
      { b: 4, draw: function (c, t, p) { photo(c, 0, t, 1 + p * .08); var k = ease(t * 2.2); txt(c, D.head, 36, H * .62 + (1 - k) * 60, 76, { a: k, f: SERIF, w: 700 }); wrap(c, D.sub, 36, H * .62 + 100, 36, W - 72, 46, { a: ease((t - .25) * 2.2), w: 700 }); } },
      { b: 4, draw: function (c, t, p) { photo(c, 1, t, 1.1 - p * .08); txt(c, 'WHEN', 36, H * .52, 30, { a: ease(t * 3), col: '#ff8fb8', sh: 0 }); wrap(c, D.when, 36, H * .52 + 62, 58, W - 72, 68, { a: ease((t - .1) * 2.4), f: SERIF, w: 700 }); } },
      { b: 4, draw: function (c, t, p) { photo(c, 2, t, 1 + p * .1); txt(c, 'TICKETS', 36, H * .5, 30, { a: ease(t * 3), col: '#ffb36b', sh: 0 }); wrap(c, D.price, 36, H * .5 + 70, 72, W - 72, 82, { a: ease((t - .1) * 2.4), f: SERIF, w: 700 }); if (D.left != null && cfg.left > 0 && cfg.left <= 100) txt(c, 'Only ' + D.left + ' left', 36, H * .5 + 250, 44, { a: ease((t - .5) * 2.4) }); } },
      { b: 4, draw: function (c, t) { endCard(c, t, 0, function (c, t) { txt(c, D.head, W / 2, H * .36, 64, { al: 'center', a: ease(t * 3), f: SERIF, w: 700 }); txt(c, D.price, W / 2, H * .36 + 88, 44, { al: 'center', a: ease((t - .15) * 3) }); if (cfg.left > 0 && cfg.left <= 100) txt(c, D.left + ' tickets left', W / 2, H * .36 + 150, 34, { al: 'center', a: ease((t - .25) * 3), col: '#ffb3b3', sh: 0 }); }); } }
    ]; } },
    { id: 'count', name: 'Countdown', desc: 'Days to go, then the button.', c: ['#3dd5ff', '#ff4d8d'], ok: function () { return cfg.days >= 1 && cfg.days <= 60; }, why: 'Needs the event to be 1 to 60 days away.', scenes: function () { return [
      { b: 4, draw: function (c, t, p) { photo(c, 1, t, 1 + p * .08); var n = Math.max(0, D.days), k = ease(t * 2.4); c.save(); c.translate(W / 2, H * .46); c.scale(.7 + .3 * k, .7 + .3 * k); txt(c, String(n), 0, 0, 300, { al: 'center', w: 800, f: SERIF, a: k }); c.restore(); txt(c, n === 1 ? 'DAY TO GO' : 'DAYS TO GO', W / 2, H * .46 + 90, 60, { al: 'center', a: ease((t - .2) * 2.4) }); } },
      { b: 4, draw: function (c, t, p) { photo(c, 2, t, 1.1 - p * .08); txt(c, D.head, 36, H * .6, 70, { a: ease(t * 2.4), f: SERIF, w: 700 }); wrap(c, D.when, 36, H * .6 + 92, 42, W - 72, 52, { a: ease((t - .2) * 2.4), w: 700 }); } },
      { b: 4, draw: function (c, t, p) { photo(c, 3, t, 1 + p * .1); wrap(c, D.price, 36, H * .58, 72, W - 72, 82, { a: ease(t * 2.4), f: SERIF, w: 700 }); } },
      { b: 4, draw: function (c, t) { endCard(c, t, 1, function (c, t) { txt(c, D.days + (D.days === 1 ? ' day to go' : ' days to go'), W / 2, H * .36, 60, { al: 'center', a: ease(t * 3), f: SERIF, w: 700 }); txt(c, D.price, W / 2, H * .36 + 80, 38, { al: 'center', a: ease((t - .15) * 3) }); }); } }
    ]; } },
    { id: 'last', name: 'Last tickets', desc: 'Urgency from your real stock.', c: ['#ffb347', '#dc2626'], ok: function () { return cfg.left > 0 && (cfg.left <= 100 || (cfg.capacity > 0 && cfg.left / cfg.capacity <= 0.25)); }, why: 'Only available when tickets are really running low (100 or fewer, or a quarter left).', scenes: function () { return [
      { b: 4, draw: function (c, t, p) { photo(c, 2, t, 1 + p * .08); var k = ease(t * 2.4); txt(c, 'ONLY', W / 2, H * .38, 56, { al: 'center', a: k, col: '#ffd0a0' }); c.save(); c.translate(W / 2, H * .5); c.scale(.7 + .3 * k, .7 + .3 * k); txt(c, String(D.left), 0, 0, 280, { al: 'center', w: 800, f: SERIF, a: k }); c.restore(); txt(c, 'TICKETS LEFT', W / 2, H * .5 + 90, 54, { al: 'center', a: ease((t - .2) * 2.4) }); } },
      { b: 4, draw: function (c, t, p) { photo(c, 0, t, 1.1 - p * .08); txt(c, D.head, 36, H * .6, 70, { a: ease(t * 2.4), f: SERIF, w: 700 }); wrap(c, D.when, 36, H * .6 + 92, 42, W - 72, 52, { a: ease((t - .2) * 2.4), w: 700 }); } },
      { b: 4, draw: function (c, t) { endCard(c, t, 2, function (c, t) { wrap(c, D.price, W / 2, H * .36, 70, W - 72, 80, { al: 'center', a: ease(t * 3), f: SERIF, w: 700 }); txt(c, 'Only ' + D.left + ' left', W / 2, H * .36 + 100, 44, { al: 'center', a: ease((t - .15) * 3), col: '#ffb3b3', sh: 0 }); }); } }
    ]; } }
  ];

  var S = { tpl: 0, len: 12, sound: null, t: 0, muted: false, rendering: false };
  var tplBox = $('prTpls');
  function drawTpls() {
    tplBox.innerHTML = '';
    TPLS.forEach(function (t, i) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'pr-tpl';
      b.style.setProperty('--c1', t.c[0]); b.style.setProperty('--c2', t.c[1]);
      var okNow = t.ok();
      b.disabled = !okNow; b.style.opacity = okNow ? '' : '.55';
      b.setAttribute('aria-pressed', i === S.tpl ? 'true' : 'false');
      b.innerHTML = '<span class="sw"></span><b></b><span></span>';
      b.querySelector('b').textContent = t.name;
      b.querySelector('b + span').textContent = okNow ? t.desc : t.why;
      b.addEventListener('click', function () { S.tpl = i; drawTpls(); replan(); });
      tplBox.appendChild(b);
    });
  }
  if (!TPLS[S.tpl].ok()) S.tpl = 0;

  /* sounds */
  var sndSel = $('prSound');
  (cfg.sounds || []).forEach(function (s, i) { var o = document.createElement('option'); o.value = String(i); o.textContent = s.title + (s.artist ? ' - ' + s.artist : '') + ' (' + Math.round(s.bpm) + ' BPM)'; sndSel.appendChild(o); });
  if (!(cfg.sounds || []).length) { $('prSoundNote').textContent = 'No sounds in the library yet. Your video will have no sound until an admin adds some, or you can add your own sound in Moments.'; sndSel.disabled = true; }
  sndSel.addEventListener('change', function () { S.sound = sndSel.value === '' ? null : cfg.sounds[parseInt(sndSel.value, 10)]; replan(); });

  /* ---------- timeline ---------- */
  var P = null, playing = false, timer = null, t0 = 0, audio = null;
  function plan() {
    var sc = TPLS[S.tpl].scenes(), beats = sc.reduce(function (a, s) { return a + s.b; }, 0);
    var spb = S.sound ? 60 / S.sound.bpm : 0, len = S.len;
    // with music, make the length a whole number of beats so the last card starts on one
    var unit = S.sound ? Math.max(1, Math.round(len / (beats * spb))) * spb : len / beats;
    var list = [], t = 0;
    sc.forEach(function (s) { list.push({ s: s, start: t, dur: s.b * unit }); t += s.b * unit; });
    return { list: list, total: t };
  }
  function drawAt(c, T, pl) {
    c.setTransform(SCALE, 0, 0, SCALE, 0, 0);
    c.clearRect(0, 0, W, H);
    for (var i = 0; i < pl.list.length; i++) {
      var it = pl.list[i];
      if (T < it.start + it.dur || i === pl.list.length - 1) { var t = Math.max(0, T - it.start); it.s.draw(c, t, Math.min(1, t / it.dur)); return; }
    }
  }
  function frame() { drawAt(cx, S.t, P); drawTimeline(); }
  function drawTimeline() {
    var tl = $('prTl');
    Array.prototype.forEach.call(tl.querySelectorAll('i'), function (e) { e.remove(); });
    P.list.forEach(function (it) { var e = document.createElement('i'); e.style.flex = it.dur; e.className = S.t >= it.start && S.t < it.start + it.dur ? 'on' : ''; tl.insertBefore(e, $('prHeadMark')); });
    $('prHeadMark').style.left = (S.t / P.total * 100) + '%';
  }
  function replan() { var was = playing; stop(); P = plan(); S.t = 0; frame(); $('prState').textContent = ''; if (was) play(); }

  /* audio: the same element feeds the speakers (preview) and the recording */
  var AC = null, dest = null, srcNode = null, outGain = null;
  function audioSetup() {
    if (!AC) { AC = new (window.AudioContext || window.webkitAudioContext)(); dest = AC.createMediaStreamDestination(); outGain = AC.createGain(); outGain.connect(dest); outGain.connect(AC.destination); }
    if (AC.state === 'suspended') AC.resume();
    if (audio) { try { audio.pause(); } catch (e) { /* ignore */ } audio = null; }
    if (srcNode) { try { srcNode.disconnect(); } catch (e) { /* ignore */ } srcNode = null; }
    if (S.sound) {
      audio = new Audio(); audio.src = S.sound.src; audio.preload = 'auto';
      srcNode = AC.createMediaElementSource(audio); srcNode.connect(outGain);
    }
    outGain.gain.value = S.muted ? 0 : 1;
  }
  function startAudio() {
    if (!audio) return;
    var go = function () { try { audio.currentTime = S.sound.offset || 0; } catch (e) { /* ignore */ } audio.play().catch(function () {}); };
    if (audio.readyState >= 1) go(); else audio.addEventListener('loadedmetadata', function once() { audio.removeEventListener('loadedmetadata', once); go(); });
  }

  function play() {
    if (playing) return;
    playing = true; $('prPlay').textContent = 'Pause';
    if (S.t >= P.total - .05) S.t = 0;
    t0 = performance.now() - S.t * 1000;
    if (S.sound) { audioSetup(); startAudio(); }
    clearInterval(timer);
    timer = setInterval(function () {
      S.t = (performance.now() - t0) / 1000;
      if (S.t >= P.total) {
        if (S.rendering) { finishRender(); return; }
        S.t = 0; t0 = performance.now(); if (audio) startAudio();
      }
      frame();
    }, 33);
  }
  function stop() { playing = false; clearInterval(timer); if (audio) audio.pause(); $('prPlay').textContent = 'Play'; }
  $('prPlay').addEventListener('click', function () { playing ? stop() : play(); });
  $('prMute').addEventListener('click', function () { S.muted = !S.muted; this.setAttribute('aria-pressed', S.muted ? 'true' : 'false'); this.textContent = S.muted ? 'Sound off' : 'Sound on'; if (outGain) outGain.gain.value = S.muted ? 0 : 1; });
  $('prTl').addEventListener('click', function (e) { var r = this.getBoundingClientRect(); S.t = Math.max(0, Math.min(P.total - .01, (e.clientX - r.left) / r.width * P.total)); if (playing) { t0 = performance.now() - S.t * 1000; if (audio) try { audio.currentTime = (S.sound.offset || 0) + S.t; } catch (er) { /* ignore */ } } frame(); });
  Array.prototype.forEach.call($('prLen').querySelectorAll('button'), function (b) {
    b.addEventListener('click', function () { S.len = parseInt(b.getAttribute('data-l'), 10); Array.prototype.forEach.call($('prLen').querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); replan(); });
  });

  /* ---------- render to a video file ---------- */
  var rec = null, chunks = [], renderedBlob = null, renderedMime = '';
  $('prRender').addEventListener('click', function () {
    if (S.rendering || !window.MediaRecorder) return;
    if (!chosen().length && pool.length) { $('prState').textContent = 'Wait a moment for the photos to load, or choose at least one.'; return; }
    stop(); S.t = 0; frame();
    if (S.sound) audioSetup(); else if (AC && audio) { audio.pause(); }
    var stream = cv.captureStream(30);
    if (S.sound && dest) dest.stream.getAudioTracks().forEach(function (t) { stream.addTrack(t); });
    var type = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'].filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0] || '';
    chunks = [];
    try { rec = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: 3500000 } : undefined); } catch (e) { $('prState').textContent = "This browser couldn't start recording."; return; }
    rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
    rec.onstop = function () {
      renderedMime = rec.mimeType || type || 'video/webm';
      renderedBlob = new Blob(chunks, { type: renderedMime });
      showResult();
    };
    S.rendering = true; $('prRender').disabled = true; $('prBar').hidden = false; $('prBar').firstChild.style.width = '0%'; $('prResult').hidden = true;
    $('prState').textContent = 'Rendering… this takes as long as the video.';
    rec.start(250);
    playing = true; $('prPlay').textContent = 'Pause'; t0 = performance.now();
    if (S.sound) startAudio();
    clearInterval(timer);
    timer = setInterval(function () {
      S.t = (performance.now() - t0) / 1000;
      $('prBar').firstChild.style.width = Math.min(100, S.t / P.total * 100) + '%';
      if (S.t >= P.total) { finishRender(); return; }
      frame();
    }, 33);
  });
  function finishRender() {
    clearInterval(timer); playing = false; $('prPlay').textContent = 'Play';
    if (audio) audio.pause();
    S.t = P.total - .01; frame();
    setTimeout(function () { if (rec && rec.state !== 'inactive') rec.stop(); }, 150); // let the last frame be captured
  }

  function showResult() {
    S.rendering = false; $('prRender').disabled = false; $('prBar').hidden = true;
    var url = URL.createObjectURL(renderedBlob), box = $('prResult'), mb = (renderedBlob.size / 1048576).toFixed(1);
    box.hidden = false;
    box.innerHTML = '<video controls playsinline loop></video><div class="mu-facts" style="justify-content:center; margin-top:10px"></div>' +
      '<div class="mu-row"><label for="prCaption">Caption</label><textarea id="prCaption" maxlength="300" rows="2" style="width:100%"></textarea></div>' +
      (cfg.canBuyBar ? '<label class="social-check" style="margin-top:8px"><input type="checkbox" id="prBuyBar" checked> Show a Get tickets bar while people watch</label>' : '') +
      '<div style="margin-top:12px"><button class="btn btn-purple" type="button" id="prPublish" style="width:auto">Publish to Moments</button></div><div class="mu-state" id="prPubState" role="status" style="margin-top:8px"></div>';
    box.querySelector('video').src = url;
    var facts = box.querySelector('.mu-facts');
    [Math.round(P.total) + ' s', mb + ' MB', '9:16', renderedMime.indexOf('mp4') > -1 ? 'MP4' : 'WebM'].forEach(function (t) { var s = document.createElement('span'); s.className = 'mu-fact'; s.textContent = t; facts.appendChild(s); });
    $('prCaption').value = (D.head ? D.head.charAt(0) + D.head.slice(1).toLowerCase() : '') + '. Tickets on obitickets.';
    $('prState').textContent = 'Done. Check it, then publish.';
    S.t = 0; frame();
    $('prPublish').addEventListener('click', publish);
  }

  /* a still from the video to show before it is played */
  function posterBlob() {
    return new Promise(function (resolve) {
      S.t = Math.min(P.total * .3, 3); frame();
      cv.toBlob(function (b) { S.t = 0; frame(); resolve(b); }, 'image/jpeg', 0.82);
    });
  }
  function publish() {
    var btn = $('prPublish'), st = $('prPubState');
    btn.disabled = true; st.classList.remove('err'); st.textContent = 'Preparing…';
    posterBlob().then(function (poster) {
      var fd = new FormData();
      fd.append('csrf_token', root.getAttribute('data-csrf'));
      fd.append('event_id', String(cfg.eventId));
      fd.append('caption', $('prCaption').value);
      fd.append('duration', String(Math.round(P.total)));
      fd.append('promo', '1');
      var bb = $('prBuyBar'); fd.append('buy_bar', !bb || bb.checked ? '1' : '0');
      fd.append('file', renderedBlob, 'promo.' + (renderedMime.indexOf('mp4') > -1 ? 'mp4' : 'webm'));
      if (poster) fd.append('poster', poster, 'cover.jpg');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', root.getAttribute('data-endpoint'));
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) { if (e.lengthComputable) st.textContent = 'Uploading… ' + Math.round(e.loaded / e.total * 100) + '%'; };
      xhr.onload = function () {
        var res = null; try { res = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
        if (xhr.status >= 200 && xhr.status < 300 && res && res.ok) {
          st.textContent = 'Published. Opening your moments…';
          location.href = '/org-social.php?event=' + encodeURIComponent(cfg.eventId) + '&done=moment#moments-admin';
          return;
        }
        st.classList.add('err');
        st.textContent = (res && res.error) || (xhr.status === 413 ? 'That video is too large for this server. Try a shorter one.' : 'Something went wrong. Please try again.');
        btn.disabled = false;
      };
      xhr.onerror = function () { st.classList.add('err'); st.textContent = 'Check your connection and try again.'; btn.disabled = false; };
      xhr.send(fd);
    });
  }

  drawTpls();
  P = plan();
  frame();
})();
