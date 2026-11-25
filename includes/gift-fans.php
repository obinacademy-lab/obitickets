<?php
/**
 * OBI GIFTS: top fans and the automatic thank-you.
 *
 * TOP FANS. Fans are ranked by the UGX value of the gifts they sent (each gift's stored value, so coins and old direct gifts compare fairly), reversed
 * gifts do not count, ties go to whoever started first. Only gifts the fan sent in their own name count: an anonymous gift is never attributed
 * to anyone, anywhere. Only the rank, name, picture and badge are shown, never an amount. Read-only: nothing here changes money.
 *   - per video: the people who gifted that video
 *   - per organizer: "This month" (calendar month) and "All time"; the three best this month also carry the badge "Top 3 this month"
 *
 * AUTOMATIC THANK-YOU. An organizer writes one message; it is sent for them whenever a gift is delivered (first-time gifters only, or every gift but
 * at most one automatic message per fan a day). It uses gift_thank() so every rule and limit of the manual thank-you applies. Kept in
 * platform_settings (key org_auto_thanks_<organizer id>) so no new table is needed.
 */

const GIFT_FANS_LIST = 10;
const GIFT_AUTO_THANKS_MAX_CHARS = 240;   // the message as written; {name} {gift} {org} grow it a little when it is sent

/** Top fans are shown only while OBI GIFTS is switched on. */
function gift_fans_visible(): bool
{
    return function_exists('gifts_ready') && gifts_ready() && function_exists('gifts_enabled') && gifts_enabled();
}

/** @return array<string,mixed> one fan, safe to show to anyone */
function gift_fan_shape(array $r, int $rank, array $monthTop): array
{
    $uid = (int) $r['sender_id'];
    $name = (string) $r['name'];
    $badges = [];
    if ($rank === 1) {
        $badges[] = 'Top fan';
    }
    if (isset($monthTop[$uid])) {
        $badges[] = 'Top 3 this month';
    }
    $url = null;
    if (function_exists('social_person') && ($p = social_person($uid)) && !empty($p['url'])) {
        $url = (string) $p['url'];
    }
    return ['rank' => $rank, 'id' => $uid, 'name' => community_display_name($name), 'initials' => community_initials($name),
            'avatar' => function_exists('user_avatar_url') ? user_avatar_url($uid) : null, 'url' => $url, 'badges' => $badges];
}

/** The start of this calendar month, on the same clock the gifts are stamped with. */
function gift_fans_month_start(): string
{
    return date('Y-m-01 00:00:00');
}

/**
 * The best fans of an organizer. $window: 'month' or 'all'.
 * @return list<array<string,mixed>>
 */
