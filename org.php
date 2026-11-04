<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('dashboard.view');
$organizerId = $ctx['organizer_id'];

// Each role only sees the figures it is allowed to see: every block below checks its own permission.
$canNet = organizer_can($ctx, 'earnings.view');
$canGross = organizer_can($ctx, 'sales.view') || organizer_can($ctx, 'analytics.view');
$canTickets = $canGross || organizer_can($ctx, 'tickets.view');
$canCheckin = organizer_can($ctx, 'checkin.use') || organizer_can($ctx, 'attendees.view') || organizer_can($ctx, 'analytics.view');
$canPayouts = organizer_can($ctx, 'payouts.view');
$canEvents = organizer_can($ctx, 'events.view');
$canOrders = organizer_can($ctx, 'tickets.view');

$days = (int) ($_GET['days'] ?? 30);
$days = in_array($days, [7, 30, 90], true) ? $days : 30;

$stats = get_organizer_dashboard_stats($organizerId);
$overview = get_organizer_overview($organizerId, $days);
$cur = $overview['cur'];
$prev = $overview['prev'];
$currency = $stats['currency'];
$attention = get_organizer_attention($ctx, $stats);
$upcoming = $canEvents ? get_upcoming_events_for_organizer($organizerId, 1) : [];
$events = $canEvents ? get_organizer_overview_events($organizerId, 5) : [];
$hasSales = $cur['gross'] > 0 || $prev['gross'] > 0;

/** The change against the previous period, or a plain sentence when there is nothing to compare with. */
function ov_trend(float $cur, float $prev, int $days): string
{
    if ($prev <= 0) {
        return $cur > 0 ? 'No sales in the ' . $days . ' days before' : 'Nothing in this period yet';
    }
    $pct = ($cur - $prev) / $prev * 100;
    return '<span class="' . ($pct >= 0 ? 'ov-up' : 'ov-down') . '">' . ($pct >= 0 ? '&#9650;' : '&#9660;') . ' ' . number_format(abs($pct), 1) . '%</span> vs previous ' . $days . ' days';
}

function ov_ago(string $when): string
{
    $s = time() - strtotime($when);
    if ($s < 60) {
        return 'Just now';
    }
    if ($s < 3600) {
        return floor($s / 60) . ' min';
    }
    if ($s < 86400) {
        return floor($s / 3600) . ' h';
    }
    return $s < 172800 ? 'Yesterday' : date('j M', strtotime($when));
}

function ov_event_chip(array $e): array
{
    if ($e['status'] === 'PUBLISHED') {
        return strtotime($e['ends_at']) >= time() ? ['live', 'On sale'] : ['end', 'Ended'];
    }
    return [
        'DRAFT' => ['draft', 'Draft'],
        'PENDING_REVIEW' => ['draft', 'In review'],
        'REJECTED' => ['bad', 'Not approved'],
        'SUSPENDED' => ['bad', 'Suspended'],
    ][$e['status']] ?? ['end', ucwords(strtolower($e['status']))];
}

