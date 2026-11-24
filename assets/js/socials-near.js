/* Socials "Near You". The videos appear at once: the server starts from the best-known place (the last Near You
   district, the place the person last browsed, else Kampala) and lists every video closest-first, widening to the next
   closest places, so the tab is never empty.
   This script only makes the place exact:
   - first tap ever: asks the browser where the person is (once), picks the nearest district town right here in the page
     and opens the tab for it; if they say no, the tab opens with the default;
   - on the Near You page: quietly re-checks the location and, only if the person is somewhere else than the list
     assumed, switches to the right place once.
   Only a district name goes to the server (and into the ot_near cookie); the position itself never leaves the phone. */
(function () {
  var dataEl = document.getElementById('soDistricts');
  var pts = [];
  try { pts = JSON.parse(dataEl ? dataEl.textContent : '[]'); } catch (e) { pts = []; }
  var GUARD = 'ot-near-fix';

  function nearest(lat, lng) {
    var best = null, bestD = Infinity, k = Math.cos(lat * Math.PI / 180);
    for (var i = 0; i < pts.length; i++) {
      var dLat = pts[i][1] - lat, dLng = (pts[i][2] - lng) * k, d = dLat * dLat + dLng * dLng;
      if (d < bestD) { bestD = d; best = pts[i][0]; }
    }
    // More than ~70 km from every district town: outside Uganda (the list just keeps its default)
    return best !== null && Math.sqrt(bestD) * 111 <= 70 ? best : null;
  }

  function hasCookie() { return /(?:^|;\s*)ot_near=/.test(document.cookie); }
  function remember(district) {
    try {
      document.cookie = 'ot_near=' + encodeURIComponent(district) + '; max-age=' + (30 * 86400) + '; path=/; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
    } catch (e) { /* the address still carries it */ }
  }

  function go(district) {
    remember(district);
    var u = new URL(location.href);
    var onHome = u.pathname === '/' || u.pathname === '/index.php';
    u.search = '';
    if (!onHome) { u.searchParams.set('play', '1'); }
    u.searchParams.set('tab', 'near');
    u.searchParams.set('near', district);
    location.href = u.toString();
  }

  function position(done, fail, timeout) {
    if (!('geolocation' in navigator) || !pts.length) { fail(); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      done(nearest(pos.coords.latitude, pos.coords.longitude));
    }, fail, { enableHighAccuracy: false, timeout: timeout, maximumAge: 600000 });
  }

  // the "Near You" tab at the top
  var link = document.querySelector('[data-near-link]');
  if (link && !link.classList.contains('on')) {
    link.addEventListener('click', function (e) {
      if (hasCookie()) { return; }   // place already known: just open it, instantly
      e.preventDefault();            // first time: ask once, then open it either way
      var target = link.getAttribute('href');
      var finished = false;
      var open = function (district) {
        if (finished) { return; }
        finished = true;
        if (district) { go(district); } else { location.href = target; }
      };
      setTimeout(function () { open(null); }, 11000);
      position(open, function () { open(null); }, 10000);
    });
  }

  // on the Near You page: make the place exact, switching once if the person is somewhere else
  if (link && link.classList.contains('on')) {
    var shown = null;
    try { shown = (JSON.parse((document.getElementById('soData') || {}).textContent || '{}') || {}).near || null; } catch (e) { shown = null; }
    if (!shown) { shown = new URL(location.href).searchParams.get('near'); }
    var check = function () {
      position(function (district) {
        if (!district) { return; }
        var fixed = null;
        try { fixed = sessionStorage.getItem(GUARD); } catch (e) { fixed = null; }
        if (district === shown) { remember(district); return; }
        if (fixed === district) { return; }   // already switched once this visit: never loop
        try { sessionStorage.setItem(GUARD, district); } catch (e) { /* fine */ }
        go(district);
      }, function () { /* no location: keep the list as it is */ }, 8000);
    };
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'geolocation' }).then(function (st) { if (st.state !== 'denied') { check(); } }, check);
    } else {
      check();
    }
  }
})();
