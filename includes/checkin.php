<?php
declare(strict_types=1);

/**
 * Looks up a ticket by its code (what a QR scan or a manual code entry
 * yields) along with everything the check-in desk needs to display and
 * verify it — which event it's for, who bought it, and its current state.
 */
function find_ticket_by_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $stmt = db()->prepare('
        SELECT t.*, oi.order_id, oi.ticket_type_id, tt.name AS tier_name, tt.event_id,
               e.title AS event_title, e.organizer_id,
               u.name AS attendee_name, u.email AS attendee_email
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = tt.event_id
        JOIN orders o ON o.id = oi.order_id
        JOIN users u ON u.id = o.user_id
        WHERE t.ticket_code = ?
    ');
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function check_in_ticket(int $ticketId): void
{
    db()->prepare("UPDATE tickets SET status = 'USED', checked_in_at = NOW() WHERE id = ?")->execute([$ticketId]);
}

/** @return array{total: int, checked_in: int} */
function get_checkin_stats(int $eventId): array
{
    $stmt = db()->prepare("
        SELECT COUNT(*) AS total, SUM(t.status = 'USED') AS checked_in
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        WHERE tt.event_id = ? AND t.status != 'CANCELLED'
    ");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    return ['total' => (int) ($row['total'] ?? 0), 'checked_in' => (int) ($row['checked_in'] ?? 0)];
}
