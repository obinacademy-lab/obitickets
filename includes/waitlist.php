<?php
declare(strict_types=1);

/**
 * Waitlist for sold-out ticket tiers. Joining is only possible while a tier
 * is genuinely sold out; whenever seats are freed (an abandoned payment is
 * released by fail_order(), or an organizer raises a tier's quantity in
 * replace_ticket_types()) notify_waitlist_for_tier() emails the next people
 * in line. It is first-come-first-served, not a hold: the email says so, and
 * the seat stays buyable by anyone until someone actually pays.
 */

/** @return 'joined'|'already'|'available'|'closed' */
function join_waitlist(int $userId, int $tierId): string
{
    $stmt = db()->prepare('
        SELECT tt.quantity_total, tt.quantity_sold, e.status, e.ends_at
        FROM ticket_types tt
        JOIN events e ON e.id = tt.event_id
        WHERE tt.id = ?
    ');
    $stmt->execute([$tierId]);
    $tier = $stmt->fetch();

    if (!$tier || $tier['status'] !== 'PUBLISHED' || strtotime($tier['ends_at']) < time()) {
        return 'closed';
    }
    if ((int) $tier['quantity_sold'] < (int) $tier['quantity_total']) {
        return 'available'; // not sold out — the buyer should just buy
    }

    $stmt = db()->prepare('SELECT notified_at FROM waitlist_entries WHERE ticket_type_id = ? AND user_id = ?');
    $stmt->execute([$tierId, $userId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['notified_at'] === null) {
            return 'already';
        }
        // Notified before but sold out again and they want back in: reset the
        // row (and their queue position) instead of adding a second one.
        db()->prepare('UPDATE waitlist_entries SET notified_at = NULL, created_at = NOW() WHERE ticket_type_id = ? AND user_id = ?')
            ->execute([$tierId, $userId]);
        return 'joined';
    }

    db()->prepare('INSERT IGNORE INTO waitlist_entries (ticket_type_id, user_id) VALUES (?, ?)')->execute([$tierId, $userId]);
    return 'joined';
}

function leave_waitlist(int $userId, int $tierId): void
{
    db()->prepare('DELETE FROM waitlist_entries WHERE ticket_type_id = ? AND user_id = ?')->execute([$tierId, $userId]);
}

/** Tier ids on this event the user is still waiting on (not yet notified). @return list<int> */
function get_waiting_tier_ids(int $userId, int $eventId): array
{
    $stmt = db()->prepare('
        SELECT w.ticket_type_id
        FROM waitlist_entries w
        JOIN ticket_types tt ON tt.id = w.ticket_type_id
        WHERE w.user_id = ? AND tt.event_id = ? AND w.notified_at IS NULL
    ');
    $stmt->execute([$userId, $eventId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Emails as many waiting people as there are open seats, in join order, and
 * returns how many were notified. Safe to call any time and from anywhere
 * seats may have opened — it recomputes availability itself and does nothing
 * if the tier is still full, paused, or its event is over.
 *
 * Seats already "spoken for" by people notified in the last 24h who haven't
 * bought yet are subtracted first, so one freed seat doesn't get five people
 * emailed across five separate triggers. People who already hold a paid or
 * pending order for the tier, and suspended accounts, are skipped.
 */
function notify_waitlist_for_tier(int $tierId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Serialise concurrent callers on the tier row so two releases landing
        // together can't both hand the same seat to the same person.
        $stmt = $pdo->prepare('SELECT event_id, name, quantity_total, quantity_sold, sales_paused FROM ticket_types WHERE id = ? FOR UPDATE');
        $stmt->execute([$tierId]);
        $tier = $stmt->fetch();
        if (!$tier || !empty($tier['sales_paused'])) {
            $pdo->commit();
            return 0;
        }

        $stmt = $pdo->prepare('SELECT title, slug, status, ends_at FROM events WHERE id = ?');
        $stmt->execute([$tier['event_id']]);
        $event = $stmt->fetch();
        if (!$event || $event['status'] !== 'PUBLISHED' || strtotime($event['ends_at']) < time()) {
            $pdo->commit();
            return 0;
        }

        $open = max(0, (int) $tier['quantity_total'] - (int) $tier['quantity_sold']);
        if ($open === 0) {
            $pdo->commit();
            return 0;
        }

        $notBought = 'NOT EXISTS (
            SELECT 1 FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.user_id = w.user_id AND oi.ticket_type_id = w.ticket_type_id AND o.status IN (\'PENDING\', \'PAID\')
        )';

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM waitlist_entries w
            WHERE w.ticket_type_id = ? AND w.notified_at > (NOW() - INTERVAL 24 HOUR) AND $notBought
        ");
        $stmt->execute([$tierId]);
        $slots = $open - (int) $stmt->fetchColumn();
        if ($slots <= 0) {
            $pdo->commit();
            return 0;
        }

        $stmt = $pdo->prepare("
            SELECT w.id, u.name, u.email
            FROM waitlist_entries w
            JOIN users u ON u.id = w.user_id
            WHERE w.ticket_type_id = ? AND w.notified_at IS NULL AND u.account_status <> 'SUSPENDED' AND $notBought
            ORDER BY w.created_at, w.id
            LIMIT " . (int) $slots
        );
        $stmt->execute([$tierId]);
        $people = $stmt->fetchAll();

        if ($people) {
            $ids = array_map('intval', array_column($people, 'id'));
            $pdo->exec('UPDATE waitlist_entries SET notified_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Email only after the commit: marking them notified is what prevents a
    // double-send, and a slow mail API shouldn't hold the tier row locked.
    $eventUrl = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($event['slug']) . '#buyPanel';
    foreach ($people as $person) {
        send_waitlist_available_email($person['email'], $person['name'], $event['title'], $tier['name'], $eventUrl);
    }

    return count($people);
}
