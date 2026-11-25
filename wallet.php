<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI COINS wallet (ADR-002, phase 3): a person's balance, the packages they can recharge with, the checkout (a sheet, assets/js/coins.js)
 * and their full wallet history. Everything about money is decided on the server (includes/coins.php, api/coins.php); this page only shows it.
 * OBI COINS are used to send OBI GIFTS. They have no cash value, cannot be withdrawn and cannot be sent from one person to another.
 */
$user = require_login();
$uid = (int) $user['id'];
if ($user['role'] === 'ADMIN') {
    header('Location: /admin-gifts.php');
    exit;
}

$ready = coins_ready();
$enabled = $ready && coins_enabled();
$wallet = $ready ? coin_wallet($uid) : ['balance' => 0, 'purchased' => 0, 'bonus' => 0, 'spent' => 0, 'status' => 'ACTIVE'];
$packages = $enabled ? coin_packages_active() : [];
$history = $ready ? coin_wallet_history($uid, 15) : ['rows' => [], 'next' => null];
$open = $ready ? coin_purchase_open($uid) : null;
$sent = $ready ? gift_sent_history($uid, 15) : ['rows' => [], 'next' => null];
$num = static fn ($n): string => number_format((int) $n);

$pageTitle = 'OBI COINS — obitickets';
$bodyClass = 'classic-page app-dark oc-page';
$hideWhatsApp = true;
$rcTabForce = 'account';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/coins.css?v=<?= @filemtime(__DIR__ . '/assets/css/coins.css') ?: time() ?>">

