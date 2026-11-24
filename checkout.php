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

// Promo code box (migration 040): apply or remove a code; the cart below re-checks it every time.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['promo_action'] ?? '', ['apply', 'remove'], true) && discounts_ready()) {
    verify_csrf();
    $code = strtoupper(trim((string) ($_POST['promo'] ?? '')));
    $_SESSION['pending_checkout']['promo'] = $_POST['promo_action'] === 'apply' ? mb_substr($code, 0, 32) : '';
    header('Location: /checkout.php' . ($_POST['promo_action'] === 'apply' ? '#promo' : ''));
    exit;
}
// A shared link like /checkout.php?promo=SUMMER10 (or event.php?...&promo=) pre-fills the code once.
if (isset($_GET['promo']) && is_string($_GET['promo']) && discounts_ready()) {
    $_SESSION['pending_checkout']['promo'] = mb_substr(strtoupper(trim($_GET['promo'])), 0, 32);
    $pending = $_SESSION['pending_checkout'];
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
// The dark app look (owner's mockup, Phase 2, 11/18): ev-night switches rc.css's light colours off and checkout-night.css on;
// checkout-app.css brings it to the mockup (back arrow, payment methods). Drop "ev-night app-dark ck-app" for the light one.
$bodyClass = 'classic-page ck-night ev-night app-dark ck-app';
$hideWhatsApp = true; // keep the pay button and totals uncluttered
include __DIR__ . '/includes/header.php';
?>

<?php
$ckWhere = event_is_online_only($event) ? (event_online_label($event) ?: 'Online') : $event['venue_name'] . ((($event['attend_mode'] ?? 'IN_PERSON') === 'HYBRID') ? ' + online' : '');
$ckWhen = format_event_date_range($event['starts_at'], $event['ends_at']);
$ckTick = static function (): string { return '<svg class="cn-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'; };
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/checkout-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/checkout-app.css') ?: time() ?>">
<div class="wrap">
  <div class="ad-top ck-top">
    <a class="ad-icon" href="/event.php?slug=<?= urlencode((string) $event['slug']) ?>" data-back aria-label="Back to the event"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <span class="ck-top-t">Checkout</span>
    <span class="ck-top-gap" aria-hidden="true"></span>
  </div>
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
          <div class="ln"><span><?= (int) $item['quantity'] ?> &times; <?= htmlspecialchars($item['name']) ?><?php if ((int) ($item['admits'] ?? 1) > 1): ?> <small class="ln-ppl">(<?= (int) $item['quantity'] * (int) $item['admits'] ?> people)</small><?php endif; ?></span><span class="mono"><?= $item['line_total'] > 0 ? htmlspecialchars($currency . ' ' . number_format($item['line_total'], 0)) : 'Free' ?></span></div>
        <?php endforeach; ?>
        <?php foreach (($cart['discounts'] ?? []) as [$dLabel, $dAmount]): ?>
          <div class="ln ln-disc"><span><?= htmlspecialchars($dLabel) ?></span><span class="mono">&minus; <?= htmlspecialchars($currency . ' ' . number_format($dAmount, 0)) ?></span></div>
        <?php endforeach; ?>
        <div class="ln"><span>Service fee<?= $paidTicketCount > 0 ? ' (UGX ' . number_format(SERVICE_FEE_PER_TICKET) . ' &times; ' . $paidTicketCount . ' paid)' : '' ?></span><span class="mono"><?= $fee > 0 ? htmlspecialchars($currency . ' ' . number_format($fee, 0)) : htmlspecialchars($currency) . ' 0' ?></span></div>
      </div>
      <?php if (discounts_ready() && ($cart['gross'] ?? 0) > 0): ?>
        <div class="ck-promo" id="promo">
          <?php if (!empty($cart['promo'])): ?>
            <form method="post" action="/checkout.php" class="ck-promo-on">
              <?= csrf_field() ?><input type="hidden" name="promo_action" value="remove">
              <span><b><?= htmlspecialchars((string) $cart['promo']['code']) ?></b> applied</span>
              <button type="submit" class="ck-promo-x">Remove</button>
            </form>
          <?php else: ?>
            <details<?= !empty($cart['promoError']) ? ' open' : '' ?>>
              <summary>Have a promo code?</summary>
              <form method="post" action="/checkout.php" class="ck-promo-form">
                <?= csrf_field() ?><input type="hidden" name="promo_action" value="apply">
                <label class="sr-only" for="promoCode">Promo code</label>
                <input id="promoCode" name="promo" type="text" maxlength="32" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Enter code" value="<?= htmlspecialchars((string) ($cart['promoCode'] ?? '')) ?>" required>
                <button type="submit">Apply</button>
              </form>
              <?php if (!empty($cart['promoError'])): ?><p class="ck-promo-err" role="alert"><?= htmlspecialchars((string) $cart['promoError']) ?></p><?php endif; ?>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="tot"><span><?= $isFree ? 'Total' : 'Pay now' ?></span><b class="mono"><?= $isFree ? 'Free' : htmlspecialchars($currency . ' ' . number_format($total, 0)) ?></b></div>
      <?php foreach ($cartNotices as $n): ?><div class="cn-alert"><?= htmlspecialchars($n) ?></div><?php endforeach; ?>
    </section>

    <section class="cn-card cn-pay" aria-label="Payment" data-payment-widget data-initiate-url="/api/initiate-payment.php" data-order-url="/order.php?id=" data-free="<?= $isFree ? '1' : '0' ?>">
      <h2><?= $isFree ? 'Confirm' : 'Payment' ?></h2>

      <?php $ckQuestions = event_questions((int) $event['id']); if ($ckQuestions): ?>
        <div class="ck-qs" data-state-keep>
          <p class="ck-methods-h">A few questions from the organizer</p>
          <?php foreach ($ckQuestions as $q): $qid = 'q' . (int) $q['id']; ?>
            <div class="cn-field">
              <label for="<?= $qid ?>"><?= htmlspecialchars((string) $q['label']) ?><?= (int) $q['required'] ? ' <span class="ck-req" aria-hidden="true">*</span>' : ' <small>(optional)</small>' ?></label>
              <?php if ($q['kind'] === 'CHOICE'): ?>
                <select id="<?= $qid ?>" class="ck-q-in" data-answer="<?= (int) $q['id'] ?>" data-label="<?= htmlspecialchars((string) $q['label']) ?>"<?= (int) $q['required'] ? ' required data-required="1"' : '' ?>>
                  <option value="">Choose&hellip;</option>
                  <?php foreach ($q['choices'] as $ch): ?><option><?= htmlspecialchars($ch) ?></option><?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="<?= $qid ?>" class="ck-q-in" type="text" maxlength="500" autocomplete="off" data-answer="<?= (int) $q['id'] ?>" data-label="<?= htmlspecialchars((string) $q['label']) ?>"<?= (int) $q['required'] ? ' required data-required="1"' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($isFree): ?>
      <div data-state="phone">
        <p class="cn-lead">Your ticket is free. No payment is needed.</p>
        <div data-error class="cn-alert hidden"></div>
        <button type="button" data-action="pay" class="cn-cta free-confirm">Confirm and get my ticket</button>
        <div class="cn-sec"><svg width="13" height="13"><use href="#ic-shield"/></svg> No payment needed &middot; your ticket and QR code appear straight away</div>
      </div>
      <?php else: ?>
      <div data-state="phone">
        <div class="ck-methods" role="group" aria-label="Payment method">
          <p class="ck-methods-h">Choose payment method</p>
          <button type="button" class="ck-method" data-method="MTN MoMo" data-hint="077" aria-pressed="false"><span class="ck-logo"><img src="/assets/images/brands/mtn-momo-logo.png" alt=""></span><b>MTN Mobile Money</b><i aria-hidden="true"></i></button>
          <button type="button" class="ck-method" data-method="Airtel Money" data-hint="075" aria-pressed="false"><span class="ck-logo"><img src="/assets/images/brands/airtel-money-logo.png" alt=""></span><b>Airtel Money</b><i aria-hidden="true"></i></button>
        </div>
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
      document.querySelectorAll('.ck-method').forEach(function (m) { m.setAttribute('aria-pressed', m.getAttribute('data-method') === pv ? 'true' : 'false'); });
      btn.disabled = !/^0\d{9}$/.test(d);
    };
    ['input', 'change', 'blur', 'keyup'].forEach(function (ev) { input.addEventListener(ev, upd); });
    // tapping a method puts the cursor in the number box (the number itself tells MTN from Airtel)
    document.querySelectorAll('.ck-method').forEach(function (m) {
      m.addEventListener('click', function () {
        if (!input.value) { input.placeholder = m.getAttribute('data-hint') + 'X XXX XXX'; }
        input.focus();
      });
    });
    upd();
    setTimeout(upd, 500); /* phones that autofill the number after load */
  }
})();
</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<script src="/assets/js/payment.js?v=<?= @filemtime(__DIR__ . '/assets/js/payment.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
