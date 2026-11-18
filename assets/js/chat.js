/* One chat (public/chat.php, 11/18). Draws the messages, sends new ones, and checks for replies every few seconds
   while the page is on screen. Message text is always set with textContent (never as HTML), and links are not made
   live. Talks to /api/social.php through window.obiSocialApi (social.js). */
(function () {
  'use strict';
  var dataEl = document.getElementById('cxData');
  if (!dataEl || !window.obiSocialApi) { return; }
  var D = JSON.parse(dataEl.textContent);
  var api = window.obiSocialApi, toast = window.obiToast || function () {};
  var body = document.getElementById('cxBody');
  var form = document.getElementById('cxForm');
  var text = document.getElementById('cxText');
  var send = form ? form.querySelector('.cx-send') : null;
  var lastId = 0, firstId = 0, lastDay = '', seenEl = null, eventId = D.event || null, busy = false;

  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt != null) { e.textContent = txt; } return e; }
  function dayLabel(ts) {
    var d = new Date(ts * 1000), now = new Date(), y = new Date(); y.setDate(now.getDate() - 1);
    if (d.toDateString() === now.toDateString()) { return 'Today'; }
    if (d.toDateString() === y.toDateString()) { return 'Yesterday'; }
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  }
  function nearBottom() { return body.scrollHeight - body.scrollTop - body.clientHeight < 120; }
  function toBottom() { body.scrollTop = body.scrollHeight; }
  // stay at the newest message while pictures load, unless the person has scrolled up to read
  var stick = true;
  body.addEventListener('scroll', function () { stick = nearBottom(); }, { passive: true });
  function onImg() { if (stick) { toBottom(); } }

  function bubble(m) {
    var wrap = el('div', 'cx-msg ' + (m.mine ? 'me' : 'them'));
    wrap.setAttribute('data-id', m.id);
    if (m.event) {
      var a = el('a', 'cx-event'); a.href = m.event.url;
      if (m.event.image) { var img = el('img'); img.alt = ''; img.addEventListener('load', onImg); img.src = m.event.image; a.appendChild(img); }
      var s = el('span'); s.appendChild(el('b', null, m.event.title)); s.appendChild(el('small', null, m.event.when + (m.event.venue ? ' · ' + m.event.venue : '')));
      s.appendChild(el('em', null, 'View event')); a.appendChild(s); wrap.appendChild(a);
    }
    if (m.body) { wrap.appendChild(el('div', 'cx-bub', m.body)); }
    wrap.appendChild(el('span', 'cx-time', m.time || ''));
    return wrap;
  }
  function addDay(ts, before) {
    var lbl = dayLabel(ts);
    if (!before && lbl === lastDay) { return; }
    var d = el('div', 'cx-day', lbl);
    if (before) { body.insertBefore(d, before); } else { body.appendChild(d); lastDay = lbl; }
  }
  function append(list) {
    var stick = nearBottom();
    list.forEach(function (m) {
      if (m.id <= lastId || body.querySelector('[data-id="' + m.id + '"]')) { return; }
      addDay(m.at);
      body.appendChild(bubble(m));
      lastId = m.id;
      if (!firstId) { firstId = m.id; }
    });
    if (stick) { toBottom(); }
  }
  // "Sent" / "Seen" under your latest message (only while it is the newest message in the chat)
  var lastSeen = 0;
  function showSeen(seenId) {
    if (typeof seenId === 'number') { lastSeen = Math.max(lastSeen, seenId); }
    if (seenEl) { seenEl.remove(); seenEl = null; }
    var all = body.querySelectorAll('.cx-msg:not(.pending):not(.cx-typing)');
    var last = all[all.length - 1];
    if (!last || !last.classList.contains('me')) { return; }
    var seen = lastSeen >= +last.getAttribute('data-id');
    seenEl = el('div', 'cx-seen' + (seen ? ' is-seen' : ''), seen ? 'Seen' : 'Sent');
    last.after(seenEl);
  }

  // "typing..." from the other person: a bubble at the bottom and a line under their name
  var typingEl = null, whoSub = document.querySelector('.cx-who small'), whoSubText = whoSub ? whoSub.textContent : '';
  function showTyping(on) {
    if (on && !typingEl) {
      var stick = nearBottom();
      typingEl = el('div', 'cx-msg them cx-typing');
      var b = el('div', 'cx-bub'); b.setAttribute('aria-label', 'typing');
      b.appendChild(el('i')); b.appendChild(el('i')); b.appendChild(el('i'));
      typingEl.appendChild(b); body.appendChild(typingEl);
      if (stick) { toBottom(); }
    } else if (!on && typingEl) {
      typingEl.remove(); typingEl = null;
    }
    if (typingEl) { body.appendChild(typingEl); }   // keep it last
    if (whoSub) { whoSub.textContent = on ? 'typing…' : whoSubText; whoSub.classList.toggle('is-typing', !!on); }
  }

  // ---- first draw (+ a button for older messages)
  if (D.messages.length >= 60) {
    var older = el('button', 'cx-older', 'Load earlier messages'); older.type = 'button';
    body.insertBefore(older, body.firstChild.nextSibling);
    older.addEventListener('click', function () {
      older.disabled = true;
      api({ action: 'older', conversation_id: D.id, before: firstId }).then(function (j) {
        older.disabled = false;
        if (!j.ok || !j.messages.length) { older.remove(); return; }
        var h = body.scrollHeight, anchor = older.nextSibling;
        j.messages.forEach(function (m) { body.insertBefore(bubble(m), anchor); });
        firstId = j.messages[0].id;
        body.scrollTop = body.scrollHeight - h;
        if (j.messages.length < 40) { older.remove(); }
      });
    });
  }
  append(D.messages);
  showSeen(D.seen);
  toBottom();

  // ---- polling (only while the page is visible)
  var timer = null;
  function poll() {
    if (document.hidden || busy) { return; }
    api({ action: 'poll', conversation_id: D.id, after: lastId }).then(function (j) {
      if (!j.ok) { return; }
      if (j.messages.length) { showTyping(false); append(j.messages); }
      showSeen(j.seen);
      showTyping(!!j.typing);
      if (j.accepted) { var p = document.getElementById('cxPending'); if (p) { p.remove(); } }
    }).catch(function () {});
  }
  function start() { if (!timer) { timer = setInterval(poll, 3000); } }
  function stop() { clearInterval(timer); timer = null; }
  document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else { poll(); start(); } });
  start();

  // ---- sending
  function sync() {
    if (!send) { return; }
    send.disabled = !(text.value.trim() || eventId);
    text.style.height = 'auto';
    text.style.height = Math.min(text.scrollHeight, 140) + 'px';
  }
  if (form) {
    text.addEventListener('input', sync);
    // tell the other side "typing..." (at most every 3 seconds), and stop when the box is emptied or left
    var typingSentAt = 0, typingOn = false;
    function typing(on) {
      var now = Date.now();
      if (on && typingOn && now - typingSentAt < 3000) { return; }
      if (!on && !typingOn) { return; }
      typingOn = on; typingSentAt = now;
      api({ action: 'typing', conversation_id: D.id, typing: on }).catch(function () {});
    }
    text.addEventListener('input', function () { typing(text.value.trim() !== ''); });
    text.addEventListener('blur', function () { typing(false); });
    window.addEventListener('pagehide', function () { if (typingOn) { typing(false); } });
    text.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer:fine)').matches) { e.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = text.value.trim();
      if ((!msg && !eventId) || busy) { return; }
      busy = true;
      var temp = bubble({ id: 0, mine: true, body: msg, event: null, time: 'Sending...' });
      temp.classList.add('pending'); body.appendChild(temp); toBottom();
      var payload = { action: 'send', conversation_id: D.id, body: msg };
      if (eventId) { payload.event_id = eventId; }
      text.value = ''; sync(); typingOn = false;   // the server clears "typing" when a message is sent
      api(payload).then(function (j) {
        busy = false;
        temp.remove();
        if (!j.ok) {
          text.value = msg; sync();
          toast(j.error || 'Message not sent. Please try again.');
          return;
        }
        if (eventId) { eventId = null; var a = document.getElementById('cxAttach'); if (a) { a.remove(); } }
        append([j.message]);
        showSeen();
        var r = document.getElementById('cxReq'); if (r) { r.remove(); }
        toBottom(); sync();
      }).catch(function () {
        busy = false; temp.remove(); text.value = msg; sync();
        toast('No connection. Your message was not sent.');
      });
    });
    sync();
  }

  // ---- accept / delete / report / remove attached event
  var sheet = document.getElementById('cxReport');
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cx]');
    if (!b) { return; }
    var what = b.getAttribute('data-cx');
    if (what === 'unattach') { eventId = null; var a = document.getElementById('cxAttach'); if (a) { a.remove(); } sync(); return; }
    if (what === 'accept') {
      api({ action: 'accept', conversation_id: D.id }).then(function (j) { if (j.ok) { var r = document.getElementById('cxReq'); if (r) { r.remove(); } toast('Moved to your chats'); } });
      return;
    }
    if (what === 'clear') {
      if (!confirm('Delete this chat? It is removed for you only; the other person keeps their copy.')) { return; }
      api({ action: 'clear', conversation_id: D.id }).then(function (j) { if (j.ok) { location.href = '/inbox.php'; } });
      return;
    }
    if (what === 'report') { sheet.hidden = false; return; }
    if (what === 'report-cancel') { sheet.hidden = true; }
  });
  if (sheet) {
    sheet.addEventListener('click', function (e) { if (e.target === sheet) { sheet.hidden = true; } });
    sheet.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, reason = f.querySelector('input[name="reason"]:checked');
      if (!reason) { toast('Pick a reason first.'); return; }
      var block = f.querySelector('input[name="block"]').checked;
      api({ action: 'report', conversation_id: D.id, reason: reason.value, block: block }).then(function (j) {
        if (!j.ok) { toast(j.error || 'Something went wrong.'); return; }
        sheet.hidden = true;
        toast('Thanks. Our team will review this chat.');
        if (block) { setTimeout(function () { location.reload(); }, 900); }
      });
    });
  }
})();
