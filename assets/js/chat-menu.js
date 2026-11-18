/* The ⋮ menu on a member profile: copy link, block / unblock (11/18). Uses window.obiSocialApi from social.js. */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu-btn]');
    document.querySelectorAll('.cx-menu-list').forEach(function (l) {
      if (!btn || l !== btn.nextElementSibling) { l.hidden = true; if (l.previousElementSibling) { l.previousElementSibling.setAttribute('aria-expanded', 'false'); } }
    });
    if (btn) {
      var list = btn.nextElementSibling;
      list.hidden = !list.hidden;
      btn.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
      return;
    }
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      var url = copy.getAttribute('data-copy');
      if (navigator.share) { navigator.share({ url: url }).catch(function () {}); }
      else if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { window.obiToast && window.obiToast('Link copied'); }); }
      return;
    }
    var blk = e.target.closest('[data-block-user]');
    if (blk && window.obiSocialApi) {
      var unblock = blk.getAttribute('data-blocked') === '1';
      if (!unblock && !confirm('Block this person? They will not be able to message you or see your profile, and you will not see theirs in your lists.')) { return; }
      window.obiSocialApi({ action: 'block', user_id: +blk.getAttribute('data-block-user'), unblock: unblock }).then(function (j) {
        if (j.ok) { location.reload(); } else { window.obiToast && window.obiToast(j.error || 'Something went wrong.'); }
      });
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { document.querySelectorAll('.cx-menu-list').forEach(function (l) { l.hidden = true; }); }
  });
})();
