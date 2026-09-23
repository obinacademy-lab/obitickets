<?php
/**
 * Renders one event card for a homepage shelf or a "similar events" row.
 * Expects $event (a row from includes/events.php's EVENT_SELECT queries)
 * and optionally $tintIndex (int, alternates the card's background tint).
 */
$tint = (($tintIndex ?? 0) % 2 === 0) ? 'var(--purple-tint)' : 'var(--purple-tint-2)';
$price = format_money($event['min_price'] ?? null, $event['min_price_currency'] ?? 'UGX');
$priceLabel = $price === 'Free entry' ? $price : 'From ' . $price;
$bannerImage = $event['banner_image'] ?? null;
?>
<a class="shelf-card" href="/event.php?slug=<?= urlencode($event['slug']) ?>">
  <?php if ($bannerImage): ?>
    <div class="shelf-art" style="background-image:url('<?= htmlspecialchars($bannerImage) ?>'); background-size:cover; background-position:center;"></div>
  <?php else: ?>
    <div class="shelf-art" style="background:<?= $tint ?>"><?= htmlspecialchars($event['banner_emoji']) ?></div>
  <?php endif; ?>
  <div class="shelf-body">
    <div class="shelf-meta"><?= strtoupper(date('M d', strtotime($event['starts_at']))) ?> &middot; <?= strtoupper(htmlspecialchars($event['venue_name'])) ?></div>
    <h4><?= htmlspecialchars($event['title']) ?></h4>
    <div class="shelf-price"><?= htmlspecialchars($priceLabel) ?></div>
  </div>
</a>
