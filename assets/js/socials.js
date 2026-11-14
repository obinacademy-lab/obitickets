/* obitickets Socials: the feed (public/socials.php).
   One post per screen. The post on screen plays; the others are paused. Watching needs no account;
   liking, commenting, following and reporting send a visitor to log in. All text is put in with textContent. */
(function () {
  'use strict';
  var dataEl = document.getElementById('soData');
  var feed = document.getElementById('soFeed');
  if (!dataEl || !feed) { return; }
  var D;
  try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var items = [];            // {data, el, video, audio, slides, timer}
  var current = null;
  var muted = true;
  var loading = false;
  var hasMore = !!D.hasMore;
  var offset = D.items.length;
  var soundBtn = document.getElementById('soSound');
  var toastEl = document.getElementById('soToast');
  var shade = document.getElementById('soShade');
  var openSheet = null, lastFocus = null;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }
  function svg(paths, size, fill) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size || 24); s.setAttribute('height', size || 24);
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('fill', fill ? 'currentColor' : 'none');
    if (!fill) { s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); }
    paths.forEach(function (d) { var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); s.appendChild(p); });
    return s;
  }
  var IC = {
    heart: ['M12 20.5s-7.5-4.6-9.300-9.400C1.500 7.600 3.900 4.500 7.200 4.500c1.900 0 3.600 1 4.800 2.600 1.200-1.600 2.900-2.600 4.800-2.600 3.300 0 5.700 3.100 4.500 6.600-1.800 4.800-9.300 9.400-9.300 9.400z'],
    chat: ['M21 12a8.500 8.500 0 0 1-12.400 7.500L3 21l1.500-5.300A8.500 8.500 0 1 1 21 12z'],
    share: ['M14 5l7 6.500-7 6.500v-4c-5 0-8.500 1.500-11 5 .5-6 4-10 11-10.500z'],
    more: ['M5 12h.01M12 12h.01M19 12h.01'],
    play: ['M8 5v14l11-7z'],
    note: ['M9 18V5l11-2v13', 'M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0z', 'M20 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z'],
    ticket: ['M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z'],
    check: ['M5 12.500l4.500 4.500L19 7.500']
  };

  function api(url, body) {
    body.csrf_token = csrf;
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { error: 'Something went wrong.', _status: r.status }; }); });
  }
  function needLogin() {
    if (D.signedIn) { return false; }
    toast('Log in to do that');
    setTimeout(function () { window.location.href = D.loginUrl; }, 700);
    return true;
  }
  function toast(msg) {
    if (!toastEl) { return; }
    toastEl.textContent = msg;
    toastEl.classList.add('on');
    clearTimeout(toast.t);
    toast.t = setTimeout(function () { toastEl.classList.remove('on'); }, 2200);
  }
  function compact(n) {
    n = Number(n) || 0;
    if (n >= 1000000) { return (n / 1000000).toFixed(n >= 10000000 ? 0 : 1).replace(/\.0$/, '') + 'M'; }
    if (n >= 1000) { return (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'K'; }
    return String(n);
  }

  // ---------------------------------------------------------------- one post
  function railButton(label, paths, count, fill) {
    var b = el('button'); b.type = 'button'; b.setAttribute('aria-label', label);
    var ic = el('span', 'so-ic'); ic.appendChild(svg(paths, 24, fill)); b.appendChild(ic);
    var c = el('span', 'so-n', count); b.appendChild(c);
    b._count = c;
    return b;
  }

  function build(d) {
    var it = { data: d, video: null, audio: null, slides: [], timer: null, viewed: false, liked: !!d.mine };
    var a = el('article', 'so-item' + (d.fit === 'fit' ? ' is-fit' : ''));
    a.setAttribute('data-id', d.id);
    a.setAttribute('aria-label', d.event.title + ', by ' + d.org.name);
    it.el = a;

    var media = el('div', 'so-media');
    if (d.type === 'video') {
      var v = document.createElement('video');
      v.setAttribute('playsinline', ''); v.setAttribute('webkit-playsinline', '');
      v.loop = true; v.muted = true; v.preload = 'none';
      if (d.poster) { v.poster = d.poster; }
      v.setAttribute('data-src', d.src);
      media.appendChild(v);
      it.video = v;
    } else if (d.type === 'slideshow' && d.slides && d.slides.length) {
      d.slides.forEach(function (src, i) {
        var s = el('img', 'so-slide' + (i === 0 ? ' on' : '')); s.alt = ''; s.loading = i === 0 ? 'eager' : 'lazy'; s.src = src;
        media.appendChild(s); it.slides.push(s);
      });
    } else {
      var img = el('img'); img.alt = ''; img.loading = 'lazy'; img.src = d.src;
      media.appendChild(img);
    }
    if (d.fit === 'fit') {
      var bg = el('div', 'so-bg'); bg.style.backgroundImage = 'url("' + String(d.poster || d.src).replace(/["\\\n]/g, '') + '")';
      media.insertBefore(bg, media.firstChild);
    }
    if (d.sound && d.sound.src && d.type !== 'video') {
      it.audio = new Audio(); it.audio.preload = 'none'; it.audio.loop = true; it.audio.setAttribute('data-src', d.sound.src);
    } else if (d.sound && d.sound.src && d.type === 'video') {
      // a video with a chosen sound: the sound plays alongside it (the video's own audio is kept quiet)
      it.audio = new Audio(); it.audio.preload = 'none'; it.audio.loop = true; it.audio.setAttribute('data-src', d.sound.src);
    }
    a.appendChild(media);

    var tap = el('button', 'so-tap'); tap.type = 'button'; tap.setAttribute('aria-label', 'Pause or play. Double tap to like.');
    a.appendChild(tap);
    var paused = el('span', 'so-paused'); paused.appendChild(svg(IC.play, 40, true)); a.appendChild(paused);
    var heart = el('span', 'so-heart', '❤️'); heart.setAttribute('aria-hidden', 'true'); a.appendChild(heart);

    // rail
    var rail = el('div', 'so-rail');
    var org = el('div', 'so-org');
    var av = d.org.url ? el('a', 'so-av') : el('span', 'so-av');
    if (d.org.url) { av.href = d.org.url; av.setAttribute('aria-label', d.org.name + ' profile'); }
    if (d.org.avatar) { var ai = el('img'); ai.alt = ''; ai.src = d.org.avatar; av.appendChild(ai); } else { av.textContent = d.org.initials; }
    org.appendChild(av);
    var fol = el('button', 'so-follow' + (d.org.following ? ' is-on' : ''), d.org.following ? '✓' : '+');
    fol.type = 'button'; fol.setAttribute('aria-label', (d.org.following ? 'Following ' : 'Follow ') + d.org.name); fol.setAttribute('aria-pressed', d.org.following ? 'true' : 'false');
    if (D.me && D.me === d.org.id) { fol.hidden = true; }
    org.appendChild(fol);
    rail.appendChild(org);

    var like = railButton('Like', IC.heart, compact(d.reactions), true);
    if (it.liked) { like.classList.add('is-on'); }
    like.setAttribute('aria-pressed', it.liked ? 'true' : 'false');
    var com = railButton('Comments', IC.chat, compact(d.comments), true);
    var sh = railButton('Share', IC.share, compact(d.shares), true);
    var more = railButton('Report', IC.more, '', false);
    rail.appendChild(like); rail.appendChild(com); rail.appendChild(sh); rail.appendChild(more);
    a.appendChild(rail);

    // info
    var info = el('div', 'so-info');
    var title = el('div', 'so-title');
    var tl = el('a', null, d.event.title); tl.href = d.event.url; title.appendChild(tl);
    if (d.org.verified) { var ver = el('span', 'so-ver'); ver.title = 'Verified organizer'; ver.appendChild(svg(IC.check, 12)); title.appendChild(ver); }
    info.appendChild(title);
    info.appendChild(el('div', 'so-by', 'by ' + d.org.name + ' · ' + d.when));
    if (d.caption) {
      var cap = el('div', 'so-cap', d.caption);
      cap.addEventListener('click', function () { cap.classList.toggle('open'); });
      info.appendChild(cap);
    }
    info.appendChild(el('div', 'so-meta', d.event.when + ' | ' + d.event.where));
    if (d.sound) {
      var snd = el('div', 'so-snd'); snd.appendChild(svg(IC.note, 15));
      snd.appendChild(el('span', null, d.sound.title + (d.sound.artist ? ' – ' + d.sound.artist : '')));
      info.appendChild(snd);
    }
    a.appendChild(info);

    var cta = el('a', 'so-cta' + (d.event.ended ? ' is-ended' : ''));
    cta.href = d.event.url + (d.event.ended ? '' : '#tickets');
    cta.appendChild(svg(IC.ticket, 22));
    cta.appendChild(el('span', null, d.event.ended ? 'See this event' : 'Get Tickets'));
    if (!d.event.ended && d.event.price) { cta.appendChild(el('small', null, d.event.price)); }
    a.appendChild(cta);

    // ---- behaviour
    var lastTap = 0, tapTimer = null;
    tap.addEventListener('click', function () {
      var now = Date.now();
      if (now - lastTap < 320) {           // double tap: like
        clearTimeout(tapTimer); lastTap = 0;
        if (!it.liked) { toggleLike(true); }
        if (!reduce) { heart.classList.remove('go'); void heart.offsetWidth; heart.classList.add('go'); }
        return;
      }
      lastTap = now;
      tapTimer = setTimeout(function () { togglePause(it); }, 330);
    });

    function toggleLike(forceOn) {
      if (needLogin()) { return; }
      var on = forceOn ? true : !it.liked;
      it.liked = on;
      like.classList.toggle('is-on', on); like.setAttribute('aria-pressed', on ? 'true' : 'false');
      d.reactions = Math.max(0, d.reactions + (on ? 1 : -1));
      like._count.textContent = compact(d.reactions);
      api('/api/community.php', { action: 'react', target: 'post', id: d.id, reaction: on ? 'LOVE' : '' }).then(function (r) {
        if (r && r.ok && typeof r.reaction_count === 'number') { d.reactions = r.reaction_count; like._count.textContent = compact(d.reactions); }
        else if (r && r._status === 401) { window.location.href = D.loginUrl; }
      });
    }
    like.addEventListener('click', function () { toggleLike(false); });
    com.addEventListener('click', function () { openComments(it, com); });
    sh.addEventListener('click', function () { openShare(it, sh); });
    more.addEventListener('click', function () { if (!needLogin()) { reportFor = it; show('soMore', more); } });
    fol.addEventListener('click', function () {
      if (needLogin()) { return; }
      var on = !d.org.following;
      setFollow(d.org.id, on);
      api('/api/community.php', { action: 'follow_organizer', organizer_id: d.org.id, follow: on }).then(function (r) {
        if (!r || !r.ok) { setFollow(d.org.id, !on); toast((r && r.error) || 'Could not update. Try again.'); }
        else { toast(on ? 'Following ' + d.org.name : 'Unfollowed'); }
      });
    });
    cta.addEventListener('click', function () {
      if (!d.event.ended) { api('/api/community.php', { action: 'moment_cta', id: d.id }); }
    });
    it.followBtn = fol; it.comBtn = com; it.shareBtn = sh;
    return it;
  }

  function setFollow(orgId, on) {
    items.forEach(function (it) {
      if (it.data.org.id !== orgId) { return; }
      it.data.org.following = on;
      it.followBtn.classList.toggle('is-on', on);
      it.followBtn.textContent = on ? '✓' : '+';
      it.followBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      it.followBtn.setAttribute('aria-label', (on ? 'Following ' : 'Follow ') + it.data.org.name);
    });
  }

  // ---------------------------------------------------------------- playing
  function start(it) {
    if (current === it) { return; }
    if (current) { stop(current); }
    current = it;
    it.el.classList.remove('is-paused');
    if (it.video) {
      if (!it.video.src) { it.video.src = it.video.getAttribute('data-src'); }
      it.video.muted = muted || !!it.audio;       // with a chosen sound, the sound is the audio
      var p = it.video.play();
      if (p && p.catch) { p.catch(function () { it.video.muted = true; it.video.play().catch(function () { it.el.classList.add('is-paused'); }); }); }
    }
    if (it.audio && !muted) { playAudio(it); }
    if (it.slides.length > 1 && !reduce) {
      var i = 0, every = 2600;
      if (it.data.sound && it.data.sound.bpm > 0) { every = Math.max(900, Math.round((60000 / it.data.sound.bpm) * (it.data.beats || 2))); }
      it.timer = setInterval(function () {
        it.slides[i].classList.remove('on'); i = (i + 1) % it.slides.length; it.slides[i].classList.add('on');
      }, every);
    }
    if (!it.viewed) {
      it.viewTimer = setTimeout(function () { it.viewed = true; api('/api/community.php', { action: 'moment_view', id: it.data.id }); }, 1500);
    }
    var idx = items.indexOf(it);
    if (hasMore && !loading && idx >= items.length - 3) { loadMore(); }
    // warm up the next video so the swipe feels instant
    var next = items[idx + 1];
    if (next && next.video && !next.video.src) { next.video.preload = 'metadata'; next.video.src = next.video.getAttribute('data-src'); }
  }
  function playAudio(it) {
    if (!it.audio) { return; }
    if (!it.audio.src) { it.audio.src = it.audio.getAttribute('data-src'); }
    try { if (it.data.sound && it.data.sound.start && !it.audio.currentTime) { it.audio.currentTime = it.data.sound.start; } } catch (e) { /* not loaded yet */ }
    it.audio.volume = it.data.sound && it.data.sound.mix ? Math.min(1, it.data.sound.mix / 100) : 0.7;
    var p = it.audio.play(); if (p && p.catch) { p.catch(function () {}); }
  }
  function stop(it) {
    if (it.video) { it.video.pause(); }
    if (it.audio) { it.audio.pause(); }
    if (it.timer) { clearInterval(it.timer); it.timer = null; }
    clearTimeout(it.viewTimer);
  }
  function togglePause(it) {
    if (it !== current) { return; }
    var paused = it.el.classList.toggle('is-paused');
    if (paused) { stop(it); current = it; }
    else {
      if (it.video) { it.video.play().catch(function () {}); }
      if (it.audio && !muted) { playAudio(it); }
    }
  }
  function applyMute() {
    if (soundBtn) { soundBtn.setAttribute('aria-pressed', muted ? 'false' : 'true'); soundBtn.setAttribute('aria-label', muted ? 'Turn sound on' : 'Turn sound off'); }
    if (!current) { return; }
    if (current.video) { current.video.muted = muted || !!current.audio; }
    if (current.audio) { if (muted) { current.audio.pause(); } else if (!current.el.classList.contains('is-paused')) { playAudio(current); } }
  }
  if (soundBtn) { soundBtn.addEventListener('click', function () { muted = !muted; applyMute(); }); }

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting && en.intersectionRatio >= 0.6) {
        for (var i = 0; i < items.length; i++) { if (items[i].el === en.target) { start(items[i]); break; } }
      }
    });
  }, { root: feed, threshold: [0.6] });

  function add(list) {
    list.forEach(function (d) {
      if (items.some(function (x) { return x.data.id === d.id; })) { return; }
      var it = build(d);
      items.push(it); feed.appendChild(it.el); io.observe(it.el);
    });
  }
  function loadMore() {
    loading = true;
    api('/api/socials.php', { action: 'feed', tab: D.tab, offset: offset, start: D.start }).then(function (r) {
      loading = false;
      if (!r || !r.ok) { hasMore = false; return; }
      offset += r.items.length; hasMore = !!r.has_more;
      add(r.items);
    }, function () { loading = false; });
  }

  feed.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
    var idx = items.indexOf(current) + (e.key === 'ArrowDown' ? 1 : -1);
    if (items[idx]) { e.preventDefault(); items[idx].el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); }
  });
  document.addEventListener('visibilitychange', function () {
    if (!current) { return; }
    if (document.hidden) { stop(current); } else if (!current.el.classList.contains('is-paused')) { var c = current; current = null; start(c); }
  });

  // ---------------------------------------------------------------- sheets
  function show(id, opener) {
    hide();
    var s = document.getElementById(id);
    if (!s) { return; }
    lastFocus = opener || document.activeElement;
    s.hidden = false; if (shade) { shade.hidden = false; }
    openSheet = s;
    var f = s.querySelector('[data-so-close]'); if (f) { f.focus(); }
  }
  function hide() {
    if (!openSheet) { return; }
    openSheet.hidden = true; if (shade) { shade.hidden = true; }
    openSheet = null;
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }
  if (shade) { shade.addEventListener('click', hide); }
  document.querySelectorAll('[data-so-close]').forEach(function (b) { b.addEventListener('click', hide); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openSheet) { hide(); } });

  // ---- comments
  var cmList = document.getElementById('soCmList'), cmTitle = document.getElementById('soCmTitle');
  var cmForm = document.getElementById('soCmForm'), cmInput = document.getElementById('soCmInput');
  var replying = document.getElementById('soReplying'), replyCancel = document.getElementById('soReplyCancel');
  var cmFor = null, replyTo = null;

  function emojiOnly(t) { return t.length <= 12 && /^(?:\p{Extended_Pictographic}|‍|️|\s)+$/u.test(t); }
  function commentNode(c, isReply) {
    var row = el('div', 'so-cm'); row.setAttribute('data-id', c.id);
    var av = el('span', 'so-av');
    if (c.avatar) { var i = el('img'); i.alt = ''; i.src = c.avatar; av.appendChild(i); } else { av.textContent = c.initials; }
    row.appendChild(av);
    var main = el('div');
    var name = el('b', null, c.name);
    if (c.organizer) { name.appendChild(el('em', null, 'Organizer')); } else if (c.ticket) { name.appendChild(el('em', null, 'Has a ticket')); }
    main.appendChild(name);
    var p = el('p', c.sticker ? 'st' : (emojiOnly(c.body) ? 'big' : null));
    if (c.replyTo) { p.appendChild(el('i', null, '@' + c.replyTo + ' ')); }
    if (c.sticker && D.stickers && D.stickers[c.sticker]) {
      var st = el('img', 'so-sticker'); st.src = '/assets/stickers/' + c.sticker + '.svg'; st.alt = D.stickers[c.sticker]; st.width = 96; st.height = 96;
      p.appendChild(st);
    } else {
      p.appendChild(document.createTextNode(c.body));
    }
    main.appendChild(p);
    var meta = el('small'); meta.appendChild(el('span', null, c.when));
    var rb = el('button', null, 'Reply'); rb.type = 'button';
    rb.addEventListener('click', function () {
      if (needLogin()) { return; }
      replyTo = c.id;
      if (replying) { replying.hidden = false; replying.firstElementChild.textContent = 'Replying to ' + c.name; }
      if (cmInput) { cmInput.focus(); }
    });
    meta.appendChild(rb);
    main.appendChild(meta);
    row.appendChild(main);
    var lk = el('button', 'so-cm-like' + (c.mine ? ' is-on' : '')); lk.type = 'button';
    lk.setAttribute('aria-label', 'Like this comment'); lk.setAttribute('aria-pressed', c.mine ? 'true' : 'false');
    lk.appendChild(svg(IC.heart, 18, true));
    var n = el('span', null, c.likes ? String(c.likes) : ''); lk.appendChild(n);
    lk.addEventListener('click', function () {
      if (needLogin()) { return; }
      c.mine = !c.mine; c.likes = Math.max(0, c.likes + (c.mine ? 1 : -1));
      lk.classList.toggle('is-on', c.mine); lk.setAttribute('aria-pressed', c.mine ? 'true' : 'false'); n.textContent = c.likes ? String(c.likes) : '';
      api('/api/community.php', { action: 'react', target: 'comment', id: c.id, reaction: c.mine ? 'LOVE' : '' });
    });
    row.appendChild(lk);
    if (!isReply) {
      var reps = el('div', 'so-cm-replies');
      (c.replies || []).forEach(function (r) { reps.appendChild(commentNode(r, true)); });
      row.appendChild(reps);
    }
    return row;
  }
  function setCount(it, n) {
    it.data.comments = n;
    it.comBtn._count.textContent = compact(n);
    if (cmFor === it && cmTitle) { cmTitle.textContent = 'Comments (' + n + ')'; }
  }
  function openComments(it, opener) {
    cmFor = it; replyTo = null; if (replying) { replying.hidden = true; }
    cmTitle.textContent = 'Comments (' + it.data.comments + ')';
    cmList.textContent = '';
    cmList.appendChild(el('p', 'so-cm-none', 'Loading...'));
    show('soComments', opener);
    api('/api/socials.php', { action: 'comments', post_id: it.data.id }).then(function (r) {
      if (cmFor !== it) { return; }
      cmList.textContent = '';
      if (!r || !r.ok) { cmList.appendChild(el('p', 'so-cm-none', (r && r.error) || 'Could not load comments.')); return; }
      if (!r.comments.length) { cmList.appendChild(el('p', 'so-cm-none', r.open ? 'No comments yet. Be the first.' : 'Comments are off for this video.')); }
      r.comments.forEach(function (c) { cmList.appendChild(commentNode(c, false)); });
      if (cmForm) { cmForm.hidden = !r.open; }
    });
  }
  if (replyCancel) { replyCancel.addEventListener('click', function () { replyTo = null; replying.hidden = true; }); }
  document.querySelectorAll('[data-so-emoji]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (needLogin() || !cmInput) { return; }
      cmInput.value += b.getAttribute('data-so-emoji');
      cmInput.focus();
    });
  });
  // stickers: a button at the start of the emoji row opens the set; tapping one sends it straight away
  var stToggle = document.getElementById('soStToggle'), stBox = document.getElementById('soStickers');
  if (stToggle && stBox) {
    stToggle.addEventListener('click', function () {
      if (needLogin()) { return; }
      stBox.hidden = !stBox.hidden;
      stToggle.setAttribute('aria-expanded', stBox.hidden ? 'false' : 'true');
    });
    stBox.querySelectorAll('[data-so-sticker]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (needLogin()) { return; }
        sendComment({ sticker: b.getAttribute('data-so-sticker') }, function () { stBox.hidden = true; stToggle.setAttribute('aria-expanded', 'false'); });
      });
    });
  }

  var sending = false;
  function sendComment(extra, done) {
    if (!cmFor || sending) { return; }
    var it = cmFor, btn = cmForm ? cmForm.querySelector('button') : null;
    var body = { action: 'comment_add', post_id: it.data.id, parent_id: replyTo };
    Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
    sending = true; if (btn) { btn.disabled = true; }
    api('/api/socials.php', body).then(function (r) {
      sending = false; if (btn) { btn.disabled = false; }
      if (!r || !r.ok) { toast((r && r.error) || 'Could not post your comment.'); if (r && r._status === 401) { window.location.href = D.loginUrl; } return; }
      if (done) { done(); }
      var none = cmList.querySelector('.so-cm-none'); if (none) { none.remove(); }
      if (r.comment) {
        var parent = r.parent_id ? cmList.querySelector('.so-cm[data-id="' + Number(r.parent_id) + '"] .so-cm-replies') : null;
        var node = commentNode(r.comment, !!parent);
        (parent || cmList).appendChild(node);
        node.scrollIntoView({ block: 'nearest' });
      }
      replyTo = null; if (replying) { replying.hidden = true; }
      setCount(it, r.count);
    }, function () { sending = false; if (btn) { btn.disabled = false; } toast('Could not post your comment.'); });
  }

  if (cmForm) {
    cmForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = cmInput.value.trim();
      if (!text) { return; }
      sendComment({ body: text }, function () { cmInput.value = ''; });
    });
  }
  // ---- share
  var shareFor = null;
  function openShare(it, opener) {
    shareFor = it;
    var url = it.data.share, text = it.data.event.title + ' on obitickets Socials';
    var links = {
      whatsapp: 'https://wa.me/?text=' + encodeURIComponent(text + ' ' + url),
      facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url),
      x: 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(url),
      telegram: 'https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(text)
    };
    document.querySelectorAll('a[data-so-share]').forEach(function (a) { a.href = links[a.getAttribute('data-so-share')]; });
    var moreBtn = document.querySelector('button[data-so-share="more"]');
    if (moreBtn) { moreBtn.hidden = !navigator.share; }
    show('soShare', opener);
  }
  function countShare() {
    if (!shareFor) { return; }
    var it = shareFor;
    api('/api/community.php', { action: 'moment_share', id: it.data.id }).then(function (r) {
      if (r && r.counted) { it.data.shares += 1; it.shareBtn._count.textContent = compact(it.data.shares); }
    });
  }
  document.querySelectorAll('[data-so-share]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      var kind = b.getAttribute('data-so-share');
      if (!shareFor) { return; }
      if (kind === 'copy') {
        e.preventDefault();
        var url = shareFor.data.share;
        var done = function () { toast('Link copied'); countShare(); hide(); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link', url); }); }
        else { window.prompt('Copy this link', url); }
        return;
      }
      if (kind === 'more') {
        e.preventDefault();
        navigator.share({ title: shareFor.data.event.title, text: shareFor.data.event.title + ' on obitickets Socials', url: shareFor.data.share }).then(function () { countShare(); hide(); }, function () {});
        return;
      }
      countShare(); setTimeout(hide, 300);
    });
  });

  // ---- report
  var reportFor = null;
  document.querySelectorAll('[data-so-report]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!reportFor) { return; }
      api('/api/community.php', { action: 'report', type: 'POST', id: reportFor.data.id, reason: b.getAttribute('data-so-report') }).then(function (r) {
        hide();
        toast(r && r.ok ? 'Thanks. Our team will review it.' : ((r && r.error) || 'Could not send the report.'));
      });
    });
  });

  // ---------------------------------------------------------------- go
  add(D.items);
  applyMute();
})();
