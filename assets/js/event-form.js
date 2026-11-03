/* Organizer event form: the attend-mode panel (in person / online / both), its live preview card,
   and the Free switch on each ticket row. Plain JS, no dependencies. */
(function () {
  'use strict';

  var panel = document.getElementById('attendPanel');
  var tierRows = document.getElementById('tier-rows');

  /* ---------- Free switch on ticket rows ---------- */
  function syncRow(row) {
    var sw = row.querySelector('.tier-free');
    var price = row.querySelector('input[name="tier_price[]"]');
    var limit = row.querySelector('input[name="tier_limit[]"]');
    if (!sw || !price) return;
    var free = sw.checked;
    row.classList.toggle('is-free', free);
    price.readOnly = free;
    if (free) { price.value = '0'; }
    if (limit) { limit.placeholder = free ? limit.getAttribute('data-free-placeholder') : limit.getAttribute('data-paid-placeholder'); }
  }
  if (tierRows) {
    tierRows.addEventListener('change', function (e) {
      if (!e.target.classList.contains('tier-free')) return;
      var row = e.target.closest('.tier-form-row');
      var price = row.querySelector('input[name="tier_price[]"]');
      var wasFree = row.classList.contains('is-free');
      syncRow(row);
      if (wasFree && !e.target.checked) { price.value = ''; price.focus(); }
      refreshPreview();
    });
    tierRows.addEventListener('input', function (e) { if (e.target.name === 'tier_price[]' || e.target.name === 'tier_name[]') refreshPreview(); });
    // the "add another ticket type" button clones a template: it needs no extra wiring because events are delegated
    Array.prototype.forEach.call(tierRows.querySelectorAll('.tier-form-row'), syncRow);
  }

  if (!panel) return;

  /* ---------- Format panel ---------- */
  function mode() {
    var r = panel.querySelector('input[name="attend_mode"]:checked');
    return r ? r.value : 'IN_PERSON';
  }
  function platform() {
    var r = panel.querySelector('input[name="online_platform"]:checked');
    return r ? r.value : '';
  }
  function setMode() { panel.setAttribute('data-mode', mode()); refreshPreview(); }

  /* ---------- Live preview ---------- */
  function $(id) { return document.getElementById(id); }
  function fmtMoney(n) { return 'UGX ' + Number(n).toLocaleString('en-US'); }
  function refreshPreview() {
    var m = mode(), where;
    var venue = ($('venue_name') && $('venue_name').value.trim()) || 'Venue to be added';
    if (m === 'ONLINE') where = 'Online' + (platform() ? ' · ' + platform() : '');
    else if (m === 'HYBRID') where = venue + ' + online';
    else where = venue;
    $('ntWhere').textContent = where;
    $('ntTitle').textContent = ($('title') && $('title').value.trim()) || 'Your event title';

    var start = $('starts_at') && $('starts_at').value;
    var when = 'Pick a date';
    if (start) {
      var d = new Date(start);
      if (!isNaN(d.getTime())) {
        when = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true }).replace(' ', '') + ' EAT';
      }
    }
    $('ntWhen').textContent = when;
    var emoji = $('banner_emoji'); $('ntEmoji').textContent = emoji ? emoji.value : '';

    var prices = [];
    if (tierRows) {
      Array.prototype.forEach.call(tierRows.querySelectorAll('.tier-form-row'), function (row) {
        var name = row.querySelector('input[name="tier_name[]"]');
        var price = row.querySelector('input[name="tier_price[]"]');
        if (name && name.value.trim() !== '' && price && price.value !== '') prices.push(Number(price.value) || 0);
      });
    }
    var paid = prices.filter(function (p) { return p > 0; });
    var freeCount = prices.length - paid.length;
    $('ntFree').hidden = freeCount === 0;
    var text = '';
    if (prices.length) {
      if (!paid.length) text = 'Free entry';
      else if (freeCount) text = 'Free and from ' + fmtMoney(Math.min.apply(null, paid));
      else text = 'From ' + fmtMoney(Math.min.apply(null, paid));
    }
    $('ntPrice').textContent = text;
  }

  panel.addEventListener('change', setMode);
  ['title', 'starts_at', 'banner_emoji', 'venue_name'].forEach(function (id) {
    var el = $(id); if (el) { el.addEventListener('input', refreshPreview); el.addEventListener('change', refreshPreview); }
  });
  setMode();
})();
