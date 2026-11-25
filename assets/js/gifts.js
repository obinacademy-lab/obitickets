/* OBI GIFTS (phase 3): the gift sheet and the gift animations. Used by the video feed (socials.js) and any page with a gift button.
   Prices, the split and the checks all live on the server (includes/gifts.php). This script only shows the screens:
   pick a gift -> confirm and pay (phone prompt) -> waiting -> delivered with an animation (or a clear failure).
   Nothing is delivered until OUR server has asked ioTec and heard "paid"; the browser's word is never trusted.
   Text is always put in with textContent. Public API: window.ObGifts.open({organizerId, postId, fxHost, onNotice}) and ObGifts.play(host, delivery). */
(function () {
  'use strict';
  var API = '/api/gifts.php';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var POLL_MS = 2500, POLL_MAX_MS = 4 * 60 * 1000;
  var shade = null, sheet = null, head = null, body = null, foot = null;
  var S = null;   // the gift in progress

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
  function money(n) { return 'UGX ' + Number(n || 0).toLocaleString('en-US'); }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  // With OBI COINS on (ADR-002) a gift costs coins, is paid from the wallet and is delivered at once. Otherwise the old direct payment runs.
  function coinMode() { return !!(S && S.data && S.data.mode === 'coins'); }
  function price(n) { return coinMode() ? num(n) + ' OBI COINS' : money(n); }
  function coinIcon() { var c = el('i', 'og-c'); c.setAttribute('aria-hidden', 'true'); return c; }
  function api(body) {
    body.csrf_token = csrf;
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { error: 'Something went wrong.', _status: r.status }; }); });
  }
  function art(g) {
    var a = el('span', 'og-art');
    if (g.image) { var i = el('img'); i.alt = ''; i.src = g.image; a.appendChild(i); } else { a.textContent = g.emoji || '🎁'; }
    return a;
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
    shade = el('div', 'og-shade'); shade.addEventListener('click', close);
    sheet = el('section', 'og-sheet'); sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-labelledby', 'ogTitle'); sheet.tabIndex = -1;
    sheet.appendChild(el('div', 'og-handle'));
    head = el('header', 'og-head'); body = el('div', 'og-body'); foot = el('div', 'og-foot');
    sheet.appendChild(head); sheet.appendChild(body); sheet.appendChild(foot);
    document.body.appendChild(shade); document.body.appendChild(sheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('on')) { close(); } });
  }
  function header(org, title) {
    head.textContent = '';
    var av = el('span', 'og-av');
    if (org && org.avatar) { var i = el('img'); i.alt = ''; i.src = org.avatar; av.appendChild(i); } else { av.textContent = org ? String(org.name || '?').slice(0, 2).toUpperCase() : 'OB'; }
    var tx = el('div', 'og-head-tx'); tx.appendChild(el('small', null, 'OBI GIFTS'));
    var b = el('b', null, title); b.id = 'ogTitle'; tx.appendChild(b);
    var x = el('button', 'og-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.addEventListener('click', close);
    head.appendChild(av); head.appendChild(tx); head.appendChild(x);
  }
  function show() {
    build();
    document.body.classList.add('og-open');
    requestAnimationFrame(function () { shade.classList.add('on'); sheet.classList.add('on'); sheet.focus({ preventScroll: true }); });
  }
  function close() {
    if (!sheet) { return; }
    shade.classList.remove('on'); sheet.classList.remove('on');
    document.body.classList.remove('og-open');
    if (S && S.step === 'wait') { S.closedWhileWaiting = true; }   // keep checking quietly: the gift is still delivered if they approve
    else if (S && S.step !== 'done') { stopPoll(); S = null; }
    else if (S && S.step === 'done') { S = null; }
  }

  // ------------------------------------------------------------------ open and load
  function open(opts) {
    build();
    if (S && S.step === 'wait') { S.closedWhileWaiting = false; }   // reopening shows the waiting screen again
    else { S = { opts: opts, step: 'load', data: null, sel: null, qty: 1, anon: false, phone: '', key: null, tx: null, timer: null, started: 0, closedWhileWaiting: false }; }
    show();
    if (S.step === 'wait') { renderWait(); return; }
    header(null, 'Send a gift'); body.textContent = ''; foot.textContent = '';
    body.appendChild(el('div', 'og-state')).appendChild(el('div', 'og-spin'));
    api({ action: 'catalog', organizer_id: opts.organizerId, post_id: opts.postId || null }).then(function (r) {
      if (!S) { return; }
      if (r._status === 401) { close(); if (opts.loginUrl) { window.location.href = opts.loginUrl; } return; }
      if (!r.ok) { return renderMessage('bad', r.error || 'Gifts are not available right now.', null); }
      S.data = r; S.phone = r.phone || '';
      if (r.recipientError) { header(r.organizer, r.organizer.name); return renderMessage('bad', r.recipientError, null); }
      if (!r.gifts.length) { header(r.organizer, r.organizer.name); return renderMessage('bad', 'There are no gifts to send right now.', null); }
      S.step = 'pick'; renderPick();
    }, function () { if (S) { renderMessage('bad', 'We could not load the gifts. Check your connection and try again.', null); } });
  }
  function renderMessage(kind, text, retry) {
    if (!S) { return; }
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'og-state'); var ring = el('div', 'og-ring ' + (kind === 'bad' ? 'bad' : 'ok'), kind === 'bad' ? '!' : '✓'); st.appendChild(ring);
    st.appendChild(el('p', null, text)); body.appendChild(st);
    var c = el('button', 'og-btn', 'Close'); c.type = 'button'; c.addEventListener('click', close); foot.appendChild(c);
  }

  // ------------------------------------------------------------------ step 1: pick a gift
  function selected() { var d = S.data; for (var i = 0; i < d.gifts.length; i++) { if (d.gifts[i].id === S.sel) { return d.gifts[i]; } } return null; }
  function total() { var g = selected(); return g ? g.price * S.qty : 0; }
  function renderPick() {
    header(S.data.organizer, S.data.organizer.name);
    body.textContent = ''; foot.textContent = '';
    var first = !S.entered; S.entered = true;   // the cards pop in when the sheet opens, not again on every tap
    var grid = el('div', 'og-grid' + (first ? ' og-enter' : '')); grid.setAttribute('role', 'listbox'); grid.setAttribute('aria-label', 'Gifts');
    grid.style.setProperty('--ph', '-' + ((Date.now() / 1000) % 6).toFixed(2) + 's');   // the grid is rebuilt on every tap: keep the loops where they were
    S.data.gifts.forEach(function (g, idx) {
      var t = el('button', 'og-tile a-' + String(g.anim || 'pop').replace(/[^a-z]/g, '') + (g.id === S.sel ? ' on' : '')); t.style.setProperty('--i', String(idx)); t.type = 'button'; t.setAttribute('role', 'option'); t.setAttribute('aria-selected', g.id === S.sel ? 'true' : 'false');
      t.setAttribute('aria-label', g.name + ', ' + price(g.price));
      t.appendChild(art(g)); t.appendChild(el('b', null, g.name));
      var pe = el('em', null, coinMode() ? num(g.price) : money(g.price)); if (coinMode()) { pe.classList.add('c'); pe.insertBefore(coinIcon(), pe.firstChild); } t.appendChild(pe);
      t.addEventListener('click', function () { S.sel = g.id; if (S.qty * g.price > S.data.limits.max) { S.qty = Math.max(1, Math.floor(S.data.limits.max / g.price)); } renderPick(); });
      grid.appendChild(t);
    });
    if (coinMode()) { body.appendChild(balanceBar()); }
    body.appendChild(grid);
    var g = selected();
    var row = el('div', 'og-row');
    var q = el('div', 'og-qty'); var minus = el('button', null, '−'); minus.type = 'button'; minus.setAttribute('aria-label', 'Fewer'); var n = el('span', null, String(S.qty));
    var plus = el('button', null, '+'); plus.type = 'button'; plus.setAttribute('aria-label', 'More');
    minus.disabled = !g || S.qty <= 1; plus.disabled = !g || (S.qty + 1) * g.price > S.data.limits.max || S.qty >= S.data.limits.maxQty;
    minus.addEventListener('click', function () { if (S.qty > 1) { S.qty--; renderPick(); } });
    plus.addEventListener('click', function () { S.qty++; renderPick(); });
    q.appendChild(minus); q.appendChild(n); q.appendChild(plus);
    var tot = el('div', 'og-total'); tot.appendChild(el('small', null, g ? 'Total' : 'Pick a gift')); tot.appendChild(el('b', null, g ? price(total()) : '—'));
    row.appendChild(q); row.appendChild(tot); body.appendChild(row);
    var short = coinMode() && g ? total() - S.data.wallet.balance : 0;
    if (short > 0) {
      // not enough OBI COINS: say how many are missing and offer to add them (the gift stays chosen while they do)
      body.appendChild(el('div', 'og-short', 'You need ' + num(short) + ' more OBI COINS for this gift.'));
      var add = el('button', 'og-btn', 'Add OBI COINS'); add.type = 'button'; add.addEventListener('click', function () { addCoins(total()); }); foot.appendChild(add);
    } else {
      var go = el('button', 'og-btn', g ? 'Continue · ' + price(total()) : 'Pick a gift'); go.type = 'button'; go.disabled = !g;
      go.addEventListener('click', function () { S.step = 'pay'; S.key = uuid(); if (coinMode()) { renderConfirm(); } else { renderPay(); } });
      foot.appendChild(go);
    }
  }

  // ------------------------------------------------------------------ OBI COINS: balance, confirm, add coins
  function balanceBar() {
    var b = el('div', 'og-bal'); b.appendChild(coinIcon()); b.appendChild(el('span', null, 'Your balance'));
    b.appendChild(el('b', null, num(S.data.wallet.balance) + ' OBI COINS'));
    var add = el('button', null, '+ Add'); add.type = 'button'; add.addEventListener('click', function () { addCoins(0); }); b.appendChild(add);
    return b;
  }
  function anonSwitch() {
    var sw = el('div', 'og-switch' + (S.anon ? ' on' : '')); sw.setAttribute('role', 'switch'); sw.tabIndex = 0; sw.setAttribute('aria-checked', S.anon ? 'true' : 'false');
    var swt = el('div'); swt.appendChild(el('b', null, 'Send anonymously')); swt.appendChild(el('small', null, 'The organizer and other viewers will not see your name.')); sw.appendChild(swt); sw.appendChild(el('i'));
    function flip() { S.anon = !S.anon; sw.classList.toggle('on', S.anon); sw.setAttribute('aria-checked', S.anon ? 'true' : 'false'); }
    sw.addEventListener('click', flip); sw.addEventListener('keydown', function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); flip(); } });
    return sw;
  }
  function renderConfirm(errorText) {
    var g = selected(), d = S.data, bal = d.wallet.balance, cost = total();
    header(d.organizer, 'Confirm your gift');
    body.textContent = ''; foot.textContent = '';
    var sum = el('div', 'og-sum'); sum.appendChild(art(g));
    var tx = el('div'); tx.appendChild(el('b', null, (S.qty > 1 ? S.qty + ' × ' : '') + g.name)); tx.appendChild(el('small', null, 'to ' + d.organizer.name)); sum.appendChild(tx);
    var pr = el('div', 'og-price'); pr.appendChild(coinIcon()); pr.appendChild(document.createTextNode(num(cost))); sum.appendChild(pr); body.appendChild(sum);
    var lines = el('div', 'og-lines');
    [['Price', num(cost) + ' OBI COINS', ''], ['Your balance', num(bal) + ' OBI COINS', ''], ['Balance after this gift', num(bal - cost) + ' OBI COINS', 'after']].forEach(function (l) {
      var r = el('div', 'og-ln' + (l[2] ? ' ' + l[2] : '')); r.appendChild(el('span', null, l[0])); r.appendChild(el('b', null, l[1])); lines.appendChild(r);
    });
    body.appendChild(lines);
    body.appendChild(anonSwitch());
    var err = el('div', 'og-err', errorText || ''); err.setAttribute('role', 'alert'); body.appendChild(err);
    var label = 'Send gift · ' + num(cost) + ' OBI COINS';
    var send = el('button', 'og-btn', label); send.type = 'button';
    send.addEventListener('click', function () {
      send.disabled = true; send.textContent = 'Sending…'; err.textContent = '';
      var mine = S;
      api({ action: 'start', organizer_id: S.opts.organizerId, post_id: S.opts.postId || null, gift_id: g.id, quantity: S.qty, anonymous: S.anon, key: S.key }).then(function (r) {
        if (S !== mine) { return; }
        if (r._status === 401) { close(); if (S && S.opts.loginUrl) { window.location.href = S.opts.loginUrl; } return; }
        if (r.insufficient) { S.data.wallet.balance = r.balance; S.step = 'pick'; renderPick(); return; }
        if (!r.ok) { send.disabled = false; send.textContent = label; err.textContent = r.error || 'We could not send the gift. Nothing was taken from your wallet. Please try again.'; return; }
        S.step = 'done'; finish({ status: 'PAID', delivery: r.delivery, balance_after: r.balance_after });
      }, function () { send.disabled = false; send.textContent = label; err.textContent = 'Connection problem. If your balance did not change, please try again.'; });
    });
    foot.appendChild(send);
    var back = el('button', 'og-btn line', 'Back'); back.type = 'button'; back.addEventListener('click', function () { S.step = 'pick'; renderPick(); }); foot.appendChild(back);
  }
  function softHide() { shade.classList.remove('on'); sheet.classList.remove('on'); document.body.classList.remove('og-open'); }
  function addCoins(needed) {
    if (!window.ObCoins) { window.location.href = '/wallet.php'; return; }
    var keep = S;   // the gift in progress stays exactly as it is while they add coins
    softHide();
    ObCoins.open({
      needed: needed, doneLabel: 'Back to my gift',
      onClose: function () { if (S === keep) { refreshAfterCoins(); } },
      onDone: function () { if (S === keep && sheet.classList.contains('on')) { refreshAfterCoins(); } },
      onNotice: function (m) { if (keep && keep.opts.onNotice) { keep.opts.onNotice(m); } }
    });
  }
  function refreshAfterCoins() {
    if (!S || S.refreshing) { return; }
    S.refreshing = true; show();
    api({ action: 'catalog', organizer_id: S.opts.organizerId, post_id: S.opts.postId || null }).then(function (r) {
      if (!S) { return; }
      S.refreshing = false;
      if (r.ok && r.gifts) { var sel = S.sel, q = S.qty; S.data = r; S.sel = sel; S.qty = q; }
      S.step = 'pick'; renderPick();
    }, function () { if (S) { S.refreshing = false; S.step = 'pick'; renderPick(); } });
  }

  // ------------------------------------------------------------------ step 2: confirm and pay
  function renderPay(errorText) {
    var g = selected(), d = S.data;
    header(d.organizer, 'Confirm your gift');
    body.textContent = ''; foot.textContent = '';
    var sum = el('div', 'og-sum'); sum.appendChild(art(g));
    var tx = el('div'); tx.appendChild(el('b', null, (S.qty > 1 ? S.qty + ' × ' : '') + g.name)); tx.appendChild(el('small', null, 'to ' + d.organizer.name)); sum.appendChild(tx);
    sum.appendChild(el('div', 'og-price', money(total()))); body.appendChild(sum);

    var f = el('div', 'og-field'); var lab = el('label', null, 'Mobile money number'); lab.setAttribute('for', 'ogPhone'); f.appendChild(lab);
    var inp = el('input'); inp.type = 'tel'; inp.id = 'ogPhone'; inp.inputMode = 'tel'; inp.autocomplete = 'tel'; inp.placeholder = '0772 123 456'; inp.value = S.phone; f.appendChild(inp);
    var ms = el('div', 'og-methods'); var mtn = el('div', 'og-method', 'MTN MoMo'); var air = el('div', 'og-method air', 'Airtel Money'); ms.appendChild(mtn); ms.appendChild(air); f.appendChild(ms);
    var note = el('div', 'og-note', 'We will send a payment request to this number. Approve it on your phone.'); f.appendChild(note); body.appendChild(f);
    function paintMethod() {
      var m = method(inp.value);
      mtn.classList.toggle('on', m === 'MTN_MOMO'); air.classList.toggle('on', m === 'AIRTEL_MONEY');
      note.textContent = m ? 'We will send a payment request to your ' + (m === 'MTN_MOMO' ? 'MTN' : 'Airtel') + ' number. Approve it on your phone.' : 'Use an MTN or Airtel number. We will send a payment request to it.';
    }
    inp.addEventListener('input', function () { S.phone = inp.value; paintMethod(); }); paintMethod();

    var sw = el('div', 'og-switch' + (S.anon ? ' on' : '')); sw.setAttribute('role', 'switch'); sw.tabIndex = 0; sw.setAttribute('aria-checked', S.anon ? 'true' : 'false');
    var swt = el('div'); swt.appendChild(el('b', null, 'Send anonymously')); swt.appendChild(el('small', null, 'The organizer and other viewers will not see your name.')); sw.appendChild(swt); sw.appendChild(el('i'));
    function flip() { S.anon = !S.anon; sw.classList.toggle('on', S.anon); sw.setAttribute('aria-checked', S.anon ? 'true' : 'false'); }
    sw.addEventListener('click', flip); sw.addEventListener('keydown', function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); flip(); } });
    body.appendChild(sw);
    var err = el('div', 'og-err', errorText || ''); err.setAttribute('role', 'alert'); body.appendChild(err);

    var pay = el('button', 'og-btn', 'Pay ' + money(total())); pay.type = 'button';
    pay.addEventListener('click', function () {
      if (!method(inp.value)) { err.textContent = 'Enter a valid MTN or Airtel number, like 0772 123 456.'; inp.focus(); return; }
      pay.disabled = true; pay.textContent = 'Starting…'; err.textContent = '';
      api({ action: 'start', organizer_id: S.opts.organizerId, post_id: S.opts.postId || null, gift_id: g.id, quantity: S.qty, anonymous: S.anon, phone: inp.value, method: method(inp.value), key: S.key }).then(function (r) {
        if (!S) { return; }
        if (r._status === 401) { close(); if (S && S.opts.loginUrl) { window.location.href = S.opts.loginUrl; } return; }
        if (!r.ok) { pay.disabled = false; pay.textContent = 'Pay ' + money(total()); err.textContent = r.error || 'We could not start the payment. Please try again.'; return; }
        S.tx = r.id; S.step = 'wait'; S.started = Date.now(); renderWait(); startPoll();
      }, function () { pay.disabled = false; pay.textContent = 'Pay ' + money(total()); err.textContent = 'Connection problem. Please try again.'; });
    });
    foot.appendChild(pay);
    var back = el('button', 'og-btn line', 'Back'); back.type = 'button'; back.addEventListener('click', function () { S.step = 'pick'; renderPick(); }); foot.appendChild(back);
  }

  // ------------------------------------------------------------------ step 3: waiting for the phone approval
  function renderWait() {
    if (!S || S.closedWhileWaiting) { return; }
    var d = S.data, g = selected();
    header(d.organizer, 'Approve on your phone');
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'og-state'); st.appendChild(el('div', 'og-spin'));
    st.appendChild(el('h3', null, 'Waiting for your approval'));
    st.appendChild(el('p', null, 'A payment request of ' + money(total()) + ' was sent to ' + S.phone + '. Approve it on your phone with your PIN.'));
    var late = el('p', 'og-note', ''); late.id = 'ogLate'; st.appendChild(late);
    body.appendChild(st);
    var c = el('button', 'og-btn line', 'Close (your gift is still delivered if you approve)'); c.type = 'button'; c.addEventListener('click', close); foot.appendChild(c);
  }
  function startPoll() {
    stopPoll();
    S.timer = setInterval(function () {
      if (!S || !S.tx) { return stopPoll(); }
      if (Date.now() - S.started > POLL_MAX_MS) {
        stopPoll();
        var l = document.getElementById('ogLate');
        if (l) { l.textContent = 'This is taking longer than usual. If you approved, your gift will still be delivered and you will get a notification.'; }
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

  // ------------------------------------------------------------------ step 4: delivered or failed
  function finish(r) {
    var delivery = r.delivery;
    if (r.balance_after != null && S.data && S.data.wallet) { S.data.wallet.balance = r.balance_after; }
    var wasOpen = sheet.classList.contains('on');
    S.step = 'done';
    var host = S.opts.fxHost;
    if (delivery) { play(host, delivery); }
    if (!wasOpen) { if (S.opts.onNotice) { S.opts.onNotice(delivery ? delivery.message : 'Your gift was delivered. Thank you!'); } S = null; return; }
    var g = selected();
    header(S.data.organizer, 'Gift sent');
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'og-state'); st.appendChild(art(g));
    st.appendChild(el('h3', null, 'Gift delivered!'));
    st.appendChild(el('p', null, delivery ? delivery.message : 'Thank you for supporting the organizer.'));
    if (coinMode() && r.balance_after != null) { st.appendChild(el('p', 'og-note', 'Your balance is now ' + num(r.balance_after) + ' OBI COINS.')); }
    body.appendChild(st);
    var done = el('button', 'og-btn', 'Done'); done.type = 'button'; done.addEventListener('click', close); foot.appendChild(done);
    setTimeout(function () { if (S && S.step === 'done' && sheet.classList.contains('on')) { close(); } }, 2300);   // then the sheet steps aside so the animation can be seen
  }
  function fail(message) {
    var wasOpen = sheet.classList.contains('on');
    if (!wasOpen) { if (S && S.opts.onNotice) { S.opts.onNotice(message || 'The gift payment did not go through.'); } S = null; return; }
    S.step = 'failed';
    header(S.data.organizer, 'Payment not completed');
    body.textContent = ''; foot.textContent = '';
    var st = el('div', 'og-state'); st.appendChild(el('div', 'og-ring bad', '!')); st.appendChild(el('h3', null, 'The payment did not go through'));
    st.appendChild(el('p', null, (message || 'It was not approved in time.') + ' You have not been charged for a gift.'));
    body.appendChild(st);
    var again = el('button', 'og-btn', 'Try again'); again.type = 'button'; again.addEventListener('click', function () { S.step = 'pay'; S.tx = null; S.key = uuid(); renderPay(); }); foot.appendChild(again);
    var c = el('button', 'og-btn line', 'Close'); c.type = 'button'; c.addEventListener('click', close); foot.appendChild(c);
  }

  // ------------------------------------------------------------------ the animation (never pauses the video)
  var COLORS = ['#F0313F', '#FBBF24', '#34D399', '#38BDF8', '#A78BFA', '#FB7185', '#FFFFFF'];
  function bigArt(g, qty) {
    var b = el('div', 'og-big');
    var src = g.animAsset || g.image;
    if (src) { var i = el('img'); i.alt = ''; i.src = src; b.appendChild(i); } else { b.textContent = g.emoji || '🎁'; }
    if (qty > 1) { b.appendChild(el('span', 'og-x-qty', '×' + qty)); }
    return b;
  }
  function bit(g, cls) {
    var b = el('span', cls);
    if (g.animAsset || g.image) { var i = el('img'); i.alt = ''; i.src = g.animAsset || g.image; b.appendChild(i); } else { b.textContent = g.emoji || '🎁'; }
    return b;
  }
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function play(host, d) {
    if (!d || !d.gift) { return; }
    host = host || document.body;
    if (host !== document.body && getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
    var g = d.gift, kind = g.anim || 'pop';
    var fx = el('div', 'og-fx og-a-' + kind + (reduce ? ' og-still' : '')); fx.setAttribute('aria-hidden', 'true');
    if (host === document.body) { fx.style.position = 'fixed'; fx.style.zIndex = '95'; }
    var i;
    if (kind === 'float' && !reduce) {
      for (i = 0; i < 11; i++) { var b = bit(g, 'og-bit'); b.style.left = rnd(34, 92).toFixed(0) + '%'; b.style.setProperty('--t', rnd(0, 1.2).toFixed(2) + 's'); b.style.setProperty('--d', rnd(2.4, 3.4).toFixed(2) + 's'); b.style.setProperty('--x', rnd(-26, 26).toFixed(0) + 'px'); fx.appendChild(b); }
      if (d.quantity > 1) { fx.appendChild(bigArt(g, d.quantity)); fx.lastChild.style.cssText = 'left:50%;top:44%;margin:-46px 0 0 -46px;animation:ogPop 3.4s both'; }
    } else {
      fx.appendChild(bigArt(g, d.quantity));
      if (!reduce) {
        if (kind === 'sparkle') { for (i = 0; i < 14; i++) { var s = el('span', 'og-spark', '✦'); s.style.setProperty('--a', (i * 360 / 14).toFixed(0) + 'deg'); s.style.setProperty('--r', rnd(100, 170).toFixed(0) + 'px'); s.style.setProperty('--t', rnd(0, 1.2).toFixed(2) + 's'); fx.appendChild(s); } }
        if (kind === 'confetti') { for (i = 0; i < 34; i++) { var c = el('span', 'og-bit'); c.style.left = rnd(0, 100).toFixed(0) + '%'; c.style.background = COLORS[i % COLORS.length]; c.style.setProperty('--t', rnd(0.2, 1.6).toFixed(2) + 's'); c.style.setProperty('--d', rnd(2.2, 3.4).toFixed(2) + 's'); c.style.setProperty('--x', rnd(-60, 60).toFixed(0) + 'px'); c.style.setProperty('--r', rnd(300, 900).toFixed(0) + 'deg'); fx.appendChild(c); } }
        if (kind === 'fly') { for (i = 0; i < 7; i++) { var t = el('span', 'og-trail'); t.style.setProperty('--t', (i * 0.07).toFixed(2) + 's'); t.style.setProperty('--k', (0.82 - i * 0.05).toFixed(2)); fx.appendChild(t); } }
        if (kind === 'jet') { fx.appendChild(el('div', 'og-contrail')); }
      }
    }
    if (d.message) { fx.appendChild(el('div', 'og-fx-msg', d.message)); }
    host.appendChild(fx);
    setTimeout(function () { if (fx.parentNode) { fx.parentNode.removeChild(fx); } }, 4700);
  }

  // a small notice for pages that have no toast of their own (the feed passes its own)
  function notice(text) {
    var n = el('div', 'og-toast', text); n.setAttribute('role', 'status'); document.body.appendChild(n);
    setTimeout(function () { n.classList.add('off'); }, 3200); setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 3700);
  }
  var _open = open;
  open = function (opts) { if (!opts.onNotice) { opts.onNotice = notice; } return _open(opts); };

  window.ObGifts = { open: function (o) { return open(o); }, play: play };
})();
