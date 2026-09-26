document.addEventListener('DOMContentLoaded', function () {
  // Footer "back to top" button
  var footToTop = document.getElementById('footToTop');
  if (footToTop) {
    footToTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // Sticky nav gains a shadow once the page scrolls past the top
  var siteNav = document.querySelector('.site-nav');
  if (siteNav) {
    var onNavScroll = function () {
      siteNav.classList.toggle('scrolled', window.scrollY > 8);
    };
    window.addEventListener('scroll', onNavScroll, { passive: true });
    onNavScroll();
  }

  // Mobile/tablet nav drawer
  var navToggle = document.getElementById('navToggle');
  var navDrawer = document.getElementById('navDrawer');
  var navBackdrop = document.getElementById('navBackdrop');
  var navDrawerClose = document.getElementById('navDrawerClose');
  if (navToggle && navDrawer && navBackdrop) {
    function openNavDrawer() {
      navDrawer.classList.add('open');
      navBackdrop.classList.add('open');
      navToggle.classList.add('open');
      document.body.classList.add('nav-open');
      navDrawer.setAttribute('aria-hidden', 'false');
      navToggle.setAttribute('aria-expanded', 'true');
    }
    function closeNavDrawer() {
      navDrawer.classList.remove('open');
      navBackdrop.classList.remove('open');
      navToggle.classList.remove('open');
      document.body.classList.remove('nav-open');
      navDrawer.setAttribute('aria-hidden', 'true');
      navToggle.setAttribute('aria-expanded', 'false');
    }
    navToggle.addEventListener('click', function () {
      if (navDrawer.classList.contains('open')) {
        closeNavDrawer();
      } else {
        openNavDrawer();
      }
    });
    navBackdrop.addEventListener('click', closeNavDrawer);
    if (navDrawerClose) navDrawerClose.addEventListener('click', closeNavDrawer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeNavDrawer();
    });
    navDrawer.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeNavDrawer);
    });
  }

  // FAQ accordion
  document.querySelectorAll('.faq-q').forEach(function (q) {
    q.addEventListener('click', function () {
      q.closest('.faq-item').classList.toggle('open');
    });
  });

  // Event countdown (event.php sets data-countdown-target on the .event-hero element)
  var countdownHost = document.querySelector('[data-countdown-target]');
  if (countdownHost) {
    var target = new Date(countdownHost.getAttribute('data-countdown-target'));
    var textEl = document.getElementById('cd-text');
    function tick() {
      var diff = Math.max(0, target - new Date());
      var d = Math.floor(diff / 86400000);
      var h = Math.floor((diff % 86400000) / 3600000);
      var m = Math.floor((diff % 3600000) / 60000);
      if (textEl) textEl.textContent = d + 'd ' + h + 'h ' + m + 'm';
    }
    tick();
    setInterval(tick, 30000);
  }

  // Event like button — toggles a like via /api/toggle-like.php and updates
  // the heart's filled state + count in place (see toggle_event_like() in
  // includes/events.php for how logged-in vs. guest identity is handled).
  var likeBtn = document.getElementById('likeBtn');
  if (likeBtn) {
    var likeCount = document.getElementById('likeCount');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    likeBtn.addEventListener('click', function () {
      if (likeBtn.disabled) return;
      likeBtn.disabled = true;
      fetch('/api/toggle-like.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          eventId: parseInt(likeBtn.getAttribute('data-event-id'), 10),
          csrf_token: csrfMeta ? csrfMeta.content : '',
        }),
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) return;
          likeBtn.classList.toggle('liked', data.liked);
          likeBtn.setAttribute('aria-pressed', data.liked ? 'true' : 'false');
          if (likeCount) likeCount.textContent = data.count;
        })
        .catch(function () {})
        .then(function () { likeBtn.disabled = false; });
    });
  }

  // Event share button — the native share sheet where supported (most mobile
  // browsers, which already list WhatsApp/Instagram/etc. themselves), else a
  // small popover with direct WhatsApp/X/Facebook links and a copy-link option.
  var shareBtn = document.getElementById('shareBtn');
  var sharePopover = document.getElementById('sharePopover');
  if (shareBtn) {
    shareBtn.addEventListener('click', function () {
      if (navigator.share) {
        navigator.share({
          title: shareBtn.getAttribute('data-share-title') || document.title,
          text: shareBtn.getAttribute('data-share-text') || '',
          url: shareBtn.getAttribute('data-share-url') || window.location.href,
        }).catch(function () {});
        return;
      }
      if (sharePopover) sharePopover.classList.toggle('open');
    });

    if (sharePopover) {
      document.addEventListener('click', function (e) {
        if (sharePopover.classList.contains('open') && !sharePopover.contains(e.target) && !shareBtn.contains(e.target)) {
          sharePopover.classList.remove('open');
        }
      });

      var copyLinkBtn = sharePopover.querySelector('[data-copy-link]');
      if (copyLinkBtn) {
        copyLinkBtn.addEventListener('click', function () {
          var url = shareBtn.getAttribute('data-share-url') || window.location.href;
          var original = copyLinkBtn.textContent;
          function flash(msg) {
            copyLinkBtn.textContent = msg;
            setTimeout(function () { copyLinkBtn.textContent = original; }, 1500);
          }
          function legacyCopy() {
            var temp = document.createElement('textarea');
            temp.value = url;
            temp.style.position = 'fixed';
            temp.style.opacity = '0';
            document.body.appendChild(temp);
            temp.focus();
            temp.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(temp);
            flash(ok ? 'Copied!' : 'Couldn\'t copy — long-press the link');
          }
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { flash('Copied!'); }).catch(legacyCopy);
          } else {
            legacyCopy();
          }
        });
      }
    }
  }

  // Banner/gallery lightbox — tap any [data-lightbox-src] element to preview
  // the full-size image (event.php's hero banner and gallery photos).
  var heroLightbox = document.getElementById('heroLightbox');
  if (heroLightbox) {
    var lightboxImg = document.getElementById('lightboxImg');
    var lightboxClose = document.getElementById('lightboxClose');
    function openLightbox(src) {
      lightboxImg.src = src;
      heroLightbox.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
    function closeLightbox() {
      heroLightbox.classList.remove('open');
      document.body.style.overflow = '';
    }
    document.querySelectorAll('[data-lightbox-src]').forEach(function (el) {
      el.addEventListener('click', function () { openLightbox(el.getAttribute('data-lightbox-src')); });
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(el.getAttribute('data-lightbox-src')); }
      });
    });
    lightboxClose.addEventListener('click', closeLightbox);
    heroLightbox.addEventListener('click', function (e) { if (e.target === heroLightbox) closeLightbox(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLightbox(); });
  }

  // Ticket quantity steppers — recompute the buy-panel's subtotal/fee/total
  // from each .tier's real data-price whenever a quantity changes, since
  // ticket prices now vary per event instead of being a fixed mockup number.
  var buyForm = document.querySelector('[data-currency]');
  var SERVICE_FEE_PER_TICKET = 700; // flat UGX fee per ticket — see SERVICE_FEE_PER_TICKET in includes/payments.php

  function formatAmount(n) {
    return Math.round(n).toLocaleString('en-US');
  }

  function updateSummary() {
    if (!buyForm) return;
    var currency = buyForm.getAttribute('data-currency') || '';
    var subtotal = 0;
    var ticketCount = 0;

    buyForm.querySelectorAll('.tier').forEach(function (tierEl) {
      var price = parseFloat(tierEl.getAttribute('data-price')) || 0;
      var qty = parseInt(tierEl.querySelector('.qty .n').textContent, 10) || 0;
      subtotal += price * qty;
      ticketCount += qty;
    });

    var fee = ticketCount * SERVICE_FEE_PER_TICKET;
    var total = subtotal + fee;

    var subtotalLabel = document.getElementById('subtotal-label');
    var subtotalAmount = document.getElementById('subtotal-amount');
    var feeLabel = document.getElementById('fee-label');
    var feeAmount = document.getElementById('fee-amount');
    var totalAmount = document.getElementById('total-amount');

    if (subtotalLabel) subtotalLabel.textContent = 'Subtotal (' + ticketCount + ' ticket' + (ticketCount === 1 ? '' : 's') + ')';
    if (subtotalAmount) subtotalAmount.textContent = currency + ' ' + formatAmount(subtotal);
    if (feeLabel) feeLabel.textContent = 'Service fee (UGX 700 × ' + ticketCount + ')';
    if (feeAmount) feeAmount.textContent = currency + ' ' + formatAmount(fee);
    if (totalAmount) totalAmount.textContent = currency + ' ' + formatAmount(total);
  }

  document.querySelectorAll('.qty').forEach(function (qty) {
    var count = qty.querySelector('.n');
    var hiddenInput = qty.querySelector('.qty-input');
    var max = parseInt(qty.closest('.tier').getAttribute('data-max'), 10);
    if (isNaN(max)) max = Infinity;
    var buttons = qty.querySelectorAll('button');

    function setCount(n) {
      n = Math.max(0, Math.min(max, n));
      count.textContent = n;
      if (hiddenInput) hiddenInput.value = n;
      updateSummary();
    }

    buttons[0].addEventListener('click', function () {
      setCount(parseInt(count.textContent, 10) - 1);
    });
    buttons[1].addEventListener('click', function () {
      setCount(parseInt(count.textContent, 10) + 1);
    });
  });

  updateSummary();

  // Organizer event form: add/remove repeatable ticket-type rows
  var tierRows = document.getElementById('tier-rows');
  var addTierBtn = document.getElementById('add-tier');

  function bindRemoveButtons() {
    document.querySelectorAll('.remove-tier').forEach(function (btn) {
      btn.onclick = function () {
        if (tierRows.querySelectorAll('.tier-form-row').length > 1) {
          btn.closest('.tier-form-row').remove();
        }
      };
    });
  }

  if (tierRows && addTierBtn) {
    var template = document.getElementById('tier-row-template');
    addTierBtn.addEventListener('click', function () {
      tierRows.appendChild(template.content.cloneNode(true));
      bindRemoveButtons();
    });
    bindRemoveButtons();
  }

  // Admin tables: role/status <select> submits its own tiny form on change
  document.querySelectorAll('.auto-submit').forEach(function (el) {
    el.addEventListener('change', function () {
      el.form.submit();
    });
  });

  // Homepage upcoming-events row: arrow buttons scroll one card-width at a time
  document.querySelectorAll('.evt-section').forEach(function (section) {
    var row = section.querySelector('.evt-row');
    if (!row) return;
    section.querySelectorAll('.evt-arrow').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dir = parseInt(btn.getAttribute('data-dir'), 10) || 1;
        row.scrollBy({ left: dir * 290, behavior: 'smooth' });
      });
    });
  });

  // Organizer "Request withdrawal" — reveals the form with a smooth expand
  var withdrawToggle = document.getElementById('withdrawToggle');
  var withdrawForm = document.getElementById('withdrawForm');
  if (withdrawToggle && withdrawForm) {
    withdrawToggle.addEventListener('click', function () {
      withdrawForm.classList.toggle('open');
      if (withdrawForm.classList.contains('open')) {
        withdrawForm.querySelector('input[name=amount]').focus();
      }
    });
  }
});
