<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * Gifts & Support (OBI GIFTS, phase 4): what the organizer earned from gifts, who sent them, and how it is going.
 * Read only. Withdrawing gift money comes in phase 5. Every number is scoped to the signed-in organizer's own account.
 */
$ctx = require_organizer_access('gifts.view');
$organizerId = $ctx['organizer_id'];

$tab = in_array($_GET['tab'] ?? '', ['history', 'analytics', 'withdrawals'], true) ? $_GET['tab'] : 'overview';
$ready = gifts_ready();
$visible = $ready && gift_org_menu_visible($organizerId);

function og_ugx(int $n): string
{
    return ($n < 0 ? '-' : '') . 'UGX ' . number_format(abs($n));
}

/** a spreadsheet would run a cell that starts with = + - @ as a formula, so those get a leading apostrophe */
function og_csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// the history filters (the same ones drive the table and the CSV)
$allGifts = $ready ? db()->query('SELECT id, name, emoji FROM gift_catalog ORDER BY sort_order, id')->fetchAll() : [];
$filters = [
    'gift' => (int) ($_GET['gift'] ?? 0),
    'days' => in_array((int) ($_GET['days'] ?? 0), [7, 30, 90, 365], true) ? (int) $_GET['days'] : 0,
    'status' => in_array($_GET['status'] ?? '', ['received', 'reversed'], true) ? $_GET['status'] : 'all',
];

if ($visible && $tab === 'history' && isset($_GET['export'])) {
    $rows = gift_org_history($organizerId, $filters, 500)['rows'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gifts-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Supporter', 'Gift', 'Quantity', 'OBI COINS', 'Value (UGX)', 'Your share (UGX)', 'Where', 'Status', 'Reference']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) $r['paid_at'], og_csv_safe($r['supporter']), og_csv_safe($r['gift_name']), (int) $r['quantity'], $r['coins'] !== null ? (int) $r['coins'] : '', (int) $r['gross_ugx'],
            (int) $r['organizer_ugx'], og_csv_safe($r['where']), $r['reversed'] ? 'Reversed' : 'Received', $r['reference'],
        ]);
    }
    fclose($out);
    exit;
}

// withdrawing gift money (phase 5): checked under a lock, then sent straight away (includes/gift-payouts.php)
$wError = null;
$doneMsg = $_SESSION['gift_wd_msg'] ?? null;
unset($_SESSION['gift_wd_msg']);
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    verify_csrf();
    if (!organizer_can($ctx, 'gifts.withdraw')) {
        http_response_code(403);
        exit('You do not have permission to withdraw gift money.');
    }
    $rawAmount = trim((string) ($_POST['amount'] ?? ''));
    if (!ctype_digit($rawAmount)) {
        $wError = 'Enter a whole number of shillings.';
    } else {
        $res = gift_withdraw_request($organizerId, (int) $ctx['actor_id'], (int) $rawAmount, (string) ($_POST['method'] ?? ''), (string) ($_POST['destination'] ?? ''), gift_disburse_fn());
        if ($res['ok']) {
            $_SESSION['gift_wd_msg'] = $res['message'];
            header('Location: /org-gifts.php?tab=withdrawals&done=1');
            exit;
        }
        $wError = $res['error'];
        $tab = 'withdrawals';
    }
    $tab = 'withdrawals';
}
// say thank you to a supporter (includes/gift-thanks.php): the message goes to them privately, never to the public
$canThank = organizer_can($ctx, 'gifts.thank');
$thanksFlash = $_SESSION['gift_thanks_flash'] ?? null;
unset($_SESSION['gift_thanks_flash']);
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'thank') {
    verify_csrf();
    if (!$canThank) {
        http_response_code(403);
        exit('You do not have permission to message supporters.');
    }
    $res = gift_thank($organizerId, (int) ($_POST['id'] ?? 0), (string) ($_POST['message'] ?? ''));
    $_SESSION['gift_thanks_flash'] = $res['ok']
        ? ['ok', $res['channel'] === 'chat' ? 'Your thank-you was sent. They will see it in their chats.' : 'Your thank-you was sent. This supporter chose to stay anonymous, so they get it as a notification and you do not learn who they are.']
        : ['error', $res['error']];
    $back = (string) ($_POST['back'] ?? '');
    header('Location: ' . (preg_match('#^/org-gifts\.php(\?[A-Za-z0-9=&_.%-]*)?$#', $back) ? $back : '/org-gifts.php?tab=history'));
    exit;
}
$orgDisplayName = (string) db()->query('SELECT COALESCE(NULLIF(op.org_name, \'\'), u.name) FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE u.id = ' . (int) $organizerId)->fetchColumn();
/** The thank-you button for one gift, or the "Thanked" mark. */
function og_thank_control(array $r, bool $canThank, string $orgName): string
{
    if (!empty($r['thanked'])) {
        return '<span class="og-thanked" title="' . htmlspecialchars((string) $r['thanked']['message'], ENT_QUOTES) . '">Thanked &#10003;</span>';
    }
    if (!$canThank || empty($r['can_thank'])) {
        return '';
    }
    return '<button type="button" class="og-thank" data-id="' . (int) $r['id'] . '" data-who="' . htmlspecialchars((string) $r['supporter'], ENT_QUOTES) . '" data-anon="' . (!empty($r['is_anonymous']) ? '1' : '0')
        . '" data-text="' . htmlspecialchars(gift_thanks_default_message((string) $r['gift_name'], $orgName), ENT_QUOTES) . '">Say thanks</button>';
}
// refresh any of this organizer's withdrawals that ioTec is still processing, so the page shows the real status
if ($ready && in_array($tab, ['overview', 'withdrawals'], true)) {
    gift_withdraw_poll_open($organizerId, 5);
}

