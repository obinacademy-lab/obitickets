/* Dark app screens (Discover, event page, checkout):
   - a page's own menu button opens the site's menu drawer (the site's top bar is hidden on phones)
   - a back button goes back when the visitor came from this site, otherwise to its link
   - the event page: long descriptions fold behind "Read more", and the bottom Buy bar steps aside while the ticket
     options themselves are on screen */
(function () {
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t) { return; }
    var menu = t.closest('[data-open-menu]');
    if (menu) {
      var nt = document.getElementById('navToggle');
      if (nt) { e.preventDefault(); nt.click(); }
      return;
    }
    var back = t.closest('[data-back]');
    if (back && window.history.length > 1 && document.referrer && document.referrer.indexOf(window.location.origin + '/') === 0) {
      e.preventDefault();
      window.history.back();
    }
  });

  var about = document.getElementById('eaAbout'), more = document.getElementById('eaMore');
  if (about && more && about.scrollHeight > 190) {
    about.classList.add('is-folded');
    more.hidden = false;
    more.addEventListener('click', function () {
      var open = about.classList.toggle('is-folded') === false;
      more.textContent = open ? 'Show less' : 'Read more';
    });
  }

  var bar = document.getElementById('eaBar'), tickets = document.getElementById('tickets');
  if (bar && tickets && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { bar.classList.toggle('is-away', en.isIntersecting); });
    }, { threshold: 0.15 }).observe(tickets);
  }
})();
