<?php
declare(strict_types=1);

/**
 * OB GIFTS, phase 4: what an ORGANIZER sees about the gifts sent to them (earnings, history, analytics).
 *
 * Rules every function here follows:
 *  - It is scoped to ONE organizer (recipient_organizer_id = ?), always, so one organizer can never read another's gifts.
 *  - Only PAID gifts are shown (SUCCESSFUL, or REFUNDED which is shown as "Reversed"). Waiting, failed and expired attempts are
 *    the supporter's business, not the organizer's.
 *  - The organizer sees their OWN share (organizer_ugx), the gift's price, and the supporter's first name. Never a phone number,
 *    never ioTec's fee, never obitickets' share, and an anonymous supporter stays anonymous everywhere (history, top supporters, export).
 *  - Nothing here touches tickets, orders or payouts.
 */

/** Has this organizer ever received a paid gift? (decides whether the menu entry shows while the master switch is off) */
function gift_org_has_activity(int $organizerId): bool
{
    static $yes = [];   // only a "yes" is remembered, so a first gift is noticed straight away
    if (isset($yes[$organizerId])) {
        return true;
    }
    try {
        $stmt = db()->prepare("SELECT 1 FROM gift_transactions WHERE recipient_organizer_id = ? AND payment_status IN ('SUCCESSFUL','REFUNDED') LIMIT 1");
        $stmt->execute([$organizerId]);
        if ($stmt->fetchColumn()) {
            $yes[$organizerId] = true;
            return true;
        }
    } catch (Throwable $e) {
        error_log('[gift] activity check failed: ' . $e->getMessage());
    }
    return false;
}

/** Whether the "Gifts & Support" page should be offered in this organizer's menu. */
function gift_org_menu_visible(int $organizerId): bool
{
    return gifts_ready() && (gifts_enabled() || gift_org_has_activity($organizerId));
}

/** The share an organizer keeps of a gift right now, as a percent (e.g. 60.0), for the "how it works" line. */
function gift_org_share_percent(): float
{
    try {
        return gift_commission_current()['organizer_bps'] / 100;
    } catch (Throwable $e) {
        return 60.0;
    }
}

/** First word of a name, or "Someone". */
function gift_first_name(?string $name): string
{
    $name = trim((string) $name);
    return $name !== '' ? (preg_split('/\s+/', $name)[0] ?? $name) : 'Someone';
}

/**
 * Display names for a list of DIFFERENT people: first name only, but when two of them share a first name the last initial is
 * added ("Flow T."), and a number if even that matches ("Flow T. (2)"), so the organizer can tell them apart.
 * @param list<?string> $fullNames  one entry per person, in display order
 * @return list<string>
 */
function gift_supporter_labels(array $fullNames): array
{
    $firstCount = array_count_values(array_map(static fn ($n) => mb_strtolower(gift_first_name($n)), $fullNames));
    $seen = [];
    $out = [];
    foreach ($fullNames as $name) {
        $first = gift_first_name($name);
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $label = $first;
        if (($firstCount[mb_strtolower($first)] ?? 0) > 1 && count($parts) > 1) {
            $label = $first . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
        }
        $seen[$label] = ($seen[$label] ?? 0) + 1;
        $out[] = $seen[$label] > 1 ? $label . ' (' . $seen[$label] . ')' : $label;
    }
    return $out;
}

/**
 * The numbers on the overview.
 * @return array{balance:array, month_ugx:int, month_gifts:int, week_ugx:int, supporters:int, gifts:int, reversed:int, reversed_ugx:int, biggest_ugx:int, in_debt:bool}
 */
function gift_org_summary(int $organizerId): array
{
    $pdo = db();
    $monthStart = date('Y-m-01 00:00:00');
    $weekStart = date('Y-m-d H:i:s', time() - 7 * 86400);

    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN payment_status = 'SUCCESSFUL' THEN 1 ELSE 0 END), 0) AS gifts,
            COUNT(DISTINCT CASE WHEN payment_status = 'SUCCESSFUL' THEN sender_id END) AS supporters,
            COALESCE(SUM(CASE WHEN payment_status = 'SUCCESSFUL' AND paid_at >= ? THEN organizer_ugx ELSE 0 END), 0) AS month_ugx,
            COALESCE(SUM(CASE WHEN payment_status = 'SUCCESSFUL' AND paid_at >= ? THEN 1 ELSE 0 END), 0) AS month_gifts,
            COALESCE(SUM(CASE WHEN payment_status = 'SUCCESSFUL' AND paid_at >= ? THEN organizer_ugx ELSE 0 END), 0) AS week_ugx,
            COALESCE(SUM(CASE WHEN payment_status = 'REFUNDED' THEN 1 ELSE 0 END), 0) AS reversed,
            COALESCE(SUM(CASE WHEN payment_status = 'REFUNDED' THEN organizer_ugx ELSE 0 END), 0) AS reversed_ugx,
            COALESCE(MAX(CASE WHEN payment_status = 'SUCCESSFUL' THEN gross_ugx END), 0) AS biggest_ugx
        FROM gift_transactions WHERE recipient_organizer_id = ?");
    $stmt->execute([$monthStart, $monthStart, $weekStart, $organizerId]);
    $r = $stmt->fetch() ?: [];
    $balance = gift_balance($organizerId);

    return [
        'balance' => $balance,
        'month_ugx' => (int) ($r['month_ugx'] ?? 0),
        'month_gifts' => (int) ($r['month_gifts'] ?? 0),
        'week_ugx' => (int) ($r['week_ugx'] ?? 0),
        'supporters' => (int) ($r['supporters'] ?? 0),
        'gifts' => (int) ($r['gifts'] ?? 0),
        'reversed' => (int) ($r['reversed'] ?? 0),
        'reversed_ugx' => (int) ($r['reversed_ugx'] ?? 0),
        'biggest_ugx' => (int) ($r['biggest_ugx'] ?? 0),
        'in_debt' => $balance['available'] < 0,
    ];
}

