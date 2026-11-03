<?php
declare(strict_types=1);

/**
 * Helpers for the home page ("Landing"): featured events, the scrolling poster wall and the visitor's next ticket.
 * Everything is built from real events; nothing is invented.
 */

/** Popular right now: most paid tickets, then most liked, then soonest. */
function get_popular_events(int $limit = 10): array
{
    $stmt = db()->prepare("
        SELECT " . event_select_sql() . ",
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
               JOIN orders o ON o.id = oi.order_id JOIN ticket_types t ON t.id = oi.ticket_type_id
              WHERE t.event_id = e.id AND o.status = 'PAID') AS tickets_sold
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.ends_at >= NOW()
        ORDER BY tickets_sold DESC, e.likes_count DESC, e.starts_at ASC
        LIMIT " . (int) $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** The soonest event this person holds a paid or free ticket for, or null. */
function get_next_ticket_for_user(int $userId): ?array
{
    $stmt = db()->prepare("
        SELECT e.id, e.title, e.slug, e.starts_at, e.ends_at, e.venue_name, e.banner_image, e.banner_emoji, e.category,
               o.id AS order_id
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE o.user_id = ? AND o.status = 'PAID' AND e.status = 'PUBLISHED' AND e.ends_at >= NOW()
        ORDER BY e.starts_at ASC
        LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Every upcoming event this person holds a paid or free ticket for, soonest first, one row per event.
 * @return list<array<string,mixed>>
 */
function get_upcoming_tickets_for_user(int $userId, int $limit = 8): array
{
    $stmt = db()->prepare("
        SELECT e.id, e.title, e.slug, e.starts_at, e.ends_at, e.venue_name, e.banner_image, e.banner_emoji, e.category,
               MIN(o.id) AS order_id
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE o.user_id = ? AND o.status = 'PAID' AND e.status = 'PUBLISHED' AND e.ends_at >= NOW()
        GROUP BY e.id, e.title, e.slug, e.starts_at, e.ends_at, e.venue_name, e.banner_image, e.banner_emoji, e.category
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
/** Inline style that gives a flyer frame its blurred backdrop (the flyer itself, softened). */
function flyer_backdrop_style(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    $safe = str_replace(["'", '"', '\\', '(', ')', ' ', "\n", "\r"], '', $path);
    return ' style="--art:url(' . htmlspecialchars($safe, ENT_QUOTES) . ')"';
}
/** A stable colour for an event that has no poster, chosen by its category. */
function landing_gradient(array $event): string
{
    $palette = [
        'linear-gradient(135deg,#7f1d1d,#dc2626 55%,#f87171)',
        'linear-gradient(135deg,#1e3a8a,#2563eb 55%,#93c5fd)',
        'linear-gradient(135deg,#7c2d12,#ea580c 55%,#fdba74)',
        'linear-gradient(135deg,#064e3b,#059669 55%,#6ee7b7)',
        'linear-gradient(135deg,#581c87,#9333ea 55%,#d8b4fe)',
        'linear-gradient(135deg,#312e81,#4f46e5 55%,#a5b4fc)',
    ];
    return $palette[crc32((string) ($event['category'] ?? $event['title'] ?? '')) % count($palette)];
}

/** @return list<array{0:string,1:string}> [label, css modifier] pairs for the small pills on a card */
function landing_tags(array $event): array
{
    $tags = [];
    if (($event['attend_mode'] ?? 'IN_PERSON') !== 'IN_PERSON') {
        $tags[] = [($event['attend_mode'] === 'HYBRID' ? 'Online + venue' : 'Online'), 'b'];
    }
    if (isset($event['min_price']) && $event['min_price'] !== null && (float) $event['min_price'] <= 0.0) {
        $tags[] = [((int) ($event['paid_tier_count'] ?? 0) > 0 ? 'Free tickets' : 'Free'), 'g'];
    }
    return $tags;
}

function landing_price_label(array $event): string
{
    $min = $event['min_price'] ?? null;
    if ($min !== null && (float) $min <= 0.0) {
        return ((int) ($event['paid_tier_count'] ?? 0) > 0) ? 'Free tickets' : 'Free entry';
    }
    return $min === null ? '' : 'From ' . format_money((string) $min, $event['min_price_currency'] ?? 'UGX');
}

/**
 * One poster for the scrolling wall: the picture (or a colour with the emoji) and the title. Decorative only:
 * it is hidden from screen readers and from the tab order, because the same events are listed below.
 */
function render_landing_poster(array $event): void
{
    $url = '/event.php?slug=' . urlencode($event['slug']);
    ?>
    <a class="ld-pc<?= !empty($event['banner_image']) ? ' has-img' : '' ?>" href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true"<?= flyer_backdrop_style($event['banner_image'] ?? null) ?>>
      <?php if (!empty($event['banner_image'])): ?>
        <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="" loading="lazy">
      <?php else: ?>
        <span class="ld-bg" style="background:<?= htmlspecialchars(landing_gradient($event)) ?>"></span>
        <em><?= htmlspecialchars((string) $event['banner_emoji']) ?></em>
      <?php endif; ?>
      <span class="ld-pt"><?= htmlspecialchars($event['title']) ?></span>
    </a>
    <?php
}

/** A featured event card with its picture, date, place, price and pills. */
function render_landing_card(array $event): void
{
    $url = '/event.php?slug=' . urlencode($event['slug']);
    $ts = strtotime((string) $event['starts_at']);
    $where = (string) $event['venue_name'] . ((($event['attend_mode'] ?? '') === 'HYBRID') ? ' + online' : '');
    ?>
    <a class="ld-fc reveal" href="<?= htmlspecialchars($url) ?>">
      <span class="ld-art"<?= flyer_backdrop_style($event['banner_image'] ?? null) ?>>
        <?php if (!empty($event['banner_image'])): ?>
          <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="ld-bg" style="background:<?= htmlspecialchars(landing_gradient($event)) ?>"></span>
          <em aria-hidden="true"><?= htmlspecialchars((string) $event['banner_emoji']) ?></em>
        <?php endif; ?>
      </span>
      <span class="ld-fb">
        <h3><?= htmlspecialchars($event['title']) ?></h3>
        <p><?= htmlspecialchars(date('D j M, g:ia', $ts)) ?> &middot; <?= htmlspecialchars($where) ?></p>
        <span class="ld-ff"><span class="ld-pr"><?= htmlspecialchars(landing_price_label($event)) ?></span><span class="ld-tags"><?php foreach (landing_tags($event) as [$label, $mod]): ?><span class="ld-pill <?= $mod ?>"><?= htmlspecialchars($label) ?></span><?php endforeach; ?></span></span>
      </span>
    </a>
    <?php
}
