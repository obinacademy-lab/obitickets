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
    // a speech bubble with three dots cut out of it (filled, like the template)
    chat: ['M12 3C6.500 3 2 6.800 2 11.500c0 2.400 1.100 4.500 3 6.100L4 21.500l4.600-2.200c1.100.300 2.200.500 3.400.500 5.500 0 10-3.800 10-8.500S17.500 3 12 3zM7.500 13a1.500 1.500 0 1 1 0-3 1.500 1.500 0 0 1 0 3zm4.500 0a1.500 1.500 0 1 1 0-3 1.500 1.500 0 0 1 0 3zm4.500 0a1.500 1.500 0 1 1 0-3 1.500 1.500 0 0 1 0 3z'],
    share: ['M13.500 4.500 22 11.800l-8.500 7.300v-4.300C7.900 14.700 4.300 16.600 2 20.500c.700-6.600 4.500-11.300 11.500-12.100z'],
    chev: ['M9 6l6 6-6 6'],
    more: ['M5 12h.01M12 12h.01M19 12h.01'],
    play: ['M8 5v14l11-7z'],
    note: ['M9 18V5l11-2v13', 'M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0z', 'M20 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z'],
    ticket: ['M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z'],
    save: ['M6 3h12a1 1 0 0 1 1 1v17l-7-4.500L5 21V4a1 1 0 0 1 1-1z'],
    mute: ['M11 5 6 9H3v6h3l5 4z', 'M22 9l-6 6', 'M16 9l6 6'],
    vol: ['M11 5 6 9H3v6h3l5 4z', 'M15.500 8.500a5 5 0 0 1 0 7', 'M18.500 5.500a9 9 0 0 1 0 13'],
    cal: ['M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z', 'M4 10h16', 'M8 2v4', 'M16 2v4'],
    pin: ['M12 21s7-6.200 7-11.500A7 7 0 0 0 5 9.500C5 14.800 12 21 12 21z', 'M12 12a2.500 2.500 0 1 0 0-5 2.500 2.500 0 0 0 0 5z'],
    up: ['M6 15l6-6 6 6'],
    down: ['M6 9l6 6 6-6'],
    // Boost: an arrow going up with two speed lines under it (filled, like the other buttons)
    boost: ['M12 2.500 20 11h-4.500v5h-7v-5H4z', 'M8.500 17.500h7v1.800h-7z', 'M8.500 20.600h7v1.400h-7z'],
    check: ['M5 12.500l4.500 4.500L19 7.500'],
    plus: ['M12 5v14', 'M5 12h14']
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

  // ---------------------------------------------------------------- one post (v4, 11/18)
  // Every picture and video is shown whole, never cut: it sits in a frame shaped like itself (--ar = width / height)
  // on a soft, blurred copy of itself. Tall media (stories, 9:16 videos) fill the screen like TikTok; anything shorter
  // sits above the caption, with the buttons in their own column, so nothing ever covers it.
  var TALL = 1.7;     // height / width at or above this fills the screen
  var WIDE = 1.1;     // width / height at or above this is a landscape picture (full width, above the buttons)
  function setShape(it, ar) {
    if (!(ar > 0.2 && ar < 5)) { return; }
    it.ar = ar;
    it.el.style.setProperty('--ar', String(Math.round(ar * 10000) / 10000));
    // 11/18 owner: pictures were too small above the words. Every post now uses the whole screen like TikTok:
    // the picture is as big as it can be while staying whole, and the words sit over its bottom (tap hides them).
    var shape = 'tall';
    ['tall', 'short', 'wide'].forEach(function (k) { it.el.classList.toggle('is-' + k, k === shape); });
    fill(it);
  }
  // when the picture is almost the screen's own shape, it fills edge to edge (loses at most a sliver at two edges)
  var FILL = 0.15;
  function fill(it) {
    if (!it.stage || !it.ar) { return; }
    var w = it.stage.clientWidth, h = it.stage.clientHeight;
    if (!w || !h) { return; }
    var r = it.ar / (w / h);
    it.el.classList.toggle('is-fill', r > 1 - FILL && r < 1 + FILL);
  }
  // how tall the words under a short picture are, so the picture ends just above them
  function measure(it) {
    fill(it);
    if (it.info && it.info.offsetHeight) { it.el.style.setProperty('--fd-ih', it.info.offsetHeight + 'px'); }
    if (it.rail && it.rail.offsetHeight) { it.el.style.setProperty('--fd-rh', it.rail.offsetHeight + 'px'); }
  }
  function cssUrl(u) { return 'url("' + String(u).replace(/["\\\n\r]/g, '') + '")'; }
  // the blurred background is painted only for posts on or next to the screen (keeps phones light)
  function warm(it) {
    if (!it || it.ambOn || !it.ambSrc) { return; }
    it.ambOn = true;
    it.amb.style.backgroundImage = cssUrl(it.ambSrc);
  }
  function paintSound() {
    items.forEach(function (x) {
      if (!x.soundBtn) { return; }
      x.soundBtn.classList.toggle('is-muted', muted);
      x.soundBtn.setAttribute('aria-pressed', muted ? 'false' : 'true');
      x.soundBtn.setAttribute('aria-label', muted ? 'Turn sound on' : 'Turn sound off');
    });
  }
  function railButton(label, paths, count, fill, cls) {
    var b = el('button', 'fd-act' + (cls ? ' ' + cls : '')); b.type = 'button'; b.setAttribute('aria-label', label);
    var ic = el('span', 'fd-ic'); ic.appendChild(svg(paths, 30, fill)); b.appendChild(ic);
    var c = el('span', 'fd-n', count); b.appendChild(c);
    b._count = c;
    return b;
  }

  function build(d) {
    var it = { data: d, video: null, audio: null, slides: [], bars: [], timer: null, viewed: false, liked: !!d.mine };
    var a = el('article', 'fd-item');
    a.setAttribute('data-id', d.id);
    a.setAttribute('aria-label', d.event.title + ', by ' + d.org.name);
    it.el = a;

    // soft background: the picture itself, blurred (a video's cover, or the event's own picture)
    var ambBox = el('div', 'fd-amb'); ambBox.setAttribute('aria-hidden', 'true');
    it.amb = el('i'); ambBox.appendChild(it.amb); a.appendChild(ambBox);
    it.ambSrc = d.type === 'video' ? (d.poster || (d.event && d.event.banner) || '') : (d.type === 'slideshow' ? (d.slides[0] || '') : d.src);

    // the picture, in a frame of its own shape
    var stage = el('div', 'fd-stage'), pic = el('div', 'fd-pic');
    it.stage = stage;
    stage.appendChild(pic); a.appendChild(stage);
    if (d.type === 'video') {
      var v = document.createElement('video');
      v.setAttribute('playsinline', ''); v.setAttribute('webkit-playsinline', '');
      v.loop = true; v.muted = true; v.preload = 'none';
      if (d.poster) { v.poster = d.poster; }
      v.setAttribute('data-src', d.src);
      pic.appendChild(v);
      it.video = v;
      v.addEventListener('loadedmetadata', function () { if (v.videoWidth && v.videoHeight) { setShape(it, v.videoWidth / v.videoHeight); measure(it); } });
      v.addEventListener('waiting', function () { a.classList.add('is-loading'); });
      v.addEventListener('playing', function () { a.classList.remove('is-loading', 'is-broken'); });
      v.addEventListener('canplay', function () { a.classList.remove('is-loading'); });
      v.addEventListener('error', function () { a.classList.remove('is-loading'); a.classList.add('is-broken'); });
      v.addEventListener('timeupdate', function () {
        if (it.progFill && v.duration) { it.progFill.style.transform = 'scaleX(' + Math.min(1, v.currentTime / v.duration).toFixed(4) + ')'; }
      });
      var spin = el('span', 'fd-spin'); spin.setAttribute('aria-hidden', 'true'); pic.appendChild(spin);
      pic.appendChild(el('p', 'fd-err', 'This video cannot play right now.'));
    } else if (d.type === 'slideshow' && d.slides && d.slides.length) {
      var bars = el('div', 'fd-bars'); bars.setAttribute('aria-hidden', 'true');
      d.slides.forEach(function (src, i) {
        var s = el('img', 'fd-slide' + (i === 0 ? ' on' : '')); s.alt = ''; s.decoding = 'async'; s.loading = i === 0 ? 'eager' : 'lazy'; s.src = src;
        if (i === 0) { s.addEventListener('load', function () { if (!d.ar && s.naturalWidth) { setShape(it, s.naturalWidth / s.naturalHeight); } }); }
        pic.appendChild(s); it.slides.push(s);
        var bar = el('i', i === 0 ? 'on' : null); bars.appendChild(bar); it.bars.push(bar);
      });
      if (d.slides.length > 1) { pic.appendChild(bars); }
    } else {
      var img = el('img'); img.alt = ''; img.decoding = 'async'; img.loading = 'lazy'; img.src = d.src;
      img.addEventListener('load', function () { if (!d.ar && img.naturalWidth) { setShape(it, img.naturalWidth / img.naturalHeight); } });
      pic.appendChild(img);
    }
    setShape(it, d.ar || (d.type === 'video' ? 9 / 16 : 4 / 5));
    if (d.sound && d.sound.src) {
      // a chosen sound plays with the post (a video's own audio is then kept quiet)
      it.audio = new Audio(); it.audio.preload = 'none'; it.audio.loop = true; it.audio.setAttribute('data-src', d.sound.src);
    }

    var tap = el('button', 'fd-tap'); tap.type = 'button';
    tap.setAttribute('aria-label', it.video ? 'Pause or play. Double tap to like.' : 'Show or hide the details. Double tap to like.');
    a.appendChild(tap);
    var paused = el('span', 'fd-paused'); paused.setAttribute('aria-hidden', 'true'); paused.appendChild(svg(IC.play, 34, true)); a.appendChild(paused);
    var heart = el('span', 'fd-heart'); heart.setAttribute('aria-hidden', 'true'); heart.appendChild(svg(IC.heart, 110, true)); a.appendChild(heart);

    if (it.video || it.audio) {
      a.classList.add('has-sound');
      var snd = el('button', 'fd-sound is-muted'); snd.type = 'button';
      snd.appendChild(svg(IC.mute, 20)); snd.appendChild(svg(IC.vol, 20));
      snd.addEventListener('click', function () { muted = !muted; applyMute(); paintSound(); toast(muted ? 'Sound off' : 'Sound on'); });
      a.appendChild(snd);
      it.soundBtn = snd;
    }

    // buttons (the mockup): like, comments, share, save
    var rail = el('div', 'fd-rail');
    var like = railButton('Like', IC.heart, compact(d.reactions), true, 'fd-like');
    if (it.liked) { like.classList.add('is-on'); }
    like.setAttribute('aria-pressed', it.liked ? 'true' : 'false');
    var com = railButton('Comments', IC.chat, compact(d.comments), true);
    var sh = railButton('Share', IC.share, compact(d.shares), true);
    d.saves = Number(d.saves) || 0;
    var save = railButton(d.saved ? 'Saved' : 'Save', IC.save, compact(d.saves), true, 'fd-save');
    if (d.saved) { save.classList.add('is-on'); }
    save.setAttribute('aria-pressed', d.saved ? 'true' : 'false');
    if (!D.canSave) { save.hidden = true; }
    // Boost (our "repost"): shows up once you like a video, like TikTok, and pushes it to more people.
    // Not on the organizer's own videos.
    var canBoost = !!D.canBoost && !(D.me && D.me === d.org.id);
    var boostLabel = function () { return d.boosts > 0 ? compact(d.boosts) : (d.boosted ? 'Boosted' : 'Boost'); };
    var boost = railButton(d.boosted ? 'Boosted' : 'Boost this video to more people', IC.boost, boostLabel(), true, 'fd-boost');
    if (d.boosted) { boost.classList.add('is-on'); }
    boost.setAttribute('aria-pressed', d.boosted ? 'true' : 'false');
    boost.hidden = !canBoost || !(it.liked || d.boosted);
    // organizer picture on top of the heart, like TikTok: tap the picture for their profile, tap the + to follow
    var fw = el('div', 'fd-fw');
    var av = d.org.url ? el('a', 'fd-av') : el('span', 'fd-av');
    if (d.org.url) { av.href = d.org.url; av.setAttribute('aria-label', d.org.name + ' profile'); }
    if (d.org.avatar) { var ai = el('img'); ai.alt = ''; ai.src = d.org.avatar; av.appendChild(ai); } else { av.textContent = d.org.initials; }
    fw.appendChild(av);
    var fol = el('button', 'fd-follow' + (d.org.following ? ' is-on is-gone' : ''));
    fol.type = 'button';
    fol.appendChild(svg(d.org.following ? IC.check : IC.plus, 14));
    fol.setAttribute('aria-label', (d.org.following ? 'Following ' : 'Follow ') + d.org.name); fol.setAttribute('aria-pressed', d.org.following ? 'true' : 'false');
    if (D.me && D.me === d.org.id) { fol.hidden = true; }
    fw.appendChild(fol);
    rail.appendChild(fw);
    rail.appendChild(like); rail.appendChild(boost); rail.appendChild(com); rail.appendChild(sh); rail.appendChild(save);
    a.appendChild(rail);
    it.rail = rail;
    function revealBoost() {
      if (!canBoost || !boost.hidden) { return; }
      boost.hidden = false;
      if (!reduce) { boost.classList.remove('pop'); void boost.offsetWidth; boost.classList.add('pop'); }
      measure(it);
    }

    // words: @handle and tick, the event under it; caption; when and where; sound (the picture and Follow are in the rail)
    var info = el('div', 'fd-info');
    it.info = info;
    var whoTx = el('div', 'fd-who-tx');
    var handle = el('div', 'fd-handle');
    // the organizer's name only (no @handle, no event title); tapping it opens their profile
    var hl = d.org.url ? el('a', null, d.org.name) : el('span', null, d.org.name);
    if (d.org.url) { hl.href = d.org.url; hl.setAttribute('aria-label', d.org.name + ' profile'); }
    handle.appendChild(hl);
    if (d.org.verified) { var ver = el('span', 'fd-ver'); ver.title = 'Verified organizer'; ver.appendChild(svg(IC.check, 10)); handle.appendChild(ver); }
    whoTx.appendChild(handle);
    info.appendChild(whoTx);
    if (d.caption) {
      var cap = el('div', 'fd-cap', d.caption);
      cap.setAttribute('role', 'button'); cap.setAttribute('tabindex', '0'); cap.setAttribute('aria-label', 'Caption. Tap to read more or less.');
      var flip = function () { var open = cap.classList.toggle('open'); a.classList.toggle('is-reading', open); };
      cap.addEventListener('click', flip);
      cap.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); } });
      info.appendChild(cap);
    }
    // date, place and sound: one per line, icons lined up in one column
    var facts = el('ul', 'fd-facts');
    var fact = function (cls, icon, filled, text) {
      var li = el('li', cls); li.appendChild(svg(icon, 15, filled)); li.appendChild(el('span', null, text)); facts.appendChild(li);
    };
    // kept short so the words do not cover the video: date and place share one line; "Original Audio" is not shown
    fact('fd-when', IC.cal, false, d.event.when + (d.event.where ? '  ·  ' + d.event.where : ''));
    if (d.sound) { fact('fd-track', IC.note, true, d.sound.title + (d.sound.artist ? ' – ' + d.sound.artist : '')); }
    info.appendChild(facts);
    a.appendChild(info);

    // one clear action: Buy tickets (free events: get a pass; ended: see the event). No price on the button.
    var free = d.event.price === 'Free';
    var label = d.event.ended ? 'See this event' : (free ? 'Get a free pass' : 'Buy tickets');
    var cta = el('a', 'fd-cta' + (d.event.ended ? ' is-ended' : ''));
    cta.href = d.event.url + (d.event.ended ? '' : '#tickets');
    cta.appendChild(el('span', 'fd-cta-l', label));
    cta.setAttribute('aria-label', label + ', ' + d.event.title);
    a.appendChild(cta);

    if (it.video) {
      var prog = el('div', 'fd-prog'); prog.setAttribute('aria-hidden', 'true');
      it.progFill = el('i'); prog.appendChild(it.progFill); a.appendChild(prog);
    }

    // ---- behaviour
    function burst() {
      if (reduce) { return; }
      heart.classList.remove('go'); void heart.offsetWidth; heart.classList.add('go');
    }
    var lastTap = 0, tapTimer = null;
    tap.addEventListener('click', function () {
      var now = Date.now();
      if (now - lastTap < 320) {           // double tap: like
        clearTimeout(tapTimer); lastTap = 0;
        if (!it.liked) { toggleLike(true); }
        if (it.liked) { revealBoost(); }
        burst();
        return;
      }
      lastTap = now;
      tapTimer = setTimeout(function () {
        // Phones start silent: the first tap on a post with sound turns the sound on (for every post after it).
        if (muted && (it.video || it.audio)) { muted = false; applyMute(); paintSound(); toast('Sound on'); return; }
        if (it.video) { togglePause(it); }
        else { a.classList.toggle('is-clear'); }   // pictures: hide the words to see the whole picture
      }, 330);
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
    like.addEventListener('click', function () { var was = it.liked; toggleLike(false); if (!was && it.liked) { burst(); revealBoost(); } });
    boost.addEventListener('click', function () {
      if (needLogin()) { return; }
      var on = !d.boosted;
      var paint = function (v, count) {
        d.boosted = v;
        if (typeof count === 'number') { d.boosts = count; } else { d.boosts = Math.max(0, d.boosts + (v ? 1 : -1)); }
        boost.classList.toggle('is-on', v); boost.setAttribute('aria-pressed', v ? 'true' : 'false');
        boost.setAttribute('aria-label', v ? 'Boosted' : 'Boost this video to more people');
        boost._count.textContent = boostLabel();
      };
      paint(on);
      api('/api/community.php', { action: 'boost_post', id: d.id, boost: on }).then(function (r) {
        if (r && r._status === 401) { window.location.href = D.loginUrl; return; }
        if (!r || !r.ok) { paint(!on); toast((r && r.error) || 'Could not boost. Try again.'); return; }
        paint(on, r.boost_count);
        toast(on ? 'Boosted: more people will see this video' : 'Boost removed');
      }, function () { paint(!on); toast('Could not boost. Try again.'); });
    });
    com.addEventListener('click', function () { openComments(it, com); });
    sh.addEventListener('click', function () { openShare(it, sh); });
    save.addEventListener('click', function () {
      if (needLogin()) { return; }
      var on = !d.saved;
      // the number moves at once (like the heart) and is corrected by the server's real count when it answers
      var paint = function (v) {
        if (v !== d.saved) { d.saves = Math.max(0, d.saves + (v ? 1 : -1)); }
        d.saved = v;
        save.classList.toggle('is-on', v); save.setAttribute('aria-pressed', v ? 'true' : 'false');
        save.setAttribute('aria-label', v ? 'Saved' : 'Save'); save._count.textContent = compact(d.saves);
      };
      paint(on);
      api('/api/community.php', { action: 'save_post', id: d.id, save: on }).then(function (r) {
        if (r && r._status === 401) { window.location.href = D.loginUrl; return; }
        if (!r || !r.ok) { paint(!on); toast((r && r.error) || 'Could not save. Try again.'); }
        else {
          if (typeof r.count === 'number') { d.saves = r.count; save._count.textContent = compact(d.saves); }
          toast(on ? 'Saved' : 'Removed from saved');
        }
      }, function () { paint(!on); toast('Could not save. Try again.'); });
    });
    fol.addEventListener('click', function () {
      if (needLogin()) { return; }
      if (d.org.following) { return; }   // the badge only follows; unfollow is on the organizer's profile
      var on = true;
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
      var b = it.followBtn;
      b.classList.toggle('is-on', on);
      b.replaceChildren(svg(on ? IC.check : IC.plus, 14));
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', (on ? 'Following ' : 'Follow ') + it.data.org.name);
      // like TikTok: a tick shows for a moment, then the badge folds away (unfollow lives on the organizer's profile)
      clearTimeout(it.folT);
      if (on) { it.folT = setTimeout(function () { b.classList.add('is-gone'); }, 1100); }
      else { b.classList.remove('is-gone'); }
    });
  }

  // ---------------------------------------------------------------- playing
  function start(it) {
    if (current === it) { return; }
    if (current) { stop(current); }
    current = it;
    it.el.classList.remove('is-paused');
    warm(it);
    if (it.video) {
      if (!it.video.src) { it.video.src = it.video.getAttribute('data-src'); }
      if (it.video.readyState < 3) { it.el.classList.add('is-loading'); }
      it.video.muted = muted || !!it.audio;       // with a chosen sound, the sound is the audio
      var p = it.video.play();
      if (p && p.catch) { p.catch(function () { it.video.muted = true; it.video.play().catch(function () { it.el.classList.add('is-paused'); }); }); }
    }
    if (it.audio && !muted) { playAudio(it); }
    if (it.slides.length > 1 && !reduce) {
      var i = it.slideAt || 0, every = 2600;
      if (it.data.sound && it.data.sound.bpm > 0) { every = Math.max(900, Math.round((60000 / it.data.sound.bpm) * (it.data.beats || 2))); }
      it.timer = setInterval(function () {
        it.slides[i].classList.remove('on'); if (it.bars[i]) { it.bars[i].classList.remove('on'); }
        i = (i + 1) % it.slides.length;
        it.slides[i].classList.add('on'); if (it.bars[i]) { it.bars[i].classList.add('on'); }
        it.slideAt = i;
      }, every);
    }
    if (!it.viewed) {
      it.viewTimer = setTimeout(function () { it.viewed = true; api('/api/community.php', { action: 'moment_view', id: it.data.id }); }, 1500);
    }
    var idx = items.indexOf(it);
    if (hasMore && !loading && idx >= items.length - 3) { loadMore(); }
    warm(items[idx - 1]); warm(items[idx + 1]); warm(items[idx + 2]);
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
    it.el.classList.remove('is-clear');
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
    if (!current) { return; }
    if (current.video) { current.video.muted = muted || !!current.audio; }
    if (current.audio) { if (muted) { current.audio.pause(); } else if (!current.el.classList.contains('is-paused')) { playAudio(current); } }
  }

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
      measure(it);
    });
    warm(items[0]); warm(items[1]);
    paintSound();
  }
  function loadMore() {
    loading = true;
    api('/api/socials.php', { action: 'feed', tab: D.tab, offset: offset, start: D.org ? null : D.start, org: D.org || null, near: D.near || null, seed: D.seed || null }).then(function (r) {
      loading = false;
      if (!r || !r.ok) { hasMore = false; return; }
      offset += r.items.length; hasMore = !!r.has_more;
      add(r.items);
    }, function () { loading = false; });
  }

  // ---------------------------------------------------------------- moving between posts
  function go(step) {
    var idx = items.indexOf(current) + step;
    if (items[idx]) { items[idx].el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); }
  }
  // keyboard: up / down (and Page Up / Down) move, space pauses a video, M turns the sound on or off
  document.addEventListener('keydown', function (e) {
    if (openSheet || e.altKey || e.ctrlKey || e.metaKey) { return; }
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) { return; }
    if (e.key === 'ArrowDown' || e.key === 'PageDown') { e.preventDefault(); go(1); }
    else if (e.key === 'ArrowUp' || e.key === 'PageUp') { e.preventDefault(); go(-1); }
    else if ((e.key === 'm' || e.key === 'M') && current && (current.video || current.audio)) { muted = !muted; applyMute(); paintSound(); }
    else if (e.key === ' ' && current && current.video && (t === document.body || t === feed)) { e.preventDefault(); togglePause(current); }
  });
  // computers: previous / next buttons beside the feed
  var app = document.getElementById('soApp');
  if (app) {
    var nav = el('div', 'fd-nav');
    var up = el('button'); up.type = 'button'; up.setAttribute('aria-label', 'Previous post'); up.appendChild(svg(IC.up, 22));
    var dn = el('button'); dn.type = 'button'; dn.setAttribute('aria-label', 'Next post'); dn.appendChild(svg(IC.down, 22));
    up.addEventListener('click', function () { go(-1); });
    dn.addEventListener('click', function () { go(1); });
    nav.appendChild(up); nav.appendChild(dn); app.appendChild(nav);
  }
  // a one-time hint for first-time visitors: how to move on
  function maybeHint() {
    if (!app || items.length < 2) { return; }
    try { if (localStorage.getItem('ot-feed-hint')) { return; } localStorage.setItem('ot-feed-hint', '1'); } catch (e) { return; }
    var wide = window.matchMedia && window.matchMedia('(min-width: 901px)').matches;
    var h = el('div', 'fd-hint'); h.setAttribute('aria-hidden', 'true');
    h.appendChild(svg(IC.up, 24)); h.appendChild(el('span', null, wide ? 'Scroll or press the down arrow for more' : 'Swipe up for more'));
    app.appendChild(h);
    var done = function () {
      feed.removeEventListener('scroll', done);
      h.classList.add('out'); setTimeout(function () { if (h.parentNode) { h.parentNode.removeChild(h); } }, 450);
    };
    feed.addEventListener('scroll', done, { passive: true });
    setTimeout(done, 4500);
  }
  // keep each short picture sitting just above its words when the screen size or the font changes
  var sizeTimer = null;
  window.addEventListener('resize', function () { clearTimeout(sizeTimer); sizeTimer = setTimeout(function () { items.forEach(measure); }, 150); });
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(function () { items.forEach(measure); }); }
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
      if (kind === 'report') {
        e.preventDefault();
        var target = shareFor;
        if (needLogin()) { return; }
        reportFor = target; show('soMore', target.shareBtn);
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
  maybeHint();
  // opened from a small picture on an organizer card: start on that post (it may not be the first)
  if (D.org && D.start) {
    for (var si = 0; si < items.length; si++) {
      if (items[si].data.id === D.start) { items[si].el.scrollIntoView({ block: 'start' }); break; }
    }
  }
})();
