<?php
declare(strict_types=1);

// =====================================================================
// Roles & permissions
// =====================================================================

const ADMIN_ROLES = [
    'SUPER_ADMIN' => 'Super Admin',
    'FINANCE_ADMIN' => 'Finance Admin',
    'EVENT_MANAGER' => 'Event Manager',
    'SUPPORT_AGENT' => 'Support Agent',
    'MARKETING_MANAGER' => 'Marketing Manager',
    'CHECKIN_STAFF' => 'Check-In Staff',
];

// A NULL admin_role (every admin account that existed before this system was
// added) behaves exactly like SUPER_ADMIN — full access — so nobody is
// locked out by this migration.
const ADMIN_PERMISSIONS = [
    'FINANCE_ADMIN' => ['dashboard.view', 'orders.view', 'orders.refund', 'payouts.view', 'payouts.process', 'promo.view', 'promo.manage', 'settings.view'],
    'EVENT_MANAGER' => ['dashboard.view', 'events.view', 'events.manage', 'categories.manage', 'organizers.view', 'organizers.verify'],
    'SUPPORT_AGENT' => ['dashboard.view', 'customers.view', 'customers.manage', 'orders.view', 'tickets.view', 'contact.view', 'contact.manage'],
    'MARKETING_MANAGER' => ['dashboard.view', 'promo.view', 'promo.manage', 'events.view'],
    'CHECKIN_STAFF' => ['dashboard.view', 'tickets.view', 'tickets.checkin'],
];

function admin_can(string $permission): bool
{
    $user = current_user();
    if (!$user || $user['role'] !== 'ADMIN') {
        return false;
    }
    $role = $user['admin_role'] ?? null;
    if ($role === null || $role === 'SUPER_ADMIN') {
        return true;
    }
    return in_array($permission, ADMIN_PERMISSIONS[$role] ?? [], true);
}

