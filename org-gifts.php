<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * Gifts & Support (OB Gifts, phase 4): what the organizer earned from gifts, who sent them, and how it is going.
 * Read only. Withdrawing gift money comes in phase 5. Every number is scoped to the signed-in organizer's own account.
 */
$ctx = require_organizer_access('gifts.view');
$organizerId = $ctx['organizer_id'];

$tab = in_array($_GET['tab'] ?? '', ['history', 'analytics'], true) ? $_GET['tab'] : 'overview';
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
    fputcsv($out, ['Date', 'Supporter', 'Gift', 'Quantity', 'Gift price (UGX)', 'Your share (UGX)', 'Where', 'Status', 'Reference']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) $r['paid_at'], og_csv_safe($r['supporter']), og_csv_safe($r['gift_name']), (int) $r['quantity'], (int) $r['gross_ugx'],
            (int) $r['organizer_ugx'], og_csv_safe($r['where']), $r['reversed'] ? 'Reversed' : 'Received', $r['reference'],
        ]);
    }
    fclose($out);
    exit;
}

$GLOBALS['orgExtraCss'] = ['org-gifts.css'];
$pageTitle = 'Gifts & Support';
$active = $tab === 'history' ? 'gifts-history' : ($tab === 'analytics' ? 'gifts-analytics' : 'gifts');
render_organizer_head($active, $ctx);

$sharePct = gift_org_share_percent();
$sharePctText = rtrim(rtrim(number_format($sharePct, 2), '0'), '.');
?>

<div class="admin-page-head">
  <div>
    <h1>Gifts &amp; Support</h1>
    <p>Money your supporters send you as gifts on your videos. It is kept completely separate from your ticket sales.</p>
  </div>
</div>

<?php if (!$visible): ?>
  <div class="admin-card"><div class="admin-empty" style="padding:34px 24px">
    <div class="admin-empty-ic"><svg width="22" height="22"><use href="#ic-heart"/></svg></div>
    <p style="margin-top:0"><?= $ready ? 'Gifts are not switched on yet. When they are, supporters will be able to send you gifts from your videos and you will see them here.' : 'Gifts are not available yet.' ?></p>
  </div></div>
<?php render_organizer_foot(); return; endif; ?>

<?php if ($tab === 'overview'):
    $sum = gift_org_summary($organizerId);
    $recent = gift_org_history($organizerId, [], 6)['rows'];
    $bal = $sum['balance'];
