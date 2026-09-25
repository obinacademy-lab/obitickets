/* obitickets admin dashboard — shell interactions.
   Vanilla JS, no build step, mirrors the plain-PHP-include architecture. */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---------------------------------------------------------------
  // Mobile drawer (existing behaviour, kept)
  // ---------------------------------------------------------------
  var sidebar = document.getElementById('adminSidebar');
  var backdrop = document.getElementById('adminSidebarBackdrop');
  var mobileToggle = document.getElementById('adminSidebarToggle');
  if (sidebar && mobileToggle && backdrop) {
    var closeDrawer = function () {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
    };
    mobileToggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('show');
    });
    backdrop.addEventListener('click', closeDrawer);
  }

  // ---------------------------------------------------------------
  // Desktop collapse (icon-only), persisted
  // ---------------------------------------------------------------
  var collapseBtn = document.getElementById('adminCollapseToggle');
  if (sidebar && collapseBtn) {
    var applyCollapsed = function (on) {
      sidebar.classList.toggle('collapsed', on);
      document.documentElement.classList.toggle('admin-collapsed-pref', on);
    };
    try {
      applyCollapsed(localStorage.getItem('adminSidebarCollapsed') === '1');
    } catch (e) { /* private mode etc — default expanded */ }
    collapseBtn.addEventListener('click', function () {
      var next = !sidebar.classList.contains('collapsed');
      applyCollapsed(next);
      try { localStorage.setItem('adminSidebarCollapsed', next ? '1' : '0'); } catch (e) {}
    });
  }

  // ---------------------------------------------------------------
  // Profile dropdown
  // ---------------------------------------------------------------
  var profile = document.getElementById('adminProfile');
  var profileBtn = document.getElementById('adminProfileBtn');
  var profileMenu = document.getElementById('adminProfileMenu');
  if (profile && profileBtn && profileMenu) {
    profileBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      profile.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (!profile.contains(e.target)) profile.classList.remove('open');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') profile.classList.remove('open');
    });
  }

  // ---------------------------------------------------------------
  // Toasts — window.adminToast(type, message)
  // ---------------------------------------------------------------
  var toastStack = document.getElementById('adminToastStack');
  var toastIcons = { success: 'ic-check', error: 'ic-x', warning: 'ic-info' };
  window.adminToast = function (type, message) {
    if (!toastStack || !message) return;
    var el = document.createElement('div');
    el.className = 'admin-toast admin-toast-' + (type || 'success');
    el.innerHTML =
      '<svg width="16" height="16"><use href="#' + (toastIcons[type] || 'ic-check') + '"/></svg>' +
      '<span class="admin-toast-msg"></span>' +
      '<button type="button" class="admin-toast-close" aria-label="Dismiss">&times;</button>';
    el.querySelector('.admin-toast-msg').textContent = message;
    toastStack.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('in'); });
    var remove = function () {
      el.classList.remove('in');
      setTimeout(function () { el.remove(); }, reduced ? 0 : 220);
    };
    el.querySelector('.admin-toast-close').addEventListener('click', remove);
    setTimeout(remove, 5000);
  };
  document.querySelectorAll('[data-flash]').forEach(function (n) {
    window.adminToast(n.getAttribute('data-flash-type') || 'success', n.getAttribute('data-flash'));
  });

  // ---------------------------------------------------------------
  // Confirm modal — replaces native confirm() everywhere in the admin.
  // Two ways to use it:
  //   1. <form data-confirm="Message" [data-danger]> — auto-intercepted.
  //   2. window.adminConfirm('Message', danger).then(function (ok) { ... })
  // ---------------------------------------------------------------
  var modalBackdrop = document.getElementById('adminModalBackdrop');
  var modalTitle = document.getElementById('adminModalTitle');
  var modalBody = document.getElementById('adminModalBody');
  var modalConfirm = document.getElementById('adminModalConfirm');
  var modalCancel = document.getElementById('adminModalCancel');
  var resolvePending = null;

  function openModal(message, danger) {
    return new Promise(function (resolve) {
      modalBody.textContent = message;
      modalTitle.textContent = 'Please confirm';
      modalConfirm.style.background = danger ? 'var(--danger)' : 'var(--purple)';
      modalBackdrop.classList.add('open');
      modalConfirm.focus();
      resolvePending = resolve;
    });
  }
  function settle(result) {
    modalBackdrop.classList.remove('open');
    var r = resolvePending;
    resolvePending = null;
    if (r) r(result);
  }
  if (modalBackdrop) {
    window.adminConfirm = openModal;
    modalCancel.addEventListener('click', function () { settle(false); });
    modalBackdrop.addEventListener('click', function (e) { if (e.target === modalBackdrop) settle(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modalBackdrop.classList.contains('open')) settle(false); });
    modalConfirm.addEventListener('click', function () { settle(true); });

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.hasAttribute('data-confirm') && !form.hasAttribute('data-confirmed')) {
        e.preventDefault();
        openModal(form.getAttribute('data-confirm'), form.hasAttribute('data-danger')).then(function (ok) {
          if (ok) {
            form.setAttribute('data-confirmed', '1');
            form.requestSubmit ? form.requestSubmit() : form.submit();
          }
        });
      }
    }, true);
  }

  // ---------------------------------------------------------------
  // KPI count-up — <span data-count-to="12500">0</span>
  // ---------------------------------------------------------------
  document.querySelectorAll('[data-count-to]').forEach(function (el) {
    var to = parseFloat(el.getAttribute('data-count-to'));
    if (isNaN(to)) return;
    if (reduced) { el.textContent = el.getAttribute('data-count-format') || to; return; }
    var prefix = el.getAttribute('data-count-prefix') || '';
    var duration = 700;
    var start = null;
    function frame(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / duration);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = prefix + Math.round(to * eased).toLocaleString('en-US');
      if (p < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  });

  // ---------------------------------------------------------------
  // Revenue line chart — draw the path in from its own true length
  // ---------------------------------------------------------------
  document.querySelectorAll('.admin-line-path').forEach(function (path) {
    var len = path.getTotalLength();
    if (reduced) { return; }
    path.style.strokeDasharray = len;
    path.style.strokeDashoffset = len;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { path.classList.add('is-drawn'); });
    });
  });

  // ---------------------------------------------------------------
  // Top progress bar — perceived speed on navigation
  // ---------------------------------------------------------------
  var bar = document.getElementById('adminProgress');
  if (bar && !reduced) {
    var start = function () {
      bar.style.transition = 'none';
      bar.style.width = '0';
      requestAnimationFrame(function () {
        bar.style.transition = 'width 4s cubic-bezier(.16,.8,.24,1), opacity .2s ease';
        bar.style.opacity = '1';
        bar.style.width = '80%';
      });
    };
    document.querySelectorAll('a.admin-nav-item, .admin-content a.link, .admin-pagination a').forEach(function (a) {
      a.addEventListener('click', function (e) {
        if (e.metaKey || e.ctrlKey || a.target === '_blank') return;
        start();
      });
    });
    document.addEventListener('submit', function (e) {
      if (!e.defaultPrevented) start();
    });
  }
})();