function require_admin_permission(string $permission): array
{
    $user = require_role('ADMIN');
    if (!admin_can($permission)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
    return $user;
}

// =====================================================================
// Audit log — every sensitive admin mutation writes one row here. Never
// updated or deleted by application code.
// =====================================================================

function log_admin_action(int $adminId, string $action, string $entityType, ?int $entityId = null, ?array $details = null): void
{
    db()->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$adminId, $action, $entityType, $entityId, $details ? json_encode($details) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function get_audit_logs(int $limit = 100, int $offset = 0): array
{
    $stmt = db()->prepare('
        SELECT al.*, u.name AS admin_name
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.admin_id
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function count_audit_logs(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
}

function get_recent_activity(int $limit = 12): array
{
    return get_audit_logs($limit, 0);
}

// =====================================================================
// Dashboard
// =====================================================================

function get_platform_stats(): array
{
    $pdo = db();
    $stats = [];
    $stats['total_users'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['total_customers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ATTENDEE'")->fetchColumn();
    $stats['total_organizers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ORGANIZER'")->fetchColumn();
    $stats['total_events'] = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $stats['published_events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'PUBLISHED'")->fetchColumn();
    $stats['pending_events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'PENDING_REVIEW'")->fetchColumn();
    $stats['upcoming_events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'PUBLISHED' AND starts_at > NOW()")->fetchColumn();
    $stats['total_orders'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'PAID'")->fetchColumn();
    $stats['total_tickets'] = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'CANCELLED'")->fetchColumn();
    $stats['checked_in'] = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'USED'")->fetchColumn();
    $stats['pending_payouts'] = (int) $pdo->query("SELECT COUNT(*) FROM payouts WHERE status IN ('PENDING','APPROVED','PROCESSING')")->fetchColumn();
    $stats['refunded_orders'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'REFUNDED'")->fetchColumn();
    $stats['new_contact_messages'] = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'NEW'")->fetchColumn();
    $stats['revenue_by_currency'] = $pdo->query("
        SELECT currency, SUM(total_amount) AS total FROM orders WHERE status = 'PAID' GROUP BY currency ORDER BY total DESC
    ")->fetchAll();
    $stats['commission_by_currency'] = $pdo->query("
        SELECT currency, SUM(commission_amount) AS total FROM orders WHERE status = 'PAID' GROUP BY currency ORDER BY total DESC
    ")->fetchAll();
    $stats['service_fees_by_currency'] = $pdo->query("
        SELECT currency, SUM(service_fee_amount) AS total FROM orders WHERE status = 'PAID' GROUP BY currency ORDER BY total DESC
    ")->fetchAll();
    return $stats;
}

/** Revenue for the last $days days, one row per day, for the dashboard sparkline. */
function get_daily_revenue(int $days = 14): array
{
    $stmt = db()->prepare("
        SELECT DATE(paid_at) AS day, SUM(total_amount) AS total, COUNT(*) AS orders
        FROM orders
        WHERE status = 'PAID' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(paid_at)
        ORDER BY day
    ");
    $stmt->execute([$days]);
    $rows = array_column($stmt->fetchAll(), null, 'day');

    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['day' => $day, 'total' => (float) ($rows[$day]['total'] ?? 0), 'orders' => (int) ($rows[$day]['orders'] ?? 0)];
    }
    return $out;
}

function get_upcoming_events_admin(int $limit = 6): array
{
    $stmt = db()->prepare("
        SELECT e.*, u.name AS organizer_name,
            (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_sold,
            (SELECT COALESCE(SUM(t.quantity_total), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_total,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.event_id = e.id AND o.status = 'PAID') AS revenue
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        WHERE e.status = 'PUBLISHED' AND e.starts_at > NOW()
        ORDER BY e.starts_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// =====================================================================
// Events
// =====================================================================

const EVENT_ADMIN_STATUSES = ['DRAFT', 'PENDING_REVIEW', 'PUBLISHED', 'SUSPENDED', 'REJECTED', 'CANCELLED'];

/**
 * @param array{status?: string, category?: string, q?: string} $filters
 */
function get_events_admin(array $filters = [], int $limit = 50, int $offset = 0): array
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'e.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['category'])) {
        $where[] = 'e.category = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(e.title LIKE ? OR u.name LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = db()->prepare("
        SELECT e.*, u.name AS organizer_name,
            (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_sold,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.event_id = e.id AND o.status = 'PAID') AS revenue
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        $whereSql
        ORDER BY e.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function count_events_admin(array $filters = []): int
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'e.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['category'])) {
        $where[] = 'e.category = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(e.title LIKE ? OR u.name LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT COUNT(*) FROM events e JOIN users u ON u.id = e.organizer_id $whereSql");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function get_event_admin_detail(int $eventId): ?array
{
    $stmt = db()->prepare('
        SELECT e.*, u.name AS organizer_name, u.email AS organizer_email, u.id AS organizer_user_id
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        WHERE e.id = ?
    ');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM ticket_types WHERE event_id = ? ORDER BY sort_order, id');
    $stmt->execute([$eventId]);
    $event['ticket_types'] = $stmt->fetchAll();

    $stmt = db()->prepare("
        SELECT
            COALESCE(SUM(o.subtotal_amount), 0) AS gross,
            COALESCE(SUM(o.commission_amount), 0) AS commission,
            COALESCE(SUM(o.service_fee_amount), 0) AS fees,
            COALESCE(SUM(CASE WHEN o.refunded_at IS NOT NULL THEN o.refund_amount ELSE 0 END), 0) AS refunded,
            COUNT(*) AS paid_orders
        FROM orders o WHERE o.event_id = ? AND o.status = 'PAID'
    ");
    $stmt->execute([$eventId]);
    $event['financials'] = $stmt->fetch();

    $stmt = db()->prepare("
        SELECT COUNT(*) AS total, SUM(t.status = 'USED') AS checked_in
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        WHERE tt.event_id = ? AND t.status != 'CANCELLED'
    ");
    $stmt->execute([$eventId]);
    $event['attendance'] = $stmt->fetch();

    $stmt = db()->prepare("SELECT * FROM audit_logs WHERE entity_type = 'event' AND entity_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$eventId]);
    $event['activity'] = $stmt->fetchAll();

    return $event;
}

function update_event_status_admin(int $adminId, int $eventId, string $status, ?string $rejectionReason = null): void
{
    $status = in_array($status, EVENT_ADMIN_STATUSES, true) ? $status : 'DRAFT';
    $stmt = db()->prepare('SELECT status FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $oldStatus = $stmt->fetchColumn();

    db()->prepare('UPDATE events SET status = ?, rejection_reason = ? WHERE id = ?')
        ->execute([$status, $status === 'REJECTED' ? $rejectionReason : null, $eventId]);

    log_admin_action($adminId, 'event.status_change', 'event', $eventId, ['from' => $oldStatus, 'to' => $status, 'reason' => $rejectionReason]);
}

function set_event_featured(int $adminId, int $eventId, bool $featured, ?string $from = null, ?string $until = null): void
{
    db()->prepare('UPDATE events SET featured = ?, featured_from = ?, featured_until = ? WHERE id = ?')
        ->execute([$featured ? 1 : 0, $from ?: null, $until ?: null, $eventId]);
    log_admin_action($adminId, $featured ? 'event.feature' : 'event.unfeature', 'event', $eventId);
}

function get_featured_events(int $limit = 6): array
{
    $stmt = db()->prepare("
        SELECT e.* FROM events e
        WHERE e.featured = 1 AND e.status = 'PUBLISHED'
          AND (e.featured_from IS NULL OR e.featured_from <= CURDATE())
          AND (e.featured_until IS NULL OR e.featured_until >= CURDATE())
        ORDER BY e.starts_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// =====================================================================
// Organizers
// =====================================================================

function get_organizers_admin(): array
{
    return db()->query("
        SELECT u.id, u.name, u.email, u.phone, u.account_status, u.created_at,
            p.org_name, p.verification_status, p.verification_notes, p.payout_provider, p.payout_phone,
            (SELECT COUNT(*) FROM events e WHERE e.organizer_id = u.id) AS event_count,
            (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t JOIN events e ON e.id = t.event_id WHERE e.organizer_id = u.id) AS tickets_sold,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o JOIN events e ON e.id = o.event_id WHERE e.organizer_id = u.id AND o.status = 'PAID') AS revenue
        FROM users u
        LEFT JOIN organizer_profiles p ON p.user_id = u.id
        WHERE u.role = 'ORGANIZER'
        ORDER BY u.created_at DESC
    ")->fetchAll();
}

function get_organizer_admin_detail(int $userId): ?array
{
    $stmt = db()->prepare("
        SELECT u.*, p.org_name, p.bio, p.payout_phone, p.payout_provider, p.verification_status, p.verification_notes, p.verified_at
        FROM users u
        LEFT JOIN organizer_profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.role = 'ORGANIZER'
    ");
    $stmt->execute([$userId]);
    $org = $stmt->fetch();
    if (!$org) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT e.*, (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_sold
        FROM events e WHERE e.organizer_id = ? ORDER BY e.created_at DESC
    ");
    $stmt->execute([$userId]);
    $org['events'] = $stmt->fetchAll();

    $stmt = db()->prepare("
        SELECT o.*, ev.title AS event_title
        FROM orders o JOIN events ev ON ev.id = o.event_id
        WHERE ev.organizer_id = ? ORDER BY o.created_at DESC LIMIT 50
    ");
    $stmt->execute([$userId]);
    $org['orders'] = $stmt->fetchAll();

    $org['balance'] = get_organizer_balance($userId);

    $stmt = db()->prepare('SELECT * FROM payouts WHERE organizer_id = ? ORDER BY requested_at DESC');
    $stmt->execute([$userId]);
    $org['payouts'] = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT al.*, u.name AS admin_name FROM audit_logs al LEFT JOIN users u ON u.id = al.admin_id WHERE al.entity_type = 'organizer' AND al.entity_id = ? ORDER BY al.created_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    $org['activity'] = $stmt->fetchAll();

    return $org;
}

/**
 * Net earnings by currency: paid orders minus commission, excluding any
 * order that has been refunded, minus payouts already PAID — and minus any
 * payout that's PENDING/APPROVED/PROCESSING, so an organizer (or admin) can
 * never allocate the same money to two overlapping withdrawal requests.
 * @return array<string, array{lifetime: float, paid_out: float, pending: float, available: float}>
 */
function get_organizer_balance(int $organizerId): array
{
    $stmt = db()->prepare("
        SELECT o.currency, COALESCE(SUM(o.subtotal_amount - o.commission_amount), 0) AS lifetime
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE e.organizer_id = ? AND o.status = 'PAID' AND o.refunded_at IS NULL
        GROUP BY o.currency
    ");
    $stmt->execute([$organizerId]);
    $balances = [];
    foreach ($stmt->fetchAll() as $row) {
        $balances[$row['currency']] = ['lifetime' => (float) $row['lifetime'], 'paid_out' => 0.0, 'pending' => 0.0, 'available' => (float) $row['lifetime']];
    }

    $stmt = db()->prepare("SELECT currency, COALESCE(SUM(amount), 0) AS paid FROM payouts WHERE organizer_id = ? AND status = 'PAID' GROUP BY currency");
    $stmt->execute([$organizerId]);
    foreach ($stmt->fetchAll() as $row) {
        $cur = $row['currency'];
        if (!isset($balances[$cur])) {
            $balances[$cur] = ['lifetime' => 0.0, 'paid_out' => 0.0, 'pending' => 0.0, 'available' => 0.0];
        }
        $balances[$cur]['paid_out'] = (float) $row['paid'];
        $balances[$cur]['available'] -= (float) $row['paid'];
    }

    $stmt = db()->prepare("SELECT currency, COALESCE(SUM(amount), 0) AS pending FROM payouts WHERE organizer_id = ? AND status IN ('PENDING','APPROVED','PROCESSING') GROUP BY currency");
    $stmt->execute([$organizerId]);
    foreach ($stmt->fetchAll() as $row) {
        $cur = $row['currency'];
        if (!isset($balances[$cur])) {
            $balances[$cur] = ['lifetime' => 0.0, 'paid_out' => 0.0, 'pending' => 0.0, 'available' => 0.0];
        }
        $balances[$cur]['pending'] = (float) $row['pending'];
        $balances[$cur]['available'] -= (float) $row['pending'];
    }

    return $balances;
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function request_withdrawal(int $organizerId, float $amount, string $method, string $destination, ?string $notes = null): array
{
    $destination = trim($destination);
    if ($amount <= 0) {
        return [false, 'Enter an amount greater than zero.'];
    }
    if ($destination === '') {
        return [false, 'Enter a phone number or account to receive the payout.'];
    }
    $balance = get_organizer_balance($organizerId);
    $available = array_sum(array_column($balance, 'available'));
    if ($amount > $available) {
        return [false, "That's more than your available balance."];
    }

    db()->prepare('INSERT INTO payouts (organizer_id, amount, currency, method, destination, notes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$organizerId, $amount, 'UGX', $method, $destination, $notes]);
    $payoutId = (int) db()->lastInsertId();
    log_admin_action($organizerId, 'payout.request', 'payout', $payoutId, ['amount' => $amount]);

    return [true, null];
}

/** The organizer's own outstanding (not yet paid/failed/rejected) withdrawal requests. */
function get_pending_payouts_for_organizer(int $organizerId): array
{
    $stmt = db()->prepare("SELECT * FROM payouts WHERE organizer_id = ? AND status IN ('PENDING','APPROVED','PROCESSING') ORDER BY requested_at DESC");
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}

function set_organizer_verification(int $adminId, int $organizerId, string $status, ?string $notes = null): void
{
    $status = in_array($status, ['UNVERIFIED', 'PENDING', 'VERIFIED', 'REJECTED'], true) ? $status : 'PENDING';
    db()->prepare('UPDATE organizer_profiles SET verification_status = ?, verification_notes = ?, verified = ?, verified_at = ? WHERE user_id = ?')
        ->execute([$status, $notes, $status === 'VERIFIED' ? 1 : 0, $status === 'VERIFIED' ? date('Y-m-d H:i:s') : null, $organizerId]);
    log_admin_action($adminId, 'organizer.verification', 'organizer', $organizerId, ['status' => $status, 'notes' => $notes]);
}

// =====================================================================
// Customers
// =====================================================================

function get_customers_admin(): array
{
    return db()->query("
        SELECT u.id, u.name, u.email, u.phone, u.account_status, u.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status = 'PAID') AS order_count,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = u.id AND o.status = 'PAID') AS total_spent
        FROM users u
        WHERE u.role = 'ATTENDEE'
        ORDER BY u.created_at DESC
    ")->fetchAll();
}

function get_customer_admin_detail(int $userId): ?array
{
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'ATTENDEE'");
    $stmt->execute([$userId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        return null;
    }

    $stmt = db()->prepare('
        SELECT o.*, e.title AS event_title
        FROM orders o JOIN events e ON e.id = o.event_id
        WHERE o.user_id = ? ORDER BY o.created_at DESC
    ');
    $stmt->execute([$userId]);
    $customer['orders'] = $stmt->fetchAll();

    $stmt = db()->prepare("
        SELECT t.*, tt.name AS tier_name, e.title AS event_title
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN orders o ON o.id = oi.order_id
        JOIN events e ON e.id = tt.event_id
        WHERE o.user_id = ? ORDER BY t.created_at DESC
    ");
    $stmt->execute([$userId]);
    $customer['tickets'] = $stmt->fetchAll();

    return $customer;
}

function set_user_account_status(int $adminId, int $userId, string $status): void
{
    $status = in_array($status, ['ACTIVE', 'SUSPENDED'], true) ? $status : 'ACTIVE';
    db()->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$status, $userId]);
    log_admin_action($adminId, $status === 'SUSPENDED' ? 'user.suspend' : 'user.reactivate', 'user', $userId);
}

// =====================================================================
// Tickets & check-in
// =====================================================================

function search_tickets_admin(string $q = '', int $limit = 100): array
{
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE t.ticket_code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR e.title LIKE ?';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
    }
    $stmt = db()->prepare("
        SELECT t.*, tt.name AS tier_name, e.title AS event_title, e.id AS event_id,
            u.name AS attendee_name, u.email AS attendee_email, o.id AS order_id
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = tt.event_id
        JOIN orders o ON o.id = oi.order_id
        JOIN users u ON u.id = o.user_id
        $where
        ORDER BY t.created_at DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_check_in(int $adminId, int $ticketId): void
{
    db()->prepare("UPDATE tickets SET status = 'USED', checked_in_at = NOW() WHERE id = ?")->execute([$ticketId]);
    log_admin_action($adminId, 'ticket.checkin', 'ticket', $ticketId);
}

function admin_reverse_checkin(int $adminId, int $ticketId): void
{
    db()->prepare("UPDATE tickets SET status = 'VALID', checked_in_at = NULL WHERE id = ?")->execute([$ticketId]);
    log_admin_action($adminId, 'ticket.reverse_checkin', 'ticket', $ticketId);
}

function admin_cancel_ticket(int $adminId, int $ticketId): void
{
    db()->prepare("UPDATE tickets SET status = 'CANCELLED' WHERE id = ?")->execute([$ticketId]);
    log_admin_action($adminId, 'ticket.cancel', 'ticket', $ticketId);
}

// =====================================================================
// Orders & refunds
// =====================================================================

/** @param array{status?: string, q?: string} $filters */
function get_orders_admin(array $filters = [], int $limit = 50, int $offset = 0): array
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'o.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR e.title LIKE ? OR o.id = ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = is_numeric($filters['q']) ? (int) $filters['q'] : 0;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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

function count_orders_admin(array $filters = []): int
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'o.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR e.title LIKE ? OR o.id = ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = is_numeric($filters['q']) ? (int) $filters['q'] : 0;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id JOIN events e ON e.id = o.event_id $whereSql");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function get_order_admin_detail(int $orderId): ?array
{
    $stmt = db()->prepare('
        SELECT o.*, u.name AS buyer_name, u.email AS buyer_email, u.id AS buyer_id,
            e.title AS event_title, e.id AS event_id, e.organizer_id
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $stmt = db()->prepare('
        SELECT oi.*, tt.name AS tier_name
        FROM order_items oi JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        WHERE oi.order_id = ?
    ');
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll();

    $stmt = db()->prepare('
        SELECT t.* FROM tickets t JOIN order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = ?
    ');
    $stmt->execute([$orderId]);
    $order['tickets'] = $stmt->fetchAll();

    return $order;
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function process_refund(int $adminId, int $orderId, float $amount, string $reason): array
{
    $stmt = db()->prepare("SELECT status, total_amount, refunded_at FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return [false, 'Order not found.'];
    }
    if ($order['status'] !== 'PAID') {
        return [false, 'Only paid orders can be refunded.'];
    }
    if ($order['refunded_at'] !== null) {
        return [false, 'This order has already been refunded.'];
    }
    if ($amount <= 0 || $amount > (float) $order['total_amount']) {
        return [false, 'Refund amount must be between 0 and the order total.'];
    }

    db()->prepare("UPDATE orders SET status = 'REFUNDED', refund_amount = ?, refund_reason = ?, refunded_at = NOW(), refunded_by = ? WHERE id = ?")
        ->execute([$amount, $reason, $adminId, $orderId]);
    log_admin_action($adminId, 'order.refund', 'order', $orderId, ['amount' => $amount, 'reason' => $reason]);

    return [true, null];
}

function get_refunds_admin(): array
{
    return db()->query("
        SELECT o.*, u.name AS buyer_name, e.title AS event_title, r.name AS refunded_by_name
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        LEFT JOIN users r ON r.id = o.refunded_by
        WHERE o.status = 'REFUNDED'
        ORDER BY o.refunded_at DESC
    ")->fetchAll();
}

// =====================================================================
// Payouts
// =====================================================================

const PAYOUT_STATUSES = ['PENDING', 'APPROVED', 'PROCESSING', 'PAID', 'FAILED', 'REJECTED'];

function get_payouts_admin(): array
{
    return db()->query('
        SELECT p.*, u.name AS organizer_name, u.email AS organizer_email, a.name AS processed_by_name
        FROM payouts p
        JOIN users u ON u.id = p.organizer_id
        LEFT JOIN users a ON a.id = p.processed_by
        ORDER BY p.requested_at DESC
    ')->fetchAll();
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function create_payout(int $adminId, int $organizerId, float $amount, string $method, string $destination, ?string $notes = null): array
{
    if ($amount <= 0) {
        return [false, 'Payout amount must be greater than zero.'];
    }
    $balance = get_organizer_balance($organizerId);
    $available = array_sum(array_column($balance, 'available'));
    if ($amount > $available) {
        return [false, 'That exceeds the organizer\'s available balance.'];
    }

    db()->prepare('INSERT INTO payouts (organizer_id, amount, currency, method, destination, notes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$organizerId, $amount, 'UGX', $method, $destination, $notes]);
    $payoutId = (int) db()->lastInsertId();
    log_admin_action($adminId, 'payout.create', 'payout', $payoutId, ['organizer_id' => $organizerId, 'amount' => $amount]);

    return [true, null];
}

function update_payout_status(int $adminId, int $payoutId, string $status): void
{
    $status = in_array($status, PAYOUT_STATUSES, true) ? $status : 'PENDING';
    $processedAt = in_array($status, ['PAID', 'FAILED', 'REJECTED'], true) ? date('Y-m-d H:i:s') : null;
    db()->prepare('UPDATE payouts SET status = ?, processed_at = ?, processed_by = ? WHERE id = ?')
        ->execute([$status, $processedAt, $adminId, $payoutId]);
    log_admin_action($adminId, 'payout.status_change', 'payout', $payoutId, ['status' => $status]);
}

// =====================================================================
// Promo codes
// =====================================================================

function get_promo_codes_admin(): array
{
    return db()->query('
        SELECT p.*, e.title AS event_title
        FROM promo_codes p
        LEFT JOIN events e ON e.id = p.event_id
        ORDER BY p.created_at DESC
    ')->fetchAll();
}

/** @return array{0: bool, 1: ?string} [success, errorMessage] */
function create_promo_code(int $adminId, array $data): array
{
    $code = strtoupper(trim($data['code'] ?? ''));
    if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
        return [false, 'Code must be 3–32 letters, numbers, - or _.'];
    }
    $stmt = db()->prepare('SELECT id FROM promo_codes WHERE code = ?');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        return [false, 'That code already exists.'];
    }
    $type = $data['discount_type'] === 'FIXED' ? 'FIXED' : 'PERCENT';
    $value = (float) ($data['discount_value'] ?? 0);
    if ($value <= 0 || ($type === 'PERCENT' && $value > 100)) {
        return [false, 'Enter a valid discount value.'];
    }

    db()->prepare('
        INSERT INTO promo_codes (code, event_id, discount_type, discount_value, min_order_amount, max_uses, starts_at, ends_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $code,
        $data['event_id'] ?: null,
        $type,
        $value,
        $data['min_order_amount'] !== '' ? (float) $data['min_order_amount'] : null,
        $data['max_uses'] !== '' ? (int) $data['max_uses'] : null,
        $data['starts_at'] ?: null,
        $data['ends_at'] ?: null,
        $adminId,
    ]);
    $id = (int) db()->lastInsertId();
    log_admin_action($adminId, 'promo.create', 'promo_code', $id, ['code' => $code]);

    return [true, null];
}

function set_promo_code_active(int $adminId, int $id, bool $active): void
{
    db()->prepare('UPDATE promo_codes SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    log_admin_action($adminId, $active ? 'promo.activate' : 'promo.deactivate', 'promo_code', $id);
}

function delete_promo_code(int $adminId, int $id): void
{
    db()->prepare('DELETE FROM promo_codes WHERE id = ?')->execute([$id]);
    log_admin_action($adminId, 'promo.delete', 'promo_code', $id);
}

// =====================================================================
// Admin users & roles
// =====================================================================

function get_all_users_admin(): array
{
    $stmt = db()->query('
        SELECT u.*,
            (SELECT COUNT(*) FROM events e WHERE e.organizer_id = u.id) AS event_count,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status = "PAID") AS order_count
        FROM users u
        ORDER BY u.created_at DESC
    ');
    return $stmt->fetchAll();
}

function get_admin_users(): array
{
    return db()->query("SELECT * FROM users WHERE role = 'ADMIN' ORDER BY created_at DESC")->fetchAll();
}

function update_user_role(int $adminId, int $userId, string $role): void
{
    $role = in_array($role, ['ATTENDEE', 'ORGANIZER', 'ADMIN'], true) ? $role : 'ATTENDEE';
    db()->prepare('UPDATE users SET role = ?, admin_role = NULL WHERE id = ?')->execute([$role, $userId]);

    if ($role === 'ORGANIZER') {
        $stmt = db()->prepare('SELECT id FROM organizer_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            $nameStmt = db()->prepare('SELECT name FROM users WHERE id = ?');
            $nameStmt->execute([$userId]);
            db()->prepare('INSERT INTO organizer_profiles (user_id, org_name) VALUES (?, ?)')
                ->execute([$userId, $nameStmt->fetchColumn()]);
        }
    }
    log_admin_action($adminId, 'user.role_change', 'user', $userId, ['role' => $role]);
}

function set_admin_role(int $adminId, int $targetUserId, ?string $adminRole): void
{
    $adminRole = $adminRole !== '' && array_key_exists($adminRole, ADMIN_ROLES) ? $adminRole : null;
    db()->prepare('UPDATE users SET admin_role = ? WHERE id = ? AND role = "ADMIN"')->execute([$adminRole, $targetUserId]);
    log_admin_action($adminId, 'admin.role_assign', 'user', $targetUserId, ['admin_role' => $adminRole]);
}

// =====================================================================
// Contact messages
// =====================================================================

function get_contact_messages_admin(string $status = ''): array
{
    if ($status !== '') {
        $stmt = db()->prepare('SELECT * FROM contact_messages WHERE status = ? ORDER BY created_at DESC');
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM contact_messages ORDER BY created_at DESC')->fetchAll();
}

function set_contact_message_status(int $adminId, int $id, string $status): void
{
    $status = in_array($status, ['NEW', 'READ', 'IN_PROGRESS', 'RESOLVED'], true) ? $status : 'NEW';
    db()->prepare('UPDATE contact_messages SET status = ? WHERE id = ?')->execute([$status, $id]);
    log_admin_action($adminId, 'contact.status_change', 'contact_message', $id, ['status' => $status]);
}

// =====================================================================
// Settings
// =====================================================================

function get_setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function get_all_settings(): array
{
    return array_column(db()->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll(), 'setting_value', 'setting_key');
}

function set_setting(int $adminId, string $key, string $value): void
{
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
    log_admin_action($adminId, 'settings.update', 'setting', null, ['key' => $key, 'value' => $value]);
}

// =====================================================================
// Global search
// =====================================================================

function admin_global_search(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $results = [];

    $stmt = db()->prepare('SELECT id, title AS label, slug FROM events WHERE title LIKE ? LIMIT 5');
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = ['type' => 'Event', 'label' => $row['label'], 'href' => '/admin-event-detail.php?id=' . $row['id']];
    }

    $stmt = db()->prepare("SELECT id, name AS label, email FROM users WHERE role = 'ORGANIZER' AND (name LIKE ? OR email LIKE ?) LIMIT 5");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = ['type' => 'Organizer', 'label' => $row['label'] . ' (' . $row['email'] . ')', 'href' => '/admin-organizer-detail.php?id=' . $row['id']];
    }

    $stmt = db()->prepare("SELECT id, name AS label, email FROM users WHERE role = 'ATTENDEE' AND (name LIKE ? OR email LIKE ?) LIMIT 5");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = ['type' => 'Customer', 'label' => $row['label'] . ' (' . $row['email'] . ')', 'href' => '/admin-customer-detail.php?id=' . $row['id']];
    }

    $stmt = db()->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([is_numeric($q) ? (int) $q : 0]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = ['type' => 'Order', 'label' => '#' . $row['id'], 'href' => '/admin-order-detail.php?id=' . $row['id']];
    }

    $stmt = db()->prepare('SELECT id, ticket_code FROM tickets WHERE ticket_code LIKE ? LIMIT 5');
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = ['type' => 'Ticket', 'label' => $row['ticket_code'], 'href' => '/admin-tickets.php?q=' . urlencode($row['ticket_code'])];
    }

    return $results;
}