/**
 * One page of gift history, newest first, with a cursor (the last id seen) so it stays fast however many gifts there are.
 *
 * @param array{gift?:int, days?:int, status?:string, before?:int} $f  status: all | received | reversed; days: 0 = all time
 * @return array{rows:list<array>, next:?int}
 */
function gift_org_history(int $organizerId, array $f = [], int $limit = 25): array
{
    $limit = max(1, min(500, $limit));
    $where = ['t.recipient_organizer_id = ?'];
    $args = [$organizerId];

    $status = (string) ($f['status'] ?? 'all');
    if ($status === 'received') {
        $where[] = "t.payment_status = 'SUCCESSFUL'";
    } elseif ($status === 'reversed') {
        $where[] = "t.payment_status = 'REFUNDED'";
    } else {
        $where[] = "t.payment_status IN ('SUCCESSFUL','REFUNDED')";
    }
    if (!empty($f['gift'])) {
        $where[] = 't.gift_id = ?';
        $args[] = (int) $f['gift'];
    }
    if (!empty($f['days']) && (int) $f['days'] > 0) {
        $where[] = 't.paid_at >= ?';
        $args[] = date('Y-m-d H:i:s', time() - (int) $f['days'] * 86400);
    }
    if (!empty($f['before'])) {
        $where[] = 't.id < ?';
        $args[] = (int) $f['before'];
    }

    $sql = "SELECT t.id, t.reference, t.paid_at, t.quantity, t.gross_ugx, t.organizer_ugx, t.is_anonymous, t.payment_status, t.post_id,
                   g.name AS gift_name, g.emoji, u.name AS sender_name, e.title AS event_title
            FROM gift_transactions t
            JOIN gift_catalog g ON g.id = t.gift_id
            LEFT JOIN users u ON u.id = t.sender_id
            LEFT JOIN event_posts p ON p.id = t.post_id
            LEFT JOIN events e ON e.id = p.event_id
            WHERE " . implode(' AND ', $where) . '
            ORDER BY t.id DESC LIMIT ' . ($limit + 1);
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $next = (int) $rows[count($rows) - 1]['id'];
    }
    foreach ($rows as &$row) {
        $row['supporter'] = !empty($row['is_anonymous']) ? 'Anonymous supporter' : gift_first_name($row['sender_name']);
        $row['reversed'] = $row['payment_status'] === 'REFUNDED';
        $row['where'] = $row['post_id'] ? ($row['event_title'] ?: 'A video') : 'Your profile';
        unset($row['sender_name']);   // the full name never leaves this function
    }
    unset($row);
    return ['rows' => $rows, 'next' => $next];
}

/**
 * Everything on the Analytics tab for the last $days days (7, 30 or 90), plus the same length of time before it for the change.
 * @return array<string,mixed>
 */
