<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('marketing.view');
$organizerId = $ctx['organizer_id'];
$events = array_values(array_filter(get_events_for_organizer($organizerId), static fn ($e) => $e['status'] === 'PUBLISHED'));

$pageTitle = 'Event Sharing';
render_organizer_head('marketing', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Event sharing</h1><p>Share your published events to sell more tickets.</p></div>
</div>

<?php if (!$events): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-share"/></svg></div>
    <h3>No published events to share yet</h3>
    <p>Publish an event to get its shareable link.</p>
  </div>
<?php else: ?>
  <div style="display:flex; flex-direction:column; gap:14px;">
    <?php foreach ($events as $e): $url = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($e['slug']); ?>
      <div class="admin-card">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
          <div>
            <strong><?= htmlspecialchars($e['banner_emoji']) ?> <?= htmlspecialchars($e['title']) ?></strong>
            <div class="muted" style="font-size:0.82rem; margin-top:2px"><?= htmlspecialchars(date('d M Y', strtotime($e['starts_at']))) ?> &middot; <?= (int) $e['tickets_sold'] ?> sold</div>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn btn-line" style="padding:8px 14px; font-size:0.82rem" href="https://wa.me/?text=<?= urlencode($e['title'] . ' — ' . $url) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
            <a class="btn btn-line" style="padding:8px 14px; font-size:0.82rem" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($url) ?>" target="_blank" rel="noopener">Facebook</a>
            <a class="btn btn-line" style="padding:8px 14px; font-size:0.82rem" href="https://twitter.com/intent/tweet?text=<?= urlencode($e['title']) ?>&url=<?= urlencode($url) ?>" target="_blank" rel="noopener">X</a>
            <button type="button" class="btn btn-purple copy-link-btn" style="padding:8px 14px; font-size:0.82rem" data-link="<?= htmlspecialchars($url, ENT_QUOTES) ?>">Copy link</button>
          </div>
        </div>
        <div class="mono muted" style="font-size:0.8rem; margin-top:12px; padding:8px 12px; background:var(--paper); border-radius:8px; word-break:break-all;"><?= htmlspecialchars($url) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="admin-card" style="margin-top:20px">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h3 style="margin-bottom:4px">Boost ticket sales</h3>
      <p class="muted" style="font-size:0.85rem">Create a discount code to encourage early or bulk purchases.</p>
    </div>
    <a class="btn btn-line" href="/org-promo.php">Manage promo codes &rarr;</a>
  </div>
</div>

<script>
document.querySelectorAll('.copy-link-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    navigator.clipboard.writeText(btn.dataset.link).then(function () {
      window.adminToast ? window.adminToast('success', 'Link copied.') : null;
    });
  });
});
</script>

<?php render_organizer_foot(); ?>
