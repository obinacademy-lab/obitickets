/* OBI MARKET customer screens (ADR-003, phase 3): the product page (choose size/colour, quantity, add to cart, buy now), the cart (change, remove),
   the checkout (delivery choice, pay, wait for the phone approval) and the order page (I received it / report a problem).
   Prices, stock and every rule live on the server (includes/market.php, api/market.php): this script only drives the screens, and every answer is
   re-checked there. Text coming from the server always goes in with textContent. */
(function () {
  'use strict';
  var API = '/api/market.php';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var dataEl = document.getElementById('mkData');
  var D = {};
  try { D = dataEl ? JSON.parse(dataEl.textContent) : {}; } catch (e) { D = {}; }

  function $(id) { return document.getElementById(id); }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
  function api(b) {
    b.csrf_token = csrf;
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(b) })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { error: 'Something went wrong. Please try again.', _status: r.status }; }); },
        function () { return { error: 'Could not reach the server. Check your connection and try again.', _status: 0 }; });
  }
  function toLogin() { location.href = '/login.php?next=' + encodeURIComponent(D.next || (location.pathname + location.search)); }
  function say(node, text, cls) {
    if (!node) { return; }
    node.textContent = text || '';
    node.className = 'mk-msg' + (cls ? ' ' + cls : '');
    node.hidden = !text;
  }
  function uuid() { try { return crypto.randomUUID(); } catch (e) { return String(Date.now()) + '-' + Math.random().toString(36).slice(2, 10); } }

  // ------------------------------------------------------------------ product page
  function product() {
    var V = D.variants || [], box = $('mkOptions');
    if (!V.length || !box) { return; }
    var colours = [], sizes = [];
    V.forEach(function (v) { if (v.colour && colours.indexOf(v.colour) < 0) { colours.push(v.colour); } if (v.size && sizes.indexOf(v.size) < 0) { sizes.push(v.size); } });
    var first = V.filter(function (v) { return v.available > 0; })[0] || V[0];
    var sel = { colour: colours.length ? first.colour : '', size: sizes.length ? first.size : '' };
    var qty = 1, cur = first;
    var msg = $('mkMsg'), qEl = $('mkQty');

    function find(c, s) {
      for (var i = 0; i < V.length; i++) { if ((!colours.length || V[i].colour === c) && (!sizes.length || V[i].size === s)) { return V[i]; } }
      return null;
    }
    function group(title, values, key) {
      var g = el('div', 'mk-opt'); g.appendChild(el('span', 'mk-opt-t', title));
      var row = el('div', 'mk-chips');
      values.forEach(function (val) {
        var b = el('button', 'mk-chip', val); b.type = 'button'; b.dataset.key = key; b.dataset.val = val;
        b.addEventListener('click', function () { choose(key, val); });
        row.appendChild(b);
      });
      g.appendChild(row); box.appendChild(g);
    }
    function choose(key, val) {
      var want = { colour: sel.colour, size: sel.size }; want[key] = val;
      var v = find(want.colour, want.size);
      if (!v || v.available <= 0) {   // that combination does not exist or is gone: move the other choice to one that does
        var alt = V.filter(function (x) { return x[key] === val && x.available > 0; })[0] || V.filter(function (x) { return x[key] === val; })[0];
        if (!alt) { return; }
        want = { colour: colours.length ? alt.colour : '', size: sizes.length ? alt.size : '' };
      }
      sel = want; qty = 1; paint();
    }
    function paint() {
      cur = find(sel.colour, sel.size) || cur;
      Array.prototype.forEach.call(box.querySelectorAll('.mk-chip'), function (b) {
        var key = b.dataset.key, val = b.dataset.val;
        var other = key === 'colour' ? { colour: val, size: sel.size } : { colour: sel.colour, size: val };
        var any = V.some(function (x) { return x[key] === val && x.available > 0; });
        var v = find(other.colour, other.size);
        b.classList.toggle('on', sel[key] === val);
        b.classList.toggle('out', !any || (v && v.available <= 0));
      });
      $('mkPrice').textContent = 'UGX ' + num(cur.price);
      var note = $('mkPriceNote'); if (note) { note.textContent = ''; }
      var st = $('mkStock');
      if (cur.available <= 0) { st.textContent = 'Sold out in this option'; st.className = 'mk-stock out'; }
      else if (cur.available <= 5) { st.textContent = 'Only ' + cur.available + ' left'; st.className = 'mk-stock low'; }
      else { st.textContent = 'In stock'; st.className = 'mk-stock'; }
      var max = Math.max(1, Math.min(D.max || 20, cur.available));
      qty = Math.min(qty, max);
      if (qEl) { qEl.textContent = String(qty); }
      var minus = $('mkMinus'), plus = $('mkPlus');
      if (minus) { minus.disabled = qty <= 1; }
      if (plus) { plus.disabled = qty >= max; }
      ['mkAdd', 'mkBuy'].forEach(function (id) { var b = $(id); if (b) { b.disabled = cur.available <= 0; } });
      say(msg, '');
    }
    if (colours.length) { group('Colour', colours, 'colour'); }
    if (sizes.length) { group('Size', sizes, 'size'); }
    paint();

    var minus = $('mkMinus'), plus = $('mkPlus');
    if (minus) { minus.addEventListener('click', function () { if (qty > 1) { qty--; paint(); } }); }
    if (plus) { plus.addEventListener('click', function () { qty++; paint(); }); }
    var thumbs = document.querySelectorAll('.mk-thumb'), main = $('mkMain');
    Array.prototype.forEach.call(thumbs, function (t) { t.addEventListener('click', function () { if (main) { main.src = t.dataset.src; } Array.prototype.forEach.call(thumbs, function (x) { x.classList.toggle('on', x === t); }); }); });

    var add = $('mkAdd'), buy = $('mkBuy');
    if (add) {
      add.addEventListener('click', function () {
        if (!D.signedIn) { return toLogin(); }
        add.disabled = true; say(msg, '');
        api({ action: 'cart_add', variant_id: cur.id, quantity: qty }).then(function (r) {
          add.disabled = cur.available <= 0;
          if (r._status === 401) { return toLogin(); }
          if (!r.ok) { return say(msg, r.error || 'Could not add it.', 'err'); }
          var c = $('mkCount'); if (c) { c.textContent = String(r.count); c.hidden = !r.count; }
          msg.textContent = ''; msg.className = 'mk-msg ok'; msg.hidden = false;
          msg.appendChild(document.createTextNode('Added to your cart. '));
          var a = el('a', null, 'View cart'); a.href = '/market-cart.php'; msg.appendChild(a);
        });
      });
    }
    if (buy) {
      buy.addEventListener('click', function () {
        if (!D.signedIn) { return toLogin(); }
        location.href = '/market-checkout.php?buy=' + cur.id + '&qty=' + qty;
      });
    }
  }

  // ------------------------------------------------------------------ cart
  function cart() {
    var lines = document.querySelectorAll('.mk-line[data-variant]');
    if (!lines.length) { return; }
    var msg = $('mkMsg');
    function set(line, q) {
      api({ action: 'cart_set', variant_id: parseInt(line.dataset.variant, 10), quantity: q }).then(function (r) {
        if (r._status === 401) { return toLogin(); }
        if (!r.ok) { return say(msg, r.error || 'Could not update your cart.', 'err'); }
        location.reload();
      });
    }
    Array.prototype.forEach.call(lines, function (line) {
      var out = line.querySelector('output');
      Array.prototype.forEach.call(line.querySelectorAll('[data-d]'), function (b) {
        b.addEventListener('click', function () {
          var q = parseInt(out.textContent, 10) + parseInt(b.dataset.d, 10);
          var max = parseInt(line.dataset.max, 10) || 1;
          if (q < 1) { return; }
          if (q > max) { return say(msg, 'There are only ' + max + ' available.', 'err'); }
          set(line, q);
        });
      });
      var rm = line.querySelector('[data-remove]');
      if (rm) { rm.addEventListener('click', function () { set(line, 0); }); }
    });
  }

  // ------------------------------------------------------------------ checkout
  function checkout() {
    var form = $('mkCheckout');
    if (!form) { return; }
    var wait = $('mkWait'), err = $('mkErr'), pay = $('mkPay'), key = uuid(), timer = null, started = 0;
    var total = D.total, deliveryTotal = D.delivery;
    function method() { var r = form.querySelector('input[name="delivery_method"]:checked'); return r ? r.value : form.delivery_method.value; }
    function recalc() {
      var m = method(), t = 0;
      Array.prototype.forEach.call(document.querySelectorAll('.mk-sumstore'), function (s) {
        var fee = m === 'DELIVERY' ? parseInt(s.dataset.fee, 10) : 0, merch = parseInt(s.dataset.merch, 10);
        s.querySelector('.mk-fee').textContent = fee ? 'UGX ' + num(fee) : (m === 'DELIVERY' ? 'Free' : 'Collection, no fee');
        t += merch + fee;
      });
      total = t; $('mkTotal').textContent = 'UGX ' + num(t); pay.textContent = 'Pay UGX ' + num(t);
      var addr = $('mkAddr'), hint = $('mkCollectHint');
      if (addr) { addr.hidden = m !== 'DELIVERY'; }
      if (hint) { hint.hidden = m === 'DELIVERY'; }
    }
    Array.prototype.forEach.call(form.querySelectorAll('input[name="delivery_method"]'), function (r) { r.addEventListener('change', recalc); });
    recalc();

    function body() {
      var b = { action: 'start', key: key };
      Array.prototype.forEach.call(form.elements, function (f) { if (f.name && f.type !== 'radio') { b[f.name] = f.value; } });
      b.delivery_method = method();
      if (D.lines) { b.lines = D.lines; }
      return b;
    }
    function showForm(message) { wait.hidden = true; form.hidden = false; pay.disabled = false; key = uuid(); say(err, message, 'err'); err.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function poll(id) {
      started = Date.now(); stop();
      timer = setInterval(function () {
        if (Date.now() - started > 4 * 60 * 1000) { stop(); $('mkWaitLate').textContent = 'This is taking longer than usual. If you approved the payment, your order will still be confirmed and you will get an email. You can check "My orders" in a minute.'; return; }
        api({ action: 'poll', id: id }).then(function (r) {
          if (r._status === 401) { stop(); return toLogin(); }
          if (r.status === 'PAID') { stop(); location.href = r.orders && r.orders.length === 1 ? '/market-order.php?n=' + encodeURIComponent(r.orders[0]) + '&paid=1' : '/market-orders.php'; }
          else if (r.status === 'FAILED') { stop(); showForm(r.message || 'The payment was not completed. You were not charged.'); }
        });
      }, 2500);
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      say(err, ''); pay.disabled = true; pay.textContent = 'Sending the request…';
      api(body()).then(function (r) {
        if (r._status === 401) { return toLogin(); }
        if (!r.ok) { pay.textContent = 'Pay UGX ' + num(total); pay.disabled = false; if (r._status !== 429) { key = uuid(); } return say(err, r.error || 'Something went wrong. Nothing was charged.', 'err'); }
        form.hidden = true; wait.hidden = false;
        $('mkWaitText').textContent = 'A payment request of UGX ' + num(r.total) + ' was sent to ' + form.phone.value + '. Approve it with your PIN and your order is confirmed.';
        window.scrollTo(0, 0);
        poll(r.id);
      });
    });
  }

  // ------------------------------------------------------------------ order page
  function order() {
    var got = $('mkReceived'), pb = $('mkProblemBtn'), pf = $('mkProblemForm'), msg = $('mkMsg');
    if (got) {
      got.addEventListener('click', function () {
        if (!window.confirm('Confirm that you received this order? The seller will be paid.')) { return; }
        got.disabled = true;
        api({ action: 'received', order_id: parseInt(got.dataset.order, 10) }).then(function (r) { if (r.ok) { location.reload(); } else { got.disabled = false; say(msg, r.error || 'Could not confirm.', 'err'); } });
      });
    }
    if (pb && pf) { pb.addEventListener('click', function () { pf.hidden = !pf.hidden; if (!pf.hidden) { pf.reason.focus(); } }); }
    if (pf) {
      pf.addEventListener('submit', function (e) {
        e.preventDefault();
        var b = pf.querySelector('button'); b.disabled = true;
        api({ action: 'problem', order_id: parseInt(b.dataset.order, 10), reason: pf.reason.value }).then(function (r) { if (r.ok) { location.reload(); } else { b.disabled = false; say(msg, r.error || 'Could not send your report.', 'err'); } });
      });
    }
  }

  // ------------------------------------------------------------------ share a product
  // WhatsApp and Facebook are plain links (their share pages). Instagram and TikTok have no web share link at all, so on phones the system share
  // sheet opens with the product picture (Instagram and TikTok are in it); everywhere the link is also copied so it can be pasted in a post,
  // story, message or bio, and on a computer the app's site opens next to it.
  function share() {
    var s = D.share, box = $('mkShareMsg');
    if (!s || !document.querySelector('.mk-share')) { return; }
    function note(text) { if (box) { box.textContent = text; box.hidden = false; } }
    function copy() {
      if (navigator.clipboard && navigator.clipboard.writeText) { return navigator.clipboard.writeText(s.url).then(function () { return true; }, function () { return false; }); }
      return Promise.resolve(false);
    }
    var names = { instagram: 'Instagram', tiktok: 'TikTok' };
    var sites = { instagram: 'https://www.instagram.com/', tiktok: 'https://www.tiktok.com/upload' };
    Array.prototype.forEach.call(document.querySelectorAll('.mk-share [data-share]'), function (b) {
      var kind = b.getAttribute('data-share');
      if (kind === 'whatsapp' || kind === 'facebook') { return; }   // ordinary links
      b.addEventListener('click', function () {
        if (kind === 'copy') {
          copy().then(function (ok) { note(ok ? 'Link copied. Paste it anywhere to share this product.' : 'Copy this link: ' + s.url); });
          return;
        }
        var app = names[kind];
        var copied = copy();   // first, while the tap still counts: the link is ready to paste
        if (navigator.share) {
          var go = function (data) {
            return navigator.share(data).then(function () { note('Shared. If ' + app + ' did not keep the link, paste it: it is copied.'); }, function (e) {
              if (!e || e.name !== 'AbortError') { copied.then(function (ok) { note(ok ? 'Link copied. Open ' + app + ' and paste it in your post, story or bio.' : 'Copy this link: ' + s.url); }); }
            });
          };
          if (s.image && navigator.canShare && window.fetch) {
            fetch(s.image).then(function (r) { return r.blob(); }).then(function (blob) {
              var file = new File([blob], 'obi-market-product.' + (blob.type === 'image/png' ? 'png' : (blob.type === 'image/webp' ? 'webp' : 'jpg')), { type: blob.type || 'image/jpeg' });
              return go(navigator.canShare({ files: [file] }) ? { files: [file], title: s.title, text: s.text + ' ' + s.url } : { title: s.title, text: s.text, url: s.url });
            }, function () { return go({ title: s.title, text: s.text, url: s.url }); });
          } else {
            go({ title: s.title, text: s.text, url: s.url });
          }
          return;
        }
        copied.then(function (ok) {
          note(ok ? 'Link copied. ' + app + ' is opening: paste the link in your post, story or bio.' : 'Copy this link and paste it in ' + app + ': ' + s.url);
          window.open(sites[kind], '_blank', 'noopener');
        });
      });
    });
  }

  product(); cart(); checkout(); order(); share();
})();
