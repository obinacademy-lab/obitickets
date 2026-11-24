<?php
require_once __DIR__ . '/includes/bootstrap.php';

// OB GIFTS (phase 6): reports for the owner/finance: sales, obitickets' income, what organizers earned, what is owed, per-organizer earnings,
// and a live check that the books add up. Read only. Gifts are separate from tickets: nothing here reads orders, tickets or ticket payouts.
$admin = require_admin_permission('gifts.view');
$ready = gifts_ready();
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$ugx = static fn ($n): string => 'UGX ' . number_format((int) $n);

// the date range: presets or a custom from/to (at most 2 years)
$range = (string) ($_GET['range'] ?? '30');
$today = date('Y-m-d');
switch ($range) {
    case 'today': [$from, $to] = [$today, $today]; break;
    case '7': [$from, $to] = [date('Y-m-d', strtotime('-6 days')), $today]; break;
    case 'month': [$from, $to] = [date('Y-m-01'), $today]; break;
    case 'lastmonth': [$from, $to] = [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))]; break;
    case '90': [$from, $to] = [date('Y-m-d', strtotime('-89 days')), $today]; break;
    case 'year': [$from, $to] = [date('Y-01-01'), $today]; break;
    case 'custom':
        $from = gift_admin_date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime('-29 days'));
        $to = gift_admin_date($_GET['to'] ?? null) ?? $today;
        break;
    default: $range = '30'; [$from, $to] = [date('Y-m-d', strtotime('-29 days')), $today];
}
if ($to < $from) { [$from, $to] = [$to, $from]; }
if (strtotime($to) - strtotime($from) > 2 * 366 * 86400) { $from = date('Y-m-d', strtotime($to . ' -2 years')); }

$r = $ready ? gift_admin_report($from, $to) : null;

// a CSV of the report (headline numbers, then the per-day or per-month rows)
if ($ready && isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gift-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['OB Gifts report', $from, $to]);
    foreach ([['Gifts paid', $r['gifts']], ['Sales (UGX)', $r['gross']], ['Organizers\' share (UGX)', $r['organizer']], ['obitickets share (UGX)', $r['platform']], ['ioTec collection fees (UGX)', $r['fees']],
              ['obitickets net after fees (UGX)', $r['net_platform']], ['Gifts reversed', $r['reversed_gifts']], ['Reversed value (UGX)', $r['reversed_gross']], ['Withdrawn by organizers (UGX)', $r['withdrawn_ugx']]] as $row) {
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    fputcsv($out, [$r['monthly'] ? 'Month' : 'Day', 'Gifts', 'Sales (UGX)', 'obitickets share (UGX)']);
    foreach ($r['series'] as $p) { fputcsv($out, [$p['label'], $p['gifts'], $p['gross'], $p['platform']]); }
    fclose($out);
    exit;
}

$orgs = $ready ? gift_admin_organizers(100) : [];
$problems = $ready ? gift_reconcile() : [];
$pageTitle = 'OB Gifts: reports';
render_admin_head('gifts');
?>

<div class="admin-page-head">
  <div><h1>OB Gifts</h1><p>How gifts are doing and where the money is. Gifts are completely separate from tickets.</p></div>
</div>

<?php if (!$ready): ?>
  <div class="admin-card" style="border-left:4px solid var(--danger)"><h3 style="margin:0 0 6px">Database update needed</h3><p style="margin:0">Run <b>migration/045_ob_gifts.sql</b> in phpMyAdmin first.</p></div>
<?php render_admin_foot(); return; endif; ?>

<?= gift_admin_tabs('reports') ?>

