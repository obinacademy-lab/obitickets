<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('ORGANIZER');
$events = get_events_for_organizer((int) $user['id']);

$statusLabels = ['PUBLISHED' => 'Published', 'DRAFT' => 'Draft', 'CANCELLED' => 'Cancelled'];

// Totals grouped by currency (an organizer's events are usually all one
// currency, but never assume it) — gross ticket sales, obitickets' 10%
// commission, and what's actually left to pay out.
$totalsByCurrency = [];
foreach ($events as $e) {
    if ((float) $e['gross_revenue'] <= 0) {
        continue;
    }
    $cur = $e['currency'] ?? 'UGX';
    $totalsByCurrency[$cur]['gross'] = ($totalsByCurrency[$cur]['gross'] ?? 0) + (float) $e['gross_revenue'];
    $totalsByCurrency[$cur]['commission'] = ($totalsByCurrency[$cur]['commission'] ?? 0) + (float) $e['commission_owed'];
}

$pageTitle = 'My events — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec">
    <div class="sec-top">
      <h2>My events</h2>
      <a class="btn btn-purple" href="/event-create.php">+ Create event</a>
    </div>

    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">Event created.</div><?php endif; ?>
    <?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Event updated.</div><?php endif; ?>

    <?php if ($totalsByCurrency): ?>
      <div class="checkout-card" style="margin-bottom:28px">
        <?php foreach ($totalsByCurrency as $cur => $t): ?>
          <div class="checkout-line"><span>Gross sales (<?= htmlspecialchars($cur) ?>)</span><span class="mono"><?= htmlspecialchars($cur . ' ' . number_format($t['gross'], 0)) ?></span></div>
          <div class="checkout-line"><span>obitickets commission (10%)</span><span class="mono">&minus;<?= htmlspecialchars($cur . ' ' . number_format($t['commission'], 0)) ?></span></div>
          <div class="checkout-line total"><span>Your payout</span><span class="mono"><?= htmlspecialchars($cur . ' ' . number_format($t['gross'] - $t['commission'], 0)) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$events): ?>
      <p style="text-align:center; padding:40px 0">You haven't created any events yet &mdash; <a href="/event-create.php">create your first one</a>.</p>
    <?php else: ?>
      <div class="my-events-list">
        <?php foreach ($events as $e): ?>
          <div class="my-event-row">
            <?php if (!empty($e['banner_image'])): ?>
              <div class="my-event-icon" style="background-image:url('<?= htmlspecialchars($e['banner_image']) ?>'); background-size:cover; background-position:center;"></div>
            <?php else: ?>
              <div class="my-event-icon"><?= htmlspecialchars($e['banner_emoji']) ?></div>
            <?php endif; ?>
            <div class="my-event-info">
              <h3><?= htmlspecialchars($e['title']) ?></h3>
              <p><?= htmlspecialchars(format_event_date_range($e['starts_at'], $e['ends_at'])) ?> &middot; <?= htmlspecialchars($e['venue_name']) ?></p>
              <div class="tag-list" style="margin-top:8px">
                <span class="tag-pill" style="<?= $e['status'] === 'PUBLISHED' ? '' : 'background:var(--line); color:var(--muted)' ?>"><?= $statusLabels[$e['status']] ?? $e['status'] ?></span>
                <span class="tag-pill"><?= (int) $e['tickets_sold'] ?> sold</span>
              </div>
              <?php if ((float) $e['gross_revenue'] > 0): ?>
                <p style="margin-top:8px; font-size:0.82rem">
                  <span class="mono"><?= htmlspecialchars($e['currency'] . ' ' . number_format((float) $e['gross_revenue'], 0)) ?></span> gross &middot;
                  payout <span class="mono"><?= htmlspecialchars($e['currency'] . ' ' . number_format((float) $e['gross_revenue'] - (float) $e['commission_owed'], 0)) ?></span>
                  <span style="color:var(--muted-2)">after 10% commission</span>
                </p>
              <?php endif; ?>
            </div>
            <div style="display:flex; gap:8px; flex:none">
              <a class="btn btn-line" href="/checkin.php?event=<?= (int) $e['id'] ?>">Check in</a>
              <a class="btn btn-line" href="/event-edit.php?id=<?= (int) $e['id'] ?>">Edit</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