function gift_top_fans_org(int $organizerId, string $window = 'all', int $limit = GIFT_FANS_LIST): array
{
    if (!gifts_ready()) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $args = [$organizerId];
    $when = '';
    if ($window === 'month') {
        $when = ' AND t.paid_at >= ?';
        $args[] = gift_fans_month_start();
    }
    $st = db()->prepare("SELECT t.sender_id, u.name, SUM(t.gross_ugx) AS v, MIN(t.paid_at) AS first_at
                         FROM gift_transactions t JOIN users u ON u.id = t.sender_id
                         WHERE t.recipient_organizer_id = ? AND t.payment_status = 'SUCCESSFUL' AND t.is_anonymous = 0 AND u.account_status = 'ACTIVE'$when
                         GROUP BY t.sender_id, u.name ORDER BY v DESC, first_at ASC, t.sender_id ASC LIMIT $limit");
    $st->execute($args);
    $rows = $st->fetchAll();
    $monthTop = $window === 'month' ? gift_fans_rank_map($rows, 3) : gift_month_top3($organizerId);
    $out = [];
    foreach ($rows as $i => $r) {
        $out[] = gift_fan_shape($r, $i + 1, $monthTop);
    }
    return $out;
}

/** @return array<int,int> user id => rank, for the first $n rows */
function gift_fans_rank_map(array $rows, int $n): array
{
    $m = [];
    foreach (array_slice($rows, 0, $n) as $i => $r) {
        $m[(int) $r['sender_id']] = $i + 1;
    }
    return $m;
}

/** The organizer's three best fans this month: user id => rank. */
function gift_month_top3(int $organizerId): array
{
    if (!gifts_ready()) {
        return [];
    }
    $st = db()->prepare("SELECT t.sender_id, SUM(t.gross_ugx) AS v, MIN(t.paid_at) AS first_at
                         FROM gift_transactions t JOIN users u ON u.id = t.sender_id
                         WHERE t.recipient_organizer_id = ? AND t.payment_status = 'SUCCESSFUL' AND t.is_anonymous = 0 AND u.account_status = 'ACTIVE' AND t.paid_at >= ?
                         GROUP BY t.sender_id ORDER BY v DESC, first_at ASC, t.sender_id ASC LIMIT 3");
    $st->execute([$organizerId, gift_fans_month_start()]);
    return gift_fans_rank_map($st->fetchAll(), 3);
}

/**
 * The fans who gifted one video, best first.
 * @return list<array<string,mixed>>
 */
function gift_top_fans_post(int $postId, int $organizerId, int $limit = GIFT_FANS_LIST): array
{
    if (!gifts_ready()) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $st = db()->prepare("SELECT t.sender_id, u.name, SUM(t.gross_ugx) AS v, MIN(t.paid_at) AS first_at
                         FROM gift_transactions t JOIN users u ON u.id = t.sender_id
                         WHERE t.post_id = ? AND t.payment_status = 'SUCCESSFUL' AND t.is_anonymous = 0 AND u.account_status = 'ACTIVE'
                         GROUP BY t.sender_id, u.name ORDER BY v DESC, first_at ASC, t.sender_id ASC LIMIT $limit");
    $st->execute([$postId]);
    $monthTop = gift_month_top3($organizerId);
    $out = [];
    foreach ($st->fetchAll() as $i => $r) {
        $out[] = gift_fan_shape($r, $i + 1, $monthTop);
    }
    return $out;
}

/**
 * The top three fans of many videos in ONE query, for the feed.
 * @param list<int> $postIds
 * @return array<int, list<array{rank:int, name:string, initials:string, avatar:?string}>>
 */
function gift_top_fans_batch(array $postIds, int $per = 3): array
{
    $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
    if (!$postIds || !gifts_ready()) {
        return [];
    }
    $st = db()->query("SELECT t.post_id, t.sender_id, u.name, SUM(t.gross_ugx) AS v, MIN(t.paid_at) AS first_at
                       FROM gift_transactions t JOIN users u ON u.id = t.sender_id
                       WHERE t.post_id IN (" . implode(',', $postIds) . ") AND t.payment_status = 'SUCCESSFUL' AND t.is_anonymous = 0 AND u.account_status = 'ACTIVE'
                       GROUP BY t.post_id, t.sender_id, u.name ORDER BY t.post_id, v DESC, first_at ASC, t.sender_id ASC");
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $pid = (int) $r['post_id'];
        if (count($out[$pid] ?? []) >= $per) {
            continue;
        }
        $out[$pid][] = ['rank' => count($out[$pid] ?? []) + 1, 'name' => community_display_name((string) $r['name']), 'initials' => community_initials((string) $r['name']),
                        'avatar' => function_exists('user_avatar_url') ? user_avatar_url((int) $r['sender_id']) : null];
    }
    return $out;
}

/** Adds `topFans` to feed items (when gifts are on). Never breaks the feed. */
function gift_attach_top_fans(array $items): array
{
    if (!$items || !gift_fans_visible()) {
        return $items;
    }
    try {
        $map = gift_top_fans_batch(array_column($items, 'id'));
        foreach ($items as &$it) {
            $it['topFans'] = $map[(int) $it['id']] ?? [];
        }
        unset($it);
    } catch (Throwable $e) {
        error_log('[gift] top fans for the feed failed: ' . $e->getMessage());
    }
    return $items;
}

// ---------------------------------------------------------------------------------------------------- the automatic thank-you

function gift_auto_thanks_default_message(): string
{
    return 'Thank you {name} for the {gift}! Your support means the world to us. - {org}';
}

/** @return array{on:bool, mode:string, message:string} mode: first (first-time gifters) | every */
function gift_auto_thanks_get(int $organizerId): array
{
    $raw = json_decode((string) get_setting('org_auto_thanks_' . $organizerId, ''), true);
    $msg = is_array($raw) && is_string($raw['message'] ?? null) && trim($raw['message']) !== '' ? (string) $raw['message'] : gift_auto_thanks_default_message();
    return ['on' => is_array($raw) && !empty($raw['on']), 'mode' => is_array($raw) && ($raw['mode'] ?? '') === 'every' ? 'every' : 'first', 'message' => $msg];
}

/** @return array{ok:bool, error?:string} */
function gift_auto_thanks_set(int $organizerId, bool $on, string $mode, string $message): array
{
    $message = gift_thanks_clean($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Write the message that will be sent.'];
    }
    if (mb_strlen($message) > GIFT_AUTO_THANKS_MAX_CHARS) {
        return ['ok' => false, 'error' => 'Keep the message to ' . GIFT_AUTO_THANKS_MAX_CHARS . ' characters or fewer.'];
    }
    coin_quiet_setting('org_auto_thanks_' . $organizerId, json_encode(['on' => $on, 'mode' => $mode === 'every' ? 'every' : 'first', 'message' => $message]));
    try {
        log_admin_action($organizerId, 'gift.auto_thanks', 'user', $organizerId, ['on' => $on, 'mode' => $mode === 'every' ? 'every' : 'first']);
    } catch (Throwable $e) {
        // the log never blocks saving
    }
    return ['ok' => true];
}

/** Fills {name} {gift} {org} in. An anonymous supporter is called "friend". The result never exceeds the thank-you limit. */
function gift_auto_thanks_render(string $template, string $fanFirstName, string $giftName, string $orgName): string
{
    $text = strtr($template, ['{name}' => $fanFirstName !== '' ? $fanFirstName : 'friend', '{gift}' => $giftName, '{org}' => $orgName]);
    return mb_strlen($text) > GIFT_THANKS_MAX_CHARS ? mb_substr($text, 0, GIFT_THANKS_MAX_CHARS - 1) . '…' : $text;
}

/**
 * Called once when a gift has been delivered. Sends the organizer's automatic thank-you if they switched it on and the rules say so.
 * Never throws and never touches money.
 * @return array{sent:bool, why:string}
 */
function gift_auto_thank(int $txId): array
{
    try {
        if (!gifts_ready()) {
            return ['sent' => false, 'why' => 'gifts not ready'];
        }
        $pdo = db();
        $st = $pdo->prepare('SELECT t.id, t.sender_id, t.recipient_organizer_id AS org, t.is_anonymous, g.name AS gift_name, u.name AS sender_name
                             FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id JOIN users u ON u.id = t.sender_id WHERE t.id = ?');
        $st->execute([$txId]);
        $tx = $st->fetch();
        if (!$tx) {
            return ['sent' => false, 'why' => 'no such gift'];
        }
        $org = (int) $tx['org'];
        $cfg = gift_auto_thanks_get($org);
        if (!$cfg['on']) {
            return ['sent' => false, 'why' => 'off'];
        }
        if ($cfg['mode'] === 'first') {
            $earlier = $pdo->prepare("SELECT COUNT(*) FROM gift_transactions WHERE recipient_organizer_id = ? AND sender_id = ? AND payment_status IN ('SUCCESSFUL','REFUNDED') AND id < ?");
            $earlier->execute([$org, (int) $tx['sender_id'], $txId]);
            if ((int) $earlier->fetchColumn() > 0) {
                return ['sent' => false, 'why' => 'not a first-time gifter'];
            }
        }
        // at most one automatic message per fan a day (in "every" mode); a person already thanked by hand today is not thanked again
        $recent = $pdo->prepare("SELECT COUNT(*) FROM gift_events e JOIN gift_transactions t ON t.id = e.gift_tx_id
                                 WHERE e.kind IN ('thanked_chat','thanked_notice') AND t.recipient_organizer_id = ? AND t.sender_id = ? AND e.created_at >= NOW() - INTERVAL 24 HOUR");
        $recent->execute([$org, (int) $tx['sender_id']]);
        if ((int) $recent->fetchColumn() > 0) {
            return ['sent' => false, 'why' => 'already thanked today'];
        }
        $orgName = (string) $pdo->query('SELECT COALESCE(NULLIF(op.org_name, \'\'), u.name) FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE u.id = ' . $org)->fetchColumn();
        $first = !empty($tx['is_anonymous']) ? '' : (function_exists('gift_first_name') ? gift_first_name((string) $tx['sender_name']) : '');
        $text = gift_auto_thanks_render($cfg['message'], $first, (string) $tx['gift_name'], $orgName);
        $res = gift_thank($org, $txId, $text);
        if (!$res['ok']) {
            gift_log($txId, 'auto_thank_skipped', mb_substr((string) $res['error'], 0, 200));
            return ['sent' => false, 'why' => (string) $res['error']];
        }
        gift_log($txId, 'auto_thank', 'sent by the automatic thank-you');
        return ['sent' => true, 'why' => 'sent'];
    } catch (Throwable $e) {
        error_log('[gift] automatic thank-you failed for gift ' . $txId . ': ' . $e->getMessage());
        return ['sent' => false, 'why' => 'error'];
    }
}
