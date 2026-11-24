<?php
require_once __DIR__ . '/includes/bootstrap.php';

// The ticket wallet in the dark app look (owner's mockup, Phase 4, 11/18): Upcoming / Past, one ticket card per
// order (flyer, when, where, how many, how much) with a Show ticket button that opens the QR codes (order.php).
$user = require_login();
$orders = get_orders_for_user((int) $user['id']);

$upcoming = $past = [];
foreach ($orders as $o) {
    if (!empty($o['event_upcoming'])) { $upcoming[] = $o; } else { $past[] = $o; }
}
usort($upcoming, static fn ($a, $b) => strcmp((string) $a['event_starts_at'], (string) $b['event_starts_at']));
// Tickets friends sent this person (includes/transfers.php): shown as their own cards, opening ticket.php.
$received = tickets_received_by_user((int) $user['id']);
$receivedUp = array_values(array_filter($received, static fn ($r) => (int) $r['event_upcoming'] === 1));
$receivedPast = array_values(array_filter($received, static fn ($r) => (int) $r['event_upcoming'] !== 1));
$hasAny = $orders || $received;

/** Online state, price label and safe flyer path for one order. */
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
$whenLabel = static function (array $o): string {
    $days = (int) ($o['event_days_to'] ?? 99);
    return $days > 1 ? 'In ' . $days . ' days' : ($days === 1 ? 'Tomorrow' : ($days === 0 ? 'Today' : 'Now'));
};

$pageTitle = 'My tickets — obitickets';
$bodyClass = 'classic-page app-dark tw-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/wallet-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/wallet-app.css') ?: time() ?>">

