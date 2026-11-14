/* obitickets Socials: the card list (public/socials.php without ?v=). The first page is drawn by the server;
   this loads the next cards as the visitor scrolls (the same markup as socials_card_html() in includes/socials.php)
   and opens the site menu from the header's menu button. All text is put in with textContent. */
(function () {
  'use strict';

  // the header's menu button opens the site's own menu drawer
  var menuBtn = document.getElementById('slMenu'), navToggle = document.getElementById('navToggle');
  if (menuBtn && navToggle) {
    menuBtn.addEventListener('click', function () { navToggle.click(); menuBtn.setAttribute('aria-expanded', 'true'); });
  }

  var list = document.getElementById('slList'), more = document.getElementById('slMore');
  if (!list) { return; }
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var tab = list.getAttribute('data-tab') || 'foryou';
  var offset = parseInt(list.getAttribute('data-offset'), 10) || 0;
  var hasMore = list.getAttribute('data-more') === '1';
  var loading = false;
  var seen = {};
  list.querySelectorAll('.sl-card').forEach(function (c) { var m = /[?&]v=(\d+)/.exec(c.getAttribute('href')); if (m) { seen[m[1]] = true; } });

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text != null) { n.textContent = text; } return n; }
  function svg(d, size, filled, width) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size); s.setAttribute('height', size); s.setAttribute('aria-hidden', 'true');
    if (filled) { s.setAttribute('fill', 'currentColor'); } else { s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', width || '2.2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); }
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); s.appendChild(p);
    return s;
  }
  function clock(sec) { if (sec == null) { return ''; } sec = Math.max(0, Math.round(sec)); return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2); }
  function safeUpload(p) { return typeof p === 'string' && /^\/uploads\/[A-Za-z0-9\/_.\-]+$/.test(p) ? p : ''; }

  function card(it) {
    var a = el('a', 'sl-card');
    a.href = '/socials.php?v=' + Number(it.id) + (tab !== 'foryou' ? '&tab=' + encodeURIComponent(tab) : '');
    a.setAttribute('aria-label', (it.type === 'video' ? 'Watch: ' : 'Open: ') + it.event.title + ', by ' + it.org.name);
    var media = el('span', 'sl-media');
    var thumb = safeUpload(it.type === 'video' ? it.poster : (it.type === 'slideshow' ? (it.slides || [])[0] : it.src));
    if (thumb) { var img = el('img'); img.alt = ''; img.loading = 'lazy'; img.src = thumb; media.appendChild(img); }
    else if (it.type === 'video') { var v = document.createElement('video'); v.muted = true; v.setAttribute('playsinline', ''); v.preload = 'metadata'; v.tabIndex = -1; v.setAttribute('aria-hidden', 'true'); v.src = it.src + '#t=0.5'; media.appendChild(v); }
    var play = el('span', 'sl-play'); play.setAttribute('aria-hidden', 'true'); play.appendChild(svg('M8 5v14l11-7z', 26, true)); media.appendChild(play);
    var badge = it.type === 'video' ? clock(it.duration) : (it.type === 'slideshow' ? (it.slides || []).length + ' photos' : 'Photo');
    if (badge) { media.appendChild(el('span', 'sl-dur', badge)); }
    var info = el('span', 'sl-info');
    info.appendChild(el('b', 'sl-title', it.event.title));
    var by = el('span', 'sl-by');
    var av = el('span', 'sl-av');
    if (it.org.avatar) { var ai = el('img'); ai.alt = ''; ai.src = it.org.avatar; av.appendChild(ai); } else { av.textContent = it.org.initials; }
    by.appendChild(av);
    by.appendChild(el('span', 'sl-handle', it.org.handle));
    if (it.org.verified) { var ver = el('span', 'sl-ver'); ver.title = 'Verified organizer'; ver.appendChild(svg('M5 12.5l4.5 4.5L19 7.5', 10, false, '4')); by.appendChild(ver); }
    var likes = el('span', 'sl-likes');
    likes.appendChild(svg('M12 20.5s-7.5-4.6-9.3-9.4C1.5 7.6 3.9 4.5 7.2 4.5c1.9 0 3.6 1 4.8 2.6 1.2-1.6 2.9-2.6 4.8-2.6 3.3 0 5.7 3.1 4.5 6.6-1.8 4.8-9.3 9.4-9.3 9.4z', 15, false));
    likes.appendChild(document.createTextNode(it.likes));
    by.appendChild(likes);
    info.appendChild(by);
    media.appendChild(info);
    a.appendChild(media);
    return a;
  }

  function load() {
    if (loading || !hasMore) { return; }
    loading = true; if (more) { more.hidden = false; }
    fetch('/api/socials.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ action: 'feed', tab: tab, offset: offset, csrf_token: csrf }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        loading = false; if (more) { more.hidden = true; }
        if (!r || !r.ok) { hasMore = false; return; }
        offset += r.items.length; hasMore = !!r.has_more;
        r.items.forEach(function (it) { if (seen[it.id]) { return; } seen[it.id] = true; list.appendChild(card(it)); });
        watch();
      }, function () { loading = false; if (more) { more.hidden = true; } });
  }

  // load the next page when the last card comes near the screen
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); load(); } });
  }, { rootMargin: '600px 0px' }) : null;
  function watch() { var last = list.lastElementChild; if (io && last && hasMore) { io.observe(last); } }
  watch();
})();
