<?php
declare(strict_types=1);

/**
 * Organizer dashboard — data access, RBAC, and team/notification/activity
 * plumbing. Mirrors the shape of includes/admin.php (same NULL-role-is-owner
 * pattern, same log-then-return-array conventions) but scoped to a single
 * organizer's own data. Every function here MUST filter by organizer_id (or
 * join through an event/order that belongs to that organizer) — this is the
 * only thing standing between one organizer and another organizer's data.
 */

const ORGANIZER_ROLES = [
    'EVENT_MANAGER' => 'Event Manager',
    'FINANCE_MANAGER' => 'Finance Manager',
    'CHECKIN_STAFF' => 'Check-In Staff',
    'MARKETING_MANAGER' => 'Marketing Manager',
];

const ORGANIZER_PERMISSIONS = [
    'EVENT_MANAGER' => ['dashboard.view', 'events.view', 'events.manage', 'tickets.view', 'tickets.manage', 'attendees.view', 'checkin.use', 'analytics.view'],
    'FINANCE_MANAGER' => ['dashboard.view', 'sales.view', 'transactions.view', 'earnings.view', 'payouts.view', 'payouts.request', 'refunds.view', 'refunds.request'],
    'CHECKIN_STAFF' => ['dashboard.view', 'checkin.use'],
    'MARKETING_MANAGER' => ['dashboard.view', 'promo.view', 'promo.manage', 'marketing.view', 'analytics.view'],
];

/**
 * Resolves which organizer account the logged-in user is acting on behalf
 * of, and with what role. Returns null if they have no organizer access at
 * all (a plain attendee, or a team invite that isn't ACTIVE yet).
 *
 * @return null|array{organizer_id: int, actor_id: int, role: ?string, is_owner: bool}
 */
function resolve_organizer_context(array $user): ?array
{
    if ($user['role'] === 'ORGANIZER') {
        return ['organizer_id' => (int) $user['id'], 'actor_id' => (int) $user['id'], 'role' => null, 'is_owner' => true];
    }
    $stmt = db()->prepare("SELECT organizer_id, role FROM organizer_team_members WHERE user_id = ? AND status = 'ACTIVE' LIMIT 1");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return ['organizer_id' => (int) $row['organizer_id'], 'actor_id' => (int) $user['id'], 'role' => $row['role'], 'is_owner' => false];
}

/** NULL role = owner = full access, same convention as admin_can()'s NULL admin_role. */
function organizer_can(?array $context, string $permission): bool
{
    if (!$context) {
        return false;
    }
    if ($context['role'] === null) {
        return true;
    }
    return in_array($permission, ORGANIZER_PERMISSIONS[$context['role']] ?? [], true);
}

/** @return array{organizer_id: int, actor_id: int, role: ?string, is_owner: bool} */
function require_organizer_access(string $permission): array
{
    $user = require_login();
    if ($user['role'] === 'ADMIN') {
        // Platform admins can open organizer tooling to help/investigate, acting as the organizer they're viewing would — but there's no single "which organizer" without a target, so admin access to these pages is via the admin dashboard's own organizer-detail views, not this shell.
        http_response_code(403);
        exit('Admins manage organizers from the admin dashboard.');
    }
    $context = resolve_organizer_context($user);
    if (!$context || !organizer_can($context, $permission)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
    return $context;
}

// =====================================================================
// Team management
// =====================================================================

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function invite_team_member(int $organizerId, int $invitedBy, string $email, string $role, ?string $name = null): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Enter a valid email address.'];
    }
    if (!array_key_exists($role, ORGANIZER_ROLES)) {
        return [false, 'Choose a valid role.'];
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE id = ? AND email = ?');
    $stmt->execute([$organizerId, $email]);
    if ($stmt->fetch()) {
        return [false, "That's your own account."];
    }
    $stmt = db()->prepare('SELECT id FROM organizer_team_members WHERE organizer_id = ? AND email = ?');
    $stmt->execute([$organizerId, $email]);
    if ($stmt->fetch()) {
        return [false, 'This person is already on your team.'];
    }

    // If an obitickets account already exists for this email, activate immediately —
    // no separate invite-token/email-accept flow to build for the common case.
    $stmt = db()->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch();

    $stmt = db()->prepare('
        INSERT INTO organizer_team_members (organizer_id, user_id, email, name, role, status, invited_by, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $organizerId,
        $existingUser['id'] ?? null,
        $email,
        $name ?: ($existingUser['name'] ?? null),
        $role,
        $existingUser ? 'ACTIVE' : 'INVITED',
        $invitedBy,
        $existingUser ? date('Y-m-d H:i:s') : null,
    ]);
    $id = (int) db()->lastInsertId();
    log_organizer_action($organizerId, $invitedBy, 'team.invite', 'team_member', $id, ['email' => $email, 'role' => $role]);

    return [true, null];
}

