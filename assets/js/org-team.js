/* Organizer Team page: filter and search the roster, confirm role changes, and a clearer invite form.
   The page works without this (every row shows, every form posts). Plain JS, no dependencies. */
(function () {
  'use strict';

  var rowsBox = document.getElementById('tmRows');
  var rows = rowsBox ? [].slice.call(rowsBox.querySelectorAll('.tm-row')) : [];
  var filters = document.getElementById('tmFilters');
  var search = document.getElementById('tmSearch');
  var none = document.getElementById('tmNone');
  var head = document.querySelector('.tm-head');
  var status = 'ALL';

  function apply() {
    var t = (search && search.value || '').toLowerCase().trim(), shown = 0;
    rows.forEach(function (r) {
      var ok = (status === 'ALL' || r.getAttribute('data-status') === status) && (!t || r.getAttribute('data-find').indexOf(t) > -1);
      r.hidden = !ok;
      if (ok) shown++;
    });
    if (none) none.hidden = shown > 0;
    if (head) head.hidden = shown === 0;
  }
  if (filters) {
    filters.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('button[data-f]') : null;
      if (!b) return;
      status = b.getAttribute('data-f');
      [].forEach.call(filters.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      apply();
    });
  }
  if (search) search.addEventListener('input', apply);

  // changing a role asks first, and snaps back if the answer is no
  if (rowsBox) {
    rowsBox.addEventListener('change', function (e) {
      var sel = e.target;
      if (!sel.classList || !sel.classList.contains('tm-sel')) return;
      var label = sel.options[sel.selectedIndex].text, who = sel.getAttribute('data-who');
      var ask = window.adminConfirm
        ? window.adminConfirm("Change this person's role? " + who + ' will become ' + label + '.')
        : Promise.resolve(window.confirm("Change this person's role? " + who + ' will become ' + label + '.'));
      ask.then(function (ok) {
        if (ok) { sel.form.requestSubmit(); } else { sel.value = sel.getAttribute('data-prev'); }
      });
    });
  }

  // the invite form: the role's plain-words description, and a clear message before anything is sent
  var form = document.getElementById('tmInvite');
  if (!form) return;
  var email = document.getElementById('tmEmail'), err = document.getElementById('tmErr'), role = document.getElementById('tmRole'), hint = document.getElementById('tmHint');

  function showHint() { var o = role.options[role.selectedIndex]; hint.textContent = o ? o.getAttribute('data-hint') || '' : ''; }
  role.addEventListener('change', showHint);
  showHint();

  function problem() {
    var v = email.value.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter an email like person@example.com.';
    var dupe = rows.some(function (r) { return r.getAttribute('data-email') === v && r.getAttribute('data-status') !== 'REVOKED'; });
    return dupe ? 'This person is already on your team.' : '';
  }
  function mark(msg) {
    err.textContent = msg;
    email.classList.toggle('is-bad', !!msg);
    if (msg) { email.setAttribute('aria-invalid', 'true'); email.setAttribute('aria-describedby', 'tmErr'); } else { email.removeAttribute('aria-invalid'); }
  }
  email.addEventListener('input', function () { if (err.textContent) mark(''); });
  form.addEventListener('submit', function (e) {
    var msg = problem();
    if (msg) { e.preventDefault(); mark(msg); email.focus(); return; }
    var btn = form.querySelector('button[type="submit"]');
    window.setTimeout(function () { btn.disabled = true; btn.textContent = 'Sending…'; }, 0);
  });
})();
