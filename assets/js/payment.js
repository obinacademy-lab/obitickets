// Mobile-money checkout widget: phone entry -> waiting (polling) -> redirect
// on success, or a retry state on failure. Attach to any element with
// [data-payment-widget] carrying data-initiate-url and data-order-url.
(function () {
  const POLL_INTERVAL_MS = 2500;
  const MAX_POLLS = 72; // ~3 minutes

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  }

  function initWidget(root) {
    const initiateUrl = root.dataset.initiateUrl;
    // Poll lives alongside initiate in the same api/ folder — derive it
    // rather than hardcode a root-relative path.
    const pollUrl = initiateUrl.replace(/initiate-[^/]+\.php(?:\?.*)?$/, 'poll-payment.php');
    const orderUrlBase = root.dataset.orderUrl; // e.g. "/order.php?id="

    const states = {
      phone: root.querySelector('[data-state="phone"]'),
      waiting: root.querySelector('[data-state="waiting"]'),
      failed: root.querySelector('[data-state="failed"]'),
    };
    const errorBox = root.querySelector('[data-error]');
    const statusText = root.querySelector('[data-status-text]');
    const failText = root.querySelector('[data-fail-text]');
    const phoneInput = root.querySelector('[data-phone-input]');
    const payBtn = root.querySelector('[data-action="pay"]');

    let pollCount = 0;
    let pollTimer = null;
    let currentOrderId = null;

    function show(state) {
      Object.values(states).forEach((el) => el && el.classList.add('hidden'));
      if (states[state]) states[state].classList.remove('hidden');
    }

    function setError(msg) {
      if (!errorBox) return;
      errorBox.textContent = msg || '';
      errorBox.classList.toggle('hidden', !msg);
    }

    payBtn?.addEventListener('click', async () => {
      const phone = phoneInput?.value.trim() || '';
      if (phone.replace(/\D/g, '').length < 9) {
        setError('Enter a valid phone number.');
        return;
      }
      setError('');
      payBtn.disabled = true; // guard against a double-click reserving stock twice
      show('waiting');
      if (statusText) statusText.textContent = 'Starting payment...';

      try {
        const res = await fetch(initiateUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ phone, csrf_token: csrfToken() }),
        });
        const data = await res.json();
        if (data.error) {
          setError(data.error);
          show('phone');
          payBtn.disabled = false;
          return;
        }
        currentOrderId = data.orderId;
        if (statusText) statusText.textContent = 'Check your phone and approve the mobile money prompt.';
        pollCount = 0;
        pollStatus();
        pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS);
      } catch {
        setError('Something went wrong. Please try again.');
        show('phone');
        payBtn.disabled = false;
      }
    });

    async function pollStatus() {
      pollCount += 1;
      try {
        const res = await fetch(pollUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ orderId: currentOrderId, csrf_token: csrfToken() }),
        });
        const data = await res.json();

        if (data.status === 'PAID') {
          clearInterval(pollTimer);
          window.location.href = orderUrlBase + currentOrderId + '&success=1';
        } else if (data.status === 'FAILED') {
          clearInterval(pollTimer);
          if (failText) failText.textContent = data.statusMessage || 'The payment was not completed.';
          show('failed');
        } else if (pollCount >= MAX_POLLS) {
          clearInterval(pollTimer);
          if (failText) failText.textContent = 'This is taking longer than expected. Please try again.';
          show('failed');
        }
      } catch {
        // transient network hiccup — keep polling until MAX_POLLS
      }
    }

    root.querySelectorAll('[data-action="retry"]').forEach((btn) =>
      btn.addEventListener('click', () => {
        setError('');
        if (payBtn) payBtn.disabled = false;
        show('phone');
      })
    );
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-payment-widget]').forEach(initWidget);
  });
})();