?>
  <?php if ($sum['in_debt']): ?>
    <div class="og-note og-note-warn">A gift sent to you was reversed after you had already taken the money out. <strong><?= og_ugx(-$bal['available']) ?></strong> will be taken from your next gifts until it is paid back. Your ticket money is never touched.</div>
  <?php endif; ?>

  <div class="admin-kpi-grid">
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Gift balance</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-wallet"/></svg></span></div>
      <span class="num"><?= og_ugx($bal['available']) ?></span>
      <span class="sub"><?= $bal['pending'] > 0 ? og_ugx($bal['pending']) . ' on its way &middot; ' : '' ?>Withdrawing gift money opens soon</span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">This month</span><span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span></div>
      <span class="num"><?= og_ugx($sum['month_ugx']) ?></span>
      <span class="sub"><?= (int) $sum['month_gifts'] ?> gift<?= $sum['month_gifts'] === 1 ? '' : 's' ?> &middot; <?= og_ugx($sum['week_ugx']) ?> in the last 7 days</span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Total earned</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-chart"/></svg></span></div>
      <span class="num"><?= og_ugx($bal['total_earned']) ?></span>
      <span class="sub"><?= (int) $sum['gifts'] ?> gift<?= $sum['gifts'] === 1 ? '' : 's' ?> received<?= $sum['biggest_ugx'] > 0 ? ' &middot; biggest ' . og_ugx($sum['biggest_ugx']) : '' ?></span>
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
            <small><?= htmlspecialchars($r['where']) ?> &middot; <?= htmlspecialchars(date('j M, H:i', strtotime((string) $r['paid_at']))) ?></small>
          </span>
          <span class="og-line-amt<?= $r['reversed'] ? ' is-reversed' : '' ?>"><?= $r['reversed'] ? 'Reversed' : '+' . og_ugx((int) $r['organizer_ugx']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="admin-card">
      <h3 style="margin-bottom:14px">How gifts work</h3>
      <div class="admin-detail-row"><span class="k">You keep</span><span class="v mono"><?= htmlspecialchars($sharePctText) ?>% of every gift</span></div>
      <div class="admin-detail-row"><span class="k">Mobile money fees</span><span class="v">Paid by obitickets, not you</span></div>
      <div class="admin-detail-row"><span class="k">Ticket money</span><span class="v">Separate, never mixed with gifts</span></div>
      <div class="admin-detail-row"><span class="k">Gifts reversed</span><span class="v mono"><?= (int) $sum['reversed'] ?><?= $sum['reversed'] > 0 ? ' (' . og_ugx($sum['reversed_ugx']) . ')' : '' ?></span></div>
      <p class="muted" style="font-size:0.82rem; margin:14px 0 0">Gifts are final for the supporter. Only the obitickets team can reverse one, for example after a payment problem.</p>
    </div>
  </div>

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
          <thead><tr><th>Date</th><th>Supporter</th><th>Gift</th><th>Where</th><th style="text-align:right">Gift price</th><th style="text-align:right">Your share</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="muted"><?= htmlspecialchars(date('j M Y, H:i', strtotime((string) $r['paid_at']))) ?></td>
              <td><?= htmlspecialchars($r['supporter']) ?></td>
              <td><span aria-hidden="true"><?= htmlspecialchars((string) $r['emoji']) ?></span> <?= (int) $r['quantity'] > 1 ? (int) $r['quantity'] . ' &times; ' : '' ?><?= htmlspecialchars($r['gift_name']) ?></td>
              <td class="muted og-wrap"><?= htmlspecialchars($r['where']) ?></td>
              <td class="mono" style="text-align:right"><?= og_ugx((int) $r['gross_ugx']) ?></td>
              <td class="mono" style="text-align:right"><?= $r['reversed'] ? '<s class="muted">' . og_ugx((int) $r['organizer_ugx']) . '</s>' : og_ugx((int) $r['organizer_ugx']) ?></td>
              <td><span class="admin-badge <?= $r['reversed'] ? 'admin-badge-danger' : 'admin-badge-success' ?>"><?= $r['reversed'] ? 'Reversed' : 'Received' ?></span></td>
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
  <p class="muted" style="font-size:0.8rem; margin-top:12px">Supporters who chose to stay anonymous show as "Anonymous supporter". Phone numbers are never shown.</p>

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

  <div class="admin-kpi-grid">
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Earned</span><span class="admin-kpi-ic" style="background:var(--success-bg); color:var(--success)"><svg width="17" height="17"><use href="#ic-cash"/></svg></span></div>
      <span class="num"><?= og_ugx($an['ugx']) ?></span>
      <span class="sub"><?php if ($an['change_pct'] === null): ?><?= $an['prev_ugx'] === 0 && $an['ugx'] > 0 ? 'First gifts in this time' : 'Nothing to compare yet' ?><?php else: ?><span class="og-delta <?= $an['change_pct'] >= 0 ? 'is-up' : 'is-down' ?>"><?= $an['change_pct'] >= 0 ? '+' : '' ?><?= (int) $an['change_pct'] ?>%</span> compared with the <?= $days ?> days before<?php endif; ?></span>
    </div>
    <div class="admin-kpi-card">
      <div class="admin-kpi-card-top"><span class="lbl">Gifts received</span><span class="admin-kpi-ic" style="background:var(--purple-tint); color:var(--purple)"><svg width="17" height="17"><use href="#ic-heart"/></svg></span></div>
      <span class="num"><?= (int) $an['gifts'] ?></span>
      <span class="sub"><?= (int) $an['units'] ?> in total counting quantities</span>
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
          <div class="og-rank-top"><span><span aria-hidden="true"><?= htmlspecialchars((string) $g['emoji']) ?></span> <?= htmlspecialchars($g['name']) ?> <small class="muted">&times; <?= (int) $g['units'] ?></small></span><strong class="mono"><?= og_ugx($g['ugx']) ?></strong></div>
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

<?php render_organizer_foot(); ?>
