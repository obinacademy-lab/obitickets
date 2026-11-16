<?php
declare(strict_types=1);

/**
 * Home page "Marketplace Grid" (owner pick 11/16 from five app layouts): search, For you / Near me / Weekend / Free
 * switch, a row of featured events, then a two-column grid of every upcoming event. Flyers are always shown whole
 * (the frame takes the flyer's shape, nothing covers it), per the owner's standing rule.
 */

/** "Tonight · 6:00 PM", "Tomorrow · 10:00 AM", "Sat 21 Nov" (East Africa Time). */
function hg_when_label(array $event): string
{
    $ts = strtotime((string) $event['starts_at']);
    $today = date('Y-m-d');
    $day = date('Y-m-d', $ts);
    if ($day === $today || ($ts < time() && strtotime((string) ($event['ends_at'] ?? $event['starts_at'])) >= time())) {
        return ((int) date('G', $ts) >= 17 ? 'Tonight' : 'Today') . ' · ' . date('g:i A', $ts);
    }
    if ($day === date('Y-m-d', strtotime('+1 day'))) {
        return 'Tomorrow · ' . date('g:i A', $ts);
    }
    return date('D j M', $ts);
}

/** True for the first two labels above, so the card can show them in red. */
function hg_is_soon(array $event): bool
{
    $ts = strtotime((string) $event['starts_at']);
    return $ts <= strtotime('tomorrow +1 day');
}

/** The short price line: "UGX 30,000", "Free", or "" when the event has no tickets yet. */
function hg_price(array $event): string
{
    $min = $event['min_price'] ?? null;
    if ($min === null) {
        return '';
    }
    if ((float) $min <= 0.0) {
        return 'Free';
    }
    return format_money((string) $min, $event['min_price_currency'] ?? 'UGX');
}

/** Where, in a few words: the town/district when we know it, else the venue; online events say so. */
function hg_place(array $event): string
{
    $mode = $event['attend_mode'] ?? 'IN_PERSON';
    if ($mode === 'ONLINE') {
        return 'Online';
    }
    $venue = (string) ($event['venue_name'] ?? '');
    $district = function_exists('event_district') ? event_district($event) : null;
    if ($district !== null && stripos($venue, $district) === false) {
        $venue = $venue !== '' ? $venue . ', ' . $district : $district;
    }
    return $venue . ($mode === 'HYBRID' ? ' + online' : '');
}

function hg_flyer(array $event, string $class): void
{
    $img = (string) ($event['banner_image'] ?? '');
    $fly = $img !== '' ? flyer_ratio($img) : '';
    ?>
    <span class="<?= $class ?><?= $img === '' ? ' is-empty' : '' ?>"<?= $fly !== '' ? ' style="--fly:' . htmlspecialchars($fly) . '"' : '' ?>>
      <?php if ($img !== ''): ?>
        <img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy">
      <?php else: ?>
        <span class="hg-emoji" style="background:<?= htmlspecialchars(landing_gradient($event)) ?>" aria-hidden="true"><?= htmlspecialchars((string) ($event['banner_emoji'] ?? '')) ?></span>
      <?php endif; ?>
    </span>
    <?php
}

/** One featured slide: whole flyer, then date, title, place, price and a Get tickets button. */
function render_hg_feature(array $event): void
{
    $url = '/event.php?slug=' . urlencode((string) $event['slug']);
    $price = hg_price($event);
    ?>
    <article class="hg-ft">
      <a class="hg-ft-art" href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true"><?php hg_flyer($event, 'hg-fly'); ?></a>
      <div class="hg-ft-tx">
        <span class="hg-when<?= hg_is_soon($event) ? ' soon' : '' ?>"><?= htmlspecialchars(hg_when_label($event)) ?></span>
        <h3><a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars((string) $event['title']) ?></a></h3>
        <span class="hg-place"><?= htmlspecialchars(hg_place($event)) ?></span>
        <div class="hg-ft-buy">
          <?php if ($price !== ''): ?><span class="hg-ft-price"><?= $price === 'Free' ? '<b class="free">Free</b>' : '<small>From</small><b>' . htmlspecialchars($price) . '</b>' ?></span><?php endif; ?>
          <a class="hg-btn" href="<?= htmlspecialchars($url) ?>"><?= $price === 'Free' ? 'Get a pass' : 'Get tickets' ?></a>
        </div>
      </div>
    </article>
    <?php
}

/** One grid card: whole flyer, date, title, place, price. The whole card is one link. */
function render_hg_card(array $event): void
{
    $url = '/event.php?slug=' . urlencode((string) $event['slug']);
    $price = hg_price($event);
    ?>
    <a class="hg-card" href="<?= htmlspecialchars($url) ?>">
      <?php hg_flyer($event, 'hg-fly'); ?>
      <span class="hg-when<?= hg_is_soon($event) ? ' soon' : '' ?>"><?= htmlspecialchars(hg_when_label($event)) ?></span>
      <b class="hg-title"><?= htmlspecialchars((string) $event['title']) ?></b>
      <span class="hg-place"><?= htmlspecialchars(hg_place($event)) ?></span>
      <?php if ($price !== ''): ?><span class="hg-price<?= $price === 'Free' ? ' free' : '' ?>"><?= $price === 'Free' ? 'Free' : 'From ' . htmlspecialchars($price) ?></span><?php endif; ?>
    </a>
    <?php
}
