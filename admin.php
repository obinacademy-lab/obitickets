<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role('ADMIN');
$stats = get_platform_stats();

$pageTitle = 'Admin — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <?php render_admin_tabs('overview'); ?>

    <div class="admin-head">
      <span class="kicker">Admin</span>
      <h1>Platform overview</h1>
      <p style="color:var(--muted)">How obitickets is doing right now, across every organizer and every event.</p>
    </div>

    <div class="admin-stat-grid">
      <div class="admin-stat"><span class="num"><?= $stats['total_users'] ?></span><span class="lbl">Users</span></div>
      <div class="admin-stat"><span class="num"><?= $stats['total_organizers'] ?></span><span class="lbl">Organizers</span></div>
      <div class="admin-stat"><span class="num"><?= $stats['published_events'] ?> <span class="of">/ <?= $stats['total_events'] ?></span></span><span class="lbl">Published events</span></div>
      <div class="admin-stat"><span class="num"><?= $stats['total_orders'] ?></span><span class="lbl">Paid orders</span></div>
      <div class="admin-stat"><span class="num"><?= $stats['checked_in'] ?> <span class="of">/ <?= $stats['total_tickets'] ?></span></span><span class="lbl">Tickets checked in</span></div>
    </div>

    <?php if ($stats['revenue_by_currency'] || $stats['commission_by_currency'] || $stats['service_fees_by_currency']): ?>
      <div class="admin-revenue-grid">
        <?php if ($stats['revenue_by_currency']): ?>
          <div class="admin-revenue-card">
            <h3>Gross ticket sales</h3>
            <p class="sub">Money that passed through the platform</p>
            <?php foreach ($stats['revenue_by_currency'] as $r): ?>
              <div class="checkout-line"><span><?= htmlspecialchars($r['currency']) ?></span><span class="mono"><?= htmlspecialchars($r['currency'] . ' ' . number_format((float) $r['total'], 0)) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($stats['commission_by_currency'] || $stats['service_fees_by_currency']): ?>
          <?php
          $currencies = array_unique(array_merge(array_column($stats['commission_by_currency'], 'currency'), array_column($stats['service_fees_by_currency'], 'currency')));
          $commissionByCur = array_column($stats['commission_by_currency'], 'total', 'currency');
          $feesByCur = array_column($stats['service_fees_by_currency'], 'total', 'currency');
          ?>
          <div class="admin-revenue-card">
            <h3>Platform revenue</h3>
            <p class="sub">What obitickets actually keeps</p>
            <?php foreach ($currencies as $cur): $c = (float) ($commissionByCur[$cur] ?? 0); $f = (float) ($feesByCur[$cur] ?? 0); ?>
              <div class="checkout-line"><span>Organizer commission (10%) &middot; <?= htmlspecialchars($cur) ?></span><span class="mono"><?= htmlspecialchars($cur . ' ' . number_format($c, 0)) ?></span></div>
              <div class="checkout-line"><span>Buyer service fee &middot; <?= htmlspecialchars($cur) ?></span><span class="mono"><?= htmlspecialchars($cur . ' ' . number_format($f, 0)) ?></span></div>
              <div class="checkout-line total"><span>Total <?= htmlspecialchars($cur) ?></span><span class="mono"><?= htmlspecialchars($cur . ' ' . number_format($c + $f, 0)) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
