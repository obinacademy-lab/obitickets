/* Create event: "Focus Flow". One question per screen, a live preview card, and a final check before saving.
   The form posts the same fields as the classic form, so nothing here changes what the server receives.
   Plain JS, no dependencies. Without it the page is simply one long form. */
(function () {
  'use strict';

  var form = document.getElementById('efForm');
  if (!form) return;

  var steps = [].slice.call(form.querySelectorAll('.ef-step'));
  var total = steps.length;
  var rate = parseFloat(form.getAttribute('data-rate')) || 0.1;
  var onlineReady = form.getAttribute('data-online') === '1';
  var maxImage = parseInt(form.getAttribute('data-max-image'), 10) || 5 * 1024 * 1024;
  var cur = 0, dirty = false, submitting = false, bannerUrl = null;

  var tierBox = document.getElementById('tier-rows');
  var tierTpl = document.getElementById('efTierTpl');
  var prev = document.getElementById('efPrev');
  var next = document.getElementById('efNext');
  var skip = document.getElementById('efSkip');

  function $(id) { return document.getElementById(id); }
  function all(sel, root) { return [].slice.call((root || form).querySelectorAll(sel)); }
  function val(id) { var el = $(id); return el ? el.value.trim() : ''; }
  function ugx(n) { return 'UGX ' + Math.round(n).toLocaleString('en-US'); }
  function checked(name) { var r = form.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; }
  function mode() { return onlineReady ? (checked('attend_mode') || 'IN_PERSON') : 'IN_PERSON'; }

  /* ---------- what is filled in ---------- */
  function tierRows() { return tierBox ? all('[data-tier]', tierBox) : []; }
  function rowVals(row) {
    return {
      name: row.querySelector('input[name="tier_name[]"]').value.trim(),
      price: row.querySelector('input[name="tier_price[]"]').value.trim(),
      qty: row.querySelector('input[name="tier_quantity[]"]').value.trim()
    };
  }
  function tierOk(t) { return t.name !== '' && t.price !== '' && !isNaN(Number(t.price)) && Number(t.price) >= 0 && /^\d+$/.test(t.qty) && Number(t.qty) >= 1; }
  function whereState() {
    var m = mode(), venue = val('venue_name'), link = val('online_url');
    var linkOk = /^https?:\/\/\S+/i.test(link);
    if (m === 'ONLINE') return { ok: linkOk, msg: 'Paste the meeting link (it starts with https://) to continue.' };
    if (m === 'HYBRID') return { ok: venue !== '' && linkOk, msg: venue === '' ? 'Add the venue name to continue.' : 'Paste the meeting link (it starts with https://) to continue.' };
    return { ok: venue !== '', msg: 'Add the venue name to continue.' };
  }
  function whenState() {
    var s = val('starts_at'), e = val('ends_at');
    if (!s || !e) return { ok: false, msg: 'Pick when it starts and when it ends.' };
    if (new Date(e) < new Date(s)) return { ok: false, msg: 'The end needs to be after the start.' };
    return { ok: true, msg: '' };
  }
  function items() {
    var w = whereState(), wh = whenState(), anyTier = tierRows().some(function (r) { return tierOk(rowVals(r)); });
    var bannerInput = $('banner_image');
    return [
      { k: 'title', ok: val('title') !== '', req: true, label: 'an event title', step: 1 },
      { k: 'where', ok: w.ok, req: true, label: mode() === 'ONLINE' ? 'a meeting link' : (mode() === 'HYBRID' ? 'a venue and a meeting link' : 'a venue'), step: 3 },
      { k: 'when', ok: wh.ok, req: true, label: 'a start and end time', step: 4 },
      { k: 'tickets', ok: anyTier, req: true, label: 'a ticket type', step: 7 },
      { k: 'banner', ok: !!(bannerInput && bannerInput.files && bannerInput.files.length), req: false, label: 'a banner', step: 5 },
      { k: 'desc', ok: val('description') !== '', req: false, label: 'a description', step: 6 }
    ];
  }

  /* ---------- preview card, checklist, gate ---------- */
  function refresh() {
    var m = mode();
    form.setAttribute('data-mode', m);
    var panel = $('attendPanel'); if (panel) panel.setAttribute('data-mode', m);

    var venue = val('venue_name') || 'Venue to be added', where;
    if (m === 'ONLINE') where = 'Online' + (checked('online_platform') ? ' · ' + checked('online_platform') : '');
    else if (m === 'HYBRID') where = venue + ' + online';
    else where = venue;
    $('ntWhere').textContent = where;
    $('ntTitle').textContent = val('title') || 'Your event title';

    var when = 'Pick a date', s = val('starts_at');
    if (s) {
      var d = new Date(s);
      if (!isNaN(d.getTime())) {
        when = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true }).replace(' ', '') + ' EAT';
      }
    }
    $('ntWhen').textContent = when;
    $('ntEmoji').textContent = bannerUrl ? '' : checked('banner_emoji');

    var prices = [], potential = 0;
    tierRows().forEach(function (row) {
      var t = rowVals(row), p = Number(t.price) || 0, qty = parseInt(t.qty, 10) || 0;
      if (t.name !== '' && t.price !== '') prices.push(p);
      potential += p * qty * (1 - rate);
      var pay = row.querySelector('[data-pay]');
      if (pay) {
        if (t.price === '') pay.textContent = '';
        else if (p === 0) pay.innerHTML = '<b>Free ticket.</b> No commission.';
        else pay.innerHTML = 'You receive <b>' + ugx(p * (1 - rate)) + '</b> per ticket';
      }
    });
    var paid = prices.filter(function (p) { return p > 0; }), freeCount = prices.length - paid.length, text = '';
    $('ntFree').hidden = freeCount === 0;
    if (prices.length) {
      if (!paid.length) text = 'Free entry';
      else if (freeCount) text = 'Free and from ' + ugx(Math.min.apply(null, paid));
      else text = (paid.length > 1 ? 'From ' : '') + ugx(Math.min.apply(null, paid));
    }
    $('ntPrice').textContent = text;
    $('efPot').textContent = ugx(potential);

    var it = items(), missing = it.filter(function (i) { return i.req && !i.ok; });
    all('[data-check]').forEach(function (li) {
      var m2 = it.filter(function (i) { return i.k === li.getAttribute('data-check'); })[0];
      li.classList.toggle('done', !!(m2 && m2.ok));
    });
    var miss = $('efMiss');
    miss.innerHTML = missing.length
      ? '<b>' + missing.length + ' to go:</b> ' + missing.map(function (x) { return x.label; }).join(', ') + '. Use Edit above to add it.'
      : '<b>All set.</b> You can publish now, or save a draft.';
    all('[data-gate]').forEach(function (b) { b.disabled = missing.length > 0; });
  }

  /* ---------- steps ---------- */
  function usableInputs(step) {
    return all('input, textarea', step).filter(function (el) {
      return el.type !== 'radio' && el.type !== 'checkbox' && el.type !== 'file' && el.type !== 'hidden' && !el.readOnly && el.offsetParent !== null;
    });
  }
  function show(n, dir, focus) {
    cur = Math.max(0, Math.min(total - 1, n));
    steps.forEach(function (s, i) {
      s.classList.remove('is-active', 'back', 'is-bad', 'shake');
      if (i === cur) { void s.offsetWidth; s.classList.add('is-active'); if (dir === 'back') s.classList.add('back'); }
    });
    var dots = $('efDots'), html = '';
    for (var i = 0; i < total; i++) html += '<i class="' + (i <= cur ? 'on' : '') + '"></i>';
    dots.innerHTML = html;
    $('efCount').textContent = 'Step ' + (cur + 1) + ' of ' + total;
    var last = cur === total - 1, req = steps[cur].hasAttribute('data-req');
    prev.disabled = cur === 0;
    next.hidden = last;
    skip.hidden = last || req;
    if (focus) {
      var step = steps[cur], first = step.querySelector('[data-focus]');
      var target = first && first.offsetParent !== null ? first : step.querySelector('.ef-h');
      if (target) { try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); } }
    }
    var top = form.getBoundingClientRect().top;
    if (top < 0 || top > 80) { try { form.scrollIntoView({ block: 'start' }); } catch (e) {} }
  }
  function stepMessage(i) {
    var step = steps[i], msg = '';
    if (i === 0 && val('title') === '') msg = 'Give your event a name to continue.';
    else if (i === 2) { var w = whereState(); if (!w.ok) msg = w.msg; }
    else if (i === 3) { var wh = whenState(); if (!wh.ok) msg = wh.msg; }
    else if (i === 6) {
      if (!tierRows().some(function (r) { return tierOk(rowVals(r)); })) {
        var named = tierRows().map(rowVals).filter(function (t) { return t.name !== ''; })[0];
        msg = !named ? 'Add at least one ticket with a name, a price (0 for free) and a quantity.'
          : (named.price === '' || isNaN(Number(named.price)) || Number(named.price) < 0) ? 'Add a price for "' + named.name + '" (type 0 if it is free).'
          : 'Add a quantity of at least 1 for "' + named.name + '".';
      }
    }
    var out = step.querySelector('[data-msg]');
    if (out) out.textContent = msg;
    step.classList.toggle('is-bad', msg !== '');
    return msg;
  }
  function go(n, dir) { show(n, dir || (n < cur ? 'back' : 'fwd'), true); }
  function forward() {
    if (cur >= total - 1) return;
    if (steps[cur].hasAttribute('data-req') && stepMessage(cur) !== '') {
      var step = steps[cur]; step.classList.remove('shake'); void step.offsetWidth; step.classList.add('shake');
      var bad = usableInputs(step)[0]; if (bad) bad.focus();
      return;
    }
    go(cur + 1, 'fwd');
  }

  next.addEventListener('click', forward);
  skip.addEventListener('click', function () { go(cur + 1, 'fwd'); });
  prev.addEventListener('click', function () { go(cur - 1, 'back'); });
  all('[data-go]').forEach(function (b) { b.addEventListener('click', function () { go(parseInt(b.getAttribute('data-go'), 10) - 1); }); });

  form.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.defaultPrevented) return;
    var t = e.target;
    if (!t || t.tagName !== 'INPUT' || t.type === 'radio' || t.type === 'checkbox' || t.type === 'file' || t.type === 'submit') return;
    e.preventDefault();
    var list = usableInputs(steps[cur]), i = list.indexOf(t);
    if (i > -1 && i < list.length - 1) { list[i + 1].focus(); return; }
    forward();
  });

  /* ---------- edits ---------- */
  function onEdit(e) {
    dirty = true;
    var el = e.target;
    if (el.name === 'attend_mode') refreshSkipState();
    var step = el.closest ? el.closest('.ef-step') : null;
    if (step && step.classList.contains('is-bad')) { stepMessage(steps.indexOf(step)); }
    refresh();
  }
  function refreshSkipState() { skip.hidden = cur === total - 1 || steps[cur].hasAttribute('data-req'); }
  form.addEventListener('input', onEdit);
  form.addEventListener('change', onEdit);

  /* ---------- ticket rows ---------- */
  function syncRow(row, fromUser) {
    var sw = row.querySelector('.tier-free'), price = row.querySelector('input[name="tier_price[]"]'), limit = row.querySelector('input[name="tier_limit[]"]');
    if (!price) return;
    var free = !!(sw && sw.checked);
    row.classList.toggle('is-free', free);
    price.readOnly = free;
    if (free) price.value = '0';
    if (limit) limit.placeholder = free ? limit.getAttribute('data-free-placeholder') : limit.getAttribute('data-paid-placeholder');
    return free;
  }
  if (tierBox) {
    tierRows().forEach(function (r) { syncRow(r); });
    tierBox.addEventListener('change', function (e) {
      if (!e.target.classList.contains('tier-free')) return;
      var row = e.target.closest('[data-tier]'), price = row.querySelector('input[name="tier_price[]"]');
      var wasFree = row.classList.contains('is-free');
      syncRow(row);
      if (wasFree && !e.target.checked) { price.value = ''; price.focus(); }
      refresh();
    });
    tierBox.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-rm]') : null;
      if (!b) return;
      var row = b.closest('[data-tier]'), rows = tierRows();
      if (rows.length > 1) { row.remove(); }
      else { all('input[type="text"], input[type="number"]', row).forEach(function (i) { i.value = ''; }); var sw = row.querySelector('.tier-free'); if (sw) sw.checked = false; syncRow(row); }
      dirty = true; refresh();
    });
  }
  var addBtn = $('add-tier');
  if (addBtn && tierTpl) {
    addBtn.addEventListener('click', function () {
      var node = tierTpl.content.firstElementChild.cloneNode(true);
      tierBox.appendChild(node);
      syncRow(node);
      var n = node.querySelector('input[name="tier_name[]"]'); if (n) n.focus();
      dirty = true; refresh();
    });
  }

  /* ---------- banner and gallery ---------- */
  var bannerInput = $('banner_image');
  if (bannerInput) {
    bannerInput.addEventListener('change', function () {
      var msg = steps[4].querySelector('[data-msg]'), f = bannerInput.files && bannerInput.files[0];
      if (msg) msg.textContent = '';
      if (bannerUrl) { try { URL.revokeObjectURL(bannerUrl); } catch (e) {} bannerUrl = null; }
      if (f && f.size > maxImage) {
        bannerInput.value = '';
        if (msg) msg.textContent = 'That image is over 5MB. Please choose a smaller one.';
        f = null;
      }
      var drop = $('efBannerDrop'), pv = $('efBannerPv'), im = $('efCardIm');
      if (f) {
        bannerUrl = URL.createObjectURL(f);
        pv.style.backgroundImage = 'url("' + bannerUrl + '")'; im.style.backgroundImage = 'url("' + bannerUrl + '")'; drop.classList.add('has');
      } else { pv.style.backgroundImage = ''; im.style.backgroundImage = ''; drop.classList.remove('has'); }
      refresh();
    });
  }
  var gallery = form.querySelector('input[name="gallery[]"]');
  if (gallery) {
    gallery.addEventListener('change', function () {
      var n = gallery.files ? gallery.files.length : 0;
      $('efGalleryLabel').textContent = n ? n + ' file' + (n > 1 ? 's' : '') + ' selected' : 'Add photos and short clips';
    });
  }

  /* ---------- saving ---------- */
  form.addEventListener('submit', function (e) {
    var missing = items().filter(function (i) { return i.req && !i.ok; });
    if (missing.length) { e.preventDefault(); go(missing[0].step - 1); stepMessage(missing[0].step - 1); return; }
    var who = e.submitter && e.submitter.value;
    if (who) $('efStatus').value = who;
    submitting = true;
    var buttons = all('[data-gate]');
    window.setTimeout(function () {
      buttons.forEach(function (b) { b.disabled = true; if (e.submitter === b) b.textContent = b.value === 'PUBLISHED' ? 'Publishing…' : 'Saving…'; });
    }, 0);
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitting && val('title') !== '') { e.preventDefault(); e.returnValue = ''; }
  });

  /* ---------- start ---------- */
  form.classList.add('is-flow');
  var start = (parseInt(form.getAttribute('data-start'), 10) || 1) - 1;
  refresh();
  show(start, 'fwd', start === 0 && !(window.matchMedia && window.matchMedia('(pointer:coarse)').matches));
})();