$GLOBALS['orgExtraCss'] = ['org-gifts.css'];
$pageTitle = 'OBI GIFTS & earnings';
$active = ['history' => 'gifts-history', 'analytics' => 'gifts-analytics', 'withdrawals' => 'gifts-withdrawals'][$tab] ?? 'gifts';
render_organizer_head($active, $ctx);

$sharePct = gift_org_share_percent();
$sharePctText = rtrim(rtrim(number_format($sharePct, 2), '0'), '.');
?>

<div class="admin-page-head">
  <div>
    <h1>OBI GIFTS &amp; earnings</h1>
    <p>The OBI GIFTS your supporters send you on your videos, and the money they earn you. It is kept completely separate from your ticket sales.</p>
  </div>
</div>

<?php if ($thanksFlash): ?><span data-flash="<?= htmlspecialchars($thanksFlash[1]) ?>"<?= $thanksFlash[0] === 'error' ? ' data-flash-type="error"' : '' ?> hidden></span><?php endif; ?>

<?php if (!$visible): ?>
  <div class="admin-card"><div class="admin-empty" style="padding:34px 24px">
    <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-heart"/></svg></div>
    <p style="margin-top:0"><?= $ready ? 'OBI GIFTS is not switched on yet. When it is, supporters will be able to send you gifts from your videos and you will see them here.' : 'Gifts are not available yet.' ?></p>
  </div></div>
<?php render_organizer_foot(); return; endif; ?>

<?php if ($tab === 'overview'):
    $sum = gift_org_summary($organizerId);
    $recent = gift_org_history($organizerId, [], 6)['rows'];
    $bal = $sum['balance'];
    $coinsLive = function_exists('coins_enabled') && coins_enabled();
    $rateNow = $coinsLive ? coin_rate_current() : null;
