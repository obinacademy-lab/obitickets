/* OBI GIFTS sounds (ADR-004). Every gift has a sound, made here by the browser (Web Audio), so there are no files to license or download.
   An admin can upload a sound file for a gift instead; that file is played in its place.
   Rules: sound only plays right after the viewer's own tap (sending a gift or pressing Preview), never while scrolling; a new sound stops
   the one before (no pile-up); everything goes through one soft limiter so nothing is harsh; the viewer can mute gifts and the choice is
   remembered on this device (on by default).
   Public API: window.ObGiftSound.play(style, assetUrl), .stop(), .isOn(), .set(bool), .unlock(), .onChange(fn) */
(function () {
  'use strict';
  var KEY = 'obGiftSound';
  var ctx = null, master = null, noiseBuf = null, current = null, audioEl = null, listeners = [];

  function isOn() { try { return localStorage.getItem(KEY) !== '0'; } catch (e) { return true; } }
  function set(v) {
    try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) { /* private mode: still works for this page */ }
    if (!v) { stop(); }
    listeners.forEach(function (f) { try { f(!!v); } catch (e) { /* ignore */ } });
  }
  function ac() {
    if (!ctx) {
      var C = window.AudioContext || window.webkitAudioContext;
      if (!C) { return null; }
      ctx = new C();
      var comp = ctx.createDynamicsCompressor();
      comp.threshold.value = -20; comp.knee.value = 12; comp.ratio.value = 8; comp.attack.value = 0.004; comp.release.value = 0.2;
      master = ctx.createGain(); master.gain.value = 0.55;
      master.connect(comp); comp.connect(ctx.destination);
      noiseBuf = ctx.createBuffer(1, ctx.sampleRate * 2, ctx.sampleRate);
      var d = noiseBuf.getChannelData(0);
      for (var i = 0; i < d.length; i++) { d[i] = Math.random() * 2 - 1; }
    }
    if (ctx.state === 'suspended') { ctx.resume(); }
    return ctx;
  }
  // called from a tap (opening the gift sheet) so the browser allows sound later
  function unlock() { if (isOn()) { ac(); } }

  function stop() {
    if (current && ctx) {
      var bus = current; current = null;
      try { bus.gain.cancelScheduledValues(ctx.currentTime); bus.gain.setTargetAtTime(0, ctx.currentTime, 0.06); } catch (e) { /* ignore */ }
      setTimeout(function () { try { bus.disconnect(); } catch (e) { /* ignore */ } }, 500);
    }
    if (audioEl) { try { audioEl.pause(); } catch (e) { /* ignore */ } audioEl = null; }
  }

  // ---------------------------------------------------------------- building blocks
  function m(n) { return 440 * Math.pow(2, (n - 69) / 12); }
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function env(g, t, vol, a, dur) {
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(Math.max(vol, 0.0002), t + a);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
  }
  function tone(bus, t, f, dur, o) {
    o = o || {};
    var osc = ctx.createOscillator(), g = ctx.createGain();
    osc.type = o.type || 'sine';
    osc.frequency.setValueAtTime(f, t);
    if (o.to) { osc.frequency.exponentialRampToValueAtTime(o.to, t + (o.slide || dur)); }
    if (o.detune) { osc.detune.value = o.detune; }
    env(g, t, o.vol || 0.3, o.attack || 0.008, dur);
    var last = g;
    if (o.lp) { var f2 = ctx.createBiquadFilter(); f2.type = 'lowpass'; f2.frequency.setValueAtTime(o.lp, t); if (o.lpTo) { f2.frequency.exponentialRampToValueAtTime(o.lpTo, t + dur); } g.connect(f2); last = f2; }
    osc.connect(g); last.connect(bus);
    osc.start(t); osc.stop(t + dur + 0.05);
  }
  function noise(bus, t, dur, o) {
    o = o || {};
    var src = ctx.createBufferSource(), f = ctx.createBiquadFilter(), g = ctx.createGain();
    src.buffer = noiseBuf; src.loop = true;
    f.type = o.type || 'lowpass'; f.frequency.setValueAtTime(o.f || 1000, t); f.Q.value = o.q || 0.7;
    if (o.to) { f.frequency.exponentialRampToValueAtTime(o.to, t + (o.slide || dur)); }
    env(g, t, o.vol || 0.3, o.attack || 0.005, dur);
    src.connect(f); f.connect(g); g.connect(bus);
    src.start(t, Math.random()); src.stop(t + dur + 0.05);
  }
  function bell(bus, t, f, dur, vol) { tone(bus, t, f, dur, { vol: vol, type: 'sine' }); tone(bus, t, f * 2.76, dur * 0.5, { vol: vol * 0.3, type: 'sine' }); tone(bus, t, f * 5.4, dur * 0.25, { vol: vol * 0.12 }); }
  function brass(bus, t, f, dur, vol) { tone(bus, t, f, dur, { type: 'sawtooth', vol: vol || 0.16, attack: 0.03, lp: 900, lpTo: 2600 }); tone(bus, t, f * 1.003, dur, { type: 'sawtooth', vol: (vol || 0.16) * 0.6, attack: 0.04, lp: 1200 }); }
  function thump(bus, t, f1, f2, dur, vol) { tone(bus, t, f1, dur, { to: f2, slide: dur * 0.8, vol: vol || 0.7, attack: 0.003 }); }
  function boom(bus, t, big) { thump(bus, t, 90, 32, big ? 1.4 : 0.9, 0.8); noise(bus, t, big ? 1.6 : 1, { type: 'lowpass', f: 1400, to: 180, vol: 0.55 }); for (var i = 0; i < 10; i++) { noise(bus, t + 0.25 + i * rnd(0.05, 0.11), 0.03, { type: 'highpass', f: 2500, vol: 0.18 }); } }
  function sparkles(bus, t, n, span) { for (var i = 0; i < n; i++) { tone(bus, t + i * (span / n) + rnd(0, 0.03), rnd(2400, 4600), 0.14, { vol: 0.09 }); } }
  function claps(bus, t, n, gap) { for (var i = 0; i < n; i++) { noise(bus, t + i * gap + rnd(0, 0.025), 0.07, { type: 'bandpass', f: rnd(1200, 2000), q: 1.3, vol: 0.5 }); } }

  // ---------------------------------------------------------------- one recipe per sound style (lengths stay inside the animation)
  var R = {
    pop: function (b, t) { tone(b, t, 560, 0.14, { to: 170, vol: 0.55 }); noise(b, t, 0.04, { type: 'bandpass', f: 2200, vol: 0.25 }); tone(b, t + 0.12, m(84), 0.3, { type: 'triangle', vol: 0.15 }); },
    heart: function (b, t) { tone(b, t, m(76), 0.22, { vol: 0.4 }); tone(b, t + 0.15, m(81), 0.5, { vol: 0.4 }); tone(b, t, m(64), 0.7, { type: 'triangle', vol: 0.14 }); },
    clap: function (b, t) { claps(b, t, 12, 0.12); claps(b, t + 0.05, 8, 0.15); },
    chime: function (b, t) { [84, 88, 91, 96].forEach(function (n, i) { bell(b, t + i * 0.09, m(n), 1.1, 0.2); }); },
    whoosh: function (b, t) { noise(b, t, 1.1, { type: 'bandpass', f: 250, to: 2600, q: 0.8, vol: 0.6, attack: 0.3 }); for (var i = 0; i < 9; i++) { noise(b, t + 0.25 + i * 0.09, 0.025, { type: 'highpass', f: 3000, vol: 0.2 }); } },
    sparkle: function (b, t) { sparkles(b, t, 14, 1.1); bell(b, t, m(91), 1.2, 0.12); },
    balloon: function (b, t) { tone(b, t, 300, 0.35, { to: 720, vol: 0.25 }); noise(b, t + 0.38, 0.09, { type: 'lowpass', f: 3500, vol: 0.8 }); thump(b, t + 0.38, 170, 60, 0.12, 0.45); },
    sweet: function (b, t) { [79, 83, 86, 91].forEach(function (n, i) { bell(b, t + i * 0.13, m(n), 1.1, 0.2); }); },
    unwrap: function (b, t) { noise(b, t, 0.4, { type: 'bandpass', f: 4200, q: 0.6, vol: 0.2 }); [72, 76, 79, 84].forEach(function (n, i) { tone(b, t + 0.42 + i * 0.08, m(n), 0.35, { type: 'triangle', vol: 0.25 }); }); sparkles(b, t + 0.75, 10, 0.9); },
    melody: function (b, t) { [72, 76, 79, 77, 81, 84].forEach(function (n, i) { tone(b, t + i * 0.2, m(n), 0.34, { type: 'triangle', vol: 0.28 }); }); tone(b, t, m(48), 1.3, { type: 'triangle', vol: 0.15 }); },
    crystal: function (b, t) { [0, 4, 7, 12, 7, 16].forEach(function (k, i) { bell(b, t + i * 0.09, m(91 + k), 1.4, 0.12); }); sparkles(b, t + 0.6, 10, 1.2); },
    mic: function (b, t) { noise(b, t, 0.05, { type: 'bandpass', f: 900, vol: 0.3 }); noise(b, t + 0.12, 0.05, { type: 'bandpass', f: 900, vol: 0.3 }); brass(b, t + 0.3, m(67), 0.22); brass(b, t + 0.52, m(72), 1.0); brass(b, t + 0.52, m(76), 1.0, 0.1); },
    popper: function (b, t) { noise(b, t, 0.1, { type: 'lowpass', f: 2600, vol: 0.9 }); thump(b, t, 220, 60, 0.1, 0.6); sparkles(b, t + 0.15, 16, 1.3); claps(b, t + 0.4, 6, 0.14); },
    medal: function (b, t) { bell(b, t, m(88), 1.8, 0.28); bell(b, t + 0.14, m(95), 1.8, 0.2); sparkles(b, t + 0.3, 8, 1); },
    royal: function (b, t) { thump(b, t, 70, 45, 0.6, 0.6); [67, 72, 76, 79].forEach(function (n, i) { brass(b, t + i * 0.17, m(n), i === 3 ? 1.3 : 0.24); }); brass(b, t + 0.51, m(72), 1.3, 0.1); thump(b, t + 0.51, 70, 45, 0.7, 0.6); bell(b, t + 0.6, m(96), 1.5, 0.1); },
    fanfare: function (b, t) { [[72, 0.12], [72, 0.12], [72, 0.12], [79, 1.1]].reduce(function (at, n) { brass(b, t + at, m(n[0]), n[1] + 0.05); return at + n[1] + 0.03; }, 0); brass(b, t + 0.45, m(76), 1.1, 0.1); brass(b, t + 0.45, m(84), 1.1, 0.08); claps(b, t + 0.6, 10, 0.13); },
    boom: function (b, t) { boom(b, t, false); boom(b, t + 0.9, false); boom(b, t + 1.8, true); },
    launch: function (b, t) { noise(b, t, 3, { type: 'lowpass', f: 180, to: 2200, vol: 0.7, attack: 0.5, slide: 2.5 }); tone(b, t, 70, 3, { to: 520, type: 'sawtooth', vol: 0.07, lp: 600, attack: 0.4 }); },
    crowd: function (b, t) { noise(b, t, 3.4, { type: 'bandpass', f: 1100, q: 0.45, vol: 0.32, attack: 0.7 }); noise(b, t + 0.2, 3, { type: 'bandpass', f: 2600, q: 0.6, vol: 0.12, attack: 0.6 }); claps(b, t + 0.3, 20, 0.13); },
    party: function (b, t) { [[72, 0], [72, 0.2], [74, 0.4], [72, 0.6], [77, 0.8], [76, 1.0]].forEach(function (n) { tone(b, t + n[1], m(n[0]), 0.3, { type: 'square', vol: 0.08, lp: 2400 }); }); [48, 53].forEach(function (n, i) { tone(b, t + i * 0.6, m(n), 0.55, { type: 'triangle', vol: 0.2 }); }); sparkles(b, t + 1.2, 10, 1); },
    drum: function (b, t) { for (var i = 0; i < 18; i++) { thump(b, t + i * 0.055, 160, 90, 0.08, 0.15 + i * 0.025); } thump(b, t + 1.05, 110, 42, 1.1, 0.9); noise(b, t + 1.05, 0.5, { type: 'lowpass', f: 900, vol: 0.4 }); thump(b, t + 1.55, 130, 50, 0.5, 0.6); thump(b, t + 1.8, 130, 50, 0.6, 0.7); },
    dj: function (b, t) { for (var i = 0; i < 8; i++) { thump(b, t + i * 0.26, 140, 45, 0.25, 0.8); noise(b, t + i * 0.26 + 0.13, 0.04, { type: 'highpass', f: 7000, vol: 0.18 }); } noise(b, t + 0.52, 0.35, { type: 'bandpass', f: 600, to: 2400, q: 3, vol: 0.4, slide: 0.12 }); noise(b, t + 1.04, 0.3, { type: 'bandpass', f: 2400, to: 500, q: 3, vol: 0.4 }); [36, 36, 39, 41].forEach(function (n, i) { tone(b, t + i * 0.52, m(n), 0.45, { type: 'sawtooth', vol: 0.1, lp: 400 }); }); },
    kick: function (b, t) { thump(b, t, 200, 60, 0.18, 0.9); noise(b, t, 0.06, { type: 'lowpass', f: 1200, vol: 0.6 }); noise(b, t + 0.35, 2.4, { type: 'bandpass', f: 1200, q: 0.5, vol: 0.32, attack: 0.3 }); claps(b, t + 0.5, 12, 0.13); },
    engine: function (b, t) { var o = { type: 'sawtooth', vol: 0.16, lp: 700, attack: 0.05 }; tone(b, t, 55, 0.9, Object.assign({ to: 120, slide: 0.5 }, o)); tone(b, t + 0.8, 70, 1, Object.assign({ to: 160, slide: 0.45 }, o)); tone(b, t + 1.7, 80, 1.8, Object.assign({ to: 210, slide: 0.6 }, o)); noise(b, t, 3.2, { type: 'lowpass', f: 300, vol: 0.2, attack: 0.2 }); },
    shutter: function (b, t) { for (var i = 0; i < 4; i++) { var at = t + i * 0.55; noise(b, at, 0.02, { type: 'highpass', f: 3500, vol: 0.6 }); noise(b, at + 0.07, 0.03, { type: 'highpass', f: 2500, vol: 0.5 }); tone(b, at + 0.12, 1300, 0.18, { to: 1900, type: 'sawtooth', vol: 0.03, lp: 3000 }); } },
    tick: function (b, t) { for (var i = 0; i < 10; i++) { noise(b, t + i * 0.25, 0.012, { type: 'highpass', f: i % 2 ? 5000 : 6500, vol: 0.35 }); } bell(b, t + 2.6, m(88), 1.6, 0.22); bell(b, t + 2.6, m(84), 1.6, 0.16); },
    jet: function (b, t) { noise(b, t, 3.6, { type: 'highpass', f: 300, to: 3200, vol: 0.4, attack: 0.6, slide: 3 }); tone(b, t, 900, 3.4, { to: 1900, vol: 0.05, attack: 0.5 }); noise(b, t, 3.6, { type: 'lowpass', f: 140, vol: 0.45, attack: 0.4 }); },
    horn: function (b, t) { [0, 1.3].forEach(function (d) { tone(b, t + d, 98, 1.0, { type: 'sawtooth', vol: 0.18, lp: 700, attack: 0.08 }); tone(b, t + d, 147, 1.0, { type: 'sawtooth', vol: 0.12, lp: 900, attack: 0.08 }); }); noise(b, t, 4.5, { type: 'lowpass', f: 500, vol: 0.18, attack: 1 }); bell(b, t + 2.8, m(84), 1.6, 0.14); },
    roar: function (b, t) { var dist = ctx.createWaveShaper(), c = new Float32Array(256); for (var i = 0; i < 256; i++) { var x = i / 128 - 1; c[i] = Math.tanh(3 * x); } dist.curve = c; dist.connect(b);
      tone(dist, t + 0.2, 120, 1.8, { to: 55, type: 'sawtooth', vol: 0.3, attack: 0.15, lp: 900, lpTo: 300 }); tone(dist, t + 0.2, 123, 1.8, { to: 58, type: 'sawtooth', vol: 0.2, attack: 0.15, lp: 700 });
      noise(b, t + 0.2, 1.8, { type: 'lowpass', f: 900, to: 250, vol: 0.5, attack: 0.15 }); boom(b, t + 2.4, true); },
    tour: function (b, t) { noise(b, t, 2, { type: 'highpass', f: 400, to: 2600, vol: 0.28, attack: 0.5 }); [72, 76, 79, 84, 88].forEach(function (n, i) { bell(b, t + 1.6 + i * 0.15, m(n), 1.4, 0.16); }); brass(b, t + 2.5, m(72), 1.4, 0.1); brass(b, t + 2.5, m(79), 1.4, 0.08); },
    nova: function (b, t) { noise(b, t, 1.8, { type: 'bandpass', f: 200, to: 5000, q: 1.5, vol: 0.4, attack: 1.5, slide: 1.8 }); tone(b, t, 180, 1.8, { to: 1400, type: 'sawtooth', vol: 0.06, attack: 1.4, lp: 2400 }); boom(b, t + 1.85, true); sparkles(b, t + 2.2, 24, 2.4); bell(b, t + 2.4, m(96), 2.4, 0.1); },
    finale: function (b, t) { R.fanfare(b, t); boom(b, t + 1.4, false); boom(b, t + 2.1, false); boom(b, t + 2.8, false); boom(b, t + 3.6, true); sparkles(b, t + 3.9, 26, 2.6); },
    cosmic: function (b, t) {
      // a slow cosmic pad that opens up, a deep hit as the galaxy arrives, shimmering stars, and a bright finale chord
      [48, 55, 60, 64, 67, 71].forEach(function (n, i) { tone(b, t, m(n), 8.2, { type: 'sawtooth', vol: 0.045, attack: 1.6, detune: i % 2 ? 7 : -7, lp: 220, lpTo: 2600 }); });
      thump(b, t + 1.0, 70, 28, 2.2, 0.8); noise(b, t + 1.0, 2.2, { type: 'lowpass', f: 800, to: 90, vol: 0.35 });
      for (var i = 0; i < 22; i++) { bell(b, t + 1.4 + i * 0.25 + rnd(0, 0.1), m(84 + [0, 2, 4, 7, 9, 12][i % 6]), 1.2, 0.05); }
      boom(b, t + 6.6, true); [72, 76, 79, 84, 88].forEach(function (n) { bell(b, t + 6.7, m(n), 2.6, 0.12); }); brass(b, t + 6.7, m(60), 2.4, 0.08);
    }
  };

  function play(style, asset) {
    if (!isOn()) { return; }
    stop();
    if (asset) {
      try { audioEl = new Audio(asset); audioEl.volume = 0.75; var p = audioEl.play(); if (p && p.catch) { p.catch(function () { audioEl = null; }); } } catch (e) { audioEl = null; }
      return;
    }
    var f = R[style] || R.pop;
    if (!ac()) { return; }
    var bus = ctx.createGain(); bus.gain.value = 1; bus.connect(master);
    current = bus;
    try { f(bus, ctx.currentTime + 0.03); } catch (e) { /* an old browser missing a node: stay silent rather than break the gift */ }
    setTimeout(function () { if (current === bus) { current = null; } try { bus.disconnect(); } catch (e) { /* ignore */ } }, 11000);
  }

  // self-check for tests: builds every recipe on a silent bus and returns the styles that fail (an empty list = all fine)
  function check() {
    if (!ac()) { return ['no Web Audio']; }
    var bad = [], bus = ctx.createGain(); bus.gain.value = 0; bus.connect(master);
    Object.keys(R).forEach(function (k) { try { R[k](bus, ctx.currentTime + 0.05); } catch (e) { bad.push(k + ': ' + e.message); } });
    setTimeout(function () { try { bus.disconnect(); } catch (e) { /* ignore */ } }, 11000);
    return bad;
  }

  window.ObGiftSound = { play: play, stop: stop, isOn: isOn, set: set, unlock: unlock, onChange: function (f) { listeners.push(f); }, styles: function () { return Object.keys(R); }, check: check };
})();
