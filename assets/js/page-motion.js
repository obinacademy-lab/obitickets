/* Shared motion for the premium pages (pricing.php, team.php): reveal on scroll, headline words, count-ups,
   sentences that light up as you read, parallax, tilt and magnetic buttons, page progress. Plain JS. Attributes:
   data-in="up|down|left|right|zoom|flip|clip|clipup|split"  data-split  data-scrub  data-count (+data-from/-pre/-suf/-dur)
   data-prog + data-par  data-pagep  data-tilt  data-mag  data-jump="id" */
(function () {
  'use strict';
  var root = document.documentElement;
  var RM = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var HOVER = !!(window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches);
  var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)); };
  function $$(s, r) { return [].slice.call((r || document).querySelectorAll(s)); }
  function fmt(n) { return Math.round(n).toLocaleString('en-US'); }

  function words(h) {
    var i = 0, out = '';
    h.textContent.trim().split(/\s+/).forEach(function (w) { out += '<span class="pm-w"><span style="--i:' + (i++) + '">' + w + '</span></span> '; });
    h.setAttribute('aria-label', h.textContent.trim());
    h.innerHTML = out;
  }
  function scrubWords(p) {
    var out = '';
    p.textContent.trim().split(/\s+/).forEach(function (w) { out += '<span class="pm-sw">' + w + '</span> '; });
    p.innerHTML = out;
  }
  function setCount(el, v) { el.textContent = (el.getAttribute('data-pre') || '') + fmt(v) + (el.getAttribute('data-suf') || ''); }
  function runCount(el) {
    if (el.classList.contains('cnt')) return;
    el.classList.add('cnt');
    var to = +el.getAttribute('data-count'), from = +(el.getAttribute('data-from') || 0), dur = +(el.getAttribute('data-dur') || 1600), t0 = null;
    if (RM) { setCount(el, to); return; }
    requestAnimationFrame(function step(t) {
      if (t0 === null) t0 = t;
      var k = clamp((t - t0) / dur, 0, 1), e = k === 1 ? 1 : 1 - Math.pow(2, -10 * k);
      setCount(el, from + (to - from) * e);
      if (k < 1) requestAnimationFrame(step);
    });
  }

  var vh = window.innerHeight;
  function reveal() {
    $$('[data-in]:not(.in)').forEach(function (el) { if (el.getBoundingClientRect().top < vh - 40) el.classList.add('in'); });
    $$('[data-count]:not(.cnt)').forEach(function (el) { if (el.getBoundingClientRect().top < vh - 20) runCount(el); });
  }
  function prog() {
    $$('[data-prog]').forEach(function (el) {
      var r = el.getBoundingClientRect();
      el.style.setProperty('--p', clamp((vh - r.top) / (vh + r.height), 0, 1).toFixed(4));
    });
    $$('[data-scrub]').forEach(function (el) {
      var r = el.getBoundingClientRect(), p = clamp((vh - vh * .16 - r.top) / (r.height + vh * .42), 0, 1), ws = el.children, k = Math.round(p * ws.length);
      for (var i = 0; i < ws.length; i++) ws[i].classList.toggle('on', i < k);
    });
    $$('[data-pagep]').forEach(function (el) {
      var max = document.documentElement.scrollHeight - vh;
      el.style.setProperty('--pp', clamp(window.pageYOffset / Math.max(1, max), 0, 1).toFixed(4));
    });
  }

  var ticking = false;
  function onScroll() { if (ticking) return; ticking = true; requestAnimationFrame(function () { ticking = false; reveal(); prog(); }); }

  function pointer(e) {
    var t = e.target.closest ? e.target.closest('[data-tilt]') : null;
    if (pointer.tilt && pointer.tilt !== t) pointer.tilt.style.transform = '';
    if (t) { var b = t.getBoundingClientRect(), x = (e.clientX - b.left) / b.width - .5, y = (e.clientY - b.top) / b.height - .5; t.style.transform = 'perspective(900px) rotateY(' + (x * 12).toFixed(2) + 'deg) rotateX(' + (-y * 10).toFixed(2) + 'deg)'; }
    pointer.tilt = t;
    var m = e.target.closest ? e.target.closest('[data-mag]') : null;
    if (pointer.mag && pointer.mag !== m) pointer.mag.style.transform = '';
    if (m) { var r = m.getBoundingClientRect(); m.style.transform = 'translate(' + ((e.clientX - r.left - r.width / 2) * .2).toFixed(1) + 'px,' + ((e.clientY - r.top - r.height / 2) * .28).toFixed(1) + 'px)'; }
    pointer.mag = m;
  }

  function init() {
    $$('[data-split]').forEach(words);
    $$('[data-scrub]').forEach(scrubWords);
    if (RM) {
      $$('[data-in]').forEach(function (e) { e.classList.add('in'); });
      $$('[data-count]').forEach(function (e) { setCount(e, +e.getAttribute('data-count')); e.classList.add('cnt'); });
      $$('[data-scrub] .pm-sw').forEach(function (w) { w.classList.add('on'); });
      window.__pmReady = true; root.classList.remove('pm');
      return;
    }
    $$('[data-count]').forEach(function (e) { setCount(e, +(e.getAttribute('data-from') || 0)); });
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', function () { vh = window.innerHeight; onScroll(); });
    if (HOVER) document.addEventListener('mousemove', pointer);
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('[data-jump]') : null;
      if (!a) return;
      var t = document.getElementById(a.getAttribute('data-jump'));
      if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
    window.__pmReady = true;
    reveal(); prog();
    window.addEventListener('load', function () { vh = window.innerHeight; reveal(); prog(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
