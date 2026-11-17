/* Home (Stories Feed): Follow buttons on organizer posts. Uses the same endpoint as the event page; guests are sent
   to log in and come back. Every button for the same organizer updates together. */
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.content : '';
  var buttons = [].slice.call(document.querySelectorAll('.st-follow'));
  if (!buttons.length) return;

  function paint(org, on) {
    buttons.forEach(function (b) {
      if (b.getAttribute('data-org') !== org) return;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.textContent = on ? 'Following' : 'Follow';
    });
  }

  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var org = b.getAttribute('data-org');
      var next = b.getAttribute('aria-pressed') !== 'true';
      paint(org, next);
      b.disabled = true;
      fetch('/api/community.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'follow_organizer', organizer_id: parseInt(org, 10), follow: next, csrf_token: csrf })
      }).then(function (r) {
        if (r.status === 401) {
          window.location.href = '/login.php?next=' + encodeURIComponent('/discover.php');
          return null;
        }
        return r.json().then(function (d) { if (!r.ok || !d || d.ok === false) throw new Error('fail'); });
      }).catch(function () {
        paint(org, !next);
      }).then(function () { b.disabled = false; });
    });
  });
})();