<form method="get" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:18px">
  <?php foreach (['today' => 'Today', '7' => '7 days', '30' => '30 days', 'month' => 'This month', 'lastmonth' => 'Last month', '90' => '90 days', 'year' => 'This year'] as $k => $label): ?>
    <a class="btn <?= $range === (string) $k ? 'btn-purple' : 'btn-line' ?>" href="/admin-gift-reports.php?range=<?= $k ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <span style="display:inline-flex; gap:6px; align-items:flex-end">
    <input type="hidden" name="range" value="custom">
    <label style="display:flex; flex-direction:column; gap:4px; font-size:.72rem; font-weight:700; color:var(--muted); text-transform:uppercase">From <input type="date" name="from" value="<?= $h($from) ?>"></label>
    <label style="display:flex; flex-direction:column; gap:4px; font-size:.72rem; font-weight:700; color:var(--muted); text-transform:uppercase">To <input type="date" name="to" value="<?= $h($to) ?>"></label>
    <button type="submit" class="btn <?= $range === 'custom' ? 'btn-purple' : 'btn-line' ?>">Show</button>
  </span>
  <a href="/admin-gift-reports.php?<?= $h(http_build_query(['range' => $range, 'from' => $from, 'to' => $to, 'export' => 1])) ?>" style="font-weight:700; font-size:.84rem; text-decoration:none; padding:9px 4px">Download CSV</a>
</form>
<p class="muted" style="font-size:.84rem; margin:-6px 0 14px"><?= $h(date('j M Y', strtotime($from))) ?> to <?= $h(date('j M Y', strtotime($to))) ?>. A gift counts on the day it was paid; a reversal on the day it was reversed.</p>

<div class="admin-kpi-grid">
  <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Gift sales</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span></div>
    <span class="num"><?= $ugx($r['gross']) ?></span><span class="sub"><?= (int) $r['gifts'] ?> gift<?= $r['gifts'] === 1 ? '' : 's' ?> &middot; <?= (int) $r['supporters'] ?> supporter<?= $r['supporters'] === 1 ? '' : 's' ?> &middot; average <?= $ugx($r['average_ugx']) ?></span></div>
  <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">obitickets income</span><span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-wallet"/></svg></span></div>
    <span class="num"><?= $ugx($r['net_platform']) ?></span><span class="sub"><?= $ugx($r['platform']) ?> share, minus <?= $ugx($r['fees']) ?> ioTec collection fees</span></div>
  <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Earned by organizers</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-people"/></svg></span></div>
    <span class="num"><?= $ugx($r['organizer']) ?></span><span class="sub"><?= $ugx($r['withdrawn_ugx']) ?> withdrawn in this time (<?= (int) $r['withdrawn_count'] ?>)</span></div>
  <div class="admin-kpi-card"><div class="admin-kpi-card-top"><span class="lbl">Reversed</span><span class="admin-kpi-ic" style="background:var(--danger-bg); color:var(--danger)"><svg width="17" height="17"><use href="#ic-receipt"/></svg></span></div>
    <span class="num"><?= $ugx($r['reversed_gross']) ?></span><span class="sub"><?= (int) $r['reversed_gifts'] ?> gift<?= $r['reversed_gifts'] === 1 ? '' : 's' ?> reversed</span></div>
</div>

