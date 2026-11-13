<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$orders = get_orders_for_user((int) $user['id']);

// Split what the person holds: the next paid event becomes the big pass, the rest are rows.
$upcoming = $past = [];
foreach ($orders as $o) {
    if (!empty($o['event_upcoming'])) { $upcoming[] = $o; } else { $past[] = $o; }
}
usort($upcoming, static fn ($a, $b) => strcmp((string) $a['event_starts_at'], (string) $b['event_starts_at']));
$pass = null;
foreach ($upcoming as $i => $o) {
    if ($o['status'] === 'PAID') { $pass = $o; unset($upcoming[$i]); break; }
}
$upcoming = array_values($upcoming);
$upcomingCount = count($upcoming) + ($pass ? 1 : 0);

/** Online state, price label and safe flyer path for one order row. */
$orderBits = static function (array $o): array {
    $event = events_online_ready() ? get_event_by_id((int) $o['event_id']) : null;
    $online = $event && event_is_online($event);
    $window = ($online && $o['status'] === 'PAID') ? online_join_window($event) : null;
    $img = (string) ($o['event_banner'] ?? '');
    return [
        'online' => $online,
        'onlineLabel' => $online ? event_online_label($event) : '',
        'join' => (bool) ($window && $window['state'] === 'open'),
        'free' => (float) $o['total_amount'] <= 0.0,
        'price' => $o['currency'] . ' ' . number_format((float) $o['total_amount'], 0),
        'img' => preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $img) ? $img : '',
    ];
};

$pageTitle = 'My tickets — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<section class="rc-head lift">
  <div class="wrap">
    <h1>Your tickets</h1>
    <p><?= $upcomingCount === 0 ? 'Nothing coming up yet' : $upcomingCount . ' upcoming event' . ($upcomingCount === 1 ? '' : 's') ?></p>
  </div>
</section>

<div class="wrap rc-tk">
  <?php if (!$orders): ?>
    <div class="rc-empty">
      <i><svg width="28" height="28" aria-hidden="true"><use href="#ic-ticket"/></svg></i>
      <h2>No tickets yet</h2>
      <p>When you get a ticket it lands here with its QR code, ready to show at the gate.</p>
      <a class="btn btn-purple" href="/search.php">Find an event</a>
    </div>
  <?php endif; ?>

  <?php if ($pass):
      $b = $orderBits($pass);
      $ts = strtotime((string) $pass['event_starts_at']);
      $days = (int) $pass['event_days_to'];
      $when = $days > 1 ? 'In ' . $days . ' days' : ($days === 1 ? 'Tomorrow' : ($days === 0 ? 'Today' : 'Happening now'));
      $qty = (int) $pass['ticket_count'];
  ?>
    <article class="rc-pass">
      <div class="rc-pass-top">
        <?php if ($b['img'] !== ''): ?><img class="rc-thumb" src="<?= htmlspecialchars($b['img']) ?>" alt=""><?php endif; ?>
        <div>
          <h2><?= htmlspecialchars($pass['event_title']) ?></h2>
          <span><?= htmlspecialchars((string) $pass['event_venue']) ?></span>
        </div>
      </div>
      <div class="rc-pass-when">
        <div><b><?= $when ?></b><small>Your next event</small></div>
        <div style="text-align:right"><strong><?= htmlspecialchars(date('D j M', $ts)) ?></strong><small><?= htmlspecialchars(date('g:ia', $ts)) ?></small></div>
      </div>
      <div class="rc-pass-facts">
        <div><small>Tickets</small><b><?= $qty > 0 ? $qty : 1 ?></b></div>
        <div><small>Paid</small><b><?php if ($b['free']): ?><span>Free</span><?php else: ?><?= htmlspecialchars($b['price']) ?><?php endif; ?></b></div>
        <div><small>Order</small><b>#<?= (int) $pass['id'] ?></b></div>
      </div>
      <?php if ($b['online']): ?><div><span class="rc-chip b"><?= htmlspecialchars($b['onlineLabel']) ?></span></div><?php endif; ?>
      <div class="rc-pass-act">
        <?php if ($b['join']): ?><a class="main wide" href="/join.php?event=<?= (int) $pass['event_id'] ?>">Join now</a><?php endif; ?>
        <a class="<?= $b['join'] ? '' : 'main' ?>" href="/order.php?id=<?= (int) $pass['id'] ?>">Show ticket</a>
        <a href="/event.php?slug=<?= urlencode((string) $pass['event_slug']) ?>">Event page</a>
      </div>
    </article>
  <?php endif; ?>

  <?php
  $renderRows = static function (array $list) use ($orderBits): void {
      foreach ($list as $o):
          $b = $orderBits($o);
          $ts = strtotime((string) $o['event_starts_at']);
          $paid = $o['status'] === 'PAID';
  ?>
    <div class="rc-row wrap">
      <?php if ($b['img'] !== ''): ?>
        <img class="rc-thumb" src="<?= htmlspecialchars($b['img']) ?>" alt="" loading="lazy">
      <?php else: ?>
        <div class="rc-date"><b><?= date('d', $ts) ?></b><small><?= strtoupper(date('M', $ts)) ?></small></div>
      <?php endif; ?>
      <div class="rc-row-tx">
        <b><?= htmlspecialchars($o['event_title']) ?></b>
        <span><?= htmlspecialchars(date('D j M Y', $ts)) ?></span>
        <div class="rc-chips">
          <span class="rc-chip <?= $paid ? 'g' : 'n' ?>"><?= htmlspecialchars($o['status']) ?></span>
          <?php if ($b['free']): ?><span class="rc-chip">Free</span><?php else: ?><span class="rc-chip"><?= htmlspecialchars($b['price']) ?></span><?php endif; ?>
          <?php if ($b['online']): ?><span class="rc-chip b"><?= htmlspecialchars($b['onlineLabel']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="rc-row-act">
        <?php if ($b['join']): ?><a class="rc-mini main" href="/join.php?event=<?= (int) $o['event_id'] ?>">Join now</a><?php endif; ?>
        <a class="rc-mini" href="/order.php?id=<?= (int) $o['id'] ?>"><?= $o['status'] === 'PENDING' ? 'Check payment' : 'View tickets' ?></a>
      </div>
    </div>
  <?php endforeach; }; ?>

  <?php if ($upcoming): ?>
    <h3 class="rc-tk-h"><?= $pass ? 'Also coming up' : 'Coming up' ?></h3>
    <div class="rc-rows"><?php $renderRows($upcoming); ?></div>
  <?php endif; ?>

  <?php if ($past): ?>
    <h3 class="rc-tk-h">Past events</h3>
    <div class="rc-rows"><?php $renderRows($past); ?></div>
  <?php endif; ?>

  <div class="rc-tk-links">
    <a class="btn btn-line" href="/profile.php">Edit profile</a>
    <a class="btn btn-line" href="/search.php">Browse events</a>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
