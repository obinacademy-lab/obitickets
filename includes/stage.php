<?php
declare(strict_types=1);

/**
 * "Stage": the dark, poster-led home page and browse look. Everything here is built from real events.
 * Rows are plain lists of events from the queries below; a row with no events is simply not shown.
 */

/** Popular right now: most paid tickets, then most liked, then soonest. */
function get_popular_events(int $limit = 10): array
{
    $stmt = db()->prepare("
        SELECT " . event_select_sql() . ",
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
               JOIN orders o ON o.id = oi.order_id JOIN ticket_types t ON t.id = oi.ticket_type_id
              WHERE t.event_id = e.id AND o.status = 'PAID') AS tickets_sold,
            e.likes_count
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.ends_at >= NOW()
        ORDER BY tickets_sold DESC, e.likes_count DESC, e.starts_at ASC
        LIMIT " . (int) $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Upcoming online and hybrid events (empty until migration 021 has run). */
function get_online_events(int $limit = 10): array
{
    if (!events_online_ready()) {
        return [];
    }
    $stmt = db()->prepare("
        SELECT " . event_select_sql() . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.ends_at >= NOW() AND e.attend_mode IN ('ONLINE', 'HYBRID')
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Events on the coming weekend (Friday to Sunday). On Friday, Saturday or Sunday that is the weekend in progress.
 * @return array{0:string,1:string} [from, to] as MySQL datetimes
 */
function stage_weekend_window(?int $now = null): array
{
    $now = $now ?? time();
    $dow = (int) date('N', $now); // 1 = Monday .. 7 = Sunday
    if ($dow >= 5) {
        $from = $now;
        $to = strtotime('sunday this week 23:59:59', $now);
    } else {
        $from = strtotime('friday this week 00:00:00', $now);
        $to = strtotime('sunday this week 23:59:59', $now);
    }
    return [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)];
}

function get_weekend_events(int $limit = 10): array
{
    [$from, $to] = stage_weekend_window();
    $stmt = db()->prepare("
        SELECT " . event_select_sql() . "
        FROM events e
        WHERE e.status = 'PUBLISHED' AND e.starts_at <= ? AND e.ends_at >= ?
        ORDER BY e.starts_at ASC
        LIMIT " . (int) $limit);
    $stmt->execute([$to, $from]);
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

/** A stable colour pair for an event without a poster, chosen by its category. */
function stage_gradient(array $event): string
{
    $palette = [
        'linear-gradient(135deg,#2a0f14,#a8242c 55%,#e2565c)',
        'linear-gradient(135deg,#101a4a,#2f58c9 55%,#79a5ff)',
        'linear-gradient(135deg,#3a1d0e,#c4622d 55%,#f0a36a)',
        'linear-gradient(135deg,#0d2b22,#1e8a63 55%,#6fe0b0)',
        'linear-gradient(135deg,#2b0f2f,#9b2fb0 55%,#e27bf0)',
        'linear-gradient(135deg,#1b1235,#4b2f9a 55%,#a88bff)',
    ];
    return $palette[crc32((string) ($event['category'] ?? $event['title'] ?? '')) % count($palette)];
}

/** @return list<array{0:string,1:string}> pairs of [label, css modifier] for the small pills on a card */
function stage_tags(array $event): array
{
    $tags = [];
    if (($event['attend_mode'] ?? 'IN_PERSON') !== 'IN_PERSON') {
        $tags[] = [($event['attend_mode'] === 'HYBRID' ? 'Online + venue' : 'Online'), ''];
    }
    if (isset($event['min_price']) && $event['min_price'] !== null && (float) $event['min_price'] <= 0.0) {
        $tags[] = [((int) ($event['paid_tier_count'] ?? 0) > 0 ? 'Free tickets' : 'Free'), 'g'];
    }
    return $tags;
}

function stage_price_label(array $event): string
{
    $min = $event['min_price'] ?? null;
    if ($min !== null && (float) $min <= 0.0) {
        return ((int) ($event['paid_tier_count'] ?? 0) > 0) ? 'Free tickets' : 'Free entry';
    }
    return $min === null ? '' : 'From ' . format_money((string) $min, $event['min_price_currency'] ?? 'UGX');
}

/** One poster card. The picture and title link to the event; the hover panel adds Get tickets and a like heart. */
function render_stage_card(array $event, ?int $rank = null, bool $liked = false): void
{
    $url = '/event.php?slug=' . urlencode($event['slug']);
    $ts = strtotime((string) $event['starts_at']);
    $where = (string) $event['venue_name'] . ((($event['attend_mode'] ?? '') === 'HYBRID') ? ' + online' : '');
    ?>
    <article class="sg-c<?= $rank !== null ? ' sg-rk' : '' ?>">
      <?php if ($rank !== null): ?><div class="sg-n" aria-hidden="true"><?= $rank ?></div><?php endif; ?>
      <div class="sg-pst">
        <a class="sg-art" href="<?= htmlspecialchars($url) ?>" aria-label="<?= htmlspecialchars($event['title']) ?>">
          <?php if (!empty($event['banner_image'])): ?>
            <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="sg-bg" style="background:<?= htmlspecialchars(stage_gradient($event)) ?>"></span>
            <span class="sg-emoji" aria-hidden="true"><?= htmlspecialchars((string) $event['banner_emoji']) ?></span>
          <?php endif; ?>
          <span class="sg-dt"><?= date('d', $ts) ?><small><?= strtoupper(date('M', $ts)) ?></small></span>
          <span class="sg-tg"><?php foreach (stage_tags($event) as [$label, $mod]): ?><span class="sg-pill<?= $mod ? ' ' . $mod : '' ?>"><?= htmlspecialchars($label) ?></span><?php endforeach; ?></span>
        </a>
        <div class="sg-info">
          <a class="sg-ttl" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($event['title']) ?></a>
          <p><?= htmlspecialchars(date('D j M, g:ia', $ts)) ?> &middot; <?= htmlspecialchars($where) ?></p>
          <div class="sg-act">
            <a class="sg-go" href="<?= htmlspecialchars($url) ?>#buyPanel">Get tickets</a>
            <button type="button" class="sg-heart" data-like="<?= (int) $event['id'] ?>" aria-pressed="<?= $liked ? 'true' : 'false' ?>" aria-label="Like this event"><?= $liked ? '&#9829;' : '&#9825;' ?></button>
            <span class="sg-pr"><?= htmlspecialchars(str_replace('From ', '', stage_price_label($event))) ?></span>
          </div>
        </div>
      </div>
    </article>
    <?php
}

/** A row of cards under a heading, with arrows. Draws nothing for an empty list. */
function render_stage_row(string $title, array $events, bool $ranked = false, ?string $seeAll = null): void
{
    if (!$events) {
        return;
    }
    static $n = 0;
    $n++;
    $likedMap = stage_liked_map($events);
    ?>
    <section class="sg-row" aria-label="<?= htmlspecialchars($title) ?>">
      <div class="sg-rh"><h2><?= htmlspecialchars($title) ?></h2><?php if ($seeAll): ?><a href="<?= htmlspecialchars($seeAll) ?>">See all &rsaquo;</a><?php endif; ?></div>
      <div class="sg-lane">
        <button type="button" class="sg-arrow l" data-lane="sgT<?= $n ?>" data-dir="-1" aria-label="Scroll left">&lsaquo;</button>
        <div class="sg-track" id="sgT<?= $n ?>">
          <?php foreach ($events as $i => $e): render_stage_card($e, $ranked ? $i + 1 : null, !empty($likedMap[(int) $e['id']])); endforeach; ?>
        </div>
        <button type="button" class="sg-arrow r" data-lane="sgT<?= $n ?>" data-dir="1" aria-label="Scroll right">&rsaquo;</button>
      </div>
    </section>
    <?php
}

/** event id => true for the events the current visitor has liked (one query per row). */
function stage_liked_map(array $events): array
{
    if (!$events) {
        return [];
    }
    $user = current_user();
    $ids = array_map(static fn ($e) => (int) $e['id'], $events);
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        if ($user) {
            $stmt = db()->prepare("SELECT event_id FROM event_likes WHERE user_id = ? AND event_id IN ($in)");
            $stmt->execute(array_merge([(int) $user['id']], $ids));
        } else {
            $stmt = db()->prepare("SELECT event_id FROM event_likes WHERE session_token = ? AND event_id IN ($in)");
            $stmt->execute(array_merge([session_id()], $ids));
        }
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $e) {
        return [];
    }
}
