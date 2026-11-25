<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET, earnings and withdrawals (ADR-003, phase 4): the three buckets (waiting for delivery confirmation / ready to withdraw / withdrawn), the withdraw form
 * (checked under a lock and sent straight away, includes/market-payouts.php), the withdrawal history and the full statement. Kept apart from ticket payouts and gifts.
 */
$ctx = require_organizer_access('market.view');
$sellerId = $ctx['organizer_id'];
$canWithdraw = organizer_can($ctx, 'market.withdraw');
$store = market_ready() ? market_store_ensure($sellerId) : null;

$wError = null;
if ($store && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    verify_csrf();
    if (!$canWithdraw) {
        http_response_code(403);
        exit('You do not have permission to withdraw money.');
    }
    $raw = trim((string) ($_POST['amount'] ?? ''));
    if (!ctype_digit($raw)) {
        $wError = 'Enter a whole number of shillings.';
    } else {
        $res = market_withdraw_request($sellerId, (int) $ctx['actor_id'], (int) $raw, (string) ($_POST['method'] ?? ''), (string) ($_POST['destination'] ?? ''), gift_disburse_fn());
        if ($res['ok']) {
            $_SESSION['om_flash'] = ['ok', (string) $res['message']];
            header('Location: /org-market-earnings.php');
            exit;
        }
        $wError = $res['error'];
    }
}
$flash = $_SESSION['om_flash'] ?? null;
unset($_SESSION['om_flash']);
if ($store) {
    market_withdraw_poll_open($sellerId, 5);   // settle anything still processing, so the page is up to date
}
$bal = $store ? market_balance($sellerId) : ['pending' => 0, 'available' => 0, 'reserved' => 0, 'withdrawn' => 0, 'total_earned' => 0];
$profile = $store ? payout_profile($sellerId) : [];
$savedPhone = (string) ($profile['payout_phone'] ?? '');
$savedMethod = (string) ($profile['payout_provider'] ?? '');
$hist = $store ? market_withdraw_history($sellerId, 15) : [];
$page = max(1, (int) ($_GET['page'] ?? 1));
$ledger = $store ? market_seller_ledger($sellerId, $page, 25) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$open = $store && market_withdraw_has_open($sellerId);
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$withdrawable = max(0, $bal['available']);

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = 'OBI MARKET earnings';
render_organizer_head('market-earnings', $ctx);
?>

<div class="admin-page-head"><div><h1>Earnings</h1><p>Your OBI MARKET money, kept separate from ticket sales and gifts.</p></div></div>

<?php if (!$store): ?>
  <div class="om-note warn">OBI MARKET is not available yet. Please check back soon.</div>
<?php else: ?>
<?php if ($flash): ?><div class="om-note <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>

<div class="ov-kpis om-kpis">
  <div class="ov-kpi"><span class="kl">Waiting for delivery confirmation</span><b class="kv">UGX <?= $num($bal['pending']) ?></b><span class="kd">Released when the customer confirms they received the order</span></div>
  <div class="ov-kpi"><span class="kl">Ready to withdraw</span><b class="kv" style="color:var(--success,#0b7a3b)">UGX <?= $num($bal['available']) ?></b><span class="kd"><?= $bal['available'] < 0 ? 'A refund is being repaid from new sales' : 'Yours to take out now' ?></span></div>
  <div class="ov-kpi"><span class="kl">Being withdrawn</span><b class="kv">UGX <?= $num($bal['reserved']) ?></b><span class="kd">On its way to your phone</span></div>
  <div class="ov-kpi"><span class="kl">Withdrawn so far</span><b class="kv">UGX <?= $num($bal['withdrawn']) ?></b><span class="kd">Total earned UGX <?= $num($bal['total_earned']) ?></span></div>
</div>

