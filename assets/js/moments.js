/* Moments: the 9:16 shelf on the event page and its full-screen, swipe-up viewer.
   All numbers come from the server (api/community.php); this file only asks and then
   redraws what the server answered. Comments reuse the community's own markup and handlers. */
(function () {
  'use strict';
  var root = document.getElementById('evRoot');
  var dataEl = document.getElementById('momentsData');
  if (!root || !dataEl) return;
  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
  var items = data.items || [];
  var EC = window.EvCommunity;
  if (!items.length || !EC || !EC.api) return;

  var api = EC.api, toast = EC.toast || function () {};
  var loginUrl = root.getAttribute('data-login'); // only present for guests
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  var EMOJI = { LOVE: '❤️', FIRE: '🔥', FUNNY: '😂', EXCITED: '😍', APPLAUSE: '👏', PARTY: '🎉', WOW: '😮' };
  var byId = {};
  items.forEach(function (it) { byId[it.id] = it; });

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }
  function compact(n) {
    if (n >= 1000000) return (Math.round(n / 100000) / 10) + 'M';
    if (n >= 1000) return (Math.round(n / 100) / 10) + 'K';
    return String(n);
  }
  function momentUrl(id) { return data.eventUrl + (data.eventUrl.indexOf('?') > -1 ? '&' : '?') + 'moment=' + id; }
  /** Same-origin address for the browser's history (shared links use the canonical event URL instead). */
  function histUrl(id) { var u = new URL(location.href); u.searchParams.set('moment', id); return u.pathname + u.search + u.hash; }
  function guestGate() {
    if (!loginUrl) return false;
    var u = new URL(data.eventUrl, location.href);
    var next = u.pathname + u.search + (u.search ? '&' : '?') + 'moment=' + (items[cur] ? items[cur].id : '');
    location.href = '/login.php?next=' + encodeURIComponent(next);
    return true;
  }

  /* ---------- shelf ---------- */
  var strip = document.getElementById('mvStrip');
  if (strip) {
    var band = strip.closest('.mv-band');
    $$('.mv-card', strip).forEach(function (card) {
      card.addEventListener('click', function () { openViewer(parseInt(card.getAttribute('data-moment'), 10), card); });
    });
    $$('[data-mv-filter]', band).forEach(function (chip) {
      chip.addEventListener('click', function () {
        var f = chip.getAttribute('data-mv-filter');
        $$('[data-mv-filter]', band).forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
        $$('.mv-card', strip).forEach(function (k) { k.hidden = f !== 'all' && k.getAttribute('data-kind') !== f; });
        strip.scrollTo({ left: 0, behavior: reduce ? 'auto' : 'smooth' });
      });
    });
    var prev = $('.mv-prev', band), next = $('.mv-next', band);
    if (prev) prev.addEventListener('click', function () { strip.scrollBy({ left: -520, behavior: reduce ? 'auto' : 'smooth' }); });
    if (next) next.addEventListener('click', function () { strip.scrollBy({ left: 520, behavior: reduce ? 'auto' : 'smooth' }); });
  }

  function refreshCard(it) {
    var card = strip && $('.mv-card[data-moment="' + it.id + '"]', strip);
    if (!card) return;
    var stats = $('.mv-stats', card);
    if (stats) stats.innerHTML = '<span>❤️ ' + compact(it.reactions) + '</span><span>💬 ' + compact(it.comments) + '</span><span>↗ ' + compact(it.shares) + '</span>';
    var tag = $('.mv-tag:not(.is-photo)', card);
    if (tag) tag.lastChild.textContent = ' ' + compact(it.views);
  }

  /* ---------- viewer ---------- */
  var V = null;          // built lazily on first open
  var order = [];        // ids in the order the viewer pages through
  var cur = 0;
  var muted = true;      // browsers only allow sound after a tap; the first tap on the sound button turns it on
  var opener = null;
  var viewed = {};
  var viewTimer = null;
  var pushed = false;

  function build() {
    var v = el('div', 'mv-viewer');
    v.hidden = true;
    v.setAttribute('role', 'dialog');
    v.setAttribute('aria-modal', 'true');
    v.setAttribute('aria-label', 'Moments');
    v.innerHTML =
      '<div class="mv-amb"></div>' +
      '<button type="button" class="mv-close" aria-label="Close">&times;</button>' +
      '<div class="mv-wrap">' +
        '<div class="mv-deck">' +
          '<div class="mv-stage"><div class="mv-reel"></div></div>' +
          '<div class="mv-pn"></div>' +
          '<div class="mv-buy" hidden><div class="tx"><small>From</small><b></b><div class="urg" hidden></div></div><button type="button" class="mv-buybtn">Get tickets</button></div>' +
          '<div class="mv-rail">' +
            '<button type="button" class="mv-ava" aria-label="Follow the organizer"></button>' +
            '<button type="button" class="mv-act mv-love" aria-label="Love"><span class="ic">❤️</span><span class="n">0</span></button>' +
            '<button type="button" class="mv-act mv-more" aria-label="Choose a reaction" aria-haspopup="true"><span class="ic">😍</span><span>React</span></button>' +
            '<button type="button" class="mv-act mv-com" aria-label="Comments"><span class="ic">💬</span><span class="n">0</span></button>' +
            '<button type="button" class="mv-act mv-sh" aria-label="Share"><span class="ic">↗</span><span class="n">0</span></button>' +
            '<div class="mv-disc" aria-hidden="true"></div>' +
            '<div class="mv-picker" role="group" aria-label="Choose a reaction"></div>' +
          '</div>' +
          '<div class="mv-udn"><button type="button" class="mv-round mv-up" aria-label="Previous moment"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg></button><button type="button" class="mv-round mv-dn" aria-label="Next moment"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button></div>' +
        '</div>' +
        '<aside class="mv-docked" aria-label="Comments"><div class="in">' +
          '<header><span class="mv-ctitle">Comments</span><button type="button" class="mv-cclose" aria-label="Close comments">&times;</button></header>' +
          '<div class="ev-post mv-post"><div class="ev-thread" data-loaded="1"><div class="ev-comments"></div><div class="mv-empty" hidden>No comments yet. Be the first.</div><div class="mv-cform"></div></div></div>' +
        '</div></aside>' +
      '</div>' +
      '<div class="mv-scrim"></div>' +
      '<div class="mv-tsheet" role="dialog" aria-label="Choose tickets"><div class="grab"></div><h4></h4><div class="meta"></div><div class="trows"></div><div class="sum"><span>Total (with fee)</span><span class="tot"></span></div><button class="go" type="button">Continue to checkout</button><div class="sec">Secure checkout \u00b7 Mobile Money \u00b7 instant QR ticket</div></div>' +
      '<div class="mv-share" role="dialog" aria-label="Share"><header><span>Share this moment</span><button type="button" class="mv-sclose" aria-label="Close">&times;</button></header><div class="mv-opts"></div><div class="mv-linkrow"><span></span><button type="button" class="mv-copy">Copy link</button></div></div>';
    root.appendChild(v);

    var reel = $('.mv-reel', v), pn = $('.mv-pn', v);
    items.forEach(function (it) {
      var c = el('div', 'mv-clip' + (it.fit === 'fit' ? ' is-fit' : '') + (it.type === 'slideshow' ? ' is-slideshow' : ''));
      c.setAttribute('data-id', it.id);
      var thumb = it.type === 'video' ? it.poster : it.src;
      if (it.fit === 'fit' && thumb) c.style.setProperty('--mv-bg', "url('" + thumb.replace(/'/g, '%27') + "')");
      var mediaHtml = it.type === 'slideshow'
        ? '<div class="mv-stack"></div><div class="mv-flash"></div><div class="mv-segs"></div>' +
          '<button type="button" class="mv-sl mv-sl-prev" aria-label="Previous photo">&#8249;</button><button type="button" class="mv-sl mv-sl-next" aria-label="Next photo">&#8250;</button>'
        : it.type === 'video'
        ? '<video playsinline loop muted preload="none"' + (it.poster ? ' poster="' + it.poster + '"' : '') + ' style="object-position:' + it.focus + '% 50%"></video>'
        : '<img class="is-photo" alt="" draggable="false" style="object-position:' + it.focus + '% 50%">';
      c.innerHTML = mediaHtml + '<div class="mv-sh"></div><div class="mv-pz"><i><svg width="30" height="30" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg></i></div>' +
        '<div class="mv-top"><span class="t"></span><button type="button" class="mv-round mv-mute" aria-label="Turn sound on"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4z"/><path d="M23 9l-6 6M17 9l6 6"/></svg></button></div>' +
        '<div class="mv-cp"><div class="nm"><span class="who"></span><span class="chip">ORGANIZER</span></div><p></p><div class="mv-snd2" hidden><span aria-hidden="true">\u266B</span><div class="mq"><div class="mqin"></div></div></div><div class="vm"></div></div>' +
        (it.type === 'video' ? '<div class="mv-pg"><i></i></div>' : '');
      $('.t', c).textContent = it.type === 'video' ? data.org.name : (it.type === 'slideshow' ? it.slides.length + ' photos' : 'Photo');
      var pill = it.sound ? it.sound.title + (it.sound.artist ? ' \u00b7 ' + it.sound.artist : '') : (it.type === 'video' ? 'Original sound \u00b7 ' + data.org.name : '');
      if (pill) {
        var mq = $('.mqin', c);
        for (var rep = 0; rep < 2; rep++) { var sp = el('span'); sp.textContent = pill; mq.appendChild(sp); }
        $('.mv-snd2', c).hidden = false;
      }
      $('.who', c).textContent = data.org.name;
      var p = $('.mv-cp p', c);
      if (it.caption) p.textContent = it.caption; else p.hidden = true;
      if (it.type !== 'video' && !it.sound) $('.mv-mute', c).hidden = true; // nothing to hear
      reel.appendChild(c);
      pn.appendChild(el('i'));
    });
    var pk = $('.mv-picker', v);
    Object.keys(EMOJI).forEach(function (k) {
      var b = el('button'); b.type = 'button'; b.setAttribute('data-r', k); b.setAttribute('aria-label', k.charAt(0) + k.slice(1).toLowerCase()); b.textContent = EMOJI[k]; pk.appendChild(b);
    });
    $('.mv-ava', v).textContent = data.org.initials;
    $('.mv-ava', v).appendChild(el('b', null, '+'));
    wire(v);
    return v;
  }

  function wire(v) {
    var reel = $('.mv-reel', v), last = 0;

    reel.addEventListener('scroll', function () {
      var i = Math.round(reel.scrollTop / Math.max(1, reel.clientHeight));
      if (i !== cur && i >= 0 && i < order.length) { activate(i); if (pushed) history.replaceState({ mv: order[i] }, '', histUrl(order[i])); }
    }, { passive: true });

    // photos: arrows, and a sideways swipe on touch screens
    var sw = null;
    reel.addEventListener('pointerdown', function (e) {
      var c = e.target.closest('.mv-clip.is-slideshow');
      sw = c && e.pointerType !== 'mouse' ? { x: e.clientX, y: e.clientY } : null;
    });
    reel.addEventListener('pointerup', function (e) {
      if (!sw) return;
      var dx = e.clientX - sw.x, dy = e.clientY - sw.y; sw = null;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 2) { var pl = players[order[cur]]; if (pl && pl.next) { dx < 0 ? pl.next() : pl.prev(); } }
    });
    reel.addEventListener('click', function (e) {
      var arrow = e.target.closest('.mv-sl');
      if (arrow) { e.stopPropagation(); var pl = players[order[cur]]; if (pl && pl.next) { arrow.classList.contains('mv-sl-next') ? pl.next() : pl.prev(); } return; }
      var mute = e.target.closest('.mv-mute');
      if (mute) { e.stopPropagation(); setMuted(!muted); return; }
      if (e.target.closest('.mv-top')) return;
      var clip = e.target.closest('.mv-clip'); if (!clip) return;
      closePicker();
      var now = Date.now();
      if (now - last < 320) {
        last = 0;
        var r = clip.getBoundingClientRect(), h = el('div', 'mv-hb', '❤️');
        h.style.left = (e.clientX - r.left) + 'px'; h.style.top = (e.clientY - r.top) + 'px';
        clip.appendChild(h); setTimeout(function () { h.remove(); }, 950);
        if (!items[cur].mine) setReaction('LOVE');
        return;
      }
      last = now;
      setTimeout(function () { if (last && Date.now() - last >= 300) { last = 0; togglePause(clip); } }, 330);
    });

    $('.mv-close', v).addEventListener('click', closeViewer);
    $('.mv-up', v).addEventListener('click', function () { go(cur - 1); });
    $('.mv-dn', v).addEventListener('click', function () { go(cur + 1); });
    $('.mv-love', v).addEventListener('click', function () { setReaction(items[cur].mine ? null : 'LOVE'); });
    $('.mv-more', v).addEventListener('click', function (e) { e.stopPropagation(); if (guestGate()) return; $('.mv-picker', v).classList.toggle('open'); });
    $$('.mv-picker button', v).forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); closePicker(); var r = b.getAttribute('data-r'); setReaction(items[cur].mine === r ? null : r); });
    });
    $('.mv-com', v).addEventListener('click', function () { v.querySelector('.mv-docked').classList.contains('open') ? closeLayers() : openComments(); });
    $('.mv-cclose', v).addEventListener('click', closeLayers);
    $('.mv-sh', v).addEventListener('click', openShare);
    $('.mv-sclose', v).addEventListener('click', closeLayers);
    $('.mv-scrim', v).addEventListener('click', closeLayers);
    $('.mv-buybtn', v).addEventListener('click', openTickets);
    $('.mv-tsheet .go', v).addEventListener('click', goCheckout);
    $('.mv-copy', v).addEventListener('click', function () {
      var url = momentUrl(items[cur].id);
      if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { toast('Link copied'); }, function () { toast(url); });
      else toast(url);
      countShare();
    });
    $('.mv-ava', v).addEventListener('click', function () {
      if (guestGate()) return;
      var hero = $('[data-ev-follow="organizer"]');
      if (hero) hero.click();
    });
    var hero = $('[data-ev-follow="organizer"]');
    if (hero && window.MutationObserver) {
      new MutationObserver(function () { sync(); }).observe(hero, { attributes: true, attributeFilter: ['aria-pressed'] });
    }
    document.addEventListener('click', function (e) { if (V && !e.target.closest('.mv-picker, .mv-more')) closePicker(); });
    document.addEventListener('ev:comment-added', function (e) {
      var it = byId[e.detail.postId];
      if (!it) return;
      it.comments = e.detail.count; sync();
      var empty = $('.mv-empty', V); if (empty) empty.hidden = true;
    });
    window.addEventListener('resize', function () { if (!V.hidden) reel.scrollTop = cur * reel.clientHeight; });
    document.addEventListener('visibilitychange', function () { if (V.hidden) return; if (document.hidden) pauseAll(); else playCurrent(); });
    document.addEventListener('keydown', onKey);
  }

  function onKey(e) {
    if (!V || V.hidden) return;
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea') { if (e.key === 'Escape') e.target.blur(); return; }
    if (e.key === 'Escape') { if (!closeLayers(true)) closeViewer(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); go(cur + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); go(cur - 1); }
    else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { var pl = players[order[cur]]; if (pl && pl.next) { e.preventDefault(); e.key === 'ArrowRight' ? pl.next() : pl.prev(); } }
    else if (e.key === 'm' || e.key === 'M') { setMuted(!muted); }
    else if (e.key === ' ') { e.preventDefault(); togglePause($$('.mv-clip', V)[cur]); }
    else if (e.key === 'Tab') {
      var f = $$('button:not([hidden]):not([disabled]), a[href], input, textarea', V).filter(function (n) { return n.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], lastEl = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); lastEl.focus(); }
      else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); first.focus(); }
    }
  }

  function idxOf(id) { for (var k = 0; k < items.length; k++) if (items[k].id === id) return k; return -1; }

  function go(i) {
    i = Math.max(0, Math.min(order.length - 1, i));
    var reel = $('.mv-reel', V);
    reel.scrollTo({ top: i * reel.clientHeight, behavior: reduce ? 'auto' : 'smooth' });
  }

  function ensureMedia(item) {
    var c = $('.mv-clip[data-id="' + item.id + '"]', V), m = c && $('video, img', c);
    if (m && !m.getAttribute('src')) { m.setAttribute('src', item.src); if (item.type === 'video') m.preload = 'metadata'; }
    return m;
  }

  function activate(i) {
    cur = i;
    var id = order[i], it = byId[id];
    // only the clips next to this one hold a media source, so swiping through 30 videos stays light
    items.forEach(function (x) {
      var pos = order.indexOf(x.id), near = pos > -1 && Math.abs(pos - i) <= 1;
      var c = $('.mv-clip[data-id="' + x.id + '"]', V), m = x.type === 'slideshow' ? null : c && $('video, img', c);
      if (!near && players[x.id]) { players[x.id].destroy(); delete players[x.id]; }
      if (!m) return;
      if (near) ensureMedia(x);
      else if (m.getAttribute('src')) { if (m.tagName === 'VIDEO') m.pause(); m.removeAttribute('src'); if (m.tagName === 'VIDEO') m.load(); }
    });
    pauseAll();
    playCurrent();
    sync();
    clearTimeout(viewTimer);
    if (!viewed[id]) {
      viewTimer = setTimeout(function () {
        viewed[id] = true;
        api({ action: 'moment_view', id: id }).then(function (res) { if (res && res.counted) { it.views++; refreshCard(it); sync(); } });
      }, 1200);
    }
    ambient(it);
    var pn = $$('.mv-pn i', V);
    pn.forEach(function (d, k) { d.style.display = k < order.length && order.length <= 10 ? '' : 'none'; d.classList.toggle('on', k === i); });
  }

  var players = {}; // id -> SlideshowPlayer or SoundTrack, made when a moment becomes the current one

  /** The player that carries a moment's sound / slideshow, created on first need. */
  function ensurePlayer(it) {
    if (players[it.id]) return players[it.id];
    var c = $('.mv-clip[data-id="' + it.id + '"]', V), p = null;
    if (it.type === 'slideshow' && window.SlideshowPlayer) {
      p = SlideshowPlayer.create({
        stack: $('.mv-stack', c), segs: $('.mv-segs', c), flash: $('.mv-flash', c),
        urls: it.slides, beats: it.beats, fx: it.fx, bpm: it.sound ? it.sound.bpm : 0,
        sound: it.sound ? { src: it.sound.src, start: it.sound.start, volume: 1 } : null
      });
    } else if (it.sound && window.SoundTrack) {
      p = SoundTrack.create({ src: it.sound.src, start: it.sound.start, volume: it.type === 'video' ? it.sound.mix / 100 : 1, loopSeconds: 30 });
      var v = $('video', c);
      if (v) p.attachVideo(v);
    }
    if (p) players[it.id] = p;
    return p;
  }

  function discState(playing) { var d = V && $('.mv-disc', V); if (d) d.classList.toggle('spin', !!playing); }

  function pauseAll() {
    $$('.mv-clip video', V).forEach(function (v) { v.pause(); });
    Object.keys(players).forEach(function (k) { players[k].pause(); });
    discState(false);
  }
  function playCurrent() {
    var it = byId[order[cur]], c = $('.mv-clip[data-id="' + order[cur] + '"]', V);
    if (!it || !c) return;
    c.classList.remove('is-paused');
    var pl = (it.sound || it.type === 'slideshow') ? ensurePlayer(it) : null;
    if (it.type === 'slideshow') { if (pl) pl.play(muted); discState(!!it.sound); return; }
    var v = $('video', c);
    if (v) {
      v.muted = muted;
      v.volume = it.sound ? Math.max(0, Math.min(1, (100 - it.sound.mix) / 100)) : 1;
      var p = v.play();
      if (p && p.catch) p.catch(function () { c.classList.add('is-paused'); });
      if (pl) pl.play(muted);
      discState(true);
    } else if (pl) { pl.play(muted); discState(true); }
    else discState(false);
  }
  function togglePause(c) {
    if (!c) return;
    var it = byId[parseInt(c.getAttribute('data-id'), 10)], pl = it && players[it.id];
    if (it && it.type === 'slideshow') {
      if (!pl) return;
      if (pl.running) { pl.pause(); c.classList.add('is-paused'); discState(false); }
      else { c.classList.remove('is-paused'); pl.play(muted); discState(!!it.sound); }
      return;
    }
    var v = $('video', c);
    if (v) {
      if (v.paused) { c.classList.remove('is-paused'); v.muted = muted; v.play().catch(function () {}); if (pl) pl.play(muted); discState(true); }
      else { v.pause(); if (pl) pl.pause(); c.classList.add('is-paused'); discState(false); }
    } else if (pl) {
      if (pl.running) { pl.pause(); c.classList.add('is-paused'); discState(false); }
      else { c.classList.remove('is-paused'); pl.play(muted); discState(true); }
    }
  }
  function setMuted(m) {
    muted = m;
    $$('.mv-clip video', V).forEach(function (v) { v.muted = m; });
    Object.keys(players).forEach(function (k) { players[k].setMuted(m); });
    $$('.mv-mute', V).forEach(function (b) {
      b.classList.toggle('is-on', !m);
      b.setAttribute('aria-label', m ? 'Turn sound on' : 'Turn sound off');
      b.innerHTML = m
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4z"/><path d="M23 9l-6 6M17 9l6 6"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 010 7M19 5a10 10 0 010 14"/></svg>';
    });
    if (!m) { var c = $('.mv-clip[data-id="' + order[cur] + '"]', V), v = c && $('video', c); if (v && v.paused && !c.classList.contains('is-paused')) v.play().catch(function () {}); }
  }
  function bindProgress() {
    $$('.mv-clip', V).forEach(function (c) {
      var v = $('video', c), bar = $('.mv-pg i', c);
      if (v && bar) v.addEventListener('timeupdate', function () {
        var it = byId[parseInt(c.getAttribute('data-id'), 10)], d = isFinite(v.duration) && v.duration > 0 ? v.duration : (it && it.duration) || 0;
        if (d) bar.style.width = Math.min(100, v.currentTime / d * 100) + '%';
      });
    });
  }

  function ambient(it) {
    var src = it.type === 'video' ? it.poster : it.src;
    var amb = $('.mv-amb', V);
    if (!src || !amb) return;
    var img = new Image();
    img.onload = function () {
      try {
        var cv = document.createElement('canvas'); cv.width = cv.height = 16;
        var cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0, 16, 16);
        var d = cx.getImageData(0, 0, 16, 16).data, r = 0, g = 0, b = 0, w = 0;
        for (var i = 0; i < d.length; i += 4) {
          var mx = Math.max(d[i], d[i + 1], d[i + 2]), sat = mx - Math.min(d[i], d[i + 1], d[i + 2]), k = sat * sat + 1;
          if (mx < 40) continue;
          r += d[i] * k; g += d[i + 1] * k; b += d[i + 2] * k; w += k;
        }
        if (!w) return;
        var top = Math.max(r, g, b) / w || 1, f = 235 / top;
        amb.style.background = 'radial-gradient(60% 50% at 50% 40%, rgba(' + Math.min(255, Math.round(r / w * f)) + ',' + Math.min(255, Math.round(g / w * f)) + ',' + Math.min(255, Math.round(b / w * f)) + ',.85), transparent 70%)';
      } catch (e) { /* keep the default glow */ }
    };
    img.src = src;
  }

  function sync() {
    if (!V) return;
    var it = byId[order[cur]];
    if (!it) return;
    var love = $('.mv-love', V);
    love.classList.toggle('on', !!it.mine);
    $('.ic', love).textContent = it.mine ? EMOJI[it.mine] : '❤️';
    $('.n', love).textContent = compact(it.reactions);
    $('.mv-com .n', V).textContent = compact(it.comments);
    $('.mv-sh .n', V).textContent = compact(it.shares);
    var hero = $('[data-ev-follow="organizer"]');
    $('.mv-ava', V).classList.toggle('is-following', !!(hero && hero.getAttribute('aria-pressed') === 'true'));
    var vm = $('.mv-clip[data-id="' + it.id + '"] .vm', V);
    if (vm) vm.textContent = (it.type === 'video' ? compact(it.views) + (it.views === 1 ? ' view' : ' views') + ' · ' : '') + it.when;
    refreshCard(it);
    syncBuy(it);
  }

  /* ---------- selling: the Get tickets bar and ticket picker ---------- */
  var BUY = data.buy || null;
  var cartQty = {};
  function money(n) { return (BUY ? BUY.currency : 'UGX') + ' ' + Math.round(n).toLocaleString('en-US'); }
  function syncBuy(it) {
    var bar = $('.mv-buy', V), show = !!(BUY && it.buyBar);
    bar.hidden = !show;
    V.classList.toggle('has-buy', show);
    if (!show) return;
    $('b', bar).textContent = money(BUY.from);
    var u = $('.urg', bar), bits = [];
    if (BUY.left != null) bits.push(BUY.left + ' left');
    if (BUY.now) bits.push('Happening now');
    else if (BUY.days != null) bits.push(BUY.days === 0 ? 'Today' : BUY.days + (BUY.days === 1 ? ' day to go' : ' days to go'));
    u.hidden = !bits.length;
    u.innerHTML = bits.length ? '<i></i>' : '';
    if (bits.length) u.appendChild(document.createTextNode(bits.join(' \u00b7 ')));
  }
  function cartTotals() {
    var n = 0, sub = 0;
    BUY.tiers.forEach(function (t) { var q = cartQty[t.id] || 0; n += q; sub += q * t.price; });
    return { n: n, total: sub + n * BUY.fee };
  }
  function paintCart() {
    var rows = $('.mv-tsheet .trows', V); rows.innerHTML = '';
    BUY.tiers.forEach(function (t) {
      var q = cartQty[t.id] || 0, row = el('div', 'trow' + (q ? ' on' : ''));
      row.innerHTML = '<div class="a"><b></b><span></span></div><div class="qty"><button type="button" aria-label="Fewer">\u2212</button><output>' + q + '</output><button type="button" aria-label="More">+</button></div>';
      $('b', row).textContent = t.name;
      $('.a span', row).textContent = money(t.price) + ' \u00b7 ' + t.left + ' left';
      var btns = row.querySelectorAll('.qty button');
      btns[0].addEventListener('click', function () { cartQty[t.id] = Math.max(0, q - 1); paintCart(); });
      btns[1].addEventListener('click', function () { cartQty[t.id] = Math.min(Math.min(10, t.left), q + 1); paintCart(); });
      rows.appendChild(row);
    });
    var c = cartTotals();
    $('.mv-tsheet .tot', V).textContent = money(c.total);
    $('.mv-tsheet .go', V).disabled = c.n === 0;
  }
  function openTickets() {
    if (!BUY) return;
    var it = byId[order[cur]];
    api({ action: 'moment_cta', id: it.id }); // counts the tap and remembers which video sent them
    if (!Object.keys(cartQty).length) cartQty[BUY.tiers[0].id] = 1;
    $('.mv-tsheet h4', V).textContent = BUY.title;
    $('.mv-tsheet .meta', V).textContent = BUY.when + (BUY.where ? ' \u00b7 ' + BUY.where : '');
    paintCart();
    closeLayers(true);
    $('.mv-scrim', V).classList.add('open');
    $('.mv-tsheet', V).classList.add('open');
  }
  function goCheckout() {
    var c = cartTotals();
    if (!BUY || c.n === 0) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    var f = document.createElement('form');
    f.method = 'post'; f.action = '/checkout.php';
    function hid(n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); }
    hid('csrf_token', meta ? meta.content : '');
    hid('event_slug', BUY.slug);
    BUY.tiers.forEach(function (t) { if (cartQty[t.id]) hid('qty[' + t.id + ']', String(cartQty[t.id])); });
    document.body.appendChild(f);
    f.submit();
  }

  function pop(node) { node.classList.remove('pop'); void node.offsetWidth; node.classList.add('pop'); }

  function setReaction(type) {
    if (guestGate()) return;
    var it = items[idxOf(order[cur])];
    var prevMine = it.mine, prevCount = it.reactions;
    it.mine = type;
    if (type && !prevMine) it.reactions++;
    if (!type && prevMine) it.reactions = Math.max(0, it.reactions - 1);
    pop($('.mv-love', V)); sync();
    api({ action: 'react', target: 'post', id: it.id, reaction: type }).then(function (res) {
      if (res && res.ok) { it.reactions = res.reaction_count; it.mine = res.my_reaction || null; }
      else { it.mine = prevMine; it.reactions = prevCount; toast((res && res.error) || 'Could not react.', true); }
      sync();
    });
  }
  function closePicker() { var p = V && $('.mv-picker', V); if (p) p.classList.remove('open'); }

  /* comments */
  function openComments() {
    var it = items[idxOf(order[cur])];
    var post = $('.mv-post', V), thread = $('.ev-thread', post), holder = $('.ev-comments', thread), cform = $('.mv-cform', thread), empty = $('.mv-empty', thread);
    post.id = 'post-' + it.id; post.setAttribute('data-post-id', it.id);
    holder.innerHTML = ''; cform.innerHTML = ''; empty.hidden = true;
    var old = $('.ev-more-comments', thread); if (old) old.remove();
    $('.mv-ctitle', V).textContent = 'Comments';
    if (!it.commentsOn) {
      empty.textContent = 'Comments are turned off for this moment.'; empty.hidden = false;
    } else if (loginUrl) {
      var a = el('a', 'ev-btn ev-btn-dark ev-btn-sm', 'Log in to comment'); a.href = '#'; a.addEventListener('click', function (e) { e.preventDefault(); guestGate(); });
      cform.appendChild(a);
    } else {
      var f = el('form', 'ev-comment-form'); f.setAttribute('data-post-id', it.id);
      f.innerHTML = '<label class="sr-only">Add a comment</label><input type="text" name="body" maxlength="1000" placeholder="Add a comment" autocomplete="off"><button type="submit" class="ev-send" aria-label="Send comment"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg></button>';
      cform.appendChild(f);
    }
    $('.mv-docked', V).classList.add('open');
    if (window.matchMedia('(max-width: 900px)').matches) $('.mv-scrim', V).classList.add('open');
    closeShare();
    if (it.commentsOn) loadComments(it, thread, holder, empty);
  }
  function loadComments(it, thread, holder, empty) {
    api({ action: 'comments', post_id: it.id }).then(function (res) {
      if (!res || !res.ok) { toast((res && res.error) || 'Could not load comments.', true); return; }
      if (order[cur] !== it.id) return; // swiped away meanwhile
      EC.htmlToNodes(res.html).forEach(function (n) { holder.appendChild(n); });
      empty.hidden = holder.children.length > 0;
      if (res.has_more) {
        var more = el('button', 'ev-link-btn ev-more-comments', 'View more comments'); more.type = 'button'; more.setAttribute('data-last', res.last_id);
        thread.insertBefore(more, holder.nextSibling);
      }
      $('.mv-ctitle', V).textContent = compact(it.comments) + (it.comments === 1 ? ' comment' : ' comments');
    });
  }

  /* share */
  function shareText(it) { return it.caption ? it.caption.slice(0, 100) : 'Watch this moment'; }
  function countShare() {
    var it = items[idxOf(order[cur])];
    api({ action: 'moment_share', id: it.id }).then(function (res) { if (res && res.counted) { it.shares++; sync(); } });
  }
  function openShare() {
    var it = items[idxOf(order[cur])], url = momentUrl(it.id), text = shareText(it);
    if (coarse && navigator.share) {
      navigator.share({ title: document.title, text: text, url: url }).then(countShare, function () {});
      return;
    }
    var opts = $('.mv-opts', V); opts.innerHTML = '';
    [['WhatsApp', '#25D366', 'WA', 'https://wa.me/?text=' + encodeURIComponent(text + ' ' + url)],
     ['X', '#111111', 'X', 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(url)],
     ['Facebook', '#1877F2', 'f', 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url)]].forEach(function (o) {
      var a = el('a', null, '<i style="background:' + o[1] + '">' + o[2] + '</i>' + o[0]);
      a.href = o[3]; a.target = '_blank'; a.rel = 'noopener'; a.addEventListener('click', countShare); opts.appendChild(a);
    });
    if (navigator.share) {
      var more = el('button', null, '<i style="background:#6B6064">&hellip;</i>More'); more.type = 'button';
      more.addEventListener('click', function () { navigator.share({ title: document.title, text: text, url: url }).then(countShare, function () {}); });
      opts.appendChild(more);
    }
    $('.mv-linkrow span', V).textContent = url.replace(/^https?:\/\//, '');
    closeLayers(true);
    $('.mv-scrim', V).classList.add('open');
    $('.mv-share', V).classList.add('open');
  }
  function closeShare() { $('.mv-share', V).classList.remove('open'); }
  /** Closes the comments panel / share box. Returns true if something was open. */
  function closeLayers(silent) {
    var open = $('.mv-docked', V).classList.contains('open') || $('.mv-share', V).classList.contains('open') || $('.mv-tsheet', V).classList.contains('open') || $('.mv-picker', V).classList.contains('open');
    $('.mv-docked', V).classList.remove('open'); $('.mv-share', V).classList.remove('open'); $('.mv-tsheet', V).classList.remove('open'); $('.mv-scrim', V).classList.remove('open'); closePicker();
    return open;
  }

  /* open / close */
  function openViewer(id, fromEl, noPush) {
    if (!V) { V = build(); bindProgress(); }
    var visible = $$('.mv-card', strip).filter(function (c) { return !c.hidden; }).map(function (c) { return parseInt(c.getAttribute('data-moment'), 10); });
    order = visible.length && visible.indexOf(id) > -1 ? visible : items.map(function (x) { return x.id; });
    var i = order.indexOf(id); if (i < 0) i = 0;
    // clips outside the current filter take no space, so scroll positions match the order list
    $$('.mv-clip', V).forEach(function (c) { c.hidden = order.indexOf(parseInt(c.getAttribute('data-id'), 10)) === -1; });
    opener = fromEl || document.activeElement;
    V.hidden = false;
    document.body.classList.add('mv-open');
    if (!sessionStorageGet('mvHint')) {
      var first = $$('.mv-clip', V)[idxOf(order[i])];
      if (first && order.length > 1 && !$('.mv-hint', first)) first.appendChild(el('div', 'mv-hint', coarse ? 'Swipe up for the next moment' : 'Scroll or press ↓ for the next moment'));
      sessionStorageSet('mvHint', '1');
    }
    var reel = $('.mv-reel', V);
    reel.scrollTop = i * reel.clientHeight;
    activate(i);
    $('.mv-close', V).focus({ preventScroll: true });
    try { if (!noPush) history.pushState({ mv: id }, '', histUrl(id)); pushed = true; } catch (e) { pushed = false; }
  }
  function closeViewer() {
    if (!V || V.hidden) return;
    closeLayers(true);
    pauseAll();
    Object.keys(players).forEach(function (k) { players[k].destroy(); delete players[k]; });
    clearTimeout(viewTimer);
    V.hidden = true;
    document.body.classList.remove('mv-open');
    if (pushed) { pushed = false; history.back(); }
    if (opener && opener.focus) opener.focus({ preventScroll: true });
  }
  window.addEventListener('popstate', function (e) {
    if (V && !V.hidden) {
      // Back was pressed: close without touching history again.
      pushed = false; closeLayers(true); pauseAll(); Object.keys(players).forEach(function (k) { players[k].destroy(); delete players[k]; }); V.hidden = true; document.body.classList.remove('mv-open');
    } else if (e.state && e.state.mv && byId[e.state.mv]) {
      openViewer(e.state.mv, null, true); // forward onto a moment we pushed earlier
    }
  });

  function sessionStorageGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function sessionStorageSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* ignore */ } }

  // a shared link (?moment=ID) opens straight onto that moment
  var wanted = parseInt(data.open, 10);
  if (wanted && byId[wanted] && strip) {
    var open = function () { openViewer(wanted, $('.mv-card[data-moment="' + wanted + '"]', strip)); };
    if (document.readyState === 'complete') setTimeout(open, 50); else window.addEventListener('load', function () { setTimeout(open, 50); });
  }
})();
