/* Header navigation: the shared "morphing" panel on desktop and the expanding groups in the phone drawer.
   Pure enhancement: every item is a normal link, so with this script off the header still works (the panel simply never opens). */
(function () {
  'use strict';
  var nav = document.getElementById('siteNav');
  var pop = document.getElementById('nvPop');
  var notch = nav && nav.querySelector('.nv-notch');
  var row = nav && nav.querySelector('.nav-row');

  /* ---------- desktop panel ---------- */
  if (nav && pop && notch && row) {
    var trigs = Array.prototype.slice.call(nav.querySelectorAll('[data-pop]'));
    var secs = Array.prototype.slice.call(pop.querySelectorAll('.nv-sec'));
    var timer = null;

    secs.forEach(function (s) { s.style.setProperty('--sw', s.getAttribute('data-w') + 'px'); });

    var closeAll = function () {
      pop.classList.remove('open'); notch.classList.remove('open');
      trigs.forEach(function (b) { b.classList.remove('hot'); b.setAttribute('aria-expanded', 'false'); });
    };
    var open = function (b) {
      var sec = pop.querySelector('[data-sec="' + b.getAttribute('data-pop') + '"]');
      if (!sec) { return; }
      var w = parseInt(sec.getAttribute('data-w'), 10);
      var h = sec.offsetHeight;
      var nr = nav.getBoundingClientRect(), br = b.getBoundingClientRect();
      var center = br.left - nr.left + br.width / 2;
      var lg = row.querySelector('.logo');
      var edge = lg ? Math.max(14, lg.getBoundingClientRect().left - nr.left) : 14; // line up with the logo when the panel is pushed to the left
      var x = Math.max(edge, Math.min(nr.width - w - edge, center - w / 2));
      var first = !pop.classList.contains('open');
      pop.classList.toggle('jump', first); notch.classList.toggle('jump', first);
      pop.style.setProperty('--w', w + 'px'); pop.style.setProperty('--h', h + 'px'); pop.style.setProperty('--x', x + 'px');
      notch.style.setProperty('--nx', (center - 7) + 'px');
      secs.forEach(function (s) { s.classList.toggle('on', s === sec); });
      trigs.forEach(function (t) { t.classList.toggle('hot', t === b); t.setAttribute('aria-expanded', t === b ? 'true' : 'false'); });
      if (first) { void pop.offsetWidth; }
      pop.classList.add('open'); notch.classList.add('open');
    };
    var later = function () { clearTimeout(timer); timer = setTimeout(closeAll, 200); };
    var hold = function () { clearTimeout(timer); };

    trigs.forEach(function (b) {
      b.addEventListener('mouseenter', function () { hold(); open(b); });
      b.addEventListener('focus', function () { open(b); });
      b.addEventListener('mouseleave', later);
      b.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
          e.preventDefault(); open(b);
          var first = pop.querySelector('.nv-sec.on a');
          if (first) { first.focus(); }
        }
      });
    });
    pop.addEventListener('mouseenter', hold);
    pop.addEventListener('mouseleave', later);
    pop.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var t = nav.querySelector('[data-pop].hot');
        closeAll(); if (t) { t.focus(); }
      }
    });
    Array.prototype.forEach.call(nav.querySelectorAll('.nv-links a:not([data-pop])'), function (a) { a.addEventListener('mouseenter', closeAll); a.addEventListener('focus', closeAll); });
    var logoLink = row.querySelector('.logo');
    if (logoLink) { logoLink.addEventListener('mouseenter', closeAll); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(); } });
    document.addEventListener('click', function (e) { if (!nav.contains(e.target)) { closeAll(); } });
    window.addEventListener('resize', closeAll);
    window.addEventListener('scroll', function () { if (pop.classList.contains('open') && window.scrollY > 240) { closeAll(); } }, { passive: true });
  }

  /* ---------- phone drawer groups ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('.nav-acc-btn'), function (btn) {
    btn.addEventListener('click', function () {
      var sub = document.getElementById(btn.getAttribute('aria-controls'));
      var open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (sub) { sub.classList.toggle('open', open); }
    });
  });
})();