// Recent activity, in sentences: paid orders plus what the team did. Check-in scans are left out, they would drown everything else.
$activityLabels = [
    'event.create' => ['ic-cal', 'Event created'], 'event.update' => ['ic-cal', 'Event updated'], 'event.duplicate' => ['ic-cal', 'Event duplicated'],
    'event.delete' => ['ic-x', 'Event deleted'], 'event.status_change' => ['ic-cal', 'Event status changed'],
    'ticket.pause_sales' => ['ic-ticket', 'Ticket sales paused'], 'ticket.resume_sales' => ['ic-ticket', 'Ticket sales resumed'],
    'promo.create' => ['ic-tag', 'Promo code created'], 'promo.activate' => ['ic-tag', 'Promo code switched on'], 'promo.deactivate' => ['ic-tag', 'Promo code switched off'], 'promo.delete' => ['ic-tag', 'Promo code deleted'],
    'payout.request' => ['ic-wallet', 'Withdrawal requested'], 'refund.request' => ['ic-receipt', 'Refund requested'],
    'team.invite' => ['ic-team', 'Team member invited'], 'team.role_change' => ['ic-team', 'Team member role changed'], 'team.revoke' => ['ic-team', 'Team member removed'],
    'support.create' => ['ic-help', 'Support ticket opened'],
];
$feed = [];
foreach (get_organizer_activity($organizerId, 10) as $a) {
    if (str_starts_with($a['action'], 'ticket.checkin')
        || (str_starts_with($a['action'], 'payout.') && !$canPayouts)
        || (str_starts_with($a['action'], 'refund.') && !organizer_can($ctx, 'refunds.view'))) {
        continue;
    }
    $label = $activityLabels[$a['action']] ?? ['ic-clock', ucfirst(str_replace(['.', '_'], ' ', $a['action']))];
    $details = $a['details'] ? (json_decode((string) $a['details'], true) ?: []) : [];
    $what = isset($details['title']) && is_scalar($details['title']) ? (string) $details['title'] : '';
    $feed[] = ['icon' => $label[0], 'tone' => '', 'title' => $label[1], 'sub' => $what !== '' ? $what . ' · ' . $a['actor_name'] : $a['actor_name'], 'when' => $a['created_at']];
}
if ($canOrders) {
    foreach (get_orders_for_organizer($organizerId, ['status' => 'PAID'], 6, 0) as $o) {
        $amount = (float) $o['subtotal_amount'];
        $feed[] = ['icon' => 'ic-receipt', 'tone' => 'green', 'title' => $amount > 0 ? 'Order paid, ' . $o['currency'] . ' ' . number_format($amount, 0) : 'Free ticket taken', 'sub' => $o['buyer_name'] . ' · ' . $o['event_title'], 'when' => $o['paid_at'] ?: $o['created_at']];
    }
}
usort($feed, static fn ($a, $b) => strtotime($b['when']) <=> strtotime($a['when']));
$feed = array_slice($feed, 0, 6);

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', current_user()['name'])[0];
$lede = "Here's how your events are doing.";
if ($upcoming) {
    $next = $upcoming[0];
    $total = (int) $next['tickets_total'];
    $lede = $next['title'] . ' ' . organizer_starts_in($next['starts_at']) . ($total > 0 ? ' and is ' . (int) round((int) $next['tickets_sold'] / $total * 100) . '% sold.' : '.');
} elseif ($canEvents && (int) $stats['total_events'] === 0) {
    $lede = 'Create your first event and your figures will start here.';
}

$pageTitle = 'Dashboard';
render_organizer_head('dashboard', $ctx);
?>

<header class="ov-top">
  <div>
    <p class="ov-eyebrow"><?= htmlspecialchars(date('D j M')) ?></p>
    <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?></h1>
    <p class="ov-lede"><?= htmlspecialchars($lede) ?></p>
  </div>
  <?php if ($canNet || $canGross || $canTickets): ?>
    <div class="ov-seg" role="group" aria-label="Period">
      <?php foreach ([7, 30, 90] as $d): ?>
        <a href="/org.php?days=<?= $d ?>" class="<?= $d === $days ? 'is-on' : '' ?>"<?= $d === $days ? ' aria-current="true"' : '' ?>><?= $d ?> days</a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</header>

