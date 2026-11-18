/* Follow / Message buttons anywhere on a page (11/18). Talks to /api/social.php with the page's form token.
   <button data-follow-user="ID" aria-pressed="false">Follow</button>
   <button data-message-user="ID">Message</button>
   Signed-out visitors are sent to log in (data-login on <body> or the button gives the URL). */
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.content : '';

  function api(payload) {
    payload.csrf_token = csrf;
    return fetch('/api/social.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { j._status = r.status; return j; });
    });
  }
  window.obiSocialApi = api;

  function toLogin(el) {
    var holder = el.closest('[data-login]');
    var to = (holder && holder.getAttribute('data-login')) || document.body.getAttribute('data-login') || ('/login.php?next=' + encodeURIComponent(location.pathname + location.search));
    location.href = to;
  }
  function toast(msg) {
    var t = document.querySelector('.sx-toast');
    if (!t) { t = document.createElement('div'); t.className = 'sx-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('on');
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('on'); }, 2600);
  }
  window.obiToast = toast;

  document.addEventListener('click', function (e) {
    var f = e.target.closest('[data-follow-user]');
    if (f) {
      e.preventDefault();
      if (f.disabled) { return; }
      var on = f.getAttribute('aria-pressed') !== 'true';
      f.disabled = true;
      api({ action: 'follow', user_id: +f.getAttribute('data-follow-user'), follow: on }).then(function (j) {
        f.disabled = false;
        if (j.login || j._status === 401) { toLogin(f); return; }
        if (!j.ok) { toast(j.error || 'Something went wrong.'); return; }
        f.setAttribute('aria-pressed', on ? 'true' : 'false');
        f.textContent = on ? (f.getAttribute('data-on') || 'Following') : (f.getAttribute('data-off') || 'Follow');
        var c = document.querySelector('[data-follower-count="' + f.getAttribute('data-follow-user') + '"]');
        if (c && typeof j.followers === 'number') { c.textContent = j.followers; }
      }).catch(function () { f.disabled = false; toast('No connection. Please try again.'); });
      return;
    }
    var m = e.target.closest('[data-message-user]');
    if (m) {
      e.preventDefault();
      if (m.disabled) { return; }
      m.disabled = true;
      var ev = m.getAttribute('data-share-event');
      api({ action: 'open', user_id: +m.getAttribute('data-message-user') }).then(function (j) {
        m.disabled = false;
        if (j.login || j._status === 401) { toLogin(m); return; }
        if (!j.ok) { toast(j.error || 'Something went wrong.'); return; }
        location.href = j.url + (ev ? '&event=' + encodeURIComponent(ev) : '');
      }).catch(function () { m.disabled = false; toast('No connection. Please try again.'); });
    }
  });
})();