<div class="wrap tw">
  <div class="ad-top">
    <h1>My Tickets</h1>
    <a class="ad-icon" href="/saved.php" aria-label="Saved events"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.4C1.5 7.6 3.9 4.5 7.2 4.5c1.9 0 3.6 1 4.8 2.6 1.2-1.6 2.9-2.6 4.8-2.6 3.3 0 5.7 3.1 4.5 6.6-1.8 4.8-9.3 9.4-9.3 9.4z"/></svg></a>
    <a class="ad-icon" href="/me.php" aria-label="My profile"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></a>
  </div>

  <?php if (!$hasAny): ?>
    <div class="tw-empty">
      <i><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z"/><path d="M14 5v14" stroke-dasharray="2 3"/></svg></i>
      <h2>No tickets yet</h2>
      <p>When you get a ticket it lands here with its QR code, ready to show at the gate.</p>
      <a class="tw-cta" href="/search.php">Find an event</a>
    </div>
  <?php else: ?>
    <div class="tw-seg" role="tablist" aria-label="Tickets">
      <button type="button" role="tab" id="twTabUp" aria-controls="twUp" aria-selected="true">Upcoming <small><?= count($upcoming) + count($receivedUp) ?></small></button>
      <button type="button" role="tab" id="twTabPast" aria-controls="twPast" aria-selected="false" tabindex="-1">Past <small><?= count($past) + count($receivedPast) ?></small></button>
    </div>

    <section id="twUp" role="tabpanel" aria-labelledby="twTabUp" class="tw-panel">
      <?php if (!$upcoming && !$receivedUp): ?>
        <div class="tw-empty tw-empty-sm"><h2>Nothing coming up</h2><p>Your next event will show here.</p><a class="tw-cta" href="/discover.php">Discover events</a></div>
      <?php endif; ?>
      <?php foreach ($upcoming as $o):
          $b = $orderBits($o);
          $ts = strtotime((string) $o['event_starts_at']);
          $qty = max(1, (int) $o['ticket_count']);
          $paid = $o['status'] === 'PAID';
      ?>
        <article class="tw-card<?= $paid ? '' : ' is-pending' ?>">
          <div class="tw-main">
            <a class="tw-art" href="/event.php?slug=<?= urlencode((string) $o['event_slug']) ?>" aria-label="Event page">
              <?php if ($b['img'] !== ''): ?><img src="<?= htmlspecialchars($b['img']) ?>" alt="" loading="lazy"><?php else: ?><span><b><?= date('d', $ts) ?></b><?= strtoupper(date('M', $ts)) ?></span><?php endif; ?>
            </a>
            <div class="tw-info">
              <?php if ($paid): ?><span class="tw-when"><?= htmlspecialchars($whenLabel($o)) ?></span><?php endif; ?>
              <h2><?= htmlspecialchars((string) $o['event_title']) ?></h2>
              <p><?= htmlspecialchars(date('D j M Y', $ts) . ' · ' . date('g:i A', $ts)) ?></p>
              <p><?= htmlspecialchars((string) $o['event_venue']) ?></p>
              <div class="tw-chips">
                <span class="tw-chip"><?= $qty ?> ticket<?= $qty === 1 ? '' : 's' ?></span>
                <?php if ($b['free']): ?><span class="tw-chip g">Free</span><?php else: ?><span class="tw-chip"><?= htmlspecialchars($b['price']) ?></span><?php endif; ?>
                <?php if ($b['online']): ?><span class="tw-chip b"><?= htmlspecialchars($b['onlineLabel']) ?></span><?php endif; ?>
                <?php if (!$paid): ?><span class="tw-chip y"><?= htmlspecialchars(ucfirst(strtolower((string) $o['status']))) ?></span><?php endif; ?>
              </div>
            </div>
          </div>
          <div class="tw-cut" aria-hidden="true"></div>
          <div class="tw-act">
            <?php if ($b['join']): ?><a class="tw-btn tw-red" href="/join.php?event=<?= (int) $o['event_id'] ?>">Join now</a><?php endif; ?>
            <?php if ($paid): ?>
              <a class="tw-btn <?= $b['join'] ? '' : 'tw-red' ?>" href="/order.php?id=<?= (int) $o['id'] ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 20h2M20 14v2"/></svg>Show ticket</a>
            <?php else: ?>
              <a class="tw-btn" href="/order.php?id=<?= (int) $o['id'] ?>">Check payment</a>
            <?php endif; ?>
            <span class="tw-no">Order #<?= (int) $o['id'] ?></span>
          </div>
        </article>
      <?php endforeach; ?>
      <?php foreach ($receivedUp as $r):
          $ts = strtotime((string) $r['event_starts_at']);
          $img = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', (string) ($r['banner_image'] ?? '')) ? (string) $r['banner_image'] : '';
          $days = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d'))) / 86400);
      ?>
        <article class="tw-card">
          <div class="tw-main">
            <a class="tw-art" href="/event.php?slug=<?= urlencode((string) $r['event_slug']) ?>" aria-label="Event page">
              <?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><span><b><?= date('d', $ts) ?></b><?= strtoupper(date('M', $ts)) ?></span><?php endif; ?>
            </a>
            <div class="tw-info">
              <span class="tw-when"><?= htmlspecialchars($days > 1 ? 'In ' . $days . ' days' : ($days === 1 ? 'Tomorrow' : ($days === 0 ? 'Today' : 'Now'))) ?></span>
              <h2><?= htmlspecialchars((string) $r['event_title']) ?></h2>
              <p><?= htmlspecialchars(date('D j M Y', $ts) . ' · ' . date('g:i A', $ts)) ?></p>
              <p><?= htmlspecialchars((string) $r['venue_name']) ?></p>
              <div class="tw-chips">
                <span class="tw-chip"><?= htmlspecialchars((string) $r['tier_name']) ?></span>
                <span class="tw-chip g">From <?= htmlspecialchars((string) ($r['from_name'] ?: 'a friend')) ?></span>
              </div>
            </div>
          </div>
          <div class="tw-cut" aria-hidden="true"></div>
          <div class="tw-act">
            <a class="tw-btn tw-red" href="/ticket.php?id=<?= (int) $r['id'] ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 20h2M20 14v2"/></svg>Show ticket</a>
            <span class="tw-no">Sent to you</span>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <section id="twPast" role="tabpanel" aria-labelledby="twTabPast" class="tw-panel" hidden>
      <?php if (!$past && !$receivedPast): ?>
        <div class="tw-empty tw-empty-sm"><h2>No past events yet</h2><p>Events you have been to will show here.</p></div>
      <?php endif; ?>
      <div class="tw-rows">
        <?php foreach ($past as $o):
            $b = $orderBits($o);
            $ts = strtotime((string) $o['event_starts_at']);
        ?>
          <a class="tw-row" href="/order.php?id=<?= (int) $o['id'] ?>">
            <span class="tw-row-art"><?php if ($b['img'] !== ''): ?><img src="<?= htmlspecialchars($b['img']) ?>" alt="" loading="lazy"><?php endif; ?></span>
            <span class="tw-row-tx">
              <b><?= htmlspecialchars((string) $o['event_title']) ?></b>
              <small><?= htmlspecialchars(date('j M Y', $ts)) ?> &middot; <?php if ($b['free']): ?><span>Free</span><?php else: ?><?= htmlspecialchars($b['price']) ?><?php endif; ?> &middot; <?= htmlspecialchars(ucfirst(strtolower((string) $o['status']))) ?></small>
            </span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
          </a>
        <?php endforeach; ?>
        <?php foreach ($receivedPast as $r): $img = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', (string) ($r['banner_image'] ?? '')) ? (string) $r['banner_image'] : ''; ?>
          <a class="tw-row" href="/ticket.php?id=<?= (int) $r['id'] ?>">
            <span class="tw-row-art"><?php if ($img !== ''): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php endif; ?></span>
            <span class="tw-row-tx">
              <b><?= htmlspecialchars((string) $r['event_title']) ?></b>
              <small><?= htmlspecialchars(date('j M Y', strtotime((string) $r['event_starts_at']))) ?> &middot; From <?= htmlspecialchars((string) ($r['from_name'] ?: 'a friend')) ?></small>
            </span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="tw-links">
    <a href="/profile.php">Edit profile</a>
    <a href="/discover.php">Browse events</a>
  </div>
</div>
<script>
(function () {
  var tabs = document.querySelectorAll('.tw-seg [role="tab"]');
  tabs.forEach(function (t, i) {
    function pick(x, focus) {
      tabs.forEach(function (y) { var on = y === x; y.setAttribute('aria-selected', on ? 'true' : 'false'); y.tabIndex = on ? 0 : -1; document.getElementById(y.getAttribute('aria-controls')).hidden = !on; });
      if (focus) { x.focus(); }
    }
    t.addEventListener('click', function () { pick(t, false); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
      e.preventDefault();
      pick(tabs[(i + 1) % tabs.length], true);
    });
  });
})();
</script>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
