<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Step 1: the event page's ticket form posts here. Stash the selection in
// the session and redirect to a plain GET — that way a login redirect (see
// require_login() below) or a page refresh never re-submits the form and
// never loses the cart.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_slug'])) {
    verify_csrf();
    $_SESSION['pending_checkout'] = [
        'event_slug' => $_POST['event_slug'],
        'qty' => $_POST['qty'] ?? [],
    ];
    header('Location: /checkout.php');
    exit;
}

$user = require_login(); // redirects to /login.php?next=/checkout.php if needed; the cart survives in the session either way

$pending = $_SESSION['pending_checkout'] ?? null;
if (!$pending) {
    header('Location: /');
    exit;
}

$event = get_event_by_slug($pending['event_slug']);
if (!$event) {
    unset($_SESSION['pending_checkout']);
    header('Location: /');
    exit;
}

$cart = resolve_checkout_cart($pending, (int) $user['id']);
if (isset($cart['error'])) {
    unset($_SESSION['pending_checkout']);
    $errorParam = match ($cart['error']) {
        'no_tickets_selected' => 'select_tickets',
        'event_ended' => 'ended',
        'limit_reached' => 'limit',
        default => 'sold_out',
    };
    header('Location: /event.php?slug=' . urlencode($pending['event_slug']) . '&error=' . $errorParam);
    exit;
}
['lineItems' => $lineItems, 'subtotal' => $subtotal, 'fee' => $fee, 'total' => $total, 'currency' => $currency] = $cart;
$isFree = !empty($cart['isFree']);
$paidTicketCount = (int) ($cart['paidTicketCount'] ?? array_sum(array_column($lineItems, 'quantity')));
$cartNotices = $cart['notices'] ?? [];
$ticketCount = array_sum(array_column($lineItems, 'quantity'));
try {
    moment_record_checkout((int) $event['id']); // only counts when the visitor came from a moment
} catch (Throwable $e) {
    error_log('[moments] checkout count failed: ' . $e->getMessage());
}

$pageTitle = 'Checkout — obitickets';
$bodyClass = 'classic-page ev-night ck-night'; // 'classic-page ck-stepper' is the light Stepper look
$hideWhatsApp = true; // keep the pay button and totals uncluttered
include __DIR__ . '/includes/header.php';
?>