<?php if ($canNet): ?>
<section class="ov-hero" aria-labelledby="ovHeroH">
  <div class="ov-hero-tx">
    <p class="ov-eyebrow" id="ovHeroH">Net earnings, last <?= $days ?> days</p>
    <div class="ov-big"><small><?= htmlspecialchars($currency) ?></small><span data-count-to="<?= (int) round($cur['net']) ?>">0</span></div>
    <p class="ov-hero-sub"><?php if ($cur['gross'] > 0): ?><?= ov_trend($cur['gross'], $prev['gross'], $days) ?> &middot; after the <?= (int) round(PLATFORM_COMMISSION_RATE * 100) ?>% obitickets commission<?php else: ?>No sales in this period yet<?php endif; ?></p>
    <div class="ov-hero-act">
      <?php if ((float) $stats['available_balance'] > 0 && organizer_can($ctx, 'payouts.request')): ?><a class="ov-btn ov-gold" href="/org-payouts.php">Request payout</a><?php endif; ?>
      <a class="ov-btn ov-ghost" href="/org-earnings.php">View statement</a>
    </div>
    <p class="ov-hero-sub ov-hero-bal">Available to withdraw <b><?= htmlspecialchars($currency) ?> <?= number_format((float) $stats['available_balance'], 0) ?></b><?php if ((int) $stats['pending_payouts_count'] > 0): ?> &middot; <?= (int) $stats['pending_payouts_count'] ?> payout<?= (int) $stats['pending_payouts_count'] === 1 ? '' : 's' ?> pending<?php endif; ?></p>
  </div>
  <?php if ($hasSales): ?>
    <div class="ov-chart" data-chart data-tone="gold" data-h="170" aria-label="Revenue per day for the last <?= $days ?> days" role="img"></div>
  <?php else: ?>
    <p class="ov-hero-empty">Your first sale will draw a line here.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="ov-kpis">
  <?php if ($canGross): ?>
    <div class="ov-kpi"><span class="kl">Gross revenue</span><b class="kv"><?= htmlspecialchars($currency) ?> <span data-count-to="<?= (int) round($cur['gross']) ?>">0</span></b><span class="kd"><?= ov_trend($cur['gross'], $prev['gross'], $days) ?></span></div>
  <?php endif; ?>
  <?php if ($canTickets): ?>
    <div class="ov-kpi"><span class="kl">Tickets sold</span><b class="kv"><span data-count-to="<?= (int) $cur['tickets'] ?>">0</span></b><span class="kd"><?= ov_trend((float) $cur['tickets'], (float) $prev['tickets'], $days) ?></span></div>
  <?php endif; ?>
  <?php if ($canGross): ?>
    <div class="ov-kpi"><span class="kl">Average order</span><b class="kv"><?= htmlspecialchars($currency) ?> <span data-count-to="<?= (int) round($cur['average']) ?>">0</span></b><span class="kd"><?= $cur['paid_orders'] ?> paid order<?= $cur['paid_orders'] === 1 ? '' : 's' ?></span></div>
  <?php endif; ?>
  <?php if ($canCheckin): ?>
    <div class="ov-kpi"><span class="kl">Check-in rate</span><b class="kv"><?= (int) $stats['tickets_sold'] > 0 ? (int) round($stats['checked_in'] / $stats['tickets_sold'] * 100) . '%' : '&mdash;' ?></b><span class="kd"><?= number_format((int) $stats['checked_in']) ?> of <?= number_format((int) $stats['tickets_sold']) ?> tickets scanned</span></div>
  <?php endif; ?>
  <?php if ($canPayouts): ?>
    <div class="ov-kpi"><span class="kl">Pending payouts</span><b class="kv"><?= (int) $stats['pending_payouts_count'] ?></b><span class="kd"><?= htmlspecialchars($currency) ?> <?= number_format((float) $stats['pending_payouts_amount'], 0) ?> requested</span></div>
  <?php endif; ?>
</div>

<div class="ov-cols">
  <?php if ($canGross): ?>
    <section class="ov-card">
      <div class="ov-ch">
        <div><h2>Revenue</h2><p>Ticket sales per day, against the period before</p></div>
        <?php if ($hasSales): ?><div class="ov-legend"><span><i></i>This period</span><span><i class="p"></i>Previous</span></div><?php endif; ?>
      </div>
      <?php if ($hasSales): ?>
        <div class="ov-chart" data-chart data-compare="1" data-h="230" aria-label="Revenue per day for the last <?= $days ?> days, against the <?= $days ?> days before" role="img"></div>
      <?php else: ?>
        <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-chart"/></svg><p>No ticket sales in the last <?= $days * 2 ?> days yet.</p></div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
  <section class="ov-card">
    <div class="ov-ch"><div><h2>Needs your attention</h2><p><?= $attention ? count($attention) . ' thing' . (count($attention) === 1 ? '' : 's') : 'You are all caught up' ?></p></div></div>
    <?php if ($attention): ?>
      <ul class="ov-list">
        <?php foreach ($attention as $t): ?>
          <li>
            <span class="ov-fi ov-<?= $t['tone'] ?>"><svg width="16" height="16" aria-hidden="true"><use href="#<?= $t['icon'] ?>"/></svg></span>
            <div><b><?= htmlspecialchars($t['title']) ?></b><span><?= htmlspecialchars($t['sub']) ?></span></div>
            <a href="<?= htmlspecialchars($t['href']) ?>"><?= htmlspecialchars($t['action']) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-check"/></svg><p>Nothing is waiting for you right now.</p></div>
    <?php endif; ?>
  </section>
