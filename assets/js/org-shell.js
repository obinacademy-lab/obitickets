/* Organizer dashboard: the phone's "More" sheet. Plain JS, no dependencies. */
(function () {
  'use strict';

  var btn = document.getElementById('orgMoreBtn');
  var sheet = document.getElementById('orgSheet');
  if (!btn || !sheet) return;

  function open() {
    sheet.hidden = false;
    void sheet.offsetWidth;
    sheet.classList.add('on');
    btn.setAttribute('aria-expanded', 'true');
    var first = sheet.querySelector('a');
    if (first) first.focus({ preventScroll: true });
  }
  function close() {
    sheet.classList.remove('on');
    btn.setAttribute('aria-expanded', 'false');
    window.setTimeout(function () { sheet.hidden = true; }, 260);
    btn.focus({ preventScroll: true });
  }

  btn.addEventListener('click', open);
  sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) close(); });
})();