<?php
$ckWhere = event_is_online_only($event) ? (event_online_label($event) ?: 'Online') : $event['venue_name'] . ((($event['attend_mode'] ?? 'IN_PERSON') === 'HYBRID') ? ' + online' : '');
$ckWhen = format_event_date_range($event['starts_at'], $event['ends_at']);
$ckTick = static function (): string { return '<svg class="cn-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'; };
?>
<div class="wrap">
  <div class="cn-head">
    <ol class="cn-crumbs" aria-label="Checkout progress">
      <li class="done"><?= $ckTick() ?>Tickets</li>
      <li class="sep" aria-hidden="true">&rsaquo;</li>
      <li class="now pay" aria-current="step">Payment</li>
      <li class="sep" aria-hidden="true">&rsaquo;</li>
      <li class="last">Your ticket</li>
    </ol>
    <h1>Checkout</h1>
    <p class="cn-sub"><?= htmlspecialchars($event['title']) ?> &middot; <?= htmlspecialchars($ckWhen) ?></p>
  </div>

  <div class="cn-grid">
    <section class="cn-card cn-order" aria-label="Your order">
      <h2>Your order</h2>
      <div class="ev">
        <?php if (!empty($event['banner_image'])): ?>
          <img class="thumb" src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
        <?php else: ?>
          <span class="thumb thumb-emoji" aria-hidden="true"><?= htmlspecialchars((string) $event['banner_emoji']) ?></span>
        <?php endif; ?>
        <div><b><?= htmlspecialchars($event['title']) ?></b><span><?= htmlspecialchars($ckWhen) ?> &middot; <?= htmlspecialchars($ckWhere) ?></span></div>
      </div>
      <div class="rows">
        <?php foreach ($lineItems as $item): ?>
          <div class="ln"><span><?= (int) $item['quantity'] ?> &times; <?= htmlspecialchars($item['name']) ?></span><span class="mono"><?= $item['line_total'] > 0 ? htmlspecialchars($currency . ' ' . number_format($item['line_total'], 0)) : 'Free' ?></span></div>
        <?php endforeach; ?>
        <div class="ln"><span>Service fee<?= $paidTicketCount > 0 ? ' (UGX ' . number_format(SERVICE_FEE_PER_TICKET) . ' &times; ' . $paidTicketCount . ' paid)' : '' ?></span><span class="mono"><?= $fee > 0 ? htmlspecialchars($currency . ' ' . number_format($fee, 0)) : htmlspecialchars($currency) . ' 0' ?></span></div>
      </div>
      <div class="tot"><span><?= $isFree ? 'Total' : 'Pay now' ?></span><b class="mono"><?= $isFree ? 'Free' : htmlspecialchars($currency . ' ' . number_format($total, 0)) ?></b></div>
      <?php foreach ($cartNotices as $n): ?><div class="cn-alert"><?= htmlspecialchars($n) ?></div><?php endforeach; ?>
    </section>

    <section class="cn-card cn-pay" aria-label="Payment" data-payment-widget data-initiate-url="/api/initiate-payment.php" data-order-url="/order.php?id=" data-free="<?= $isFree ? '1' : '0' ?>">
      <h2><?= $isFree ? 'Confirm' : 'Payment' ?></h2>

      <?php if ($isFree): ?>
      <div data-state="phone">
        <p class="cn-lead">Your ticket is free. No payment is needed.</p>
        <div data-error class="cn-alert hidden"></div>
        <button type="button" data-action="pay" class="cn-cta free-confirm">Confirm and get my ticket</button>
        <div class="cn-sec"><svg width="13" height="13"><use href="#ic-shield"/></svg> No payment needed &middot; your ticket and QR code appear straight away</div>
      </div>
      <?php else: ?>
      <div data-state="phone">
        <div class="cn-field">
          <label for="phone">Mobile money phone number</label>
          <div class="cn-box">
            <input type="tel" id="phone" data-phone-input inputmode="tel" placeholder="07XX XXX XXX" autocomplete="tel" value="<?= htmlspecialchars((string) ($_SESSION['checkout_phone_hint'] ?? '')) ?>">
            <span class="cn-chip" data-chip><?= $ckTick() ?><span data-chip-text></span></span>
          </div>
          <div class="cn-hint">MTN or Airtel &mdash; you'll get a prompt on this number to approve the payment.</div>
        </div>
        <div data-error class="cn-alert hidden"></div>
        <button type="button" data-action="pay" class="cn-cta" disabled>Pay <?= htmlspecialchars($currency . ' ' . number_format($total, 0)) ?></button>
        <div class="cn-sec"><svg width="13" height="13"><use href="#ic-shield"/></svg> Secure checkout &middot; instant QR ticket</div>
        <div class="cn-logos"><span>Pay with</span><span class="b"><img src="/assets/images/brands/mtn-momo-logo.png" alt="MTN MoMo"></span><span class="b"><img src="/assets/images/brands/airtel-money-logo.png" alt="Airtel Money"></span></div>
      </div>
      <?php endif; ?>

      <div data-state="waiting" class="hidden cn-wait">
        <div class="cn-spin" aria-hidden="true"></div>
        <h3><?= $isFree ? 'Getting your ticket' : 'Check your phone' ?></h3>
        <p data-status-text>Starting payment&hellip;</p>
      </div>

      <div data-state="failed" class="hidden">
        <div class="cn-alert" data-fail-text></div>
        <button type="button" data-action="retry" class="cn-cta cn-ghost">Try again</button>
      </div>
    </section>
  </div>
</div>

<script>
(function () {
  /* the progress: Payment counts as done once the prompt has gone to the phone */
  var crumbs = document.querySelector('.cn-crumbs');
  var waiting = document.querySelector('[data-state="waiting"]');
  if (crumbs && waiting) {
    var sync = function () { crumbs.classList.toggle('is-paying', !waiting.classList.contains('hidden')); };
    new MutationObserver(sync).observe(waiting, { attributes: true, attributeFilter: ['class'] });
    sync();
  }
  /* the provider shows up as you type, and Pay waits for a complete number */
  var input = document.querySelector('[data-phone-input]');
  var btn = document.querySelector('[data-state="phone"] [data-action="pay"]');
  var chip = document.querySelector('[data-chip]');
  var txt = document.querySelector('[data-chip-text]');
  if (input && btn && chip && txt) {
    var digits = function (v) { var d = String(v).replace(/\D/g, ''); if (d.indexOf('256') === 0) d = '0' + d.slice(3); return d; };
    var provider = function (d) {
      var p = d.slice(0, 3);
      if (['077', '078', '076', '039'].indexOf(p) > -1) return 'MTN MoMo';
      if (['070', '075', '074', '020'].indexOf(p) > -1) return 'Airtel Money';
      return '';
    };
    var upd = function () {
      var d = digits(input.value), pv = provider(d);
      chip.classList.toggle('on', !!pv);
      txt.textContent = pv;
      btn.disabled = !/^0\d{9}$/.test(d);
    };
    ['input', 'change', 'blur', 'keyup'].forEach(function (ev) { input.addEventListener(ev, upd); });
    upd();
    setTimeout(upd, 500); /* phones that autofill the number after load */
  }
})();
</script>
<script src="/assets/js/payment.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