function gift_org_analytics(int $organizerId, int $days = 30): array
{
    $days = in_array($days, [7, 30, 90], true) ? $days : 30;
    $pdo = db();
    $from = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
    $prevFrom = date('Y-m-d 00:00:00', time() - (2 * $days - 1) * 86400);
    $ok = "t.recipient_organizer_id = ? AND t.payment_status = 'SUCCESSFUL'";

    // totals now and for the period before
    $tot = $pdo->prepare("SELECT COUNT(*) gifts, COALESCE(SUM(organizer_ugx),0) ugx, COALESCE(SUM(quantity),0) units, COUNT(DISTINCT sender_id) supporters
                          FROM gift_transactions t WHERE $ok AND t.paid_at >= ?");
    $tot->execute([$organizerId, $from]);
    $now = $tot->fetch() ?: [];
    $prev = $pdo->prepare("SELECT COALESCE(SUM(organizer_ugx),0) ugx FROM gift_transactions t WHERE $ok AND t.paid_at >= ? AND t.paid_at < ?");
    $prev->execute([$organizerId, $prevFrom, $from]);
    $prevUgx = (int) $prev->fetchColumn();
    $nowUgx = (int) ($now['ugx'] ?? 0);

    // per day, with the empty days filled in so the chart has no gaps
    $dayStmt = $pdo->prepare("SELECT DATE(paid_at) d, COUNT(*) gifts, SUM(organizer_ugx) ugx FROM gift_transactions t WHERE $ok AND t.paid_at >= ? GROUP BY DATE(paid_at)");
    $dayStmt->execute([$organizerId, $from]);
    $byDay = [];
    foreach ($dayStmt->fetchAll() as $r) {
        $byDay[$r['d']] = ['gifts' => (int) $r['gifts'], 'ugx' => (int) $r['ugx']];
    }
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400);
        $series[] = ['date' => $d, 'gifts' => $byDay[$d]['gifts'] ?? 0, 'ugx' => $byDay[$d]['ugx'] ?? 0];
    }

    // which gifts earn the most
    $g = $pdo->prepare("SELECT g.name, g.emoji, COUNT(*) gifts, SUM(t.quantity) units, SUM(t.organizer_ugx) ugx
                        FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id WHERE $ok AND t.paid_at >= ?
                        GROUP BY g.id, g.name, g.emoji ORDER BY ugx DESC LIMIT 10");
    $g->execute([$organizerId, $from]);
    $byGift = $g->fetchAll();

    // which videos earn the most (gifts sent from the profile have no video)
    $v = $pdo->prepare("SELECT t.post_id, COALESCE(e.title, '') title, COUNT(*) gifts, SUM(t.organizer_ugx) ugx
                        FROM gift_transactions t LEFT JOIN event_posts p ON p.id = t.post_id LEFT JOIN events e ON e.id = p.event_id
                        WHERE $ok AND t.paid_at >= ? GROUP BY t.post_id, e.title ORDER BY ugx DESC LIMIT 8");
    $v->execute([$organizerId, $from]);
    $byVideo = [];
    foreach ($v->fetchAll() as $r) {
        $byVideo[] = ['title' => $r['post_id'] ? ($r['title'] !== '' ? $r['title'] : 'A video') : 'Your profile', 'gifts' => (int) $r['gifts'], 'ugx' => (int) $r['ugx']];
    }

    // top supporters: people who did not hide their name, one line each; anonymous supporters are only ever added up together
    $s = $pdo->prepare("SELECT t.sender_id, MAX(u.name) name, COUNT(*) gifts, SUM(t.organizer_ugx) ugx
                        FROM gift_transactions t LEFT JOIN users u ON u.id = t.sender_id
                        WHERE $ok AND t.paid_at >= ? AND t.is_anonymous = 0 GROUP BY t.sender_id ORDER BY ugx DESC LIMIT 5");
    $s->execute([$organizerId, $from]);
    $top = [];
    $topRows = $s->fetchAll();
    $labels = gift_supporter_labels(array_column($topRows, 'name'));
    foreach ($topRows as $i => $r) {
        $top[] = ['name' => $labels[$i], 'gifts' => (int) $r['gifts'], 'ugx' => (int) $r['ugx']];
    }
    $an = $pdo->prepare("SELECT COUNT(*) gifts, COALESCE(SUM(organizer_ugx),0) ugx FROM gift_transactions t WHERE $ok AND t.paid_at >= ? AND t.is_anonymous = 1");
    $an->execute([$organizerId, $from]);
    $anon = $an->fetch() ?: [];

    // supporters who gave more than once in the period
    $rep = $pdo->prepare("SELECT COUNT(*) FROM (SELECT sender_id FROM gift_transactions t WHERE $ok AND t.paid_at >= ? GROUP BY sender_id HAVING COUNT(*) > 1) x");
    $rep->execute([$organizerId, $from]);

    $gifts = (int) ($now['gifts'] ?? 0);
    return [
        'days' => $days,
        'gifts' => $gifts,
        'units' => (int) ($now['units'] ?? 0),
        'ugx' => $nowUgx,
        'supporters' => (int) ($now['supporters'] ?? 0),
        'repeat_supporters' => (int) $rep->fetchColumn(),
        'average_ugx' => $gifts > 0 ? intdiv($nowUgx, $gifts) : 0,
        'prev_ugx' => $prevUgx,
        'change_pct' => $prevUgx > 0 ? (int) round(($nowUgx - $prevUgx) / $prevUgx * 100) : null,
        'series' => $series,
        'by_gift' => array_map(static fn ($r) => ['name' => $r['name'], 'emoji' => $r['emoji'], 'gifts' => (int) $r['gifts'], 'units' => (int) $r['units'], 'ugx' => (int) $r['ugx']], $byGift),
        'by_video' => $byVideo,
        'top_supporters' => $top,
        'anonymous' => ['gifts' => (int) ($anon['gifts'] ?? 0), 'ugx' => (int) ($anon['ugx'] ?? 0)],
    ];
}
