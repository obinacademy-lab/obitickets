/* obitickets Socials: the list ("Shorts Shelf", public/socials.php without ?v= / ?org=). Chips, a featured event and
   every post as a tall tile two across. The server draws every tile (socials_short_tile_html in includes/socials.php),
   including the ones loaded here as the visitor scrolls, so both always match. */
(function () {
  'use strict';

  var list = document.getElementById('slList'), more = document.getElementById('slMore');
  if (!list) { return; }
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var tab = list.getAttribute('data-tab') || 'foryou';
  var cat = list.getAttribute('data-cat') || '';
  var offset = parseInt(list.getAttribute('data-offset'), 10) || 0;
  var hasMore = list.getAttribute('data-more') === '1';
  var loading = false;

  function load() {
    if (loading || !hasMore) { return; }
    loading = true; if (more) { more.hidden = false; }
    fetch('/api/socials.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ action: 'shorts', tab: tab, cat: cat, offset: offset, csrf_token: csrf }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        loading = false; if (more) { more.hidden = true; }
        if (!r || !r.ok) { hasMore = false; return; }
        offset += r.count; hasMore = !!r.has_more;
        // the HTML comes from our own server, built with every value escaped
        list.insertAdjacentHTML('beforeend', r.html);
        watch();
      }, function () { loading = false; if (more) { more.hidden = true; } });
  }

  // load the next tiles when the last one comes near the screen
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); load(); } });
  }, { rootMargin: '600px 0px' }) : null;
  function watch() { var last = list.lastElementChild; if (io && last && hasMore) { io.observe(last); } }
  watch();

  // keep the chosen chip in view when the row of chips is wider than the screen
  var on = document.querySelector('.ss-chips a.on');
  if (on && on.scrollIntoView && on.offsetLeft > window.innerWidth - 80) {
    on.parentNode.scrollLeft = on.offsetLeft - 16;
  }
})();
