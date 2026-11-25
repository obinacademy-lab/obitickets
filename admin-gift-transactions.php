<?php
require_once __DIR__ . '/includes/bootstrap.php';

// OBI GIFTS (phase 6): every gift transaction, one transaction in full, reversing a gift and the sender-refund follow-up.
// Reading needs gifts.view. Reversing a gift and recording that the sender was paid back need gifts.refund. Gifts are separate from tickets.
$admin = require_admin_permission('gifts.view');
$adminId = (int) $admin['id'];
$ready = gifts_ready();
$canRefund = admin_can('gifts.refund');
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$ugx = static fn ($n): string => 'UGX ' . number_format((int) $n);
$flash = $_SESSION['gift_admin_flash'] ?? null;
unset($_SESSION['gift_admin_flash']);
$error = null;
$viewId = (int) ($_GET['id'] ?? 0);

// ---- actions on one transaction
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $txId = (int) ($_POST['id'] ?? 0);
    if (!in_array($action, ['reverse', 'sender_refunded'], true) || $txId <= 0) {
        http_response_code(400);
        exit('Bad request.');
    }
    if (!$canRefund) {
        http_response_code(403);
        exit('You do not have permission to reverse gifts or record refunds.');
    }
    if ($action === 'reverse') {
        $res = gift_reverse($txId, $adminId, (string) ($_POST['reason'] ?? ''));
        $msg = $res['ok'] ? 'The gift was reversed. ' . ($res['balance_after'] < 0 ? 'The organizer had already withdrawn it, so their gift balance is now ' . $ugx($res['balance_after']) . ' and is repaid from their future gifts. ' : '')
            . 'Now send the money back to the sender and record it here.' : $res['error'];
    } else {
        $res = gift_admin_mark_sender_refunded($txId, $adminId, (string) ($_POST['note'] ?? ''));
        $msg = $res['ok'] ? 'Recorded: the sender has been paid back.' : $res['error'];
    }
    $_SESSION['gift_admin_flash'] = $msg;
    header('Location: /admin-gift-transactions.php?id=' . $txId);
    exit;
}

$filters = $ready ? gift_admin_tx_filters($_GET) : [];

// ---- CSV of the current filter (no full phone numbers, no card of anyone's contact beyond what support needs)
if ($ready && isset($_GET['export']) && !$viewId) {
    $rows = gift_admin_tx_list($filters, 2000)['rows'];
    $safe = static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;   // a spreadsheet would run these as formulas
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gift-transactions-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Created', 'Paid', 'Reference', 'Status', 'Gift', 'Quantity', 'Gross (UGX)', 'Organizer share (UGX)', 'obitickets share (UGX)', 'ioTec fee (UGX)', 'Organizer', 'Sender', 'Sender email', 'Anonymous', 'Method', 'Phone']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], (string) $r['paid_at'], $r['reference'], gift_admin_status_label($r)[0], $safe((string) $r['gift_name']), (int) $r['quantity'], (int) $r['gross_ugx'], (int) $r['organizer_ugx'],
            (int) $r['platform_ugx'], $r['gateway_fee_ugx'] !== null ? (int) $r['gateway_fee_ugx'] : '', $safe((string) $r['organizer_name']), $safe((string) $r['sender_name']), $safe((string) $r['sender_email']),
            $r['is_anonymous'] ? 'yes' : 'no', $r['payment_method'], $r['phone_masked']]);
    }
    fclose($out);
    exit;
}

$allGifts = $ready ? db()->query('SELECT id, name, emoji FROM gift_catalog ORDER BY sort_order, id')->fetchAll() : [];
$pageTitle = 'OBI GIFTS: transactions';
render_admin_head('gifts');
?>

<div class="admin-page-head">
  <div><h1>OBI GIFTS</h1><p>Every gift payment, from the first tap to the ledger. Gifts are completely separate from tickets.</p></div>
</div>

<?php if ($flash): ?><span data-flash="<?= $h($flash) ?>" hidden></span><?php endif; ?>
<?php if (!$ready): ?>
  <div class="admin-card" style="border-left:4px solid var(--danger)"><h3 style="margin:0 0 6px">Database update needed</h3><p style="margin:0">Run <b>migration/045_ob_gifts.sql</b> in phpMyAdmin first.</p></div>