</div>

<div class="ov-cols">
  <?php if ($canEvents): ?>
    <section class="ov-card">
      <div class="ov-ch"><div><h2>Your events</h2></div><a class="ov-all" href="/my-events.php">View all</a></div>
      <?php if ($events): ?>
        <div class="ov-events">
          <?php foreach ($events as $e): [$chipClass, $chipText] = ov_event_chip($e); $total = (int) $e['tickets_total']; $sold = (int) $e['tickets_sold']; ?>
            <a class="ov-ev" href="/event-edit.php?id=<?= (int) $e['id'] ?>">
              <span class="ov-poster"<?= $e['banner_image'] ? ' style="background-image:url(' . htmlspecialchars(str_replace(["'", '"', '\\', '(', ')', ' ', "\n", "\r"], '', (string) $e['banner_image']), ENT_QUOTES) . ')"' : '' ?>><?= $e['banner_image'] ? '' : htmlspecialchars($e['banner_emoji']) ?></span>
              <span class="ov-ev-tx"><b><?= htmlspecialchars($e['title']) ?></b><small><?= htmlspecialchars(date('D j M Y', strtotime($e['starts_at']))) ?> &middot; <?= htmlspecialchars($e['venue_name']) ?></small><i class="ov-chip ov-chip-<?= $chipClass ?>"><?= htmlspecialchars($chipText) ?></i></span>
              <span class="ov-sold"><span><b><?= number_format($sold) ?></b> / <?= number_format($total) ?> sold</span><span class="ov-bar"><i style="width:<?= $total > 0 ? min(100, (int) round($sold / $total * 100)) : 0 ?>%"></i></span></span>
              <?php if ($canGross): ?><span class="ov-rev"><?= htmlspecialchars($currency) ?> <?= number_format((float) $e['revenue'], 0) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-cal"/></svg><p>You have not created an event yet.</p><?php if (organizer_can($ctx, 'events.manage')): ?><a class="ov-btn ov-red" href="/event-create.php">Create your first event</a><?php endif; ?></div>
      <?php endif; ?>
    </section>
  <?php elseif (organizer_can($ctx, 'checkin.use')): ?>
    <section class="ov-card">
      <div class="ov-ch"><div><h2>Check-in</h2><p>Scan tickets at the door</p></div></div>
      <a class="ov-btn ov-red" href="/checkin.php">Open check-in</a>
    </section>
  <?php endif; ?>
  <section class="ov-card">
    <div class="ov-ch"><div><h2>Recent activity</h2></div></div>
    <?php if ($feed): ?>
      <ul class="ov-list ov-feed">
        <?php foreach ($feed as $f): ?>
          <li>
            <span class="ov-fi<?= $f['tone'] ? ' ov-' . $f['tone'] : '' ?>"><svg width="16" height="16" aria-hidden="true"><use href="#<?= $f['icon'] ?>"/></svg></span>
            <div><b><?= htmlspecialchars($f['title']) ?></b><span><?= htmlspecialchars($f['sub']) ?></span></div>
            <time datetime="<?= htmlspecialchars(date('c', strtotime($f['when']))) ?>"><?= htmlspecialchars(ov_ago($f['when'])) ?></time>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="ov-none"><svg width="22" height="22" aria-hidden="true"><use href="#ic-clock"/></svg><p>Nothing yet. Sales and your team's actions will show up here.</p></div>
    <?php endif; ?>
  </section>
</div>

<?php if (($canNet || $canGross) && $hasSales): ?>
  <script type="application/json" id="ovData"><?= json_encode(['currency' => $currency, 'cur' => $cur['days'], 'prev' => $prev['days']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <script src="/assets/js/org-overview.js?v=<?= @filemtime(__DIR__ . '/assets/js/org-overview.js') ?: time() ?>"></script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