<div class="wrap oc">
  <div class="ad-top">
    <a class="ad-icon" href="/me.php" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>OBI COINS</h1>
  </div>

  <section class="oc-hero" aria-label="Your OBI COINS balance">
    <small>Your balance</small>
    <div class="oc-bal"><i class="oc-coin" style="--s:44px" aria-hidden="true"></i><b id="ocBalance"><?= $num($wallet['balance']) ?></b><span>OBI COINS</span></div>
    <div class="oc-stats">
      <div><b><?= $num($wallet['purchased']) ?></b><span>Bought</span></div>
      <div><b><?= $num($wallet['bonus']) ?></b><span>Bonus</span></div>
      <div><b><?= $num($wallet['spent']) ?></b><span>Spent on gifts</span></div>
    </div>
    <?php if ($enabled && $packages && $wallet['status'] === 'ACTIVE'): ?>
      <button type="button" class="oc-recharge" id="ocRecharge">Recharge OBI COINS</button>
    <?php else: ?>
      <button type="button" class="oc-recharge off" disabled><?= $wallet['status'] === 'FROZEN' ? 'Your wallet is on hold' : 'Recharging is not open yet' ?></button>
    <?php endif; ?>
  </section>

  <?php if ($wallet['status'] === 'FROZEN'): ?>
    <div class="oc-empty" style="margin-bottom:16px"><b>Your wallet is on hold</b>Please contact support. Your OBI COINS are safe.</div>
  <?php endif; ?>

  <button type="button" class="oc-pending" id="ocPending"<?= $open ? '' : ' hidden' ?>>
    <span class="oc-spin" aria-hidden="true"></span>
    <span id="ocPendingText"><?= $open ? 'A payment of UGX ' . $num($open['price_ugx']) . ' is waiting for your approval on ' . htmlspecialchars(coin_phone_display($open['phone'])) . '. Tap to see it.' : '' ?></span>
  </button>

  <div class="oc-h"><span>Recharge OBI COINS</span><small>Pay with MTN or Airtel</small></div>
  <?php if (!$ready || !$enabled): ?>
    <div class="oc-empty"><b>OBI COINS is not open yet</b>You will be able to recharge here, then use your OBI COINS to send OBI GIFTS to event organizers.</div>
  <?php elseif (!$packages): ?>
    <div class="oc-empty"><b>No packages right now</b>Please check back soon.</div>
  <?php else: ?>
    <div class="oc-pk" id="ocPackages">
      <?php foreach ($packages as $p): ?>
        <button type="button" class="oc-card<?= (int) $p['is_featured'] === 1 ? ' hot' : '' ?>" data-pkg="<?= (int) $p['id'] ?>" aria-label="<?= htmlspecialchars($p['name'] . ': ' . $num($p['coins']) . ' OBI COINS' . ((int) $p['bonus_coins'] ? ' plus ' . $num($p['bonus_coins']) . ' bonus' : '') . ', UGX ' . $num($p['price_ugx']), ENT_QUOTES) ?>">
          <?php if ((int) $p['is_featured'] === 1): ?><span class="tag">Most popular</span><?php endif; ?>
          <i class="oc-coin" style="--s:38px" aria-hidden="true"></i>
          <span class="nm"><?= htmlspecialchars($p['name']) ?></span>
          <span class="big"><?= $num($p['coins']) ?></span>
          <span class="lb">OBI COINS</span>
          <span class="bonus"><?= (int) $p['bonus_coins'] ? '+ ' . $num($p['bonus_coins']) . ' bonus' : '' ?></span>
          <span class="price">UGX <?= $num($p['price_ugx']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="oc-fine">OBI COINS are used to send OBI GIFTS to event organizers. They have no cash value, cannot be withdrawn and cannot be sent to another person.</p>

  <div class="oc-tabs" role="tablist" aria-label="Your OBI COINS activity">
    <button type="button" role="tab" id="ocTabWallet" aria-controls="ocPanelWallet" aria-selected="true">Wallet history</button>
    <button type="button" role="tab" id="ocTabGifts" aria-controls="ocPanelGifts" aria-selected="false" tabindex="-1">Gifts you sent</button>
  </div>
  <div class="oc-panel" id="ocPanelWallet" role="tabpanel" aria-labelledby="ocTabWallet">
  <?php if (!$history['rows']): ?>
    <div class="oc-empty"><b>No activity yet</b>Your purchases and gifts will show here.</div>
  <?php else: ?>
    <div class="oc-list" id="ocHistory">
      <?php foreach ($history['rows'] as $r):
          $up = $r['coins'] > 0;
          $icon = ['PURCHASE' => '+', 'BONUS' => '★', 'GIFT_SPEND' => '🎁', 'GIFT_REFUND' => '↩', 'PURCHASE_REVERSAL' => '↩', 'BONUS_REVERSAL' => '↩', 'ADJUSTMENT' => '✎'][$r['entry_type']] ?? '•';
      ?>
        <div class="oc-row">
          <span class="ic" aria-hidden="true"><?= $icon ?></span>
          <span class="tx"><b><?= htmlspecialchars($r['label']) ?></b><small><?= htmlspecialchars(date('j M Y, H:i', strtotime((string) $r['created_at']))) ?><?= $r['note'] ? ' · ' . htmlspecialchars((string) $r['note']) : '' ?></small></span>
          <span class="amt <?= $up ? 'up' : 'down' ?>"><?= ($up ? '+' : '−') . $num(abs($r['coins'])) ?><small>Balance <?= $num($r['balance_after']) ?></small></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($history['next']): ?><button type="button" class="oc-more" id="ocMore" data-before="<?= (int) $history['next'] ?>">Show older</button><?php endif; ?>
  <?php endif; ?>
  </div>
  <div class="oc-panel" id="ocPanelGifts" role="tabpanel" aria-labelledby="ocTabGifts" hidden>
  <?php if (!$sent['rows']): ?>
    <div class="oc-empty"><b>No gifts sent yet</b>When you send an OBI GIFT to an event organizer, it shows here.</div>
  <?php else: ?>
    <div class="oc-list" id="ocGifts">
      <?php foreach ($sent['rows'] as $g): ?>
        <div class="oc-row">
          <span class="ic" aria-hidden="true"><?= htmlspecialchars($g['emoji'] !== '' ? $g['emoji'] : '🎁') ?></span>
          <span class="tx"><b><?= (int) $g['quantity'] > 1 ? (int) $g['quantity'] . ' × ' : '' ?><?= htmlspecialchars($g['gift']) ?></b><small>to <?= htmlspecialchars($g['to']) ?> · <?= htmlspecialchars(date('j M Y, H:i', strtotime($g['when']))) ?><?= $g['anonymous'] ? ' · anonymous' : '' ?></small></span>
          <span class="amt <?= $g['reversed'] ? 'rev' : 'down' ?>"><?= $g['coins'] !== null ? '−' . $num($g['coins']) . '<small>OBI COINS</small>' : 'UGX ' . $num($g['ugx']) ?><?= $g['reversed'] ? '<small>Reversed</small>' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($sent['next']): ?><button type="button" class="oc-more" id="ocMoreGifts" data-before="<?= (int) $sent['next'] ?>">Show older</button><?php endif; ?>
  <?php endif; ?>
  </div>
</div>

<script src="/assets/js/coins.js?v=<?= @filemtime(__DIR__ . '/assets/js/coins.js') ?: time() ?>" defer></script>
<script>
(function () {
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  function toast(msg) {
    var t = document.createElement('div'); t.className = 'oc-toast'; t.textContent = msg; document.body.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; }, 3200); setTimeout(function () { t.remove(); }, 3700);
  }
  function whenReady(fn) { if (window.ObCoins) { fn(); } else { window.addEventListener('load', function () { if (window.ObCoins) { fn(); } }); } }
  var done = function () { location.reload(); };
  document.addEventListener('click', function (e) {
    var card = e.target.closest && e.target.closest('[data-pkg]');
    if (card) { whenReady(function () { ObCoins.open({ packageId: parseInt(card.getAttribute('data-pkg'), 10), onDone: done, onNotice: toast }); }); return; }
    if (e.target.closest && e.target.closest('#ocRecharge')) { whenReady(function () { ObCoins.open({ onDone: done, onNotice: toast }); }); return; }
    if (e.target.closest && e.target.closest('#ocPending')) { whenReady(function () { ObCoins.open({ onDone: done, onNotice: toast }); }); }
  });
  <?php if ($open): ?>
  // a payment was left waiting: keep checking it quietly, the page refreshes the moment it is paid
  whenReady(function () { ObCoins.open({ resume: true, silent: true, onDone: done, onNotice: toast }); });
  <?php endif; ?>
  // the two history tabs
  var tabW = document.getElementById('ocTabWallet'), tabG = document.getElementById('ocTabGifts');
  function pickTab(t) {
    [[tabW, 'ocPanelWallet'], [tabG, 'ocPanelGifts']].forEach(function (p) { var on = p[0] === t; p[0].setAttribute('aria-selected', on ? 'true' : 'false'); p[0].tabIndex = on ? 0 : -1; document.getElementById(p[1]).hidden = !on; });
  }
  if (tabW && tabG) { tabW.addEventListener('click', function () { pickTab(tabW); }); tabG.addEventListener('click', function () { pickTab(tabG); }); }
  var moreG = document.getElementById('ocMoreGifts');
  if (moreG) {
    moreG.addEventListener('click', function () {
      moreG.disabled = true;
      fetch('/api/coins.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'gifts', before: parseInt(moreG.getAttribute('data-before'), 10), csrf_token: csrf }) })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (!j.ok) { moreG.disabled = false; return; }
          var list = document.getElementById('ocGifts');
          j.rows.forEach(function (g) {
            var row = document.createElement('div'); row.className = 'oc-row';
            var ic = document.createElement('span'); ic.className = 'ic'; ic.textContent = g.emoji || '🎁'; row.appendChild(ic);
            var tx = document.createElement('span'); tx.className = 'tx'; var b = document.createElement('b'); b.textContent = (g.quantity > 1 ? g.quantity + ' × ' : '') + g.gift; var s = document.createElement('small');
            var d = new Date(String(g.when).replace(' ', 'T')); s.textContent = 'to ' + g.to + ' · ' + d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + (g.anonymous ? ' · anonymous' : '');
            tx.appendChild(b); tx.appendChild(s); row.appendChild(tx);
            var a = document.createElement('span'); a.className = 'amt ' + (g.reversed ? 'rev' : 'down');
            if (g.coins !== null) { a.appendChild(document.createTextNode('−' + Number(g.coins).toLocaleString('en-US'))); var sm = document.createElement('small'); sm.textContent = 'OBI COINS'; a.appendChild(sm); } else { a.appendChild(document.createTextNode('UGX ' + Number(g.ugx).toLocaleString('en-US'))); }
            if (g.reversed) { var rv = document.createElement('small'); rv.textContent = 'Reversed'; a.appendChild(rv); }
            row.appendChild(a); list.appendChild(row);
          });
          if (j.next) { moreG.setAttribute('data-before', j.next); moreG.disabled = false; } else { moreG.remove(); }
        }, function () { moreG.disabled = false; });
    });
  }
  var more = document.getElementById('ocMore');
  if (more) {
    more.addEventListener('click', function () {
      more.disabled = true;
      fetch('/api/coins.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'history', before: parseInt(more.getAttribute('data-before'), 10), csrf_token: csrf }) })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (!j.ok) { more.disabled = false; return; }
          var list = document.getElementById('ocHistory');
          var icons = { PURCHASE: '+', BONUS: '★', GIFT_SPEND: '🎁', GIFT_REFUND: '↩', PURCHASE_REVERSAL: '↩', BONUS_REVERSAL: '↩', ADJUSTMENT: '✎' };
          j.rows.forEach(function (r) {
            var row = document.createElement('div'); row.className = 'oc-row';
            var ic = document.createElement('span'); ic.className = 'ic'; ic.textContent = icons[r.entry_type] || '•'; row.appendChild(ic);
            var tx = document.createElement('span'); tx.className = 'tx'; var b = document.createElement('b'); b.textContent = r.label; var s = document.createElement('small');
            var d = new Date(String(r.created_at).replace(' ', 'T')); s.textContent = d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + (r.note ? ' · ' + r.note : '');
            tx.appendChild(b); tx.appendChild(s); row.appendChild(tx);
            var a = document.createElement('span'); a.className = 'amt ' + (r.coins > 0 ? 'up' : 'down'); a.textContent = (r.coins > 0 ? '+' : '−') + Math.abs(r.coins).toLocaleString('en-US');
            var sm = document.createElement('small'); sm.textContent = 'Balance ' + Number(r.balance_after).toLocaleString('en-US'); a.appendChild(sm); row.appendChild(a); list.appendChild(row);
          });
          if (j.next) { more.setAttribute('data-before', j.next); more.disabled = false; } else { more.remove(); }
        }, function () { more.disabled = false; });
    });
  }
})();
</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
