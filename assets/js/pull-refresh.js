/* Pull down to refresh, on every page (owner 11/21).
   The browser's own pull-to-refresh is off site-wide (style.css: overscroll-behavior-y:none) and the installed app
   has none at all, so this draws its own: at the very top of the page, drag down, a round arrow follows the finger,
   turns into a spinner past the line, and letting go reloads the page.
   It stays out of the way when: the page (or the box under the finger) is not at its top, a menu or sheet has locked
   the page (overflow hidden), the finger starts on a form field, slider or video controls, or an element (or the
   body) carries data-no-ptr. Touch screens only; mouse users have the browser's reload button. */
(function () {
  if (!('ontouchstart' in window) || window.__obiPtr) return;
  window.__obiPtr = true;

  var LINE = 72, MAX = 120, startY = 0, startX = 0, dy = 0, on = false, armed = false, busy = false, box = null;

  var css = document.createElement('style');
  css.textContent =
    '.obi-ptr{position:fixed;left:50%;top:0;z-index:2147483000;width:42px;height:42px;margin-left:-21px;border-radius:50%;' +
    'background:#fff;color:#DC2626;display:grid;place-items:center;box-shadow:0 6px 20px rgba(0,0,0,.28);' +
    'transform:translateY(-60px);opacity:0;pointer-events:none;will-change:transform}' +
    '.obi-ptr svg{width:22px;height:22px;transition:transform .15s}' +
    '.obi-ptr.ready svg{transform:rotate(180deg)}' +
    '.obi-ptr.back{transition:transform .25s ease,opacity .25s ease}' +
    '.obi-ptr.spin svg{animation:obiPtrSpin .8s linear infinite}' +
    '@keyframes obiPtrSpin{to{transform:rotate(360deg)}}' +
    '@media (prefers-reduced-motion:reduce){.obi-ptr.spin svg{animation:none}}';
  document.head.appendChild(css);

  var dot = document.createElement('div');
  dot.className = 'obi-ptr';
  dot.setAttribute('aria-hidden', 'true');
  var ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg>';
  var SPIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.2-8.56"/></svg>';
  dot.innerHTML = ARROW;

  var topSafe = function () {
    var v = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--obi-safe-top'), 10);
    return isNaN(v) ? 0 : v;
  };
  var pageTop = function () {
    var se = document.scrollingElement || document.documentElement;
    return (se.scrollTop || 0) <= 0 && (window.scrollY || 0) <= 0;
  };
  // a menu, sheet or pop-up is open: pulling down there means "close" or "scroll", never "reload"
  var locked = function () {
    var b = document.body, h = document.documentElement;
    return b.hasAttribute('data-no-ptr')
      || /\b(nav-open|ticket-sheet-open|eu-open|menu-open|drawer-open)\b/.test(b.className + ' ' + h.className)
      || b.style.overflow === 'hidden' || h.style.overflow === 'hidden'
      || !!document.querySelector('dialog[open], [aria-modal="true"]:not([hidden]), .open[class*="sheet"], .open[class*="drawer"], .open[class*="modal"]');
  };
  // The scrolling box under the finger must be at its own top too (feed, chat, sheets...).
  var scrollerAtTop = function (el) {
    for (; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
      if (el.hasAttribute && el.hasAttribute('data-no-ptr')) return false;
      var s = getComputedStyle(el);
      if ((s.overflowY === 'auto' || s.overflowY === 'scroll') && el.scrollHeight > el.clientHeight + 1) {
        box = el;
        if (el.scrollTop > 0) return false;
      }
    }
    return true;
  };

  function place(y, cls) {
    dot.style.transform = 'translateY(' + y + 'px)';
    dot.style.opacity = String(Math.min(1, Math.max(0, (y + 20) / 60)));
    if (cls !== undefined) dot.className = 'obi-ptr ' + cls;
  }

  document.addEventListener('touchstart', function (e) {
    on = false; armed = false; dy = 0; box = null;
    if (busy || e.touches.length !== 1) return;
    var t = e.target;
    if (t.closest && t.closest('input, textarea, select, [contenteditable="true"], input[type="range"], video[controls]')) return;
    if (!pageTop() || locked() || !scrollerAtTop(t)) return;
    startY = e.touches[0].clientY;
    startX = e.touches[0].clientX;
    on = true;
  }, { passive: true });

  document.addEventListener('touchmove', function (e) {
    if (!on || busy) return;
    var y = e.touches[0].clientY, x = e.touches[0].clientX;
    dy = y - startY;
    if (!armed) {
      // only a clear downward drag counts; sideways swipes and upward scrolls are left alone
      if (Math.abs(x - startX) > Math.abs(dy) || dy < 8) {
        if (dy < -4 || Math.abs(x - startX) > 12) on = false;
        return;
      }
      if (box && box.scrollTop > 0) { on = false; return; }
      armed = true;
      if (!dot.parentNode) document.body.appendChild(dot);
    }
    if (e.cancelable) e.preventDefault();
    var pull = Math.min(MAX, (dy - 8) * 0.55);
    place(pull - 50 + topSafe(), pull >= LINE * 0.55 + 18 ? 'ready' : '');
  }, { passive: false });

  function end() {
    if (!on) return;
    on = false;
    if (!armed) return;
    var pull = Math.min(MAX, (dy - 8) * 0.55);
    if (pull >= LINE * 0.55 + 18) {
      busy = true;
      dot.innerHTML = SPIN;
      place(36 + topSafe(), 'back spin');
      setTimeout(function () { location.reload(); }, 180);
    } else {
      place(-60, 'back');
    }
  }
  document.addEventListener('touchend', end, { passive: true });
  document.addEventListener('touchcancel', function () { if (armed && !busy) place(-60, 'back'); on = false; }, { passive: true });

  // coming back to a page from the back/forward cache: clear a spinner left from before
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { busy = false; dot.innerHTML = ARROW; place(-60, 'back'); }
  });
})();