function get_team_members(int $organizerId): array
{
    $stmt = db()->prepare('
        SELECT tm.*, u.name AS user_name, u.avatar_url
        FROM organizer_team_members tm
        LEFT JOIN users u ON u.id = tm.user_id
        WHERE tm.organizer_id = ?
        ORDER BY FIELD(tm.status, "ACTIVE", "INVITED", "REVOKED"), tm.invited_at DESC
    ');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

/** A pending invite becomes ACTIVE once that email has an obitickets account — call after checking. */
function activate_team_member_if_registered(int $organizerId, int $memberId): bool
{
    $stmt = db()->prepare('SELECT email FROM organizer_team_members WHERE id = ? AND organizer_id = ? AND status = "INVITED"');
    $stmt->execute([$memberId, $organizerId]);
    $member = $stmt->fetch();
    if (!$member) {
        return false;
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$member['email']]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }
    db()->prepare('UPDATE organizer_team_members SET user_id = ?, status = "ACTIVE", joined_at = NOW() WHERE id = ?')
        ->execute([$user['id'], $memberId]);

    return true;
}

function update_team_member_role(int $organizerId, int $actorId, int $memberId, string $role): void
{
    if (!array_key_exists($role, ORGANIZER_ROLES)) {
        return;
    }
    db()->prepare('UPDATE organizer_team_members SET role = ? WHERE id = ? AND organizer_id = ?')->execute([$role, $memberId, $organizerId]);
    log_organizer_action($organizerId, $actorId, 'team.role_change', 'team_member', $memberId, ['role' => $role]);
}

function revoke_team_member(int $organizerId, int $actorId, int $memberId): void
{
    db()->prepare('UPDATE organizer_team_members SET status = "REVOKED" WHERE id = ? AND organizer_id = ?')->execute([$memberId, $organizerId]);
    log_organizer_action($organizerId, $actorId, 'team.revoke', 'team_member', $memberId);
}

// =====================================================================
// Notifications
// =====================================================================

function create_notification(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
{
    db()->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $type, $title, $body, $link]);
}

function get_notifications(int $userId, int $limit = 30): array
{
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function count_unread_notifications(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function mark_notification_read(int $userId, int $id): void
{
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([$id, $userId]);
}

function mark_all_notifications_read(int $userId): void
{
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$userId]);
}

// =====================================================================
// Organizer activity log
// =====================================================================