<div class="ov-cols">
  <section class="ov-card">
    <div class="ov-ch"><div><h2>Withdraw</h2><p>Sent to your mobile money. Smallest withdrawal UGX <?= $num(MARKET_WITHDRAW_MIN_UGX) ?>.</p></div></div>
    <?php if ($wError): ?><div class="om-note bad" role="alert"><?= $h($wError) ?></div><?php endif; ?>
    <?php if (!$canWithdraw): ?>
      <p class="om-fine">Only the account owner and finance managers can withdraw.</p>
    <?php elseif (!market_withdraw_enabled()): ?>
      <div class="om-note warn">Withdrawals are not open yet. Your money is safe and will be waiting for you.</div>
    <?php elseif ($open): ?>
      <div class="om-note warn">You have a withdrawal being processed. You can request another once it is done.</div>
    <?php elseif ($withdrawable < MARKET_WITHDRAW_MIN_UGX): ?>
      <p class="om-fine">You can withdraw once you have at least UGX <?= $num(MARKET_WITHDRAW_MIN_UGX) ?> ready. <?= $bal['pending'] > 0 ? 'UGX ' . $num($bal['pending']) . ' is waiting for customers to confirm delivery.' : '' ?></p>
    <?php else: ?>
      <form class="om-wd" method="post" action="/org-market-earnings.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
        <label class="om-field"><span>Amount (UGX)</span><input name="amount" inputmode="numeric" value="<?= (int) $withdrawable ?>" required><small>You can withdraw up to UGX <?= $num($withdrawable) ?>.</small></label>
        <label class="om-field"><span>Network</span><select name="method"><option value="MTN_MOMO"<?= $savedMethod !== 'AIRTEL_MONEY' ? ' selected' : '' ?>>MTN Mobile Money</option><option value="AIRTEL_MONEY"<?= $savedMethod === 'AIRTEL_MONEY' ? ' selected' : '' ?>>Airtel Money</option></select></label>
        <label class="om-field"><span>Mobile money number</span><input name="destination" type="tel" inputmode="tel" value="<?= $h($savedPhone) ?>" required placeholder="0772 123 456"></label>
        <button class="om-btn primary" type="submit" onclick="return confirm('Send this money now? It cannot be undone.')">Withdraw now</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="ov-card">
    <div class="ov-ch"><div><h2>Your withdrawals</h2><p>The latest ones</p></div></div>
    <?php if (!$hist): ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-wallet"/></svg><p>No withdrawals yet.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap"><table class="admin-table om-table"><thead><tr><th>When</th><th>Amount</th><th>To</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($hist as $w): ?><tr><td><?= $h(date('j M, g:ia', strtotime($w['when']))) ?><small class="om-sub"><?= $h($w['reference']) ?></small></td><td class="mono">UGX <?= $num($w['amount']) ?></td><td><?= $h($w['destination']) ?></td>
          <td><span class="om-badge <?= $h($w['tone']) ?>"><?= $h($w['label']) ?></span><?php if ($w['status'] === 'PROCESSING' && !empty($w['note'])): ?><small class="om-sub"><?= $h($w['note']) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>

<section class="ov-card" style="margin-top:18px">
  <div class="ov-ch"><div><h2>Statement</h2><p>Every movement of your OBI MARKET money</p></div></div>
  <?php if (!$ledger['rows']): ?>
    <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-receipt"/></svg><p>Nothing yet. Your sales will show here.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table om-table om-ledger"><thead><tr><th>Date</th><th>What happened</th><th>Money</th><th>obitickets commission</th></tr></thead><tbody>
      <?php foreach ($ledger['rows'] as $r): [$label, $amt, $tone] = market_ledger_words($r); ?>
        <tr><td><?= $h(date('j M Y, g:ia', strtotime((string) $r['created_at']))) ?></td><td><?= $h($label) ?><?php if (!empty($r['note']) && !in_array($r['entry_type'], ['SALE'], true)): ?><small class="om-sub"><?= $h($r['note']) ?></small><?php endif; ?></td>
          <td class="amt <?= $amt > 0 ? 'pos' : ($amt < 0 ? 'neg' : '') ?>"><?= $amt === 0 ? '&mdash;' : ($amt > 0 ? '+' : '&minus;') . ' UGX ' . $num(abs($amt)) ?></td>
          <td class="mono"><?= (int) $r['platform_delta'] === 0 ? '&mdash;' : 'UGX ' . $num(abs((int) $r['platform_delta'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($ledger['pages'] > 1): ?>
      <nav class="om-pager" aria-label="Pages">
        <?php if ($ledger['page'] > 1): ?><a href="?page=<?= $ledger['page'] - 1 ?>">&larr; Newer</a><?php endif; ?>
        <span>Page <?= (int) $ledger['page'] ?> of <?= (int) $ledger['pages'] ?></span>
        <?php if ($ledger['page'] < $ledger['pages']): ?><a href="?page=<?= $ledger['page'] + 1 ?>">Older &rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php render_organizer_foot(); ?>
