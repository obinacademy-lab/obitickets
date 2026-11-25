<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET checkout (ADR-003, phase 3): contact, delivery or collection, the mobile money number, a summary the server priced. "Pay" goes to
 * api/market.php (start -> poll) via assets/js/market.js; the order is confirmed only after OUR server hears "paid" from ioTec.
 * ?buy=<variant>&qty=<n> checks out just that item ("Buy now") instead of the whole cart.
 */
$user = require_login();
$uid = (int) $user['id'];
$open = market_ready() && market_enabled();
if (!$open) {
    header('Location: /market-cart.php');
    exit;
}

$only = null;
if (isset($_GET['buy'])) {
    $only = [['variant_id' => max(0, (int) $_GET['buy']), 'quantity' => max(1, min(MARKET_MAX_QTY_LINE, (int) ($_GET['qty'] ?? 1)))]];
}
$probe = market_checkout_quote($uid, $only, 'DELIVERY');
$method = in_array('DELIVERY', $probe['methods'], true) ? 'DELIVERY' : 'COLLECTION';
$quote = market_checkout_quote($uid, $only, $method);
if (!$quote['lines']) {
    header('Location: /market-cart.php');
    exit;
}

// remembered details from the last checkout, else from the account
$last = db()->prepare('SELECT customer_name, customer_email, customer_phone, delivery_name, delivery_phone, delivery_address, delivery_district, phone FROM market_checkouts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$last->execute([$uid]);
$prev = $last->fetch() ?: [];
$hint = function_exists('coin_phone_hint') ? coin_phone_hint($user) : '';
$val = [
    'customer_name' => (string) ($prev['customer_name'] ?? $user['name'] ?? ''),
    'customer_email' => (string) ($prev['customer_email'] ?? $user['email'] ?? ''),
    'customer_phone' => (string) ($prev['customer_phone'] ?? $hint),
    'delivery_name' => (string) ($prev['delivery_name'] ?? ''),
    'delivery_phone' => (string) ($prev['delivery_phone'] ?? ''),
    'delivery_address' => (string) ($prev['delivery_address'] ?? ''),
    'delivery_district' => (string) ($prev['delivery_district'] ?? ''),
    'phone' => (string) ($prev['phone'] ?? $hint),
];
if (preg_match('/^256(\d{9})$/', iotec_normalize_phone($val['phone']), $m)) { $val['phone'] = '0' . $m[1]; }
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$districts = array_keys(uganda_districts());
$canDeliver = in_array('DELIVERY', $quote['methods'], true);
$canCollect = in_array('COLLECTION', $quote['methods'], true);

$pageTitle = 'Checkout — OBI MARKET';
$bodyClass = 'classic-page app-dark mk-page';
$hideWhatsApp = true;
$rcTabForce = 'discover';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/market.css?v=<?= @filemtime(__DIR__ . '/assets/css/market.css') ?: time() ?>">

<div class="wrap mk mk-narrow">
  <div class="ad-top">
    <a class="ad-icon" href="/market-cart.php" data-back aria-label="Back to cart"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Checkout</h1>
  </div>

  <?php if (!$quote['buyable']): ?>
    <div class="mk-msg err"><?= $h($quote['problems'] ? implode(' ', $quote['problems']) : 'Something in your order cannot be bought right now.') ?> <a href="/market-cart.php">Back to your cart</a></div>
  <?php else: ?>
  <form id="mkCheckout" class="mk-form" autocomplete="on" novalidate>
    <section class="mk-card2">
      <h3>1. Your details</h3>
      <label>Full name<input name="customer_name" value="<?= $h($val['customer_name']) ?>" autocomplete="name" required maxlength="120"></label>
      <label>Email for your confirmation<input name="customer_email" type="email" value="<?= $h($val['customer_email']) ?>" autocomplete="email" required maxlength="190"></label>
      <label>Phone number (the seller may call you)<input name="customer_phone" type="tel" inputmode="tel" value="<?= $h($val['customer_phone']) ?>" autocomplete="tel" required placeholder="0772 123 456"></label>
    </section>

    <section class="mk-card2">
      <h3>2. Delivery</h3>
      <?php if ($canDeliver && $canCollect): ?>
      <div class="mk-seg" role="radiogroup" aria-label="How do you want it?">
        <label><input type="radio" name="delivery_method" value="DELIVERY" checked><span>Delivery</span></label>
        <label><input type="radio" name="delivery_method" value="COLLECTION"><span>I will collect</span></label>
      </div>
      <?php else: ?>
      <input type="hidden" name="delivery_method" value="<?= $canDeliver ? 'DELIVERY' : 'COLLECTION' ?>">
      <p class="mk-hint"><?= $canDeliver ? 'This order is delivered to you.' : 'This order is for collection from the seller.' ?></p>
      <?php endif; ?>
      <div id="mkAddr"<?= $canDeliver ? '' : ' hidden' ?>>
        <label>Receiver&rsquo;s name<input name="delivery_name" value="<?= $h($val['delivery_name']) ?>" autocomplete="shipping name" maxlength="120"></label>
        <label>Receiver&rsquo;s phone<input name="delivery_phone" type="tel" inputmode="tel" value="<?= $h($val['delivery_phone']) ?>" autocomplete="shipping tel" placeholder="0772 123 456"></label>
        <label>Address (street, building, landmark)<input name="delivery_address" value="<?= $h($val['delivery_address']) ?>" autocomplete="shipping street-address" maxlength="255"></label>
        <label>District or city<input name="delivery_district" value="<?= $h($val['delivery_district']) ?>" list="mkDistricts" autocomplete="shipping address-level2" maxlength="80"></label>
        <datalist id="mkDistricts"><?php foreach ($districts as $d): ?><option value="<?= $h($d) ?>"><?php endforeach; ?></datalist>
        <label>Note for the seller (optional)<input name="delivery_note" maxlength="255" placeholder="e.g. call when you arrive"></label>
      </div>
      <p class="mk-hint" id="mkCollectHint"<?= $canDeliver ? ' hidden' : '' ?>>The seller will tell you where and when to collect once your order is confirmed.</p>
    </section>

    <section class="mk-card2">
      <h3>3. Order summary</h3>
      <?php foreach ($quote['stores'] as $s): ?>
      <div class="mk-sumstore" data-fee="<?= (int) $s['fee'] ?>" data-merch="<?= (int) $s['merch'] ?>" data-deliver="<?= $s['can_deliver'] ? 1 : 0 ?>">
        <b><?= $h($s['store']) ?></b>
        <?php foreach ($s['lines'] as $l): ?><div class="mk-sumline"><span><?= (int) $l['quantity'] ?> &times; <?= $h($l['name']) ?><?= $l['label'] !== '' ? ' (' . $h($l['label']) . ')' : '' ?></span><span>UGX <?= $num($l['line']) ?></span></div><?php endforeach; ?>
        <div class="mk-sumline fee"><span>Delivery</span><span class="mk-fee">UGX <?= $num($s['delivery']) ?></span></div>
      </div>
      <?php endforeach; ?>
      <div class="mk-sumline tot"><span>Total to pay</span><b id="mkTotal">UGX <?= $num($quote['total']) ?></b></div>
    </section>

    <section class="mk-card2">
      <h3>4. Pay with mobile money</h3>
      <label>MTN or Airtel number<input name="phone" type="tel" inputmode="tel" value="<?= $h($val['phone']) ?>" autocomplete="tel" required placeholder="0772 123 456"></label>
      <p class="mk-hint" id="mkPayHint">We will send a payment request to this number. Approve it on your phone.</p>
      <div class="mk-msg err" id="mkErr" role="alert" hidden></div>
      <button type="submit" class="mk-btn big" id="mkPay">Pay UGX <?= $num($quote['total']) ?></button>
      <p class="mk-fine">The seller receives your money only after you confirm that your order arrived. If something is wrong you can report it from your order page.</p>
    </section>
  </form>

  <div class="mk-wait" id="mkWait" hidden>
    <div class="mk-spin" aria-hidden="true"></div>
    <h2 id="mkWaitTitle">Approve the payment on your phone</h2>
    <p id="mkWaitText"></p>
    <p class="mk-hint" id="mkWaitLate"></p>
  </div>
  <script type="application/json" id="mkData"><?= json_encode(['lines' => $only, 'total' => $quote['total'], 'delivery' => $quote['delivery']], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <script src="/assets/js/market.js?v=<?= @filemtime(__DIR__ . '/assets/js/market.js') ?: time() ?>" defer></script>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
