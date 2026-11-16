/* Browse: "Near me" asks the browser where the buyer is, picks the nearest district town right here in the page and
   opens ?near=<District>. The position itself never leaves the phone. The Where picker opens as soon as it changes. */
(function () {
  var btn = document.getElementById('sxNear');
  var msg = document.getElementById('sxMsg');
  var pick = document.getElementById('sxWhere');

  if (pick) {
    pick.addEventListener('change', function () { pick.form.submit(); });
  }
  if (!btn) return;

  function say(text) {
    if (!msg) return;
    msg.textContent = text;
    msg.hidden = false;
  }

  function nearest(lat, lng) {
    var pts = [];
    try { pts = JSON.parse(btn.getAttribute('data-pts') || '[]'); } catch (e) { return null; }
    var best = null, bestD = Infinity, k = Math.cos(lat * Math.PI / 180);
    for (var i = 0; i < pts.length; i++) {
      var dLat = pts[i][1] - lat, dLng = (pts[i][2] - lng) * k;
      var d = dLat * dLat + dLng * dLng;
      if (d < bestD) { bestD = d; best = pts[i][0]; }
    }
    // More than ~70 km from every district town: outside Uganda.
    return best !== null && Math.sqrt(bestD) * 111 <= 70 ? best : null;
  }

  btn.addEventListener('click', function () {
    if (btn.classList.contains('on')) return; // already near a district; the x next to it clears it
    if (!('geolocation' in navigator)) {
      say('Your browser cannot share your location. Pick your district instead.');
      if (pick) pick.focus();
      return;
    }
    btn.classList.add('busy');
    btn.setAttribute('aria-busy', 'true');
    say('Finding events near you…');
    navigator.geolocation.getCurrentPosition(function (pos) {
      var d = nearest(pos.coords.latitude, pos.coords.longitude);
      btn.classList.remove('busy');
      btn.removeAttribute('aria-busy');
      if (!d) {
        say('You seem to be outside Uganda. Pick a district to see events there.');
        if (pick) pick.focus();
        return;
      }
      window.location.href = btn.getAttribute('data-url').replace('__D__', encodeURIComponent(d));
    }, function (err) {
      btn.classList.remove('busy');
      btn.removeAttribute('aria-busy');
      say(err && err.code === 1
        ? 'Location is turned off for this site. Pick your district instead.'
        : 'We could not find your location just now. Pick your district instead.');
      if (pick) pick.focus();
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
  });
})();
