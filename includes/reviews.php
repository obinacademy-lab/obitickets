<?php
declare(strict_types=1);

/**
 * Post-event reviews & ratings — one per (event, user), gated on having
 * actually attended (a checked-in ticket), not just having bought one.
 */

/** True only if this user has at least one checked-in ticket for this event — the actual attendance proof, not just a purchase. */
function user_attended_event(int $userId, int $eventId): bool
{
    $stmt = db()->prepare("
        SELECT 1 FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN orders o ON o.id = oi.order_id
        WHERE o.user_id = ? AND o.event_id = ? AND t.status = 'USED'
        LIMIT 1
    ");
    $stmt->execute([$userId, $eventId]);
    return (bool) $stmt->fetchColumn();
}

function get_user_review_for_event(int $userId, int $eventId): ?array
{
    $stmt = db()->prepare('SELECT * FROM event_reviews WHERE user_id = ? AND event_id = ?');
    $stmt->execute([$userId, $eventId]);
    return $stmt->fetch() ?: null;
}

/**
 * Upserts — a second submission from the same attendee edits their existing
 * review rather than erroring, via the (event_id, user_id) unique key.
 * @return array{0: bool, 1: ?string} [success, errorMessage]
 */
function submit_event_review(int $userId, int $eventId, int $rating, ?string $comment): array
{
    if ($rating < 1 || $rating > 5) {
        return [false, 'Please choose a star rating.'];
    }
    if (!user_attended_event($userId, $eventId)) {
        return [false, 'Only checked-in attendees can leave a review for this event.'];
    }

    $comment = $comment !== null ? trim($comment) : null;
    $comment = $comment === '' ? null : $comment;

    db()->prepare('
        INSERT INTO event_reviews (event_id, user_id, rating, comment)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)
    ')->execute([$eventId, $userId, $rating, $comment]);

    return [true, null];
}

function get_reviews_for_event(int $eventId): array
{
    $stmt = db()->prepare('
        SELECT r.*, u.name AS reviewer_name
        FROM event_reviews r
        JOIN users u ON u.id = r.user_id
        WHERE r.event_id = ?
        ORDER BY r.created_at DESC
    ');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** @return array{count:int, average:?float} */
function get_event_rating_summary(int $eventId): array
{
    $stmt = db()->prepare('SELECT COUNT(*) AS n, AVG(rating) AS avg_rating FROM event_reviews WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    return ['count' => (int) $row['n'], 'average' => $row['n'] > 0 ? round((float) $row['avg_rating'], 1) : null];
}

/** Aggregate across every one of an organizer's events — shown on their public profile page. */
function get_organizer_rating_summary(int $organizerUserId): array
{
    $stmt = db()->prepare('
        SELECT COUNT(*) AS n, AVG(r.rating) AS avg_rating
        FROM event_reviews r
        JOIN events e ON e.id = r.event_id
        WHERE e.organizer_id = ?
    ');
    $stmt->execute([$organizerUserId]);
    $row = $stmt->fetch();
    return ['count' => (int) $row['n'], 'average' => $row['n'] > 0 ? round((float) $row['avg_rating'], 1) : null];
}

/** Newest first, for the admin moderation page. */
function get_all_reviews_admin(int $limit = 200): array
{
    $stmt = db()->prepare('
        SELECT r.*, u.name AS reviewer_name, u.email AS reviewer_email, e.title AS event_title, e.slug AS event_slug
        FROM event_reviews r
        JOIN users u ON u.id = r.user_id
        JOIN events e ON e.id = r.event_id
        ORDER BY r.created_at DESC
        LIMIT ' . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function delete_event_review(int $adminId, int $reviewId): void
{
    $stmt = db()->prepare('SELECT event_id, user_id FROM event_reviews WHERE id = ?');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch();
    if (!$review) {
        return;
    }
    db()->prepare('DELETE FROM event_reviews WHERE id = ?')->execute([$reviewId]);
    log_admin_action($adminId, 'review.delete', 'event', (int) $review['event_id'], ['reviewer_user_id' => (int) $review['user_id']]);
}

/** Renders a row of 5 filled/empty stars for a given rating (rounded to the nearest whole star). */
function render_star_rating(float $rating): string
{
    $filled = (int) round($rating);
    $html = '<span class="review-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $filled ? '&#9733;' : '<span class="empty">&#9733;</span>';
    }
    return $html . '</span>';
}

/** Distinct attendees who actually checked in — who cron/send-review-prompts.php emails. */
function get_checked_in_attendees_for_event(int $eventId): array
{
    $stmt = db()->prepare("
        SELECT DISTINCT u.id, u.name, u.email
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN orders o ON o.id = oi.order_id
        JOIN users u ON u.id = o.user_id
        WHERE o.event_id = ? AND t.status = 'USED'
    ");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}