?>
  <?php if ($sum['in_debt']): ?>
    <div class="og-note og-note-warn">A gift sent to you was reversed after you had already taken the money out. <strong><?= og_ugx(-$bal['available']) ?></strong> will be taken from your next gifts until it is paid back. Your ticket money is never touched.</div>
  <?php endif; ?>

  <div class="admin-kpi-grid og-kpis">
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Available to withdraw</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-wallet"/></svg></span></div>
      <span class="num"><?= og_ugx($bal['available']) ?></span>
      <span class="sub"><?= $bal['reserved'] > 0 ? og_ugx($bal['reserved']) . ' being sent &middot; ' : '' ?><?php if (gift_withdraw_enabled() && organizer_can($ctx, 'gifts.withdraw')): ?><a href="/org-gifts.php?tab=withdrawals" style="font-weight:700; color:var(--ov-red,#E11D48); text-decoration:none">Withdraw</a><?php else: ?>Withdrawing opens soon<?php endif; ?></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Pending earnings</span><span class="admin-kpi-ic" style="background:var(--warn-bg, #fff7e6); color:var(--warn, #8a5a00)"><svg width="17" height="17"><use href="#ic-clock"/></svg></span></div>
      <span class="num"><?= og_ugx($bal['pending']) ?></span>
      <span class="sub"><?= $bal['pending'] > 0 ? 'Waiting to become available' : 'New gifts are available to you at once' ?></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Your earnings (<?= htmlspecialchars($sharePctText) ?>%)</span><span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span></div>
      <span class="num"><?= og_ugx($bal['total_earned']) ?></span>
      <span class="sub"><?= og_ugx($sum['month_ugx']) ?> this month &middot; <?= og_ugx($sum['week_ugx']) ?> in 7 days</span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Withdrawn</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-receipt"/></svg></span></div>
      <span class="num"><?= og_ugx($bal['total_withdrawn']) ?></span>
      <span class="sub"><?= (int) $sum['withdrawn_count'] ?> completed payout<?= $sum['withdrawn_count'] === 1 ? '' : 's' ?></span>
    </div>
  </div>

  <div class="admin-kpi-grid og-kpis" style="margin-top:14px">
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">OBI COINS received</span><span class="admin-kpi-ic" aria-hidden="true"><i class="og-coin"></i></span></div>
      <span class="num"><?= number_format($sum['coins_received']) ?></span>
      <span class="sub">sent to you as gifts</span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Gross gift value</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-chart"/></svg></span></div>
      <span class="num"><?= og_ugx($sum['gross_ugx']) ?></span>
      <span class="sub">what your gifts were worth in UGX before the split</span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Gifts received</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-heart"/></svg></span></div>
      <span class="num"><?= (int) $sum['gifts'] ?></span>
      <span class="sub"><?= (int) $sum['month_gifts'] ?> this month<?= $sum['biggest_ugx'] > 0 ? ' &middot; biggest worth ' . og_ugx($sum['biggest_ugx']) : '' ?></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Supporters</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-people"/></svg></span></div>
      <span class="num"><?= (int) $sum['supporters'] ?></span>
      <span class="sub">different people have sent you a gift</span>
    </div>
  </div>

  <div class="admin-grid-2" style="margin-top:20px">
    <div class="admin-card">
      <div class="og-card-head"><h3>Latest gifts</h3><?php if ($recent): ?><a href="/org-gifts.php?tab=history">See all</a><?php endif; ?></div>
      <?php if (!$recent): ?>
        <div class="admin-empty" style="padding:26px 12px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-heart"/></svg></div><p style="margin-top:0">No gifts yet. They appear here the moment a supporter sends one.</p></div>
      <?php else: foreach ($recent as $r): ?>
        <div class="og-line">
          <span class="og-emoji" aria-hidden="true"><?= htmlspecialchars((string) ($r['emoji'] ?: '🎁')) ?></span>
          <span class="og-line-main">
            <strong><?= htmlspecialchars($r['supporter']) ?></strong> sent <?= (int) $r['quantity'] > 1 ? (int) $r['quantity'] . ' &times; ' : 'a ' ?><?= htmlspecialchars($r['gift_name']) ?>
            <small><?= htmlspecialchars($r['where']) ?> &middot; <?= htmlspecialchars(date('j M, H:i', strtotime((string) $r['paid_at']))) ?><?= $r['coins'] !== null ? ' &middot; ' . number_format($r['coins']) . ' OBI COINS' : '' ?></small>
          </span>
          <?= og_thank_control($r, $canThank, $orgDisplayName) ?>
          <span class="og-line-amt<?= $r['reversed'] ? ' is-reversed' : '' ?>"><?= $r['reversed'] ? 'Reversed' : '+' . og_ugx((int) $r['organizer_ugx']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">How gifts work</h3>
      <?php if ($coinsLive): ?>
        <div class="admin-detail-row"><span class="k">Supporters pay with</span><span class="v">OBI COINS</span></div>
        <div class="admin-detail-row"><span class="k">Each gift is valued in UGX</span><span class="v">at the rate on the day it was sent</span></div>
        <?php if ($rateNow): $cfgNow = gift_commission_current(); $valNow = coin_unit_ugx(1000, $rateNow['rate_e4']); $mineNow = gift_split($valNow, $cfgNow['platform_bps'], $cfgNow['organizer_bps'])['organizer']; ?>
          <div class="admin-detail-row"><span class="k">Today</span><span class="v mono">1,000 OBI COINS = <?= og_ugx($valNow) ?>, you earn <?= og_ugx($mineNow) ?></span></div>
        <?php endif; ?>
        <div class="admin-detail-row"><span class="k">You keep</span><span class="v mono"><?= htmlspecialchars($sharePctText) ?>% of that value</span></div>
      <?php else: ?>
        <div class="admin-detail-row"><span class="k">You keep</span><span class="v mono"><?= htmlspecialchars($sharePctText) ?>% of every gift</span></div>
      <?php endif; ?>
      <div class="admin-detail-row"><span class="k">Mobile money fees</span><span class="v">Paid by obitickets, not you</span></div>
      <div class="admin-detail-row"><span class="k">Ticket money</span><span class="v">Separate, never mixed with gifts</span></div>
      <div class="admin-detail-row"><span class="k">Gifts reversed</span><span class="v mono"><?= (int) $sum['reversed'] ?><?= $sum['reversed'] > 0 ? ' (' . og_ugx($sum['reversed_ugx']) . ')' : '' ?></span></div>
      <p class="muted" style="font-size:0.82rem; margin:14px 0 0">Gifts are final for the supporter. Only the obitickets team can reverse one, for example after a payment problem.</p>
    </div>
  </div>

  <?php if ($sum['popular']): $topN = max(1, $sum['popular'][0]['gifts']); ?>
    <div class="admin-card" style="margin-top:20px">
      <h3 style="margin-bottom:14px">Most popular gifts</h3>
      <?php foreach ($sum['popular'] as $g): ?>
        <div class="og-rank">
          <div class="og-rank-top"><span><span aria-hidden="true"><?= htmlspecialchars((string) $g['emoji']) ?></span> <?= htmlspecialchars($g['name']) ?> <small class="muted">&times; <?= (int) $g['units'] ?><?= $g['coins'] > 0 ? ' &middot; ' . number_format($g['coins']) . ' OBI COINS' : '' ?></small></span><strong class="mono"><?= (int) $g['gifts'] ?> gift<?= $g['gifts'] === 1 ? '' : 's' ?></strong></div>
          <div class="og-rank-bar"><i style="width:<?= max(2, round($g['gifts'] / $topN * 100)) ?>%"></i></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'history'):
    $page = gift_org_history($organizerId, $filters + ['before' => (int) ($_GET['before'] ?? 0)], 25);
    $rows = $page['rows'];
    $keep = array_filter(['tab' => 'history', 'gift' => $filters['gift'] ?: null, 'days' => $filters['days'] ?: null, 'status' => $filters['status'] !== 'all' ? $filters['status'] : null]);
