// Event community interactions: follow, react, comment, report, block, moderate,
// "show more". All state lives on the server (api/community.php); the page only
// asks and then redraws what the server answered. Guests are sent to log in.
(function () {
  var root = document.getElementById('evRoot');
  if (!root) return;

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  var loginUrl = root.getAttribute('data-login'); // only present for guests
  var eventUrl = root.getAttribute('data-event-url') || location.href.split('#')[0];

  // Keep the sticky tab bar directly under the sticky site nav, whatever its height.
  function setNavHeight() {
    var nav = document.querySelector('.site-nav');
    if (nav) root.style.setProperty('--ev-nav', nav.offsetHeight + 'px');
  }
  setNavHeight();
  window.addEventListener('resize', setNavHeight);

  function api(payload) {
    payload.csrf_token = csrf;
    return fetch('/api/community.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().then(function (d) { d._status = r.status; return d; })
        .catch(function () { return { error: 'Something went wrong. Please try again.', _status: r.status }; });
    }).catch(function () { return { error: 'Check your connection and try again.' }; });
  }

  var toastEl = document.getElementById('evToast');
  var toastTimer = null;
  function toast(msg, isError) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-error', !!isError);
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
  }

  function needLogin() {
    if (loginUrl) { window.location.href = loginUrl; return true; }
    return false;
  }

  function htmlToNodes(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    return Array.prototype.slice.call(tmp.children);
  }

  function closeFloating(except) {
    document.querySelectorAll('.ev-picker:not([hidden])').forEach(function (p) { if (p !== except) p.hidden = true; });
    document.querySelectorAll('.ev-menu:not([hidden])').forEach(function (m) {
      if (m !== except) { m.hidden = true; var b = m.parentNode.querySelector('.ev-menu-btn'); if (b) b.setAttribute('aria-expanded', 'false'); }
    });
  }

  // ---------- follow buttons (event + organizer) ----------
  function renderFollow(type, id, following, followers) {
    document.querySelectorAll('[data-ev-follow="' + type + '"][data-id="' + id + '"]').forEach(function (b) {
      b.setAttribute('aria-pressed', following ? 'true' : 'false');
      var label = b.querySelector('.ev-follow-label');
      var text = following ? 'Following' : (type === 'event' ? 'Follow event' : 'Follow');
      if (label) label.textContent = text; else b.textContent = text;
    });
    if (typeof followers === 'number') {
      if (type === 'event') {
        var f = document.getElementById('pulseFollowing'); if (f) f.textContent = followers.toLocaleString();
      } else {
        var a = document.getElementById('orgFollowers'); if (a) a.textContent = followers.toLocaleString();
        document.querySelectorAll('.orgFollowersMirror').forEach(function (n) { n.textContent = followers.toLocaleString(); });
      }
    }
  }

  // ---------- reactions ----------
  var REACTION_EMOJI = { LOVE: '❤️', FIRE: '🔥', FUNNY: '😂', EXCITED: '😍', APPLAUSE: '👏', PARTY: '🎉', WOW: '😮' };
  function nice(key) { return key.charAt(0) + key.slice(1).toLowerCase(); }

  function applyReaction(btn, isComment, res, reaction) {
    var on = !!res.my_reaction;
    btn.setAttribute('data-my', res.my_reaction || '');
    btn.classList.toggle('is-on', on);
    if (isComment) {
      btn.textContent = on ? REACTION_EMOJI[res.my_reaction] + ' ' + nice(res.my_reaction) : 'React';
      var cnt = btn.closest('.ev-comment-meta').querySelector('.ev-comment-count');
      if (cnt) cnt.textContent = res.reaction_count > 0 ? '❤️ ' + res.reaction_count : '';
    } else {
      var ico = btn.querySelector('.ev-react-ico'), lab = btn.querySelector('.ev-react-label');
      if (on) { if (ico) ico.textContent = REACTION_EMOJI[res.my_reaction]; if (lab) lab.textContent = nice(res.my_reaction); }
      else {
        if (ico) ico.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 5.6a5.2 5.2 0 0 0-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 0 0-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 0 0 0-7.4z"/></svg>';
        if (lab) lab.textContent = 'React';
      }
      if (res.summary_html) {
        var post = btn.closest('.ev-post'), sum = post.querySelector('.ev-react-sum');
        var nodes = htmlToNodes(res.summary_html);
        if (sum && nodes[0]) sum.replaceWith(nodes[0]);
      }
    }
  }

  // ---------- comments ----------
  function loadComments(thread, postId, afterId) {
    var holder = thread.querySelector('.ev-comments');
    return api({ action: 'comments', post_id: postId, after_id: afterId || null }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Could not load comments.', true); return; }
      htmlToNodes(res.html).forEach(function (n) { holder.appendChild(n); });
      thread.setAttribute('data-loaded', '1');
      var old = thread.querySelector('.ev-more-comments'); if (old) old.remove();
      if (res.has_more) {
        var more = document.createElement('button');
        more.type = 'button'; more.className = 'ev-link-btn ev-more-comments'; more.textContent = 'View more comments';
        more.setAttribute('data-last', res.last_id);
        thread.insertBefore(more, holder.nextSibling);
      }
    });
  }

  function setCommentCount(post, n) {
    var btn = post.querySelector('.ev-count-btn');
    var text = n + ' comment' + (n === 1 ? '' : 's');
    if (btn) { btn.textContent = text; return; }
    var stats = post.querySelector('.ev-post-stats');
    if (stats) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'ev-count-btn'; b.setAttribute('data-act', 'toggle-comments'); b.textContent = text;
      stats.appendChild(b);
    }
  }

  // ---------- report dialog ----------
  var modal = document.getElementById('evReport');
  var reportTarget = null;
  function openReport(type, id) {
    if (!modal) return;
    reportTarget = { type: type, id: id };
    modal.querySelectorAll('input[name="reason"]').forEach(function (r) { r.checked = false; });
    var d = document.getElementById('evReportDetails'); if (d) d.value = '';
    modal.hidden = false;
    var first = modal.querySelector('input[name="reason"]'); if (first) first.focus();
  }
  function closeReport() { if (modal) modal.hidden = true; reportTarget = null; }
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('[data-modal="cancel"]')) { closeReport(); return; }
      if (e.target.closest('[data-modal="send"]')) {
        var checked = modal.querySelector('input[name="reason"]:checked');
        if (!checked) { toast('Choose a reason first.', true); return; }
        var details = document.getElementById('evReportDetails');
        var t = reportTarget; closeReport();
        api({ action: 'report', type: t.type, id: t.id, reason: checked.value, details: details ? details.value : '' }).then(function (res) {
          toast(res.ok ? "Thanks. We'll take a look." : (res.error || 'Could not send the report.'), !res.ok);
        });
      }
    });
  }

  // ---------- click delegation ----------
  root.addEventListener('click', function (e) {
    var t = e.target;

    var copyBtn = t.closest('[data-ev-copy]');
    if (copyBtn) {
      var shareUrl = copyBtn.getAttribute('data-ev-copy');
      if (navigator.share) { navigator.share({ title: document.title, url: shareUrl }).catch(function () {}); }
      else if (navigator.clipboard) { navigator.clipboard.writeText(shareUrl).then(function () { toast('Link copied'); }); }
      return;
    }

    var follow = t.closest('[data-ev-follow]');
    if (follow) {
      if (needLogin()) return;
      var type = follow.getAttribute('data-ev-follow'), id = follow.getAttribute('data-id');
      var next = follow.getAttribute('aria-pressed') !== 'true';
      renderFollow(type, id, next); // optimistic
      api(type === 'event' ? { action: 'follow_event', event_id: id, follow: next } : { action: 'follow_organizer', organizer_id: id, follow: next })
        .then(function (res) {
          if (res.ok) renderFollow(type, id, res.following, res.followers);
          else { renderFollow(type, id, !next); toast(res.error || 'Something went wrong.', true); }
        });
      return;
    }

    var pickBtn = t.closest('.ev-picker-btn');
    if (pickBtn) {
      var wrap = pickBtn.closest('.ev-react-wrap'), trigger = wrap.querySelector('.ev-react-btn');
      var isComment = !!pickBtn.closest('.ev-comment');
      var holder = pickBtn.closest(isComment ? '.ev-comment' : '.ev-post');
      var reaction = pickBtn.getAttribute('data-reaction');
      var mine = trigger.getAttribute('data-my');
      wrap.querySelector('.ev-picker').hidden = true;
      api({ action: 'react', target: isComment ? 'comment' : 'post', id: holder.getAttribute(isComment ? 'data-comment-id' : 'data-post-id'), reaction: mine === reaction ? null : reaction })
        .then(function (res) { if (res.ok) applyReaction(trigger, isComment, res); else toast(res.error || 'Could not react.', true); });
      return;
    }

    var menuBtn = t.closest('.ev-menu-btn');
    if (menuBtn) {
      if (needLogin()) return;
      var menu = menuBtn.parentNode.querySelector('.ev-menu'), open = menu.hidden;
      closeFloating(menu);
      menu.hidden = !open;
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }

    var actBtn = t.closest('[data-act]');
    if (actBtn) {
      var act = actBtn.getAttribute('data-act');
      var post = actBtn.closest('.ev-post');

      if (act === 'react-open') {
        if (needLogin()) return;
        var picker = actBtn.parentNode.querySelector('.ev-picker');
        var willOpen = picker.hidden;
        closeFloating(picker);
        picker.hidden = !willOpen;
        return;
      }
      if (act === 'toggle-comments' && post) {
        var thread = post.querySelector('.ev-thread');
        if (!thread) return;
        thread.hidden = !thread.hidden;
        if (!thread.hidden && thread.getAttribute('data-loaded') === '0') loadComments(thread, post.getAttribute('data-post-id'));
        return;
      }
      if (act === 'reply') {
        if (needLogin()) return;
        var comment = actBtn.closest('.ev-comment'), main = comment.querySelector('.ev-comment-main');
        var existing = main.querySelector(':scope > .ev-comment-form');
        if (existing) { existing.querySelector('input').focus(); return; }
        var form = document.createElement('form');
        form.className = 'ev-comment-form is-reply';
        form.setAttribute('data-post-id', post.getAttribute('data-post-id'));
        form.setAttribute('data-parent-id', comment.getAttribute('data-comment-id'));
        form.innerHTML = '<label class="sr-only">Write a reply</label><input type="text" name="body" maxlength="1000" placeholder="Write a reply" autocomplete="off"><button type="submit" class="ev-send" aria-label="Send reply"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg></button>';
        main.insertBefore(form, main.querySelector('.ev-replies'));
        form.querySelector('input').focus();
        return;
      }
      if (act === 'share-post' && post) {
        var url = eventUrl + '#post-' + post.getAttribute('data-post-id');
        if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); }
        else if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { toast('Link copied'); }); }
        return;
      }

      // menu items
      var type2 = actBtn.getAttribute('data-type'), id2 = actBtn.getAttribute('data-id');
      closeFloating(null);
      if (act === 'report') { openReport(type2, id2); return; }
      if (act === 'block') {
        var uid = actBtn.getAttribute('data-user');
        if (!window.confirm("Block this person? You won't see their posts or comments, and they won't see yours.")) return;
        api({ action: 'block', user_id: uid, block: true }).then(function (res) {
          if (!res.ok) { toast(res.error || 'Could not block.', true); return; }
          document.querySelectorAll('[data-author="' + uid + '"]').forEach(function (n) { n.remove(); });
          toast('Blocked.');
        });
        return;
      }
      if (act === 'hide' || act === 'delete') {
        if (act === 'delete' && !window.confirm('Delete this for good?')) return;
        api({ action: 'moderate', type: type2, id: id2, act: act }).then(function (res) {
          if (!res.ok) { toast(res.error || "You can't do that.", true); return; }
          var el = type2 === 'POST' ? actBtn.closest('.ev-post') : actBtn.closest('.ev-comment');
          if (el) el.remove();
          toast(act === 'hide' ? 'Hidden. You can restore it from Event Social.' : 'Deleted.');
        });
        return;
      }
      if (act === 'pin' || act === 'unpin') {
        api({ action: 'pin', id: id2, pin: act === 'pin' }).then(function (res) {
          if (res.ok) window.location.reload(); else toast(res.error || 'Could not change that.', true);
        });
        return;
      }
    }

    var moreComments = t.closest('.ev-more-comments');
    if (moreComments) {
      var th = moreComments.closest('.ev-thread'), pp = moreComments.closest('.ev-post');
      moreComments.disabled = true;
      loadComments(th, pp.getAttribute('data-post-id'), moreComments.getAttribute('data-last'));
      return;
    }

    if (t.closest('#evMore')) {
      var moreBtn = document.getElementById('evMore'), feed = document.getElementById('evFeed');
      moreBtn.disabled = true;
      api({ action: 'feed_more', event_id: root.getAttribute('data-event-id'), before_id: feed.getAttribute('data-last-id') }).then(function (res) {
        moreBtn.disabled = false;
        if (!res.ok) { toast(res.error || 'Could not load more.', true); return; }
        htmlToNodes(res.html).forEach(function (n) {
          feed.appendChild(n);
          n.querySelectorAll('.ev-post-photo').forEach(function (p) {
            p.addEventListener('click', function () { window.open(p.getAttribute('data-lightbox-src'), '_blank', 'noopener'); });
          });
        });
        if (res.last_id) feed.setAttribute('data-last-id', res.last_id);
        if (!res.has_more) moreBtn.parentNode.remove();
      });
    }
  });

  // ---------- comment submit ----------
  root.addEventListener('submit', function (e) {
    var form = e.target.closest('.ev-comment-form');
    if (!form) return;
    e.preventDefault();
    var input = form.querySelector('input'), btn = form.querySelector('button'), body = input.value.trim();
    if (!body) return;
    var postId = form.getAttribute('data-post-id'), parentId = form.getAttribute('data-parent-id');
    btn.disabled = true;
    api({ action: 'comment_add', post_id: postId, body: body, parent_id: parentId || null }).then(function (res) {
      btn.disabled = false;
      if (!res.ok) { toast(res.error || 'Could not post your comment.', true); return; }
      var nodes = htmlToNodes(res.html), post = document.getElementById('post-' + postId);
      if (parentId) {
        var parent = document.getElementById('comment-' + parentId);
        var replies = parent ? parent.querySelector('.ev-replies') : null;
        if (replies) nodes.forEach(function (n) { replies.appendChild(n); });
        form.remove();
      } else if (post) {
        var holder = post.querySelector('.ev-comments');
        nodes.forEach(function (n) { holder.appendChild(n); });
        input.value = '';
      }
      if (post) setCommentCount(post, res.count);
    });
  });

  // ---------- composer niceties ----------
  var fileInput = document.querySelector('#evComposer input[type="file"]');
  var fileName = document.getElementById('evFileName');
  if (fileInput && fileName) {
    fileInput.addEventListener('change', function () { fileName.textContent = fileInput.files.length ? fileInput.files[0].name : 'Photo'; });
  }
  var composer = document.getElementById('evComposer');
  if (composer) {
    composer.addEventListener('submit', function () {
      var b = document.getElementById('evPostBtn');
      if (b) { b.disabled = true; b.textContent = 'Posting…'; } // one tap, one post, even on slow data
    });
  }

  // ---------- close floating things ----------
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.ev-picker') && !e.target.closest('[data-act="react-open"]') && !e.target.closest('.ev-menu-wrap')) closeFloating(null);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeFloating(null); closeReport(); }
  });

  // ---------- tab highlight ----------
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.ev-tabs a[data-tab]'));
  var sections = tabs.map(function (a) {
    var id = a.getAttribute('data-tab'); return id === 'tickets' ? null : document.getElementById(id);
  });
  var ticking = false;
  function updateTabs() {
    ticking = false;
    var y = window.scrollY + (parseInt(getComputedStyle(root).getPropertyValue('--ev-nav'), 10) || 78) + 90, active = 0;
    sections.forEach(function (s, i) { if (s && s.offsetTop <= y) active = i; });
    tabs.forEach(function (a, i) { a.classList.toggle('is-active', i === active); });
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(updateTabs); } }, { passive: true });
  updateTabs();
})();
