/* OBI COINS (ADR-002, phase 3): the recharge sheet. Used by the wallet page, and by any screen that needs to offer "add OBI COINS"
   (the gift sheet does, when someone does not have enough). Prices, bonuses and every check live on the server (includes/coins.php).
   This script only shows the screens: choose a package -> see exactly what you get and what you pay in UGX -> phone number -> waiting -> done (or a clear failure).
   Coins are added only after OUR server has asked ioTec and heard "paid"; the browser's word is never trusted. Text always goes in with textContent.
   Public API: window.ObCoins.open({packageId, needed, doneLabel, onDone, onClose, onNotice, resume, silent}) */
(function () {
  'use strict';
  var API = '/api/coins.php';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var POLL_MS = 2500, POLL_MAX_MS = 4 * 60 * 1000;
  var shade = null, sheet = null, head = null, body = null, foot = null;
  var S = null;   // the purchase in progress

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function money(n) { return 'UGX ' + num(n); }
  function coin(size) { var c = el('i', 'oc-coin'); if (size) { c.style.setProperty('--s', size + 'px'); } c.setAttribute('aria-hidden', 'true'); return c; }
  function api(b) {
    b.csrf_token = csrf;
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(b) })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { error: 'Something went wrong.', _status: r.status }; }); });
  }
  function uuid() { try { return crypto.randomUUID(); } catch (e) { return String(Date.now()) + '-' + Math.random().toString(36).slice(2, 10); } }
  function method(phone) {
    var d = String(phone || '').replace(/\D/g, '');
    if (d.charAt(0) === '0') { d = '256' + d.slice(1); }
    if (/^256(77|78|76|39)\d{7}$/.test(d)) { return 'MTN_MOMO'; }
    if (/^256(70|75|74|20)\d{7}$/.test(d)) { return 'AIRTEL_MONEY'; }
    return null;
  }

  // ------------------------------------------------------------------ the sheet frame
  function build() {
    if (sheet) { return; }
    shade = el('div', 'oc-shade'); shade.addEventListener('click', close);
    sheet = el('section', 'oc-sheet'); sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-labelledby', 'ocTitle'); sheet.tabIndex = -1;
    sheet.appendChild(el('div', 'oc-handle'));
    head = el('header', 'oc-head'); body = el('div', 'oc-body'); foot = el('div', 'oc-foot');
    sheet.appendChild(head); sheet.appendChild(body); sheet.appendChild(foot);
    document.body.appendChild(shade); document.body.appendChild(sheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('on')) { close(); } });
  }
  function header(title) {
    head.textContent = '';
    head.appendChild(coin(40));
    var tx = el('div', 'oc-head-tx'); tx.appendChild(el('small', null, 'OBI COINS'));
    var b = el('b', null, title); b.id = 'ocTitle'; tx.appendChild(b);
    var x = el('button', 'oc-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.addEventListener('click', close);
    head.appendChild(tx); head.appendChild(x);
  }
  function show() {
    build();
    document.body.classList.add('oc-open');
    requestAnimationFrame(function () { shade.classList.add('on'); sheet.classList.add('on'); sheet.focus({ preventScroll: true }); });
  }
  function hide() {
    if (!sheet) { return; }
    shade.classList.remove('on'); sheet.classList.remove('on');
    document.body.classList.remove('oc-open');
  }
  function close() {
    if (!sheet) { return; }
    hide();
    if (S && S.step === 'wait') {
      S.closedWhileWaiting = true;   // keep checking quietly: the coins are added if they approve
      if (S.opts.onClose && !S.notifiedClose) { S.notifiedClose = true; S.opts.onClose(false); }   // the screen underneath may come back now
    } else if (S) {
      var o = S.opts || {}, fin = S.finished, r = S.result;
      stopPoll(); S = null;
      if (fin && o.onDone) { o.onDone(r); }
      if (o.onClose) { o.onClose(!!fin, r); }
    }
  }

  // ------------------------------------------------------------------ open and load
  function open(opts) {
    opts = opts || {};
    if (S && S.step === 'wait') { S.opts = opts; if (sheet && !sheet.classList.contains('on')) { show(); } renderWait(); return; }
    S = { opts: opts, step: 'load', pkg: null, phone: '', key: null, tx: null, started: 0, timer: null, data: null };
    build();
    if (!(opts.resume && opts.silent)) { show(); header('Add OBI COINS'); body.textContent = ''; foot.textContent = ''; body.appendChild(el('div', 'oc-loading', 'Loading…')); }
    var mine = S;
    api({ action: 'packages' }).then(function (d) {
      if (S !== mine) { return; }
      if (d._status === 401) { location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search); return; }
      if (!d.ok) { return fail(d.error || 'OBI COINS is not available right now.', true); }
      S.data = d; S.phone = d.phone || '';
      if (d.open && !S.opts.noResume) { S.tx = d.open.id; S.price = d.open.price; S.gets = d.open.coins; S.phone = d.open.phone || S.phone; S.step = 'wait'; S.started = Date.now(); if (!sheet.classList.contains('on') && !opts.silent) { show(); } renderWait(); startPoll(); return; }
      if (!d.enabled) { return fail('OBI COINS is not available right now. Please try again later.', true); }
      var want = S.opts.packageId ? pkgById(S.opts.packageId) : null;
      if (want) { S.pkg = want; S.step = 'pay'; S.key = uuid(); renderPay(); } else { S.step = 'pick'; renderPick(); }
    }, function () { if (S === mine) { fail('Could not load. Check your connection and try again.', true); } });
  }
  function pkgById(id) { var l = S.data.packages; for (var i = 0; i < l.length; i++) { if (l[i].id === id) { return l[i]; } } return null; }

  // ------------------------------------------------------------------ step 1: choose a package (when opened without one)
  function renderPick() {
    header('Add OBI COINS');
    body.textContent = ''; foot.textContent = '';
    var d = S.data;
    if (S.opts.needed) {
      var short = Math.max(0, S.opts.needed - (d.wallet.balance || 0));
      body.appendChild(el('div', 'oc-note-top', 'You need ' + num(short) + ' more OBI COINS for this gift. Your balance is ' + num(d.wallet.balance) + '.'));
    }
    var needMore = S.opts.needed ? Math.max(0, S.opts.needed - (d.wallet.balance || 0)) : 0;
    if (d.custom && needMore > 0) { body.appendChild(customBox(needMore)); }   // short for a gift: the exact amount comes first, already filled in
    if (!d.packages.length && !d.custom) { body.appendChild(el('div', 'oc-loading', 'No packages are available right now.')); }
    d.packages.forEach(function (p) {
      var b = el('button', 'oc-pk-row' + (p.featured ? ' hot' : '')); b.type = 'button';
      b.appendChild(coin(34));
      var tx = el('span', 'oc-pk-tx'); tx.appendChild(el('b', null, num(p.coins) + ' OBI COINS')); if (p.bonus) { tx.appendChild(el('em', null, '+ ' + num(p.bonus) + ' bonus')); } b.appendChild(tx);
      b.appendChild(el('span', 'oc-pk-price', money(p.price)));
      b.addEventListener('click', function () { S.pkg = p; S.step = 'pay'; S.key = uuid(); renderPay(); });
      body.appendChild(b);
    });
    if (d.custom && !(needMore > 0)) { body.appendChild(customBox(0)); }
    var c = el('button', 'oc-btn line', 'Close'); c.type = 'button'; c.addEventListener('click', close); foot.appendChild(c);
  }

  // "any amount": the person types how many OBI COINS they want. The price shown is a guide; the server works it out again.
  function customBox(prefill) {
    var cu = S.data.custom;
    var box = el('div', 'oc-custom');
    var lab = el('label', null, prefill > 0 ? 'Buy exactly what you need, or any number of OBI COINS' : 'Or enter the number of OBI COINS you want'); lab.setAttribute('for', 'ocCustom'); box.appendChild(lab);
    var row = el('div', 'oc-custom-row');
    var inp = el('input'); inp.id = 'ocCustom'; inp.type = 'text'; inp.inputMode = 'numeric'; inp.autocomplete = 'off'; inp.placeholder = 'e.g. ' + num(Math.min(cu.max, 200000));
    if (prefill > 0) { inp.value = num(Math.min(cu.max, Math.max(cu.min, prefill))); }
    var go = el('button', 'oc-btn', 'Continue'); go.type = 'button';
    row.appendChild(inp); row.appendChild(go); box.appendChild(row);
    var hint = el('div', 'oc-custom-hint'); box.appendChild(hint);
    function coins() { var v = parseInt(String(inp.value).replace(/[^\d]/g, ''), 10); return isNaN(v) ? 0 : v; }
    function price(c) { return Math.ceil(c * cu.priceE4 / 10000); }
    function paint() {
      var c = coins(), okay = c >= cu.min && c <= cu.max;
      go.disabled = !okay;
      hint.classList.toggle('bad', c > 0 && !okay);
      var range = cu.max >= 100000000 ? 'at least ' + num(cu.min) + ' OBI COINS' : 'between ' + num(cu.min) + ' and ' + num(cu.max) + ' OBI COINS';   // no maximum since 11/26
      hint.textContent = c === 0 ? 'Any number of OBI COINS, ' + range + '.' : (okay ? 'You pay ' + money(price(c)) + ' for ' + num(c) + ' OBI COINS.' : 'Choose ' + range + '.');
    }
    inp.addEventListener('input', function () {
      var c = coins(), at = inp.value.length - inp.selectionStart;
      inp.value = c ? num(Math.min(c, 99999999)) : '';   // show 497,500 as it is typed
      var pos = Math.max(0, inp.value.length - at); try { inp.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
      paint();
    });
    function choose() { var c = coins(); if (c < cu.min || c > cu.max) { return; } S.pkg = { id: 0, custom: true, name: 'Custom amount', coins: c, bonus: 0, total: c, price: price(c) }; S.step = 'pay'; S.key = uuid(); renderPay(); }
    go.addEventListener('click', choose);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); choose(); } });
    paint();
    return box;
  }

  // ------------------------------------------------------------------ step 2: see what you get, see what you pay, pay
  function renderPay(errorText) {
    var p = S.pkg;
    header('Confirm your purchase');
    body.textContent = ''; foot.textContent = '';
    var sum = el('div', 'oc-sum');
    function line(k, v, cls) { var r = el('div', 'oc-line' + (cls ? ' ' + cls : '')); r.appendChild(el('span', null, k)); r.appendChild(el('b', null, v)); sum.appendChild(r); }
    line('Package', p.name);
    line('OBI COINS', num(p.coins));
    if (p.bonus) { line('Bonus OBI COINS', '+ ' + num(p.bonus), 'bonus'); }
    line('You receive', num(p.total) + ' OBI COINS', 'total');
    line('You pay', money(p.price), 'pay');
    body.appendChild(sum);

    var f = el('div', 'oc-field'); var lab = el('label', null, 'Mobile money number'); lab.setAttribute('for', 'ocPhone'); f.appendChild(lab);
    var inp = el('input'); inp.type = 'tel'; inp.id = 'ocPhone'; inp.inputMode = 'tel'; inp.autocomplete = 'tel'; inp.placeholder = '0772 123 456'; inp.value = S.phone; f.appendChild(inp);
    var ms = el('div', 'oc-methods'); var mtn = el('div', 'oc-method', 'MTN MoMo'); var air = el('div', 'oc-method air', 'Airtel Money'); ms.appendChild(mtn); ms.appendChild(air); f.appendChild(ms);
    var note = el('div', 'oc-note', ''); f.appendChild(note); body.appendChild(f);
    function paint() {
      var m = method(inp.value);
      mtn.classList.toggle('on', m === 'MTN_MOMO'); air.classList.toggle('on', m === 'AIRTEL_MONEY');
      note.textContent = m ? 'We will send a payment request of ' + money(p.price) + ' to your ' + (m === 'MTN_MOMO' ? 'MTN' : 'Airtel') + ' number. Approve it on your phone.' : 'Use an MTN or Airtel number. We will send a payment request to it.';
    }
    inp.addEventListener('input', function () { S.phone = inp.value; paint(); }); paint();
    var err = el('div', 'oc-err', errorText || ''); err.id = 'ocErr'; err.hidden = !errorText; body.appendChild(err);

    var pay = el('button', 'oc-btn', 'Pay ' + money(p.price)); pay.type = 'button';
    pay.addEventListener('click', function () {
      if (!method(inp.value)) { err.textContent = 'Enter a valid MTN or Airtel number, like 0772 123 456.'; err.hidden = false; return; }
      pay.disabled = true; pay.textContent = 'Sending the request…'; err.hidden = true;
      var mine = S;
      var req = { action: 'start', package_id: p.id, phone: inp.value, key: S.key, method: method(inp.value) };
      if (p.custom) { req.custom_coins = p.coins; }
      api(req).then(function (r) {
        if (S !== mine) { return; }
        if (r._status === 401) { location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search); return; }
        if (!r.ok) { renderPay(r.error || 'Something went wrong. Please try again.'); return; }
        S.tx = r.id; S.price = r.price; S.gets = r.coins + r.bonus; S.step = 'wait'; S.started = Date.now(); renderWait(); startPoll();
      }, function () { if (S === mine) { renderPay('Could not reach the server. Nothing was charged. Please try again.'); } });
    });
    foot.appendChild(pay);
    if (S.data && (S.data.packages.length > 1 || S.data.custom) && !S.opts.packageId) {
      var back = el('button', 'oc-btn line', 'Back'); back.type = 'button'; back.addEventListener('click', function () { S.step = 'pick'; renderPick(); }); foot.appendChild(back);
    }
  }

  // ------------------------------------------------------------------ step 3: waiting for the phone approval
  function renderWait() {
    header('Waiting for your approval');
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'oc-state'); st.appendChild(el('div', 'oc-spin'));
    st.appendChild(el('h3', null, 'Approve the payment on your phone'));
    st.appendChild(el('p', null, 'A payment request of ' + money(S.price) + ' was sent to ' + S.phone + '. Approve it with your PIN and ' + num(S.gets) + ' OBI COINS are added to your wallet.'));
    var late = el('p', 'oc-note', ''); late.id = 'ocLate'; st.appendChild(late);
    body.appendChild(st);
    var c = el('button', 'oc-btn line', 'Close (your OBI COINS are added if you approve)'); c.type = 'button'; c.addEventListener('click', close); foot.appendChild(c);
  }
  function startPoll() {
    stopPoll();
    S.timer = setInterval(function () {
      if (!S || !S.tx) { return stopPoll(); }
      if (Date.now() - S.started > POLL_MAX_MS) {
        stopPoll();
        var l = document.getElementById('ocLate');
        if (l) { l.textContent = 'This is taking longer than usual. If you approved, your OBI COINS will still be added and you will get a notification.'; }
        return;
      }
      var mine = S;
      api({ action: 'poll', id: mine.tx }).then(function (r) {
        if (S !== mine || !r || r._status === 401) { return; }
        if (r.status === 'PAID') { stopPoll(); finish(r); }
        else if (r.status === 'FAILED') { stopPoll(); fail(r.message); }
      }, function () { /* a missed check: the next one tries again */ });
    }, POLL_MS);
  }
  function stopPoll() { if (S && S.timer) { clearInterval(S.timer); S.timer = null; } }

  // ------------------------------------------------------------------ step 4: added, or a clear failure
  function finish(r) {
    var wasOpen = sheet && sheet.classList.contains('on');
    S.step = 'done'; S.finished = true; S.result = r;
    var gained = r.credited || S.gets || 0;
    var msg = num(gained) + ' OBI COINS were added. Your balance is ' + num(r.balance) + '.';
    if (!wasOpen) { var cb = S.opts.onNotice, dn = S.opts.onDone; S = null; if (cb) { cb(msg); } if (dn) { dn(r); } return; }
    header('OBI COINS added');
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'oc-state'); st.appendChild(coin(72)); st.lastChild.classList.add('oc-pop');
    st.appendChild(el('h3', null, '+ ' + num(gained) + ' OBI COINS'));
    st.appendChild(el('p', null, 'Your balance is now ' + num(r.balance) + ' OBI COINS.'));
    body.appendChild(st);
    var done = el('button', 'oc-btn', S.opts.doneLabel || 'Done'); done.type = 'button'; done.addEventListener('click', close); foot.appendChild(done);
  }
  function fail(message, fatal) {
    var wasOpen = sheet && sheet.classList.contains('on');
    var onNotice = S && S.opts.onNotice, pkg = S && S.pkg, opts = S && S.opts;
    if (!wasOpen) { S = null; if (onNotice) { onNotice(message || 'The payment was not completed. You were not charged.'); } return; }
    if (S) { S.step = 'failed'; }
    header(fatal ? 'Not available' : 'Payment not completed');
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'oc-state bad'); st.appendChild(el('div', 'oc-bang', '!'));
    st.appendChild(el('h3', null, fatal ? 'OBI COINS is not available' : 'Payment not completed'));
    st.appendChild(el('p', null, message || 'The payment was not completed. You were not charged.'));
    body.appendChild(st);
    if (!fatal && pkg) {
      var again = el('button', 'oc-btn', 'Try again'); again.type = 'button';
      again.addEventListener('click', function () { var o = opts || {}; stopPoll(); S = null; o.packageId = pkg.id; open(o); });
      foot.appendChild(again);
    }
    var c = el('button', 'oc-btn line', 'Close'); c.type = 'button'; c.addEventListener('click', function () { stopPoll(); S = null; hide(); if (opts && opts.onClose) { opts.onClose(false); } }); foot.appendChild(c);
  }

  window.ObCoins = { open: open };
})();
