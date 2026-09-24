document.addEventListener('DOMContentLoaded', function () {
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
});
