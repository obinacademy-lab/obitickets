/* Home page: "Your next event". Each slide has a live countdown; with more than one upcoming ticket the slides
   move sideways (arrows, dots, swipe, and a slow auto-slide that pauses on hover, focus and touch).
   Optional: without script the slides still scroll sideways and every one has its View ticket button. */
(function () {
  'use strict';
  var box = document.getElementById('ldNext');
  if (!box) return;
  var track = box.querySelector('.ld-nx-track');
  var slides = [].slice.call(box.querySelectorAll('.ld-nx-slide'));

  /* countdowns */
  function tick() {
    var t = Date.now();
    slides.forEach(function (s) {
      var cd = s.querySelector('.ld-cd');
      if (!cd) return;
      var start = parseInt(s.getAttribute('data-start'), 10) * 1000, end = parseInt(s.getAttribute('data-end'), 10) * 1000;
      if (t >= start && t <= end) { cd.textContent = 'Happening now'; return; }
      if (t > end) { cd.textContent = ''; return; }
      var m = Math.floor((start - t) / 60000), d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60), mm = m % 60;
      cd.textContent = (d > 0 ? d + 'd ' : '') + h + 'h ' + mm + 'm';
    });
  }
  tick();
  setInterval(tick, 30000);

  if (box.getAttribute('data-multi') !== '1' || !track || slides.length < 2) return;

  /* slider */
  var dots = [].slice.call(box.querySelectorAll('.ld-nx-dot'));
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var cur = 0, timer = null, paused = false;

  function mark(i) {
    cur = i;
    slides.forEach(function (s, k) { s.classList.toggle('is-on', k === i); });
    dots.forEach(function (d, k) { d.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
  }
  function go(i) {
    i = (i + slides.length) % slides.length;
    track.scrollTo({ left: slides[i].offsetLeft - track.offsetLeft, behavior: reduce ? 'auto' : 'smooth' });
    mark(i);
  }
  function nearest() {
    var best = 0, bd = Infinity;
    slides.forEach(function (s, k) { var d = Math.abs((s.offsetLeft - track.offsetLeft) - track.scrollLeft); if (d < bd) { bd = d; best = k; } });
    return best;
  }
  var raf = 0;
  track.addEventListener('scroll', function () {
    if (raf) return;
    raf = requestAnimationFrame(function () { raf = 0; var n = nearest(); if (n !== cur) mark(n); });
  }, { passive: true });

  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-nx]');
    if (b) { go(cur + (b.getAttribute('data-nx') === 'next' ? 1 : -1)); restart(); return; }
    var d = e.target.closest('.ld-nx-dot');
    if (d) { go(parseInt(d.getAttribute('data-i'), 10)); restart(); }
  });
  track.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') { go(cur + 1); restart(); e.preventDefault(); }
    if (e.key === 'ArrowLeft') { go(cur - 1); restart(); e.preventDefault(); }
  });

  /* slow auto-slide, off for reduced motion, paused while the person is interacting or the tab is hidden */
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function start() { if (reduce || paused || timer) return; timer = setInterval(function () { if (!document.hidden) go(cur + 1); }, 5500); }
  function restart() { stop(); start(); }
  ['mouseenter', 'focusin', 'touchstart'].forEach(function (ev) { box.addEventListener(ev, function () { paused = true; stop(); }, { passive: true }); });
  ['mouseleave', 'focusout'].forEach(function (ev) { box.addEventListener(ev, function () { paused = false; start(); }); });
  box.addEventListener('touchend', function () { setTimeout(function () { paused = false; start(); }, 4000); }, { passive: true });
  window.addEventListener('resize', function () { track.scrollTo({ left: slides[cur].offsetLeft - track.offsetLeft, behavior: 'auto' }); });

  mark(0);
  start();
})();