?>
  <form method="get" class="og-filters">
    <input type="hidden" name="tab" value="history">
    <label>Gift
      <select name="gift">
        <option value="0">All gifts</option>
        <?php foreach ($allGifts as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $filters['gift'] === (int) $g['id'] ? ' selected' : '' ?>><?= htmlspecialchars(trim(($g['emoji'] ?? '') . ' ' . $g['name'])) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>When
      <select name="days">
        <?php foreach ([0 => 'All time', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $d => $label): ?><option value="<?= $d ?>"<?= $filters['days'] === $d ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <?php foreach (['all' => 'All', 'received' => 'Received', 'reversed' => 'Reversed'] as $k => $label): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn btn-primary btn-sm">Show</button>
    <a class="og-export" href="/org-gifts.php?<?= htmlspecialchars(http_build_query($keep + ['export' => 1])) ?>">Download CSV</a>
  </form>

  <div class="admin-card" style="margin-top:16px">
    <?php if (!$rows): ?>
      <div class="admin-empty" style="padding:30px 24px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-heart"/></svg></div><p style="margin-top:0">No gifts match.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table og-table">
          <thead><tr><th>Date</th><th>Supporter</th><th>Gift</th><th>Where</th><th style="text-align:right">OBI COINS</th><th style="text-align:right">Value (UGX)</th><th style="text-align:right">Your share</th><th>Status</th><th>Thank-you</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="muted"><?= htmlspecialchars(date('j M Y, H:i', strtotime((string) $r['paid_at']))) ?></td>
              <td><?= htmlspecialchars($r['supporter']) ?></td>
              <td><span aria-hidden="true"><?= htmlspecialchars((string) $r['emoji']) ?></span> <?= (int) $r['quantity'] > 1 ? (int) $r['quantity'] . ' &times; ' : '' ?><?= htmlspecialchars($r['gift_name']) ?></td>
              <td class="muted og-wrap"><?= htmlspecialchars($r['where']) ?></td>
              <td class="mono" style="text-align:right"><?= $r['coins'] !== null ? number_format($r['coins']) : '—' ?></td>
              <td class="mono" style="text-align:right"><?= og_ugx((int) $r['gross_ugx']) ?></td>
              <td class="mono" style="text-align:right"><?= $r['reversed'] ? '<s class="muted">' . og_ugx((int) $r['organizer_ugx']) . '</s>' : og_ugx((int) $r['organizer_ugx']) ?></td>
              <td><span class="admin-badge <?= $r['reversed'] ? 'admin-badge-danger' : 'admin-badge-success' ?>"><?= $r['reversed'] ? 'Reversed' : 'Received' ?></span></td>
              <td><?= og_thank_control($r, $canThank, $orgDisplayName) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($page['next']): ?>
        <div style="text-align:center; margin-top:16px"><a class="btn btn-secondary btn-sm" href="/org-gifts.php?<?= htmlspecialchars(http_build_query($keep + ['before' => $page['next']])) ?>">Show older gifts</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:0.8rem; margin-top:12px">Value is what the gift was worth in UGX at the OBI COINS rate on the day it was sent; your share is your cut of that value. Supporters who chose to stay anonymous show as "Anonymous supporter". Phone numbers are never shown.</p>

<?php elseif ($tab === 'withdrawals'):
    $bal = gift_balance($organizerId);
    $hist = gift_withdraw_history($organizerId, 30);
    $canWd = organizer_can($ctx, 'gifts.withdraw');
    $prof = payout_profile($organizerId);
    $savedMobile = in_array($prof['payout_provider'] ?? '', ['MTN_MOMO', 'AIRTEL_MONEY'], true) && payout_valid_phone((string) ($prof['payout_phone'] ?? ''));
    $wProv = $savedMobile ? $prof['payout_provider'] : 'MTN_MOMO';
    $wNum = $savedMobile ? (string) $prof['payout_phone'] : '';
    $avail = max(0, $bal['available']);
    $wOpen = gift_withdraw_has_open($organizerId);
    $tones = ['success' => 'admin-badge-success', 'warn' => 'admin-badge-warn', 'danger' => 'admin-badge-danger'];
?>
  <?php if (isset($_GET['done'])): ?><span data-flash="<?= htmlspecialchars($doneMsg ?: 'Your withdrawal is in.', ENT_QUOTES) ?>" hidden></span><?php endif; ?>
  <?php if ($wError): ?><span data-flash="<?= htmlspecialchars($wError, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>
  <div class="admin-grid-2">
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Your withdrawals</h3>
      <?php if (!$hist): ?>
        <div class="admin-empty" style="padding:26px 12px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-wallet"/></svg></div><p style="margin-top:0">You have not withdrawn any gift money yet.</p></div>
      <?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table og-table">
            <thead><tr><th>Reference</th><th>Requested</th><th style="text-align:right">Amount</th><th>Sent to</th><th>Status</th><th>Completed</th></tr></thead>
            <tbody>
              <?php foreach ($hist as $w): ?>
                <tr>
                  <td class="mono muted" style="font-size:.78rem"><?= htmlspecialchars($w['reference']) ?></td>
                  <td class="muted"><?= htmlspecialchars(date('j M Y, H:i', strtotime($w['when']))) ?></td>
                  <td class="mono" style="text-align:right"><?= og_ugx($w['amount']) ?></td>
                  <td><?= htmlspecialchars($w['destination']) ?> <span class="muted" style="font-size:.78rem"><?= $w['method'] === 'AIRTEL_MONEY' ? 'Airtel' : 'MTN' ?></span></td>
                  <td><span class="admin-badge <?= $tones[$w['tone']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars($w['label']) ?></span><?= $w['note'] ? '<br><span class="muted" style="font-size:.76rem">Decision: ' . htmlspecialchars($w['note']) . '</span>' : '' ?></td>
                  <td class="muted"><?= $w['done'] ? htmlspecialchars(date('j M Y, H:i', strtotime($w['done']))) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted" style="font-size:0.8rem; margin:12px 0 0">If a withdrawal could not be sent, the money is back in your gift balance straight away.</p>
      <?php endif; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">Withdraw gift earnings</h3>
      <p style="margin:0 0 4px"><span class="muted" style="font-size:.82rem">Gift balance</span><br><strong class="mono" style="font-size:1.35rem"><?= og_ugx($bal['available']) ?></strong></p>
      <?php if ($bal['reserved'] > 0): ?><p class="muted" style="font-size:.82rem; margin:0 0 4px"><?= og_ugx($bal['reserved']) ?> is on its way to you now.</p><?php endif; ?>
      <div style="height:12px"></div>
      <?php if (!$canWd): ?>
        <p class="muted" style="font-size:.9rem; margin:0">Only the account owner or a Finance Manager can withdraw gift money.</p>
      <?php elseif (!gift_withdraw_enabled()): ?>
        <p style="font-size:.92rem; margin:0 0 6px"><b>Withdrawing gift money is not open yet.</b></p>
        <p class="muted" style="font-size:.84rem; margin:0">Your gift balance is safe. You will be able to take it out here as soon as withdrawals open.</p>
      <?php elseif ($wOpen): ?>
        <div class="admin-empty" style="padding:20px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-clock"/></svg></div><p style="margin-top:0">You already have a gift withdrawal being processed. You can request another once it is done.</p></div>
      <?php elseif ($bal['available'] < 0): ?>
        <p style="font-size:.92rem; margin:0 0 6px"><b>A reversed gift is being paid back.</b></p>
        <p class="muted" style="font-size:.84rem; margin:0"><?= og_ugx(-$bal['available']) ?> will be taken from your next gifts first. You can withdraw once new gifts cover it. Your ticket money is never touched.</p>
      <?php elseif ($avail < GIFT_WITHDRAW_MIN_UGX): ?>
        <p style="font-size:.92rem; margin:0 0 6px"><b>The smallest withdrawal is UGX <?= number_format(GIFT_WITHDRAW_MIN_UGX) ?>.</b></p>
        <p class="muted" style="font-size:.84rem; margin:0">You can withdraw once your gift balance reaches UGX <?= number_format(GIFT_WITHDRAW_MIN_UGX) ?>.</p>
      <?php else: ?>
        <form method="post" id="gwForm" data-confirm="Send the money now? Money sent to a wrong number cannot be brought back.">
          <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
          <p class="muted" style="font-size:.86rem; margin:0 0 12px">Paid straight to your mobile money, no waiting. obitickets pays the mobile money fee, so you receive the full amount you ask for.</p>
          <div class="admin-form-row"><label>Amount (UGX)</label><input type="number" id="gwAmount" name="amount" min="<?= GIFT_WITHDRAW_MIN_UGX ?>" step="1" max="<?= $avail ?>" value="<?= $avail ?>" required></div>
          <p class="muted" style="font-size:.8rem; margin:-4px 0 12px">From UGX <?= number_format(GIFT_WITHDRAW_MIN_UGX) ?> up to UGX <?= number_format($avail) ?> (your whole gift balance).</p>
          <div class="admin-form-row"><label>Send to</label>
            <select name="method" id="gwMethod">
              <option value="MTN_MOMO" <?= $wProv === 'MTN_MOMO' ? 'selected' : '' ?>>MTN MoMo</option>
              <option value="AIRTEL_MONEY" <?= $wProv === 'AIRTEL_MONEY' ? 'selected' : '' ?>>Airtel Money</option>
            </select>
          </div>
          <div class="admin-form-row"><label>Mobile money number</label><input type="text" inputmode="tel" id="gwNumber" name="destination" value="<?= htmlspecialchars($wNum, ENT_QUOTES) ?>" required placeholder="e.g. 0772 123 456" autocomplete="tel"></div>
          <button class="btn btn-purple btn-block" type="submit">Withdraw now</button>
        </form>
        <script>
        (function () {
          var f = document.getElementById('gwForm'), a = document.getElementById('gwAmount'), n = document.getElementById('gwNumber'), m = document.getElementById('gwMethod');
          function upd() { f.setAttribute('data-confirm', 'Send UGX ' + Number(a.value || 0).toLocaleString() + ' to ' + (n.value || 'this number') + ' now? Money sent to a wrong number cannot be brought back.'); }
          // pick MTN or Airtel from the number as it is typed (070/075/074/020 = Airtel, 077/078/076/039 = MTN)
          function guess() { var d = n.value.replace(/\D/g, ''); if (d.indexOf('256') === 0) { d = '0' + d.slice(3); } var p = d.slice(0, 3); if (['070', '075', '074', '020'].indexOf(p) > -1) { m.value = 'AIRTEL_MONEY'; } else if (['077', '078', '076', '039'].indexOf(p) > -1) { m.value = 'MTN_MOMO'; } }
          a.addEventListener('input', upd); n.addEventListener('input', function () { guess(); upd(); }); upd();
        })();
        </script>
      <?php endif; ?>
    </div>
  </div>

<?php else:
    $days = (int) ($_GET['days'] ?? 30);
    $an = gift_org_analytics($organizerId, $days);
    $days = $an['days'];
    $max = max(1, ...array_column($an['series'], 'ugx'));
    $n = count($an['series']);
    $barW = 600 / $n;
?>
  <div class="og-range">
    <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label): ?>
      <a href="/org-gifts.php?tab=analytics&amp;days=<?= $d ?>" class="<?= $days === $d ? 'is-on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <div class="admin-kpi-grid og-kpis">
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Earned</span><span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span></div>
      <span class="num"><?= og_ugx($an['ugx']) ?></span>
      <span class="sub"><?php if ($an['change_pct'] === null): ?><?= $an['prev_ugx'] === 0 && $an['ugx'] > 0 ? 'First gifts in this time' : 'Nothing to compare yet' ?><?php else: ?><span class="og-delta <?= $an['change_pct'] >= 0 ? 'is-up' : 'is-down' ?>"><?= $an['change_pct'] >= 0 ? '+' : '' ?><?= (int) $an['change_pct'] ?>%</span> compared with the <?= $days ?> days before<?php endif; ?></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Gifts received</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-heart"/></svg></span></div>
      <span class="num"><?= (int) $an['gifts'] ?></span>
      <span class="sub"><?= (int) $an['units'] ?> in total counting quantities<?= $an['coins'] > 0 ? ' &middot; ' . number_format($an['coins']) . ' OBI COINS' : '' ?></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Average gift</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-chart"/></svg></span></div>
      <span class="num"><?= og_ugx($an['average_ugx']) ?></span>
      <span class="sub">your share per gift</span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Supporters</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-people"/></svg></span></div>
      <span class="num"><?= (int) $an['supporters'] ?></span>
      <span class="sub"><?= (int) $an['repeat_supporters'] ?> sent more than one</span>
    </div>
  </div>

  <div class="admin-card" style="margin-top:20px">
    <h3 style="margin-bottom:14px">Earnings per day</h3>
    <?php if ($an['ugx'] === 0): ?>
      <div class="admin-empty" style="padding:26px 12px"><div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-chart"/></svg></div><p style="margin-top:0">No gifts in this time yet.</p></div>
    <?php else: ?>
      <svg class="og-chart" viewBox="0 0 600 170" preserveAspectRatio="none" role="img" aria-label="Gift earnings per day for the last <?= $days ?> days">
        <?php foreach ($an['series'] as $i => $p): $h = $p['ugx'] > 0 ? max(3, round($p['ugx'] / $max * 150)) : 0; ?>
          <rect class="og-bar" x="<?= round($i * $barW + $barW * 0.12, 2) ?>" y="<?= 160 - $h ?>" width="<?= round($barW * 0.76, 2) ?>" height="<?= $h ?>" rx="2"><title><?= htmlspecialchars(date('j M', strtotime($p['date']))) ?>: <?= og_ugx($p['ugx']) ?> from <?= (int) $p['gifts'] ?> gift<?= $p['gifts'] === 1 ? '' : 's' ?></title></rect>
        <?php endforeach; ?>
        <line x1="0" x2="600" y1="160.5" y2="160.5" class="og-axis"/>
      </svg>
      <div class="og-chart-foot"><span><?= htmlspecialchars(date('j M', strtotime($an['series'][0]['date']))) ?></span><span>Best day <?= og_ugx($max) ?></span><span>Today</span></div>
    <?php endif; ?>
  </div>

  <div class="admin-grid-2" style="margin-top:20px">
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Which gifts earn the most</h3>
      <?php if (!$an['by_gift']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: $top = max(1, $an['by_gift'][0]['ugx']); foreach ($an['by_gift'] as $g): ?>
        <div class="og-rank">
          <div class="og-rank-top"><span><span aria-hidden="true"><?= htmlspecialchars((string) $g['emoji']) ?></span> <?= htmlspecialchars($g['name']) ?> <small class="muted">&times; <?= (int) $g['units'] ?><?= $g['coins'] > 0 ? ' &middot; ' . number_format($g['coins']) . ' OBI COINS' : '' ?></small></span><strong class="mono"><?= og_ugx($g['ugx']) ?></strong></div>
          <div class="og-rank-bar"><i style="width:<?= max(2, round($g['ugx'] / $top * 100)) ?>%"></i></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="admin-card">
      <h3 style="margin-bottom:14px">Top supporters</h3>
      <?php if (!$an['top_supporters'] && !$an['anonymous']['gifts']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: ?>
        <?php foreach ($an['top_supporters'] as $i => $s): ?>
          <div class="og-line"><span class="og-rankno"><?= $i + 1 ?></span><span class="og-line-main"><strong><?= htmlspecialchars($s['name']) ?></strong><small><?= (int) $s['gifts'] ?> gift<?= $s['gifts'] === 1 ? '' : 's' ?></small></span><span class="og-line-amt"><?= og_ugx($s['ugx']) ?></span></div>
        <?php endforeach; ?>
        <?php if ($an['anonymous']['gifts'] > 0): ?>
          <div class="og-line"><span class="og-rankno">?</span><span class="og-line-main"><strong>Anonymous supporters</strong><small><?= (int) $an['anonymous']['gifts'] ?> gift<?= $an['anonymous']['gifts'] === 1 ? '' : 's' ?> together</small></span><span class="og-line-amt"><?= og_ugx($an['anonymous']['ugx']) ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="admin-card" style="margin-top:20px">
    <h3 style="margin-bottom:14px">Where gifts come from</h3>
    <?php if (!$an['by_video']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>Video / event</th><th style="text-align:right">Gifts</th><th style="text-align:right">Your share</th></tr></thead>
          <tbody>
            <?php foreach ($an['by_video'] as $v): ?>
              <tr><td><?= htmlspecialchars($v['title']) ?></td><td class="mono" style="text-align:right"><?= (int) $v['gifts'] ?></td><td class="mono" style="text-align:right"><?= og_ugx($v['ugx']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($visible && $canThank): ?>
<dialog id="ogThanks" class="og-dialog" aria-labelledby="ogThanksTitle">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="thank"><input type="hidden" name="id" id="ogThanksId"><input type="hidden" name="back" value="<?= htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/org-gifts.php')) ?>">
    <h3 id="ogThanksTitle">Say thank you</h3>
    <p class="muted" id="ogThanksWho" style="margin:0 0 10px; font-size:.86rem"></p>
    <textarea name="message" id="ogThanksText" rows="5" maxlength="<?= (int) GIFT_THANKS_MAX_CHARS ?>" required></textarea>
    <div class="muted" style="display:flex; justify-content:space-between; gap:12px; font-size:.78rem; margin:4px 0 12px"><span id="ogThanksNote"></span><span><span id="ogThanksCount">0</span>/<?= (int) GIFT_THANKS_MAX_CHARS ?></span></div>
    <div style="display:flex; gap:10px; justify-content:flex-end"><button type="button" class="btn btn-line" id="ogThanksCancel">Cancel</button><button type="submit" class="btn btn-purple">Send thank-you</button></div>
  </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('ogThanks'); if (!dlg || typeof dlg.showModal !== 'function') { return; }
  var id = document.getElementById('ogThanksId'), who = document.getElementById('ogThanksWho'), text = document.getElementById('ogThanksText'), note = document.getElementById('ogThanksNote'), count = document.getElementById('ogThanksCount');
  function recount() { count.textContent = String(text.value.length); }
  text.addEventListener('input', recount);
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.og-thank') : null;
    if (!b) { return; }
    var anon = b.getAttribute('data-anon') === '1';
    id.value = b.getAttribute('data-id');
    who.textContent = 'To: ' + b.getAttribute('data-who');
    note.textContent = anon ? 'They stay anonymous: it reaches them as a notification, and you will not see who they are.' : 'It goes to them as a private chat message.';
    text.value = b.getAttribute('data-text'); recount();
    dlg.showModal(); text.focus();
  });
  document.getElementById('ogThanksCancel').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
})();
</script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
