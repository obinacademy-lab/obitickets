<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Saved events: every event this person follows (the "Follow event" button on an event page), soonest first.
// Reached from the heart in the home page's top bar.
$user = require_login();

$stmt = db()->prepare("
    SELECT " . event_select_sql() . ", e.status
    FROM event_follows f
    JOIN events e ON e.id = f.event_id
    WHERE f.user_id = ? AND e.status = 'PUBLISHED'
    ORDER BY (e.ends_at < NOW()) ASC, e.starts_at ASC
    LIMIT 100
");
$stmt->execute([(int) $user['id']]);
$saved = $stmt->fetchAll();
$upcomingCount = count(array_filter($saved, static fn ($e) => strtotime((string) $e['ends_at']) >= time()));

$pageTitle = 'Saved events — obitickets';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<section class="rc-head lift">
  <div class="wrap">
    <h1>Saved events</h1>
    <p><?= $upcomingCount === 0 ? 'Nothing saved coming up' : $upcomingCount . ' coming up' ?></p>
  </div>
</section>

<div class="wrap rc-tk">
  <?php if (!$saved): ?>
    <div class="rc-empty">
      <i><svg width="28" height="28" aria-hidden="true"><use href="#ic-heart"/></svg></i>
      <h2>Nothing saved yet</h2>
      <p>Tap <b>Follow event</b> on any event page and it shows up here, with updates from the organizer.</p>
      <a class="btn btn-purple" href="/search.php">Find an event</a>
    </div>
  <?php else: ?>
    <div class="rc-rows">
      <?php foreach ($saved as $e):
          $ts = strtotime((string) $e['starts_at']);
          $ended = strtotime((string) $e['ends_at']) < time();
          $img = (string) ($e['banner_image'] ?? '');
          $price = landing_price_label($e);
      ?>
      <a class="rc-row" href="/event.php?slug=<?= urlencode((string) $e['slug']) ?>">
        <?php if ($img !== ''): ?>
          <img class="rc-thumb" src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="rc-date"><b><?= date('d', $ts) ?></b><small><?= strtoupper(date('M', $ts)) ?></small></span>
        <?php endif; ?>
        <span class="rc-row-tx">
          <b><?= htmlspecialchars((string) $e['title']) ?></b>
          <span><?= htmlspecialchars(date('D j M, g:ia', $ts) . ' · ' . $e['venue_name']) ?></span>
          <span class="rc-chips">
            <?php if ($ended): ?><span class="rc-chip n">Ended</span><?php elseif ($price !== ''): ?><span class="rc-chip"><?= htmlspecialchars($price) ?></span><?php endif; ?>
          </span>
        </span>
        <svg width="18" height="18" aria-hidden="true"><use href="#ic-chev"/></svg>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
