/* Photo slideshows that change on the beat, and sound that rides along with a video or photo.
   Used by the viewer (moments.js) and by the organizer's live preview (org-moments.js).

   The audio element is the clock: the photo on screen is worked out from where the music is, so cuts
   stay on the beat even if the browser stalls. With no sound, a timer keeps the same pace. The music
   keeps running (muted) when a visitor has sound off, so turning it on joins in mid-beat.

     var p = SlideshowPlayer.create({ stack, segs, flash, urls, beats, fx, bpm, sound: {src,start,volume}, onChange });
     p.play(muted); p.pause(); p.setMuted(bool); p.goto(i); p.next(); p.prev(); p.update({...}); p.destroy();

     var t = SoundTrack.create({ src, start, volume, loopSeconds });
     t.attachVideo(videoEl); t.play(muted); t.pause(); t.setMuted(bool); t.destroy();
*/
(function (g) {
  'use strict';

  var DEFAULT_SLIDE_SECONDS = 2.5;

  function makeAudio(src, volume) {
    var a = new Audio();
    a.preload = 'auto';
    a.loop = false;
    a.volume = Math.max(0, Math.min(1, volume == null ? 1 : volume));
    a.src = src;
    return a;
  }

  /* ---------- slideshow ---------- */
  function SlideshowPlayer(o) {
    this.o = o;
    this.cur = -1;
    this.running = false;
    this.muted = true;
    this.raf = 0;
    this.t0 = 0;          // timer clock: performance.now() when the cycle began
    this.pausedAt = 0;
    this.audio = null;
    this.slides = [];
    this.segEls = [];
    this.build();
    this.configureAudio();
  }

  SlideshowPlayer.prototype.n = function () { return this.o.urls.length; };
  SlideshowPlayer.prototype.slideSeconds = function () {
    return this.o.sound && this.o.bpm ? this.o.beats * 60 / this.o.bpm : DEFAULT_SLIDE_SECONDS;
  };
  SlideshowPlayer.prototype.cycle = function () { return this.slideSeconds() * this.n(); };

  SlideshowPlayer.prototype.build = function () {
    var o = this.o, self = this;
    o.stack.innerHTML = '';
    this.slides = o.urls.map(function (u, i) {
      var s = document.createElement('div');
      s.className = 'mv-slide';
      var im = document.createElement('img');
      im.alt = '';
      im.draggable = false;
      im.src = u;
      s.appendChild(im);
      o.stack.appendChild(s);
      return s;
    });
    this.applyFx();
    if (o.segs) {
      o.segs.innerHTML = '';
      this.segEls = o.urls.map(function () { var i = document.createElement('i'), b = document.createElement('b'); i.appendChild(b); o.segs.appendChild(i); return i; });
    }
    this.show(0, true);
  };

  SlideshowPlayer.prototype.applyFx = function () {
    var st = this.o.stack;
    st.classList.remove('fx-cut', 'fx-fade', 'fx-zoom', 'fx-flash');
    st.classList.add('fx-' + (this.o.fx || 'zoom'));
  };

  SlideshowPlayer.prototype.configureAudio = function () {
    var o = this.o;
    if (this.audio) { try { this.audio.pause(); } catch (e) { /* ignore */ } this.audio = null; }
    if (o.sound && o.sound.src) {
      this.audio = makeAudio(o.sound.src, o.sound.volume);
      this.audio.muted = this.muted;
    }
  };

  /** Seconds into the cycle, from the music if it is playing, else from the timer. */
  SlideshowPlayer.prototype.clock = function () {
    var a = this.audio, sd = this.o.sound, cyc = this.cycle();
    if (a && sd && !a.paused && !a.ended) {
      var t = a.currentTime - (sd.start || 0);
      if (t >= cyc - 0.02 || t < -0.3) { a.currentTime = sd.start || 0; t = 0; }
      return Math.max(0, t);
    }
    return ((performance.now() - this.t0) / 1000) % cyc;
  };

  SlideshowPlayer.prototype.show = function (i, silent) {
    var o = this.o;
    this.cur = i;
    this.slides.forEach(function (s, k) { s.classList.toggle('cur', k === i); });
    if (this.segEls.length) this.segEls.forEach(function (e, k) { e.classList.toggle('done', k < i); e.firstChild.style.width = k < i ? '100%' : '0%'; });
    if (!silent) {
      var fx = o.fx || 'zoom', s = this.slides[i];
      if (fx === 'zoom' && s) { s.style.animation = 'none'; void s.offsetWidth; s.style.animation = ''; }
      if (fx === 'flash' && o.flash) { o.flash.classList.remove('go'); void o.flash.offsetWidth; o.flash.classList.add('go'); }
    }
    if (o.onChange) o.onChange(i);
  };

  SlideshowPlayer.prototype.tick = function () {
    if (!this.running) return;
    var sec = this.slideSeconds(), t = this.clock(), i = Math.min(this.n() - 1, Math.floor(t / sec));
    if (i !== this.cur) this.show(i, false);
    if (this.segEls[i]) this.segEls[i].firstChild.style.width = (((t - i * sec) / sec) * 100).toFixed(1) + '%';
    var self = this;
    this.raf = setTimeout(function () { self.tick(); }, 40);
  };

  SlideshowPlayer.prototype.play = function (muted) {
    if (this.n() < 1) return;
    var self = this, sd = this.o.sound;
    this.muted = muted !== false;
    this.running = true;
    clearTimeout(this.raf);
    var at = this.cur > 0 ? this.cur * this.slideSeconds() : 0; // resume on the photo we were on
    this.t0 = performance.now() - at * 1000;
    if (this.audio && sd) {
      var a = this.audio;
      a.muted = this.muted;
      var start = function () {
        try { a.currentTime = (sd.start || 0) + at; } catch (e) { /* not seekable yet */ }
        var p = a.play();
        if (p && p.catch) p.catch(function () { /* blocked: the timer clock keeps the pace */ });
      };
      if (a.readyState >= 1) start(); else a.addEventListener('loadedmetadata', function once() { a.removeEventListener('loadedmetadata', once); if (self.running) start(); });
    }
    this.raf = setTimeout(function () { self.tick(); }, 40);
  };

  SlideshowPlayer.prototype.pause = function () {
    this.running = false;
    clearTimeout(this.raf);
    if (this.audio) this.audio.pause();
  };

  SlideshowPlayer.prototype.setMuted = function (m) {
    this.muted = !!m;
    var a = this.audio, sd = this.o.sound;
    if (!a || !sd) return;
    a.muted = this.muted;
    // sound was blocked while muted (some phones): join in on the right beat now there has been a tap
    if (this.running && a.paused) {
      var t = this.clock();
      try { a.currentTime = (sd.start || 0) + t; } catch (e) { /* ignore */ }
      var p = a.play();
      if (p && p.catch) p.catch(function () {});
    }
  };

  SlideshowPlayer.prototype.goto = function (i) {
    var n = this.n();
    i = ((i % n) + n) % n;
    var sec = this.slideSeconds(), sd = this.o.sound;
    this.t0 = performance.now() - i * sec * 1000;
    if (this.audio && sd && !this.audio.paused) {
      try { this.audio.currentTime = (sd.start || 0) + i * sec; } catch (e) { /* ignore */ }
    }
    this.show(i, false);
  };
  SlideshowPlayer.prototype.next = function () { this.goto(this.cur + 1); };
  SlideshowPlayer.prototype.prev = function () { this.goto(this.cur - 1); };

  /** Change pace, transition or sound on the fly (the composer's live preview). */
  SlideshowPlayer.prototype.update = function (patch) {
    var soundChanged = 'sound' in patch;
    for (var k in patch) this.o[k] = patch[k];
    this.applyFx();
    if (soundChanged) {
      var wasRunning = this.running, muted = this.muted;
      this.configureAudio();
      if (wasRunning) this.play(muted);
    } else if (this.running) {
      this.goto(this.cur < 0 ? 0 : this.cur);
    }
  };

  SlideshowPlayer.prototype.destroy = function () {
    this.running = false;
    clearTimeout(this.raf);
    if (this.audio) { this.audio.pause(); this.audio.removeAttribute('src'); this.audio.load(); this.audio = null; }
  };

  /* ---------- sound under a video or a single photo ---------- */
  function SoundTrack(o) {
    this.o = o;
    this.audio = makeAudio(o.src, o.volume);
    this.muted = true;
    this.running = false;
    this.video = null;
    this.lastT = 0;
  }

  SoundTrack.prototype.restart = function (offset) {
    var a = this.audio, start = this.o.start || 0;
    try { a.currentTime = start + (offset || 0); } catch (e) { /* not seekable yet */ }
  };

  SoundTrack.prototype.attachVideo = function (v) {
    var self = this;
    this.video = v;
    v.addEventListener('timeupdate', function () {
      // the video looped: start the music over with it
      if (v.currentTime + 0.3 < self.lastT && self.running) self.restart(v.currentTime);
      self.lastT = v.currentTime;
    });
    v.addEventListener('seeked', function () { if (self.running) self.restart(v.currentTime); });
    v.addEventListener('pause', function () { if (self.running) self.audio.pause(); });
    v.addEventListener('play', function () { if (self.running) { self.restart(v.currentTime); self.audio.play().catch(function () {}); } });
  };

  SoundTrack.prototype.play = function (muted) {
    var self = this, a = this.audio;
    this.muted = muted !== false;
    this.running = true;
    a.muted = this.muted;
    var begin = function () {
      self.restart(self.video ? self.video.currentTime : 0);
      var p = a.play();
      if (p && p.catch) p.catch(function () {});
    };
    if (a.readyState >= 1) begin(); else a.addEventListener('loadedmetadata', function once() { a.removeEventListener('loadedmetadata', once); if (self.running) begin(); });
    // a photo with music loops its chosen part so the sound never just stops
    if (!this.video) {
      clearInterval(this.loopTimer);
      var span = this.o.loopSeconds || 30;
      this.loopTimer = setInterval(function () { if (a.currentTime >= (self.o.start || 0) + span - 0.05 || a.ended) self.restart(0); }, 200);
    }
  };

  SoundTrack.prototype.pause = function () {
    this.running = false;
    clearInterval(this.loopTimer);
    this.audio.pause();
  };

  SoundTrack.prototype.setMuted = function (m) {
    this.muted = !!m;
    this.audio.muted = this.muted;
    if (this.running && this.audio.paused) {
      this.restart(this.video ? this.video.currentTime : 0);
      var p = this.audio.play();
      if (p && p.catch) p.catch(function () {});
    }
  };

  SoundTrack.prototype.destroy = function () {
    this.running = false;
    clearInterval(this.loopTimer);
    this.audio.pause();
    this.audio.removeAttribute('src');
    this.audio.load();
  };

  g.SlideshowPlayer = { create: function (o) { return new SlideshowPlayer(o); } };
  g.SoundTrack = { create: function (o) { return new SoundTrack(o); } };
})(window);