function log_organizer_action(int $organizerId, int $actorId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $details = null): void
{
    db()->prepare('INSERT INTO organizer_activity_log (organizer_id, actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$organizerId, $actorId, $action, $entityType, $entityId, $details ? json_encode($details) : null]);
}

function get_organizer_activity(int $organizerId, int $limit = 15): array
{
    $stmt = db()->prepare('
        SELECT oal.*, u.name AS actor_name
        FROM organizer_activity_log oal
        JOIN users u ON u.id = oal.actor_id
        WHERE oal.organizer_id = ?
        ORDER BY oal.created_at DESC
        LIMIT ?
    ');
    $stmt->bindValue(1, $organizerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// =====================================================================
// Dashboard
// =====================================================================

function get_organizer_dashboard_stats(int $organizerId): array
{
    $pdo = db();
    $stats = [];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'PUBLISHED' AND starts_at > NOW()) AS upcoming FROM events WHERE organizer_id = ?");
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch();
    $stats['total_events'] = (int) $row['total'];
    $stats['upcoming_events'] = (int) ($row['upcoming'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS sold, SUM(t.status = 'USED') AS checked_in
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = tt.event_id
        WHERE e.organizer_id = ? AND t.status != 'CANCELLED'
    ");
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch();
    $stats['tickets_sold'] = (int) $row['sold'];
    $stats['checked_in'] = (int) ($row['checked_in'] ?? 0);

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.user_id) FROM orders o JOIN events e ON e.id = o.event_id WHERE e.organizer_id = ? AND o.status = 'PAID'");
    $stmt->execute([$organizerId]);
    $stats['total_attendees'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT o.currency, COALESCE(SUM(o.total_amount), 0) AS gross
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE e.organizer_id = ? AND o.status = 'PAID'
        GROUP BY o.currency
    ");
    $stmt->execute([$organizerId]);
    $stats['gross_by_currency'] = $stmt->fetchAll();

    $balance = get_organizer_balance($organizerId);
    $stats['net_earnings'] = array_sum(array_column($balance, 'lifetime'));
    $stats['available_balance'] = array_sum(array_column($balance, 'available'));
    $stats['currency'] = array_key_first($balance) ?: 'UGX';

    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payouts WHERE organizer_id = ? AND status IN ('PENDING','APPROVED','PROCESSING')");
    $stmt->execute([$organizerId]);
    [$stats['pending_payouts_count'], $stats['pending_payouts_amount']] = array_map(static fn ($v) => is_numeric($v) ? $v + 0 : $v, $stmt->fetch(PDO::FETCH_NUM));

    return $stats;
}

/** Mirrors get_daily_revenue() in admin.php, scoped to one organizer's events. */
function get_organizer_daily_revenue(int $organizerId, int $days = 14): array
{
    $stmt = db()->prepare("
        SELECT DATE(o.paid_at) AS day, SUM(o.total_amount) AS total
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE e.organizer_id = ? AND o.status = 'PAID' AND o.paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(o.paid_at)
        ORDER BY day
    ");
    $stmt->execute([$organizerId, $days]);
    $rows = array_column($stmt->fetchAll(), null, 'day');

    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['day' => $day, 'total' => (float) ($rows[$day]['total'] ?? 0)];
    }
    return $out;
}

/** Mirrors get_period_comparison() in admin.php, scoped to one organizer. */
function get_organizer_period_comparison(int $organizerId, int $days = 30): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN o.total_amount ELSE 0 END), 0) AS cur_revenue,
            COALESCE(SUM(CASE WHEN o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND o.paid_at < DATE_SUB(NOW(), INTERVAL ? DAY) THEN o.total_amount ELSE 0 END), 0) AS prev_revenue,
            SUM(CASE WHEN o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS cur_orders,
            SUM(CASE WHEN o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND o.paid_at < DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS prev_orders
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE e.organizer_id = ? AND o.status = 'PAID'
    ");
    $stmt->execute([$days, $days * 2, $days, $days, $days * 2, $days, $organizerId]);
    $row = $stmt->fetch();

    $pct = static function ($cur, $prev) {
        $cur = (float) $cur;
        $prev = (float) $prev;
        if ($prev <= 0) {
            return $cur > 0 ? null : null;
        }
        return (($cur - $prev) / $prev) * 100;
    };

    return [
        'revenue' => ['percent' => $pct($row['cur_revenue'], $row['prev_revenue'])],
        'orders' => ['percent' => $pct($row['cur_orders'], $row['prev_orders'])],
    ];
}

function get_upcoming_events_for_organizer(int $organizerId, int $limit = 5): array
{
    $stmt = db()->prepare("
        SELECT e.*,
            (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_sold,
            (SELECT COALESCE(SUM(t.quantity_total), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_total,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.event_id = e.id AND o.status = 'PAID') AS revenue
        FROM events e
        WHERE e.organizer_id = ? AND e.status = 'PUBLISHED' AND e.starts_at > NOW()
        ORDER BY e.starts_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $organizerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// =====================================================================
// Orders & attendees
// =====================================================================

/** @param array{status?: string, q?: string, event_id?: int} $filters */
function get_orders_for_organizer(int $organizerId, array $filters = [], int $limit = 30, int $offset = 0): array
{
    [$whereSql, $params] = build_organizer_order_where($organizerId, $filters);
    $stmt = db()->prepare("
        SELECT o.*, u.name AS buyer_name, u.email AS buyer_email, e.title AS event_title
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        $whereSql
        ORDER BY o.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function count_orders_for_organizer(int $organizerId, array $filters = []): int
{
    [$whereSql, $params] = build_organizer_order_where($organizerId, $filters);
    $stmt = db()->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id JOIN events e ON e.id = o.event_id $whereSql");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** @return array{0: string, 1: array} */
function build_organizer_order_where(int $organizerId, array $filters): array
{
    $where = ['e.organizer_id = ?'];
    $params = [$organizerId];
    if (!empty($filters['status'])) {
        $where[] = 'o.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['event_id'])) {
        $where[] = 'o.event_id = ?';
        $params[] = (int) $filters['event_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR o.id = ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = is_numeric($filters['q']) ? (int) $filters['q'] : 0;
    }
    return ['WHERE ' . implode(' AND ', $where), $params];
}

function get_organizer_order_status_counts(int $organizerId): array
{
    $counts = array_fill_keys(['PENDING', 'PAID', 'FAILED', 'CANCELLED', 'REFUNDED'], 0);
    $stmt = db()->prepare('SELECT o.status, COUNT(*) AS n FROM orders o JOIN events e ON e.id = o.event_id WHERE e.organizer_id = ? GROUP BY o.status');
    $stmt->execute([$organizerId]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    return $counts;
}

function get_order_detail_for_organizer(int $organizerId, int $orderId): ?array
{
    $stmt = db()->prepare('
        SELECT o.*, u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone, e.title AS event_title, e.id AS event_id
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        WHERE o.id = ? AND e.organizer_id = ?
    ');
    $stmt->execute([$orderId, $organizerId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }
    $stmt = db()->prepare('SELECT oi.*, tt.name AS tier_name FROM order_items oi JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE oi.order_id = ?');
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT t.* FROM tickets t JOIN order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = ?');
    $stmt->execute([$orderId]);
    $order['tickets'] = $stmt->fetchAll();

    return $order;
}

/** @param array{q?: string, status?: string, event_id?: int} $filters One row per ticket = one attendee. */
function get_attendees_for_organizer(int $organizerId, array $filters = [], int $limit = 30, int $offset = 0): array
{
    [$whereSql, $params] = build_organizer_attendee_where($organizerId, $filters);
    $stmt = db()->prepare("
        SELECT t.*, tt.name AS tier_name, e.title AS event_title, e.id AS event_id,
            u.name AS attendee_name, u.email AS attendee_email, u.phone AS attendee_phone, o.id AS order_id, o.status AS payment_status, o.created_at AS purchased_at
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = tt.event_id
        JOIN orders o ON o.id = oi.order_id
        JOIN users u ON u.id = o.user_id
        $whereSql
        ORDER BY o.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function count_attendees_for_organizer(int $organizerId, array $filters = []): int
{
    [$whereSql, $params] = build_organizer_attendee_where($organizerId, $filters);
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = tt.event_id
        JOIN orders o ON o.id = oi.order_id
        JOIN users u ON u.id = o.user_id
        $whereSql
    ");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** @return array{0: string, 1: array} */
function build_organizer_attendee_where(int $organizerId, array $filters): array
{
    $where = ['e.organizer_id = ?'];
    $params = [$organizerId];
    if (!empty($filters['status'])) {
        $where[] = 't.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['event_id'])) {
        $where[] = 'e.id = ?';
        $params[] = (int) $filters['event_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR t.ticket_code LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    return ['WHERE ' . implode(' AND ', $where), $params];
}

function get_organizer_attendee_counts(int $organizerId): array
{
    $counts = array_fill_keys(['VALID', 'USED', 'CANCELLED'], 0);
    $stmt = db()->prepare('
        SELECT t.status, COUNT(*) AS n
        FROM tickets t JOIN order_items oi ON oi.id = t.order_item_id JOIN ticket_types tt ON tt.id = oi.ticket_type_id JOIN events e ON e.id = tt.event_id
        WHERE e.organizer_id = ?
        GROUP BY t.status
    ');
    $stmt->execute([$organizerId]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    return $counts;
}

// =====================================================================
// Event lifecycle actions from the My Events list (create/edit already
// exist as full pages — these are the smaller list-row actions)
// =====================================================================

/**
 * Organizer-initiated status changes only ever move between DRAFT,
 * PUBLISHED and CANCELLED — matching exactly what event-edit.php's own form
 * already lets an organizer choose. PENDING_REVIEW/SUSPENDED/REJECTED are
 * admin-only states set from the admin dashboard, never from here.
 * @return array{0: bool, 1: ?string}
 */
function set_event_status_by_organizer(int $organizerId, int $actorId, int $eventId, string $newStatus): array
{
    if (!in_array($newStatus, ['DRAFT', 'PUBLISHED', 'CANCELLED'], true)) {
        return [false, 'Invalid status.'];
    }
    $stmt = db()->prepare('SELECT status FROM events WHERE id = ? AND organizer_id = ?');
    $stmt->execute([$eventId, $organizerId]);
    $event = $stmt->fetch();
    if (!$event) {
        return [false, 'Event not found.'];
    }
    if (!in_array($event['status'], ['DRAFT', 'PUBLISHED', 'CANCELLED'], true)) {
        return [false, 'This event is under admin review — its status can only be changed from the admin dashboard.'];
    }
    db()->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$newStatus, $eventId]);
    log_organizer_action($organizerId, $actorId, 'event.status_change', 'event', $eventId, ['status' => $newStatus]);

    return [true, null];
}

/** @return array{0: bool, 1: ?string} */
function delete_event_for_organizer(int $organizerId, int $actorId, int $eventId): array
{
    $stmt = db()->prepare('SELECT status FROM events WHERE id = ? AND organizer_id = ?');
    $stmt->execute([$eventId, $organizerId]);
    $event = $stmt->fetch();
    if (!$event) {
        return [false, 'Event not found.'];
    }
    if ($event['status'] !== 'DRAFT') {
        return [false, 'Only draft events can be deleted — cancel a published event instead.'];
    }
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity_sold), 0) FROM ticket_types WHERE event_id = ?');
    $stmt->execute([$eventId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return [false, 'This event already has ticket sales and cannot be deleted.'];
    }
    db()->prepare('DELETE FROM ticket_types WHERE event_id = ?')->execute([$eventId]);
    db()->prepare('DELETE FROM event_media WHERE event_id = ?')->execute([$eventId]);
    db()->prepare('DELETE FROM events WHERE id = ?')->execute([$eventId]);
    log_organizer_action($organizerId, $actorId, 'event.delete', 'event', $eventId);

    return [true, null];
}

/** @return array{0: bool, 1: int|string|null} [success, newEventId-or-error] */
function duplicate_event_for_organizer(int $organizerId, int $actorId, int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND organizer_id = ?');
    $stmt->execute([$eventId, $organizerId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        return [false, 'Event not found.'];
    }
    $tiers = array_map(static fn ($t) => [
        'name' => $t['name'], 'description' => $t['description'] ?? '', 'price' => $t['price'],
        'currency' => $t['currency'], 'quantity_total' => $t['quantity_total'],
    ], get_ticket_types_for_event($eventId));

    $newId = create_event($organizerId, [
        'title' => $existing['title'] . ' (copy)', 'category' => $existing['category'], 'description' => $existing['description'],
        'venue_name' => $existing['venue_name'], 'venue_address' => $existing['venue_address'], 'banner_emoji' => $existing['banner_emoji'],
        'banner_image' => $existing['banner_image'], 'starts_at' => $existing['starts_at'], 'ends_at' => $existing['ends_at'], 'status' => 'DRAFT',
    ], $tiers);
    log_organizer_action($organizerId, $actorId, 'event.duplicate', 'event', $newId, ['from' => $eventId]);

    return [true, $newId];
}

// =====================================================================
// Ticket types (cross-event overview)
// =====================================================================

function get_ticket_types_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare('
        SELECT tt.*, e.title AS event_title, e.id AS event_id, e.status AS event_status,
            COALESCE((SELECT SUM(oi.quantity * oi.unit_price) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.ticket_type_id = tt.id AND o.status = "PAID"), 0) AS revenue
        FROM ticket_types tt
        JOIN events e ON e.id = tt.event_id
        WHERE e.organizer_id = ?
        ORDER BY e.starts_at DESC, tt.sort_order ASC
    ');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function set_ticket_type_paused(int $organizerId, int $actorId, int $tierId, bool $paused): array
{
    $stmt = db()->prepare('SELECT tt.id FROM ticket_types tt JOIN events e ON e.id = tt.event_id WHERE tt.id = ? AND e.organizer_id = ?');
    $stmt->execute([$tierId, $organizerId]);
    if (!$stmt->fetch()) {
        return [false, 'Ticket type not found.'];
    }
    db()->prepare('UPDATE ticket_types SET sales_paused = ? WHERE id = ?')->execute([$paused ? 1 : 0, $tierId]);
    log_organizer_action($organizerId, $actorId, $paused ? 'ticket.pause_sales' : 'ticket.resume_sales', 'ticket_type', $tierId);

    return [true, null];
}

// =====================================================================
// Sales, earnings & transactions
// =====================================================================

function get_organizer_sales_summary(int $organizerId, int $days = 30): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS gross,
            (SELECT COALESCE(SUM(t.status != 'CANCELLED'), 0) FROM tickets t JOIN order_items oi ON oi.id = t.order_item_id JOIN ticket_types tt ON tt.id = oi.ticket_type_id JOIN events e2 ON e2.id = tt.event_id JOIN orders o2 ON o2.id = oi.order_id WHERE e2.organizer_id = ? AND o2.status = 'PAID' AND o2.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS tickets_sold
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE e.organizer_id = ? AND o.status = 'PAID' AND o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$organizerId, $days, $organizerId, $days]);
    $summary = $stmt->fetch();
    $summary['gross'] = (float) $summary['gross'];
    $summary['orders'] = (int) $summary['orders'];
    $summary['tickets_sold'] = (int) $summary['tickets_sold'];
    $summary['aov'] = $summary['orders'] > 0 ? $summary['gross'] / $summary['orders'] : 0.0;

    $stmt = $pdo->prepare("
        SELECT e.id, e.title, COUNT(o.id) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue
        FROM events e LEFT JOIN orders o ON o.event_id = e.id AND o.status = 'PAID' AND o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        WHERE e.organizer_id = ?
        GROUP BY e.id
        HAVING revenue > 0
        ORDER BY revenue DESC
    ");
    $stmt->execute([$days, $organizerId]);
    $summary['by_event'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT tt.name, tt.event_id, e.title AS event_title, COALESCE(SUM(oi.quantity), 0) AS sold, COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
        FROM ticket_types tt
        JOIN events e ON e.id = tt.event_id
        LEFT JOIN order_items oi ON oi.ticket_type_id = tt.id
        LEFT JOIN orders o ON o.id = oi.order_id AND o.status = 'PAID' AND o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        WHERE e.organizer_id = ?
        GROUP BY tt.id
        HAVING revenue > 0
        ORDER BY revenue DESC
    ");
    $stmt->execute([$days, $organizerId]);
    $summary['by_tier'] = $stmt->fetchAll();

    return $summary;
}

function get_organizer_earnings_breakdown(int $organizerId): array
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(o.subtotal_amount), 0) AS gross, COALESCE(SUM(o.commission_amount), 0) AS platform_fees,
            COALESCE(SUM(o.service_fee_amount), 0) AS service_fees,
            (SELECT COALESCE(SUM(refund_amount), 0) FROM orders o2 JOIN events e2 ON e2.id = o2.event_id WHERE e2.organizer_id = ? AND o2.status = 'REFUNDED') AS refunds
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE e.organizer_id = ? AND o.status = 'PAID'
    ");
    $stmt->execute([$organizerId, $organizerId]);
    $row = $stmt->fetch();
    $balance = get_organizer_balance($organizerId);

    return [
        'gross' => (float) $row['gross'],
        'platform_fees' => (float) $row['platform_fees'],
        'refunds' => (float) $row['refunds'],
        'net_earnings' => (float) $row['gross'] - (float) $row['platform_fees'],
        'available_balance' => array_sum(array_column($balance, 'available')),
        'pending_balance' => array_sum(array_column($balance, 'pending')),
        'total_paid_out' => array_sum(array_column($balance, 'paid_out')),
        'currency' => array_key_first($balance) ?: 'UGX',
    ];
}

/** History of an organizer's own withdrawal requests (payments.php's get_pending_payouts_for_organizer() covers only open ones). */
function get_payouts_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare('SELECT * FROM payouts WHERE organizer_id = ? ORDER BY requested_at DESC');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

// =====================================================================
// Refund requests (organizer requests → admin approves)
// =====================================================================

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function request_refund(int $organizerId, int $orderId, float $amount, string $reason): array
{
    $stmt = db()->prepare('SELECT o.status, o.total_amount FROM orders o JOIN events e ON e.id = o.event_id WHERE o.id = ? AND e.organizer_id = ?');
    $stmt->execute([$orderId, $organizerId]);
    $order = $stmt->fetch();
    if (!$order) {
        return [false, 'Order not found.'];
    }
    if ($order['status'] !== 'PAID') {
        return [false, 'Only paid orders can be refunded.'];
    }
    if ($amount <= 0 || $amount > (float) $order['total_amount']) {
        return [false, 'Refund amount must be between 0 and the order total.'];
    }
    $stmt = db()->prepare("SELECT id FROM refund_requests WHERE order_id = ? AND status IN ('REQUESTED','APPROVED')");
    $stmt->execute([$orderId]);
    if ($stmt->fetch()) {
        return [false, 'A refund request for this order is already open.'];
    }
    if (trim($reason) === '') {
        return [false, 'Enter a reason for the refund.'];
    }

    db()->prepare('INSERT INTO refund_requests (order_id, organizer_id, amount, reason) VALUES (?, ?, ?, ?)')
        ->execute([$orderId, $organizerId, $amount, trim($reason)]);
    $id = (int) db()->lastInsertId();
    log_organizer_action($organizerId, $organizerId, 'refund.request', 'order', $orderId, ['amount' => $amount]);

    return [true, null];
}

function get_refund_requests_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare('
        SELECT rr.*, o.total_amount, u.name AS buyer_name, e.title AS event_title
        FROM refund_requests rr
        JOIN orders o ON o.id = rr.order_id
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        WHERE rr.organizer_id = ?
        ORDER BY rr.requested_at DESC
    ');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

function get_refund_requests_admin(string $status = 'REQUESTED'): array
{
    $where = $status !== '' ? 'WHERE rr.status = ?' : '';
    $stmt = db()->prepare("
        SELECT rr.*, o.total_amount, o.currency, u.name AS buyer_name, e.title AS event_title, org.name AS organizer_name
        FROM refund_requests rr
        JOIN orders o ON o.id = rr.order_id
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        JOIN users org ON org.id = rr.organizer_id
        $where
        ORDER BY rr.requested_at DESC
    ");
    $stmt->execute($status !== '' ? [$status] : []);
    return $stmt->fetchAll();
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function approve_refund_request(int $adminId, int $requestId): array
{
    $stmt = db()->prepare("SELECT * FROM refund_requests WHERE id = ? AND status = 'REQUESTED'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return [false, 'Refund request not found or already handled.'];
    }
    [$ok, $err] = process_refund($adminId, (int) $request['order_id'], (float) $request['amount'], (string) $request['reason']);
    if (!$ok) {
        return [false, $err];
    }
    db()->prepare("UPDATE refund_requests SET status = 'COMPLETED', processed_at = NOW(), processed_by = ? WHERE id = ?")->execute([$adminId, $requestId]);
    create_notification((int) $request['organizer_id'], 'refund', 'Refund completed', 'Your refund request for order #' . $request['order_id'] . ' has been processed.', '/org-refunds.php');

    return [true, null];
}

function reject_refund_request(int $adminId, int $requestId): void
{
    $stmt = db()->prepare("SELECT organizer_id, order_id FROM refund_requests WHERE id = ? AND status = 'REQUESTED'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return;
    }
    db()->prepare("UPDATE refund_requests SET status = 'REJECTED', processed_at = NOW(), processed_by = ? WHERE id = ?")
        ->execute([$adminId, $requestId]);
    create_notification((int) $request['organizer_id'], 'refund', 'Refund request rejected', 'Your refund request for order #' . $request['order_id'] . ' was rejected.', '/org-refunds.php');
}

// =====================================================================
// Promo codes (organizer-scoped — always tied to one of their own events)
// =====================================================================

function get_promo_codes_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare('
        SELECT p.*, e.title AS event_title
        FROM promo_codes p
        JOIN events e ON e.id = p.event_id
        WHERE e.organizer_id = ?
        ORDER BY p.created_at DESC
    ');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function create_promo_code_for_organizer(int $organizerId, int $actorId, array $data): array
{
    $eventId = (int) ($data['event_id'] ?? 0);
    $stmt = db()->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ?');
    $stmt->execute([$eventId, $organizerId]);
    if (!$stmt->fetch()) {
        return [false, 'Choose one of your own events.'];
    }
    $code = strtoupper(trim($data['code'] ?? ''));
    if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
        return [false, 'Code must be 3–32 letters, numbers, - or _.'];
    }
    $stmt = db()->prepare('SELECT id FROM promo_codes WHERE code = ?');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        return [false, 'That code already exists.'];
    }
    $type = ($data['discount_type'] ?? '') === 'FIXED' ? 'FIXED' : 'PERCENT';
    $value = (float) ($data['discount_value'] ?? 0);
    if ($value <= 0 || ($type === 'PERCENT' && $value > 100)) {
        return [false, 'Enter a valid discount value.'];
    }

    db()->prepare('
        INSERT INTO promo_codes (code, event_id, discount_type, discount_value, min_order_amount, max_uses, starts_at, ends_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $code, $eventId, $type, $value,
        ($data['min_order_amount'] ?? '') !== '' ? (float) $data['min_order_amount'] : null,
        ($data['max_uses'] ?? '') !== '' ? (int) $data['max_uses'] : null,
        $data['starts_at'] ?: null,
        $data['ends_at'] ?: null,
        $actorId,
    ]);
    $id = (int) db()->lastInsertId();
    log_organizer_action($organizerId, $actorId, 'promo.create', 'promo_code', $id, ['code' => $code]);

    return [true, null];
}

/** @return array{0: bool, 1: ?string} */
function set_promo_code_active_for_organizer(int $organizerId, int $actorId, int $id, bool $active): array
{
    $stmt = db()->prepare('SELECT p.id FROM promo_codes p JOIN events e ON e.id = p.event_id WHERE p.id = ? AND e.organizer_id = ?');
    $stmt->execute([$id, $organizerId]);
    if (!$stmt->fetch()) {
        return [false, 'Promo code not found.'];
    }
    db()->prepare('UPDATE promo_codes SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    log_organizer_action($organizerId, $actorId, $active ? 'promo.activate' : 'promo.deactivate', 'promo_code', $id);

    return [true, null];
}

/** @return array{0: bool, 1: ?string} */
function delete_promo_code_for_organizer(int $organizerId, int $actorId, int $id): array
{
    $stmt = db()->prepare('SELECT p.id FROM promo_codes p JOIN events e ON e.id = p.event_id WHERE p.id = ? AND e.organizer_id = ?');
    $stmt->execute([$id, $organizerId]);
    if (!$stmt->fetch()) {
        return [false, 'Promo code not found.'];
    }
    db()->prepare('DELETE FROM promo_codes WHERE id = ?')->execute([$id]);
    log_organizer_action($organizerId, $actorId, 'promo.delete', 'promo_code', $id);

    return [true, null];
}

// =====================================================================
// Analytics (real data only — no view/click tracking exists yet, so
// nothing here reports a metric that isn't actually measured)
// =====================================================================

function get_organizer_event_performance(int $organizerId): array
{
    $stmt = db()->prepare("
        SELECT e.id, e.title, e.status, e.starts_at,
            COALESCE((SELECT SUM(t.quantity_sold) FROM ticket_types t WHERE t.event_id = e.id), 0) AS tickets_sold,
            COALESCE((SELECT SUM(t.quantity_total) FROM ticket_types t WHERE t.event_id = e.id), 0) AS capacity,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.event_id = e.id AND o.status = 'PAID'), 0) AS revenue,
            (SELECT COUNT(*) FROM tickets tk JOIN order_items oi ON oi.id = tk.order_item_id JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE tt.event_id = e.id AND tk.status != 'CANCELLED') AS attendance_pool,
            (SELECT COUNT(*) FROM tickets tk JOIN order_items oi ON oi.id = tk.order_item_id JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE tt.event_id = e.id AND tk.status = 'USED') AS checked_in,
            (SELECT COUNT(*) FROM orders o WHERE o.event_id = e.id AND o.status = 'REFUNDED') AS refunded_orders,
            (SELECT COUNT(*) FROM orders o WHERE o.event_id = e.id AND o.status IN ('PAID','REFUNDED')) AS total_orders
        FROM events e
        WHERE e.organizer_id = ?
        ORDER BY e.starts_at DESC
    ");
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

function get_organizer_customer_stats(int $organizerId, int $days = 30): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.user_id) FROM orders o JOIN events e ON e.id = o.event_id WHERE e.organizer_id = ? AND o.status = 'PAID'");
    $stmt->execute([$organizerId]);
    $total = (int) $stmt->fetchColumn();

    // "New" = their first-ever paid order with this organizer fell inside the window.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT o.user_id, MIN(o.paid_at) AS first_paid
            FROM orders o JOIN events e ON e.id = o.event_id
            WHERE e.organizer_id = ? AND o.status = 'PAID'
            GROUP BY o.user_id
            HAVING first_paid >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ) x
    ");
    $stmt->execute([$organizerId, $days]);
    $new = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(AVG(x.spend), 0) FROM (SELECT o.user_id, SUM(o.total_amount) AS spend FROM orders o JOIN events e ON e.id = o.event_id WHERE e.organizer_id = ? AND o.status = 'PAID' GROUP BY o.user_id) x");
    $stmt->execute([$organizerId]);
    $avgSpend = (float) $stmt->fetchColumn();

    return ['total_customers' => $total, 'new_customers' => $new, 'returning_customers' => max(0, $total - $new), 'average_spend' => $avgSpend];
}

// =====================================================================
// Support tickets (reuses contact_messages — organizer_id set = a support
// ticket, NULL = a public "contact us" message; same admin inbox handles both)
// =====================================================================

function create_organizer_support_ticket(int $organizerId, string $name, string $email, string $subject, string $message, string $priority = 'MEDIUM'): int
{
    $priority = in_array($priority, ['LOW', 'MEDIUM', 'HIGH'], true) ? $priority : 'MEDIUM';
    $stmt = db()->prepare('INSERT INTO contact_messages (organizer_id, name, email, topic, priority, message) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$organizerId, $name, $email, $subject !== '' ? $subject : 'Organizer support', $priority, $message]);
    $id = (int) db()->lastInsertId();
    log_organizer_action($organizerId, $organizerId, 'support.create', 'contact_message', $id);

    return $id;
}

function get_support_tickets_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare('SELECT * FROM contact_messages WHERE organizer_id = ? ORDER BY created_at DESC');
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

// =====================================================================
// Settings
// =====================================================================

function update_organizer_profile(int $userId, array $fields): void
{
    db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')
        ->execute([$fields['name'], $fields['phone'] ?: null, $userId]);
    db()->prepare('UPDATE organizer_profiles SET org_name = ?, bio = ?, payout_provider = ?, payout_phone = ? WHERE user_id = ?')
        ->execute([$fields['org_name'], $fields['bio'] ?: null, $fields['payout_provider'], $fields['payout_phone'] ?: null, $userId]);
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function change_own_password(int $userId, string $currentPassword, string $newPassword): array
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($currentPassword, $hash)) {
        return [false, 'Your current password is incorrect.'];
    }
    if (strlen($newPassword) < 8) {
        return [false, 'New password must be at least 8 characters.'];
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

    return [true, null];
}
