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
      <span style="display:flex; gap:10px; flex-wrap:wrap">
        <a class="btn btn-line" href="/profile.php">Edit profile</a>
        <a class="btn btn-line" href="/">Browse events</a>
      </span>
    </div>

    <?php if (!$orders): ?>
      <p style="text-align:center; padding:40px 0">You haven't bought any tickets yet &mdash; <a href="/">find an event</a>.</p>
    <?php else: ?>
      <div class="my-events-list">
        <?php foreach ($orders as $o):
            $oEvent = events_online_ready() ? get_event_by_id((int) $o['event_id']) : null;
            $oOnline = $oEvent && event_is_online($oEvent);
            $oFree = (float) $o['total_amount'] <= 0.0;
            $oWindow = ($oOnline && $o['status'] === 'PAID') ? online_join_window($oEvent) : null;
        ?>
          <div class="my-event-row">
            <div class="my-event-icon">🎟️</div>
            <div class="my-event-info">
              <h3><?= htmlspecialchars($o['event_title']) ?></h3>
              <p><?= htmlspecialchars(date('D j M Y', strtotime($o['event_starts_at']))) ?></p>
              <div class="tag-list" style="margin-top:8px">
                <span class="tag-pill" style="<?= $o['status'] === 'PAID' ? '' : 'background:var(--line); color:var(--muted)' ?>"><?= htmlspecialchars($o['status']) ?></span>
                <span class="tag-pill"><?= $oFree ? 'Free' : htmlspecialchars($o['currency'] . ' ' . number_format((float) $o['total_amount'], 0)) ?></span>
                <?php if ($oOnline): ?><span class="tag-pill" style="background:#E6F1FF; color:#1456B8"><?= htmlspecialchars(event_online_label($oEvent)) ?></span><?php endif; ?>
              </div>
            </div>
            <?php if ($oWindow && $oWindow['state'] === 'open'): ?><a class="btn btn-purple" href="/join.php?event=<?= (int) $o['event_id'] ?>">Join now</a><?php endif; ?>
            <a class="btn btn-line" href="/order.php?id=<?= (int) $o['id'] ?>"><?= $o['status'] === 'PENDING' ? 'Check payment' : 'View tickets' ?></a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