<?php render_admin_foot(); return; endif; ?>

<?= gift_admin_tabs('transactions') ?>

<?php if ($viewId):
    $t = gift_admin_tx_detail($viewId);
    if (!$t): ?>
      <div class="admin-card"><p style="margin:0">That transaction does not exist. <a href="/admin-gift-transactions.php">Back to the list</a></p></div>
    <?php else:
      [$stLabel, $stClass] = gift_admin_status_label($t);
      $when = static fn ($v): string => $v ? date('d M Y, H:i:s', strtotime((string) $v)) : '—';
      $timeline = [];
      foreach ($t['events'] as $e) { $timeline[] = [(string) $e['created_at'], 'System', ucfirst(str_replace('_', ' ', (string) $e['kind'])), (string) $e['details']]; }
      foreach ($t['audit'] as $a) { $timeline[] = [(string) $a['created_at'], (string) ($a['admin_name'] ?: 'Admin'), (string) $a['action'], (string) $a['details']]; }
      usort($timeline, static fn ($x, $y) => strcmp($x[0], $y[0]));
    ?>
    <p style="margin:0 0 14px"><a href="/admin-gift-transactions.php" class="muted" style="font-weight:700; text-decoration:none">&larr; All transactions</a></p>
    <div class="admin-grid-2">
      <div class="admin-card">
        <h3 style="margin:0 0 12px"><?= $h($t['reference']) ?> <span class="admin-badge <?= $stClass ?>"><?= $h($stLabel) ?></span></h3>
        <div class="admin-detail-row"><span class="k">Gift</span><span class="v"><?= $h(trim(($t['emoji'] ?? '') . ' ' . $t['gift_name'])) ?> &times; <?= (int) $t['quantity'] ?></span></div>
        <div class="admin-detail-row"><span class="k">Price paid</span><span class="v mono"><?= $ugx($t['gross_ugx']) ?></span></div>
        <div class="admin-detail-row"><span class="k">Organizer's share</span><span class="v mono"><?= $ugx($t['organizer_ugx']) ?></span></div>
        <div class="admin-detail-row"><span class="k">obitickets' share</span><span class="v mono"><?= $ugx($t['platform_ugx']) ?></span></div>
        <div class="admin-detail-row"><span class="k">ioTec collection fee</span><span class="v mono"><?= $t['gateway_fee_ugx'] !== null ? $ugx($t['gateway_fee_ugx']) . ' (paid by obitickets)' : 'not reported' ?></span></div>
        <div class="admin-detail-row"><span class="k">Split used</span><span class="v">version <?= (int) $t['config_version'] ?></span></div>
        <div class="admin-detail-row"><span class="k">Started</span><span class="v"><?= $h($when($t['created_at'])) ?></span></div>
        <div class="admin-detail-row"><span class="k">Paid</span><span class="v"><?= $h($when($t['paid_at'])) ?></span></div>
        <div class="admin-detail-row"><span class="k">Reversed</span><span class="v"><?= $h($when($t['refunded_at'])) ?></span></div>
        <div class="admin-detail-row"><span class="k">Paid with</span><span class="v"><?= $t['payment_method'] === 'AIRTEL_MONEY' ? 'Airtel Money' : 'MTN MoMo' ?> &middot; <span class="mono"><?= $h(gift_admin_format_phone($t['phone'])) ?></span></span></div>
        <div class="admin-detail-row"><span class="k">ioTec reference</span><span class="v mono" style="font-size:.8rem; word-break:break-all"><?= $h($t['provider_reference'] ?: '—') ?></span></div>
        <div class="admin-detail-row"><span class="k">Sent from</span><span class="v"><?= $t['post_id'] ? $h(($t['video_event'] ?: 'A video')) : 'The organizer\'s profile' ?></span></div>
        <?php if ($t['status_message']): ?><div class="admin-detail-row"><span class="k">Message</span><span class="v"><?= $h($t['status_message']) ?></span></div><?php endif; ?>
      </div>

      <div class="admin-card">
        <h3 style="margin:0 0 12px">People</h3>
        <div class="admin-detail-row"><span class="k">Sender</span><span class="v"><?= $h($t['sender_name'] ?: 'Unknown') ?><br><span class="muted" style="font-size:.8rem; font-weight:500"><?= $h($t['sender_email']) ?></span><?php if ($t['is_anonymous']): ?><br><span class="admin-badge admin-badge-muted" style="margin-top:4px">Anonymous to the organizer</span><?php endif; ?></span></div>
        <div class="admin-detail-row"><span class="k">Organizer</span><span class="v"><?= $h($t['organizer_name']) ?><br><span class="muted" style="font-size:.8rem; font-weight:500"><?= $h($t['organizer_email']) ?></span><br><a href="/admin-gift-transactions.php?organizer=<?= (int) $t['recipient_organizer_id'] ?>" style="font-size:.8rem; font-weight:700; text-decoration:none">All their gifts</a></span></div>
        <div class="admin-detail-row"><span class="k">Their gift balance now</span><span class="v mono"><?= $ugx($t['organizer_balance']['available']) ?></span></div>
        <div class="admin-detail-row"><span class="k">Withdrawn so far</span><span class="v mono"><?= $ugx($t['organizer_balance']['total_withdrawn']) ?></span></div>

        <?php if ($t['can_reverse']): ?>
          <h3 style="margin:22px 0 8px">Reverse this gift</h3>
          <?php if ($canRefund): ?>
            <p class="muted" style="font-size:.84rem; margin:0 0 10px">Gifts are final for the sender. Reverse one only for a real problem (a payment error, fraud, a duplicate). It takes <b><?= $ugx($t['organizer_ugx']) ?></b> off the organizer's gift earnings
              <?= $t['balance_after_reversal'] < 0 ? '<b style="color:var(--danger)">and leaves them owing ' . $ugx(-$t['balance_after_reversal']) . '</b>, repaid from their future gifts only (never from ticket money).' : 'and leaves them with ' . $ugx($t['balance_after_reversal']) . '.' ?>
              The sender is told they will be paid back, and you then send them <b><?= $ugx($t['gross_ugx']) ?></b> by hand and record it.</p>
            <form method="post" data-confirm="Reverse this gift? This cannot be undone.">
              <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <div class="admin-form-row"><label>Reason (kept in the audit log)</label><input type="text" name="reason" required maxlength="200" placeholder="e.g. Sender was charged twice"></div>
              <button class="btn btn-line" type="submit" style="color:var(--danger)">Reverse gift</button>
            </form>
          <?php else: ?><p class="muted" style="font-size:.84rem; margin:0">Only a Finance Admin or the top-level admin can reverse a gift.</p><?php endif; ?>
        <?php elseif ($t['payment_status'] === 'REFUNDED'):
            $rf = $t['sender_refund']; ?>
          <h3 style="margin:22px 0 8px">Refund to the sender</h3>
          <?php if ($rf['done']): ?>
            <p style="margin:0 0 4px"><span class="admin-badge admin-badge-success">Sent back</span> <span class="muted" style="font-size:.84rem"><?= $h($when($rf['at'])) ?></span></p>
            <p class="muted" style="font-size:.84rem; margin:0"><?= $h($rf['note']) ?></p>
          <?php else: ?>
            <p style="margin:0 0 8px"><span class="admin-badge admin-badge-warn">Money still owed to the sender</span></p>
            <p class="muted" style="font-size:.84rem; margin:0 0 10px">Send <b><?= $ugx($t['gross_ugx']) ?></b> to <b class="mono"><?= $h(gift_admin_format_phone($t['phone'])) ?></b> (<?= $t['payment_method'] === 'AIRTEL_MONEY' ? 'Airtel Money' : 'MTN MoMo' ?>) from the ioTec portal or the MoMo app, then record it here. The sender is told once you do.</p>
            <?php if ($canRefund): ?>
              <form method="post" data-confirm="Record that the money was sent back to the sender?">
                <?= csrf_field() ?><input type="hidden" name="action" value="sender_refunded"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <div class="admin-form-row"><label>How was it sent back?</label><input type="text" name="note" required maxlength="200" placeholder="e.g. MoMo transaction 123456789"></div>
                <button class="btn btn-purple" type="submit">Mark as sent back</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-card" style="margin-top:20px">
      <h3 style="margin:0 0 12px">Ledger</h3>
      <?php if (!$t['ledger']): ?><p class="muted" style="margin:0">No money has been recorded for this gift (it was never paid).</p><?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>#</th><th>Account</th><th>Entry</th><th style="text-align:right">Amount</th><th>Type</th><th>Note</th></tr></thead>
            <tbody>
              <?php foreach ($t['ledger'] as $l): ?>
                <tr><td class="muted mono"><?= (int) $l['id'] ?></td><td><?= $h($l['account']) ?></td><td><?= $h($l['entry']) ?></td><td class="mono" style="text-align:right"><?= $ugx($l['amount_ugx']) ?></td><td><?= $h($l['entry_type']) ?><?= $l['reverses_id'] ? ' <span class="muted">of #' . (int) $l['reverses_id'] . '</span>' : '' ?></td><td class="muted"><?= $h($l['note']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted" style="font-size:.78rem; margin:10px 0 0">The ledger is append-only: a reversal adds opposite rows and never edits the original ones.</p>
      <?php endif; ?>
    </div>

    <div class="admin-card" style="margin-top:20px">
      <h3 style="margin:0 0 12px">Timeline</h3>
      <?php if (!$timeline): ?><p class="muted" style="margin:0">Nothing recorded yet.</p><?php else: ?>
        <div class="admin-table-wrap" style="border:none; box-shadow:none;">
          <table class="admin-table">
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
            <tbody>
              <?php foreach ($timeline as [$at, $who, $what, $detail]): ?>
                <tr><td class="muted mono" style="white-space:nowrap"><?= $h(date('d M, H:i:s', strtotime($at))) ?></td><td><?= $h($who) ?></td><td><?= $h($what) ?></td><td class="muted" style="word-break:break-word"><?= $h($detail) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

<?php else:
    $page = gift_admin_tx_list($filters, 30);
    $rows = $page['rows'];
    $keep = array_filter(['status' => $filters['status'] !== 'all' ? $filters['status'] : null, 'gift' => $filters['gift'] ?: null, 'organizer' => $filters['organizer'] ?: null, 'q' => $filters['q'] !== '' ? $filters['q'] : null,
                          'from' => $filters['from'], 'to' => $filters['to'], 'min' => $filters['min'] ?: null]);
    $owed = gift_admin_refunds_owed();
?>
  <?php if ($owed['count'] > 0): ?>
    <div class="admin-card" style="margin-bottom:16px; border-left:4px solid var(--warn)">
      <p style="margin:0"><b><?= (int) $owed['count'] ?> reversed gift<?= $owed['count'] === 1 ? '' : 's' ?></b> still need the money sent back to the sender (<b><?= $ugx($owed['ugx']) ?></b><?= $owed['oldest'] ? ', oldest reversed ' . $h(date('d M', strtotime($owed['oldest']))) : '' ?>).
        <a href="/admin-gift-transactions.php?status=reversed" style="font-weight:700">Open the reversed gifts</a>, then record each refund on its page.</p>
    </div>
  <?php endif; ?>

  <form method="get" class="og-filters" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:16px">
    <label style="display:flex; flex-direction:column; gap:5px; font-size:.74rem; font-weight:700; color:var(--muted); text-transform:uppercase">Search
      <input type="text" name="q" value="<?= $h($filters['q']) ?>" placeholder="Reference, name or e-mail" style="min-width:210px">
    </label>
    <label style="display:flex; flex-direction:column; gap:5px; font-size:.74rem; font-weight:700; color:var(--muted); text-transform:uppercase">Status
      <select name="status"><?php foreach (['all' => 'All', 'paid' => 'Paid', 'reversed' => 'Reversed', 'waiting' => 'Waiting', 'failed' => 'Failed / expired'] as $k => $l): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    </label>
    <label style="display:flex; flex-direction:column; gap:5px; font-size:.74rem; font-weight:700; color:var(--muted); text-transform:uppercase">Gift
      <select name="gift"><option value="0">All gifts</option><?php foreach ($allGifts as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $filters['gift'] === (int) $g['id'] ? ' selected' : '' ?>><?= $h(trim(($g['emoji'] ?? '') . ' ' . $g['name'])) ?></option><?php endforeach; ?></select>
    </label>
    <label style="display:flex; flex-direction:column; gap:5px; font-size:.74rem; font-weight:700; color:var(--muted); text-transform:uppercase">From <input type="date" name="from" value="<?= $h($filters['from']) ?>"></label>
    <label style="display:flex; flex-direction:column; gap:5px; font-size:.74rem; font-weight:700; color:var(--muted); text-transform:uppercase">To <input type="date" name="to" value="<?= $h($filters['to']) ?>"></label>
    <label style="display:flex; flex-direction:column; gap:5px; font-size:.74rem; font-weight:700; color:var(--muted); text-transform:uppercase">At least (UGX) <input type="number" name="min" min="0" step="500" value="<?= $filters['min'] ?: '' ?>" style="width:120px"></label>
    <?php if ($filters['organizer']): ?><input type="hidden" name="organizer" value="<?= (int) $filters['organizer'] ?>"><?php endif; ?>
    <button type="submit" class="btn btn-purple">Show</button>
    <a class="btn btn-line" href="/admin-gift-transactions.php">Clear</a>
    <a href="/admin-gift-transactions.php?<?= $h(http_build_query($keep + ['export' => 1])) ?>" style="font-weight:700; font-size:.84rem; text-decoration:none; padding:9px 4px">Download CSV</a>
  </form>
  <?php if ($filters['organizer']): ?><p class="muted" style="font-size:.84rem; margin:-4px 0 12px">Showing one organizer's gifts only. <a href="/admin-gift-transactions.php">Show everyone</a></p><?php endif; ?>

  <div class="admin-card">
    <?php if (!$rows): ?>
      <p class="muted" style="margin:0">No gifts match.</p>
    <?php else: ?>
      <div class="admin-table-wrap" style="border:none; box-shadow:none;">
        <table class="admin-table">
          <thead><tr><th>When</th><th>Reference</th><th>Gift</th><th>Sender</th><th>Organizer</th><th style="text-align:right">Price</th><th style="text-align:right">Organizer</th><th style="text-align:right">obitickets</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): [$lab, $cls] = gift_admin_status_label($r); ?>
              <tr>
                <td class="muted" style="white-space:nowrap"><?= $h(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
                <td class="mono" style="font-size:.8rem"><a href="/admin-gift-transactions.php?id=<?= (int) $r['id'] ?>" style="font-weight:700; text-decoration:none"><?= $h($r['reference']) ?></a></td>
                <td style="white-space:nowrap"><span aria-hidden="true"><?= $h((string) $r['emoji']) ?></span> <?= (int) $r['quantity'] > 1 ? (int) $r['quantity'] . ' &times; ' : '' ?><?= $h($r['gift_name']) ?></td>
                <td><?= $h($r['sender_name'] ?: 'Unknown') ?><?= $r['is_anonymous'] ? ' <span class="muted" style="font-size:.74rem">(anonymous)</span>' : '' ?><br><span class="muted mono" style="font-size:.74rem"><?= $h($r['phone_masked']) ?></span></td>
                <td><?= $h($r['organizer_name']) ?></td>
                <td class="mono" style="text-align:right; white-space:nowrap"><?= $ugx($r['gross_ugx']) ?></td>
                <td class="mono" style="text-align:right; white-space:nowrap"><?= $ugx($r['organizer_ugx']) ?></td>
                <td class="mono" style="text-align:right; white-space:nowrap"><?= $ugx($r['platform_ugx']) ?></td>
                <td><span class="admin-badge <?= $cls ?>"><?= $h($lab) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($page['next']): ?><div style="text-align:center; margin-top:16px"><a class="btn btn-line" href="/admin-gift-transactions.php?<?= $h(http_build_query($keep + ['before' => $page['next']])) ?>">Show older</a></div><?php endif; ?>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:.8rem; margin-top:12px">Phone numbers are masked here and shown in full on a transaction's own page.</p>
<?php endif; ?>

<?php render_admin_foot(); ?>
