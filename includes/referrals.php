<?php
// Referral rewards (11/24, migration 041). Organizer-funded and opt-in per event:
//  - every buyer of the event gets a personal invite link (event.php?slug=...&ref=CODE),
//  - a visitor who opens it and buys counts as one friend (the inviter never counts themselves),
//  - every N friends who pay earns the inviter a one-time promo code for this event worth the reward.
// The reward is an ordinary promo code (includes/payments.php promo_lookup), so it comes off the event's ticket money.

function referrals_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = function_exists('discounts_ready') && discounts_ready() && (bool) db()->query("SHOW TABLES LIKE 'referral_links'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** [friends needed, reward] when this event runs referrals, else null. */
function referral_terms(array $event): ?array
{
    if (!referrals_ready() || empty($event['referral_friends']) || (float) ($event['referral_reward'] ?? 0) <= 0) {
        return null;
    }
    return [(int) $event['referral_friends'], (float) $event['referral_reward']];
}

/** Saves the organizer's referral settings (null friends = off). */
function set_event_referrals(int $eventId, ?int $friends, ?float $reward): void
{
    if (!referrals_ready()) {
        return;
    }
    $on = $friends !== null && $friends >= 1 && $friends <= 50 && $reward !== null && $reward > 0;
    db()->prepare('UPDATE events SET referral_friends = ?, referral_reward = ? WHERE id = ?')
        ->execute([$on ? $friends : null, $on ? round($reward, 0) : null, $eventId]);
}

/** This person's invite link row for the event (made on first use). */
function referral_link_for(int $eventId, int $userId): ?array
{
    if (!referrals_ready()) {
        return null;
    }
    $get = db()->prepare('SELECT * FROM referral_links WHERE event_id = ? AND user_id = ?');
    $get->execute([$eventId, $userId]);
    if ($row = $get->fetch()) {
        return $row;
    }
    for ($i = 0; $i < 5; $i++) {
        $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        try {
            db()->prepare('INSERT INTO referral_links (event_id, user_id, code) VALUES (?, ?, ?)')->execute([$eventId, $userId, $code]);
            break;
        } catch (PDOException $e) {
            // a clash on the code (retry) or a parallel insert for the same person (read it below)
        }
    }
    $get->execute([$eventId, $userId]);
    return $get->fetch() ?: null;
}

function referral_url(array $event, array $link): string
{
    return rtrim(APP_URL, '/') . '/event.php?slug=' . rawurlencode((string) $event['slug']) . '&ref=' . rawurlencode((string) $link['code']);
}

/** event.php calls this: remember who invited this visitor (for 7 days, this event only). */
function referral_capture(array $event): void
{
    $ref = $_GET['ref'] ?? null;
    if (!is_string($ref) || !preg_match('/^[A-F0-9]{8}$/', strtoupper($ref)) || !referral_terms($event)) {
        return;
    }
    $stmt = db()->prepare('SELECT id, user_id FROM referral_links WHERE code = ? AND event_id = ?');
    $stmt->execute([strtoupper($ref), (int) $event['id']]);
    $link = $stmt->fetch();
    $me = current_user();
    if ($link && (!$me || (int) $me['id'] !== (int) $link['user_id'])) {
        $_SESSION['referral'][(int) $event['id']] = ['link' => (int) $link['id'], 'at' => time()];
    }
}

/** initiate-payment calls this after the order is made: tie it to the inviter's link (never the buyer's own). */
function referral_attach_order(int $orderId, int $eventId, int $buyerId): void
{
    $r = $_SESSION['referral'][$eventId] ?? null;
    if (!$r || time() - (int) $r['at'] > 7 * 86400 || !referrals_ready()) {
        return;
    }
    db()->prepare('UPDATE orders o JOIN referral_links l ON l.id = ? SET o.referral_link_id = l.id WHERE o.id = ? AND l.user_id <> ?')
        ->execute([(int) $r['link'], $orderId, $buyerId]);
}

/** Friends who paid through this link (one per person, however many orders they made). */
function referral_friend_count(int $linkId): int
{
    $stmt = db()->prepare("SELECT COUNT(DISTINCT user_id) FROM orders WHERE referral_link_id = ? AND status = 'PAID'");
    $stmt->execute([$linkId]);
    return (int) $stmt->fetchColumn();
}

/** The reward codes this link has earned, newest first, with whether they are used. */
function referral_rewards_for_link(int $linkId): array
{
    $stmt = db()->prepare('SELECT p.code, p.discount_value, p.used_count FROM referral_rewards r JOIN promo_codes p ON p.id = r.promo_code_id WHERE r.link_id = ? ORDER BY r.id DESC');
    $stmt->execute([$linkId]);
    return $stmt->fetchAll();
}

/**
 * finalize_order_success calls this once an order is PAID: if it came through an invite link and the inviter has
 * now reached another N friends, they get a new one-time code and a notification.
 */
function referral_after_paid(int $orderId): void
{
    if (!referrals_ready()) {
        return;
    }
    $stmt = db()->prepare('SELECT o.referral_link_id, l.user_id AS inviter_id, e.* FROM orders o JOIN referral_links l ON l.id = o.referral_link_id JOIN events e ON e.id = l.event_id WHERE o.id = ?');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row || !($terms = referral_terms($row))) {
        return;
    }
    [$need, $reward] = $terms;
    $linkId = (int) $row['referral_link_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM referral_links WHERE id = ? FOR UPDATE')->execute([$linkId]);   // one reward at a time per link
        $earned = intdiv(referral_friend_count($linkId), $need);
        $have = $pdo->prepare('SELECT COUNT(*) FROM referral_rewards WHERE link_id = ?');
        $have->execute([$linkId]);
        $new = [];
        for ($i = (int) $have->fetchColumn(); $i < $earned; $i++) {
            $code = 'REF' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 7));
            $pdo->prepare("INSERT INTO promo_codes (code, event_id, discount_type, discount_value, max_uses, created_by) VALUES (?, ?, 'FIXED', ?, 1, ?)")
                ->execute([$code, (int) $row['id'], $reward, (int) $row['inviter_id']]);
            $pid = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO referral_rewards (link_id, promo_code_id) VALUES (?, ?)')->execute([$linkId, $pid]);
            $new[] = $code;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[referral] reward failed for order ' . $orderId . ': ' . $e->getMessage());
        return;
    }
    foreach ($new as $code) {
        try {
            create_notification((int) $row['inviter_id'], 'referral_reward', 'You earned UGX ' . number_format($reward) . ' off',
                'Your friends bought tickets to ' . $row['title'] . '. Use code ' . $code . ' at checkout.', '/event.php?slug=' . rawurlencode((string) $row['slug']));
        } catch (Throwable $e) {
            error_log('[referral] notify failed: ' . $e->getMessage());
        }
    }
}
