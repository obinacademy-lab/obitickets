<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$orders = get_orders_for_user((int) $user['id']);

$pageTitle = 'My tickets — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <div class="sec-top">
      <h2>My tickets</h2>
      <a class="btn btn-line" href="/">Browse events</a>
    </div>

    <?php if (!$orders): ?>
      <p style="text-align:center; padding:40px 0">You haven't bought any tickets yet &mdash; <a href="/">find an event</a>.</p>
    <?php else: ?>
      <div class="my-events-list">
        <?php foreach ($orders as $o): ?>
          <div class="my-event-row">
            <div class="my-event-icon">🎟️</div>
            <div class="my-event-info">
              <h3><?= htmlspecialchars($o['event_title']) ?></h3>
              <p><?= htmlspecialchars(date('D j M Y', strtotime($o['event_starts_at']))) ?></p>
              <div class="tag-list" style="margin-top:8px">
                <span class="tag-pill" style="<?= $o['status'] === 'PAID' ? '' : 'background:var(--line); color:var(--muted)' ?>"><?= htmlspecialchars($o['status']) ?></span>
                <span class="tag-pill"><?= htmlspecialchars($o['currency'] . ' ' . number_format((float) $o['total_amount'], 0)) ?></span>
              </div>
            </div>
            <a class="btn btn-line" href="/order.php?id=<?= (int) $o['id'] ?>">View tickets</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
