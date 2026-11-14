/* obitickets Socials: the list (public/socials.php without ?v= / ?org=), one card per organizer holding their posts.
   The server draws every card (socials_org_card_html in includes/socials.php), including the ones loaded here as the
   visitor scrolls, so both always match. The header's menu button opens the site's own menu drawer. */
(function () {
  'use strict';

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

  function load() {
    if (loading || !hasMore) { return; }
    loading = true; if (more) { more.hidden = false; }
    fetch('/api/socials.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ action: 'org_cards', tab: tab, offset: offset, csrf_token: csrf }) })
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

  // load the next cards when the last one comes near the screen
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); load(); } });
  }, { rootMargin: '600px 0px' }) : null;
  function watch() { var last = list.lastElementChild; if (io && last && hasMore) { io.observe(last); } }
  watch();
})();