<div class="admin-grid-2" style="margin-top:20px">
  <div class="admin-card">
    <h3 style="margin:0 0 12px">Payments started in this time</h3>
    <div class="admin-detail-row"><span class="k">Started</span><span class="v mono"><?= (int) $r['started'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Paid (including reversed later)</span><span class="v mono" style="color:var(--success)"><?= (int) $r['succeeded'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Failed, declined or expired</span><span class="v mono"><?= (int) $r['failed'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Still waiting</span><span class="v mono"><?= (int) $r['waiting'] ?></span></div>
    <div class="admin-detail-row"><span class="k">Success rate</span><span class="v mono"><?= $r['success_rate'] === null ? '—' : (int) $r['success_rate'] . '%' ?></span></div>
  </div>
  <div class="admin-card">
    <h3 style="margin:0 0 12px">Money position right now</h3>
    <div class="admin-detail-row"><span class="k">Owed to organizers (can withdraw)</span><span class="v mono"><?= $ugx($r['owed_available'] + $r['owed_pending']) ?></span></div>
    <div class="admin-detail-row"><span class="k">On its way to organizers</span><span class="v mono"><?= $ugx($r['owed_reserved']) ?><?= $r['withdraw_open_count'] > 0 ? ' <span class="muted" style="font-weight:500">(' . (int) $r['withdraw_open_count'] . ' withdrawal' . ($r['withdraw_open_count'] === 1 ? '' : 's') . ')</span>' : '' ?></span></div>
    <div class="admin-detail-row"><span class="k">Organizers who owe back (reversed after withdrawing)</span><span class="v mono"><?= (int) $r['debtors'] ?><?= $r['debtors'] > 0 ? ' (' . $ugx($r['debt_ugx']) . ')' : '' ?></span></div>
    <div class="admin-detail-row"><span class="k">Refunds still owed to senders</span><span class="v mono"><?= (int) $r['refunds_owed']['count'] ?><?= $r['refunds_owed']['count'] > 0 ? ' (' . $ugx($r['refunds_owed']['ugx']) . ')' : '' ?></span></div>
    <?php if ($r['refunds_owed']['count'] > 0): ?><p style="margin:10px 0 0; font-size:.84rem"><a href="/admin-gift-transactions.php?status=reversed" style="font-weight:700">Open the reversed gifts</a> and record each refund once it is sent.</p><?php endif; ?>
  </div>
</div>

<div class="admin-card" style="margin-top:20px">
  <h3 style="margin:0 0 6px">Books check</h3>
  <?php if (!$problems): ?>
    <p style="margin:0"><span class="admin-badge admin-badge-success">All consistent</span> <span class="muted" style="font-size:.84rem">Every gift's split adds up, every ledger balances, every organizer's balance matches their gifts and withdrawals. Checked just now.</span></p>
  <?php else: ?>
    <p style="margin:0 0 8px"><span class="admin-badge admin-badge-danger"><?= count($problems) ?> problem<?= count($problems) === 1 ? '' : 's' ?></span> <span class="muted" style="font-size:.84rem">Do not change anything by hand. Tell the developer.</span></p>
    <ul style="margin:0; padding-left:18px; font-size:.86rem"><?php foreach (array_slice($problems, 0, 20) as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>

<div class="admin-card" style="margin-top:20px">
  <h3 style="margin:0 0 14px">Sales <?= $r['monthly'] ? 'per month' : 'per day' ?></h3>
  <?php $max = max(1, ...array_column($r['series'], 'gross')); $n = count($r['series']); $barW = 600 / max(1, $n); ?>
  <?php if ($r['gross'] === 0): ?>
    <p class="muted" style="margin:0">No gifts were paid in this time.</p>
  <?php else: ?>
    <svg viewBox="0 0 600 170" preserveAspectRatio="none" role="img" aria-label="Gift sales over time" style="display:block; width:100%; height:170px">
      <?php foreach ($r['series'] as $i => $p): $hgt = $p['gross'] > 0 ? max(3, round($p['gross'] / $max * 150)) : 0; ?>
        <rect x="<?= round($i * $barW + $barW * 0.12, 2) ?>" y="<?= 160 - $hgt ?>" width="<?= round($barW * 0.76, 2) ?>" height="<?= $hgt ?>" rx="2" fill="var(--purple, #E11D48)" opacity=".9"><title><?= $h($p['label']) ?>: <?= $ugx($p['gross']) ?> from <?= (int) $p['gifts'] ?> gift<?= $p['gifts'] === 1 ? '' : 's' ?> (obitickets <?= $ugx($p['platform']) ?>)</title></rect>
      <?php endforeach; ?>
      <line x1="0" x2="600" y1="160.5" y2="160.5" stroke="var(--line-strong, #ccc)" stroke-width="1" vector-effect="non-scaling-stroke"/>
    </svg>
    <div style="display:flex; justify-content:space-between; font-size:.76rem; color:var(--muted); margin-top:8px"><span><?= $h($r['series'][0]['label']) ?></span><span>Best <?= $r['monthly'] ? 'month' : 'day' ?> <?= $ugx($max) ?></span><span><?= $h($r['series'][$n - 1]['label']) ?></span></div>
  <?php endif; ?>
</div>

<div class="admin-grid-2" style="margin-top:20px">
  <div class="admin-card">
    <h3 style="margin:0 0 12px">Which gifts sell</h3>
    <?php if (!$r['by_gift']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: foreach ($r['by_gift'] as $g): ?>
      <div class="admin-detail-row"><span class="k"><span aria-hidden="true"><?= $h((string) $g['emoji']) ?></span> <?= $h($g['name']) ?> <span style="font-size:.78rem">&times; <?= (int) $g['units'] ?></span></span><span class="v mono"><?= $ugx($g['gross']) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
  <div class="admin-card">
    <h3 style="margin:0 0 12px">Top organizers in this time</h3>
    <?php if (!$r['top_organizers']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: foreach ($r['top_organizers'] as $o): ?>
      <div class="admin-detail-row"><span class="k"><a href="/admin-gift-transactions.php?organizer=<?= (int) $o['id'] ?>" style="text-decoration:none; font-weight:700"><?= $h($o['name']) ?></a> <span style="font-size:.78rem"><?= (int) $o['gifts'] ?> gift<?= $o['gifts'] === 1 ? '' : 's' ?></span></span><span class="v mono"><?= $ugx($o['gross']) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="admin-card" style="margin-top:20px">
  <h3 style="margin:0 0 6px">Top supporters in this time</h3>
  <p class="muted" style="font-size:.8rem; margin:0 0 10px">Admins see real names here, including supporters who stay anonymous to organizers.</p>
  <?php if (!$r['top_supporters']): ?><p class="muted" style="margin:0">Nothing yet.</p><?php else: foreach ($r['top_supporters'] as $s): ?>
    <div class="admin-detail-row"><span class="k"><strong style="color:var(--ink)"><?= $h($s['name'] ?: 'Unknown') ?></strong> <span style="font-size:.78rem"><?= $h($s['email']) ?> &middot; <?= (int) $s['gifts'] ?> gift<?= $s['gifts'] === 1 ? '' : 's' ?><?= $s['anonymous_sometimes'] ? ' &middot; sometimes anonymous' : '' ?></span></span><span class="v mono"><?= $ugx($s['gross']) ?></span></div>
  <?php endforeach; endif; ?>
</div>

<div class="admin-card" style="margin-top:20px">
  <h3 style="margin:0 0 6px">Earnings per organizer</h3>
  <p class="muted" style="font-size:.8rem; margin:0 0 12px">All time, for every organizer who has received a gift. "Gift balance" is what they can withdraw now; a negative number means a reversed gift is being repaid from their future gifts.</p>
  <?php if (!$orgs): ?><p class="muted" style="margin:0">No organizer has received a gift yet.</p><?php else: ?>
    <div class="admin-table-wrap" style="border:none; box-shadow:none;">
      <table class="admin-table">
        <thead><tr><th>Organizer</th><th style="text-align:right">Gifts</th><th style="text-align:right">Earned</th><th style="text-align:right">Withdrawn</th><th style="text-align:right">On its way</th><th style="text-align:right">Gift balance</th><th>Last gift</th></tr></thead>
        <tbody>
          <?php foreach ($orgs as $o): ?>
            <tr>
              <td><a href="/admin-gift-transactions.php?organizer=<?= (int) $o['id'] ?>" style="font-weight:700; text-decoration:none"><?= $h($o['name']) ?></a><br><span class="muted" style="font-size:.76rem"><?= $h($o['email']) ?></span></td>
              <td class="mono" style="text-align:right"><?= (int) $o['gifts'] ?></td>
              <td class="mono" style="text-align:right; white-space:nowrap"><?= $ugx($o['earned']) ?></td>
              <td class="mono" style="text-align:right; white-space:nowrap"><?= $ugx($o['withdrawn']) ?></td>
              <td class="mono" style="text-align:right; white-space:nowrap"><?= $ugx($o['reserved']) ?></td>
              <td class="mono" style="text-align:right; white-space:nowrap<?= $o['in_debt'] ? '; color:var(--danger)' : '' ?>"><?= $ugx($o['available']) ?><?= $o['in_debt'] ? ' <span class="admin-badge admin-badge-danger">owes</span>' : '' ?></td>
              <td class="muted" style="white-space:nowrap"><?= $o['last_gift'] ? $h(date('d M Y', strtotime($o['last_gift']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_admin_foot(); ?>
