/* Event page motion layer. Pure decoration: every hook the page and checkout
   rely on (#cd-text, #likeBtn, #buyPanel, .qty .n, #total-amount ...) is left
   exactly as it was; this file only watches them and adds animation around them.
   If this script fails to run, the page is complete and static. */
(function () {
  'use strict';
  var root = document.getElementById('evRoot');
  if (!root) return;

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var hero = root.querySelector('.ev-hero');
  document.body.classList.add('ev-motion');

  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

  /* ---------- title: split into words so they can rise out of a mask ---------- */
  var title = $('.ev-title');
  if (title && !reduce) {
    var text = title.textContent.trim();
    title.setAttribute('aria-label', text);
    title.textContent = '';
    text.split(/\s+/).forEach(function (word, i, all) {
      var w = document.createElement('span');
      w.className = 'ev-w';
      w.setAttribute('aria-hidden', 'true');
      w.style.setProperty('--wi', i);
      var inner = document.createElement('span');
      inner.textContent = word;
      w.appendChild(inner);
      title.appendChild(w);
      if (i < all.length - 1) title.appendChild(document.createTextNode(' '));
    });
  }

  /* ---------- glow behind the hero, tinted from the poster ---------- */
  if (hero) {
    var glow = document.createElement('div');
    glow.className = 'ev-glow';
    glow.setAttribute('aria-hidden', 'true');
    glow.innerHTML = '<i></i><i></i><i></i>';
    hero.insertBefore(glow, hero.firstChild);

    var img = $('.ev-cover img');
    var sample = function () {
      try {
        var c = document.createElement('canvas');
        c.width = c.height = 24;
        var ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, 24, 24);
        var d = ctx.getImageData(0, 0, 24, 24).data;
        var r = 0, g = 0, b = 0, wsum = 0;
        for (var i = 0; i < d.length; i += 4) {
          if (d[i + 3] < 200) continue;
          var mx = Math.max(d[i], d[i + 1], d[i + 2]);
          var mn = Math.min(d[i], d[i + 1], d[i + 2]);
          var sat = mx - mn;
          var wgt = sat * sat + 1;
          // ignore near-white and near-black pixels, they carry no colour
          if (mx > 245 && sat < 20) continue;
          if (mx < 40) continue;
          r += d[i] * wgt; g += d[i + 1] * wgt; b += d[i + 2] * wgt; wsum += wgt;
        }
        if (!wsum) return;
        r /= wsum; g /= wsum; b /= wsum;
        var top = Math.max(r, g, b) || 1;
        var k = 235 / top; // lift dark posters so the glow stays visible
        hero.style.setProperty('--glow', Math.round(Math.min(255, r * k)) + ',' + Math.round(Math.min(255, g * k)) + ',' + Math.round(Math.min(255, b * k)));
      } catch (e) { /* keep the default red glow */ }
    };
    if (img) {
      if (img.complete && img.naturalWidth) sample();
      else img.addEventListener('load', sample, { once: true });
    }
  }

  /* ---------- poster: finish intro, then tilt toward the pointer ---------- */
  var cover = $('.ev-cover');
  if (cover) {
    var sheen = document.createElement('div');
    sheen.className = 'ev-shine';
    sheen.setAttribute('aria-hidden', 'true');
    cover.appendChild(sheen);
    var ready = function () { cover.classList.add('is-ready'); };
    cover.addEventListener('animationend', ready, { once: true });
    setTimeout(ready, 2500);

    if (!reduce && canHover) {
      cover.addEventListener('pointermove', function (e) {
        if (e.pointerType && e.pointerType !== 'mouse') return;
        var r = cover.getBoundingClientRect();
        var x = (e.clientX - r.left) / r.width;
        var y = (e.clientY - r.top) / r.height;
        cover.classList.add('is-tilting');
        cover.style.transform = 'perspective(1100px) rotateY(' + ((x - 0.5) * 10).toFixed(2) + 'deg) rotateX(' + ((0.5 - y) * 8).toFixed(2) + 'deg)';
        cover.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
        cover.style.setProperty('--my', (y * 100).toFixed(1) + '%');
      });
      cover.addEventListener('pointerleave', function () {
        cover.classList.remove('is-tilting');
        cover.style.transform = '';
      });
    }
  }

  /* ---------- countdown tiles (the #cd-text line is still maintained by main.js) ---------- */
  var cdHost = $('[data-countdown-target]');
  if (cdHost && title) {
    var raw = String(cdHost.getAttribute('data-countdown-target') || '');
    var target = new Date(/^\d{4}-\d\d-\d\d \d/.test(raw) ? raw.replace(' ', 'T') : raw);
    if (!isNaN(target) && target - new Date() > 0) {
      var wrap = document.createElement('div');
      wrap.className = 'ev-cd';
      wrap.setAttribute('role', 'timer');
      wrap.setAttribute('aria-label', 'Time until the event starts');
      var units = [['d', 'Days'], ['h', 'Hours'], ['m', 'Min'], ['s', 'Sec']];
      var cells = {};
      units.forEach(function (u) {
        var cell = document.createElement('div');
        cell.className = 'ev-cd-u' + (u[0] === 's' ? ' is-sec' : '');
        cell.innerHTML = '<b></b><small>' + u[1] + '</small>';
        wrap.appendChild(cell);
        cells[u[0]] = cell.firstChild;
      });
      title.parentNode.insertBefore(wrap, title.nextSibling);
      $('.ev-hero-text').classList.add('ev-has-cd');
      var last = {};
      var stop;
      var paint = function () {
        var s = Math.max(0, Math.floor((target - new Date()) / 1000));
        if (s === 0) {
          clearInterval(stop);
          wrap.remove();
          $('.ev-hero-text').classList.remove('ev-has-cd');
          var textEl = document.getElementById('cd-text');
          if (textEl && textEl.parentNode) textEl.parentNode.innerHTML = '<span class="tk">&#127917;</span> Happening now';
          return;
        }
        var v = { d: Math.floor(s / 86400), h: Math.floor(s % 86400 / 3600), m: Math.floor(s % 3600 / 60), s: s % 60 };
        Object.keys(v).forEach(function (k) {
          var t = String(v[k]);
          if (t.length < 2) t = '0' + t;
          if (last[k] !== t) {
            last[k] = t;
            cells[k].innerHTML = '<span>' + t + '</span>';
          }
        });
      };
      paint();
      stop = setInterval(paint, 1000);
    }
  }

  /* ---------- count-up for the activity numbers ---------- */
  if (!reduce) {
    $$('.ev-pulse-item b').forEach(function (el) {
      var original = el.textContent;
      var to = parseInt(original.replace(/[^\d]/g, ''), 10);
      if (!to) return;
      var t0 = null;
      setTimeout(function () {
        requestAnimationFrame(function step(t) {
          if (t0 === null) t0 = t;
          var p = Math.min(1, (t - t0) / 1200);
          el.textContent = Math.round(to * (1 - Math.pow(1 - p, 4))).toLocaleString('en-US');
          if (p < 1) requestAnimationFrame(step); else el.textContent = original;
        });
      }, 700);
    });
  }

  /* ---------- tabs: sliding underline, lift shadow ---------- */
  var tabs = $('.ev-tabs');
  var row = $('.ev-tabs-row');
  if (tabs && row) {
    var ink = document.createElement('span');
    ink.className = 'ev-tab-ink';
    ink.setAttribute('aria-hidden', 'true');
    row.appendChild(ink);
    row.classList.add('has-ink');
    var moveInk = function () {
      var a = $('a.is-active', row) || $('a', row);
      if (!a) return;
      ink.style.width = a.offsetWidth + 'px';
      ink.style.transform = 'translateX(' + a.offsetLeft + 'px)';
    };
    var mo = new MutationObserver(moveInk);
    $$('a', row).forEach(function (a) { mo.observe(a, { attributes: true, attributeFilter: ['class'] }); });
    window.addEventListener('resize', moveInk);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(moveInk);
    moveInk();

    var stickTop = function () { return parseFloat(getComputedStyle(tabs).top) || 0; };
    var onScroll = function () { tabs.classList.toggle('is-stuck', tabs.getBoundingClientRect().top <= stickTop() + 1); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------- reactions: burst of the chosen emoji ---------- */
  if (!reduce) {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.ev-picker-btn');
      if (!btn || !root.contains(btn)) return;
      var wrapEl = btn.closest('.ev-react-wrap');
      var trigger = wrapEl && wrapEl.querySelector('[data-my]');
      if (trigger && trigger.getAttribute('data-my') === btn.getAttribute('data-reaction')) return; // un-reacting
      var emoji = btn.textContent.trim();
      var r = btn.getBoundingClientRect();
      for (var i = 0; i < 9; i++) {
        var s = document.createElement('span');
        s.className = 'ev-burst';
        s.textContent = emoji;
        s.setAttribute('aria-hidden', 'true');
        s.style.left = (r.left + r.width / 2 - 11) + 'px';
        s.style.top = (r.top + r.height / 2 - 11) + 'px';
        s.style.setProperty('--dx', (Math.random() * 160 - 80).toFixed(0) + 'px');
        s.style.setProperty('--dy', (-60 - Math.random() * 120).toFixed(0) + 'px');
        s.style.setProperty('--r', (Math.random() * 80 - 40).toFixed(0) + 'deg');
        s.style.animationDelay = (i * 30) + 'ms';
        document.body.appendChild(s);
        setTimeout(function (n) { n.remove(); }, 1400, s);
      }
    }, true);
  }

  /* ---------- ticket card: quantity roll, total count-to, button sweep ---------- */
  var buy = document.getElementById('buyPanel');
  if (buy) {
    var buyBtn = $('.poster-foot .btn', buy);
    var sweep = function (el) {
      if (!el || reduce) return;
      el.classList.remove('ev-sweep');
      void el.offsetWidth;
      el.classList.add('ev-sweep');
    };
    $$('.qty .n', buy).forEach(function (n) {
      new MutationObserver(function () {
        var tier = n.closest('.tier');
        if (tier) tier.classList.toggle('has', (parseInt(n.textContent, 10) || 0) > 0);
        if (reduce) return;
        n.classList.remove('ev-bump');
        void n.offsetWidth;
        n.classList.add('ev-bump');
        sweep(buyBtn);
      }).observe(n, { childList: true, characterData: true, subtree: true });
    });

    var total = document.getElementById('total-amount');
    if (total && !reduce) {
      var shown = parseInt(total.textContent.replace(/[^\d]/g, ''), 10) || 0;
      var raf = 0;
      var writing = false;
      new MutationObserver(function () {
        if (writing) return;
        var txt = total.textContent;
        var to = parseInt(txt.replace(/[^\d]/g, ''), 10) || 0;
        var prefix = txt.replace(/[\d,.\s]+$/, '') + ' ';
        var from = shown;
        shown = to;
        cancelAnimationFrame(raf);
        var t0 = null;
        raf = requestAnimationFrame(function step(t) {
          if (t0 === null) t0 = t;
          var p = Math.min(1, (t - t0) / 450);
          var v = Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)));
          writing = true;
          total.textContent = p < 1 ? prefix + v.toLocaleString('en-US') : txt;
          setTimeout(function () { writing = false; }, 0);
          if (p < 1) raf = requestAnimationFrame(step);
        });
      }).observe(total, { childList: true, characterData: true, subtree: true });
    }
  }

  /* ---------- hero button: one sweep after the intro ---------- */
  var heroBuy = $('.ev-actions .ev-btn-primary');
  if (heroBuy && !reduce) setTimeout(function () { heroBuy.classList.add('ev-sweep'); }, 1500);

  /* ---------- phone: buy bar slides up once the hero buttons are out of view ---------- */
  var actions = $('.ev-actions');
  var bar = $('.mobile-buy-bar');
  if (actions && bar && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      var en = entries[entries.length - 1];
      document.body.classList.toggle('ev-bar-on', !en.isIntersecting && en.boundingClientRect.bottom < 0);
    }, { threshold: 0 }).observe(actions);
  }
})();
