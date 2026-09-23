<?php
declare(strict_types=1);

/** Platform-wide numbers for the admin overview page. */
function get_platform_stats(): array
{
    $pdo = db();
    $stats = [];
    $stats['total_users'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['total_organizers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ORGANIZER'")->fetchColumn();
    $stats['total_events'] = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $stats['published_events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'PUBLISHED'")->fetchColumn();
    $stats['total_orders'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'PAID'")->fetchColumn();
    $stats['total_tickets'] = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'CANCELLED'")->fetchColumn();
    $stats['checked_in'] = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'USED'")->fetchColumn();
    $stats['revenue_by_currency'] = $pdo->query("
        SELECT currency, SUM(total_amount) AS total FROM orders WHERE status = 'PAID' GROUP BY currency ORDER BY total DESC
    ")->fetchAll();
    // obitickets' own two revenue streams: the buyer-facing service fee and
    // the organizer-side commission (PLATFORM_COMMISSION_RATE) — kept apart
    // from revenue_by_currency above, which is gross money that passed
    // through the platform, not money the platform actually keeps.
    $stats['commission_by_currency'] = $pdo->query("
        SELECT currency, SUM(commission_amount) AS total FROM orders WHERE status = 'PAID' GROUP BY currency ORDER BY total DESC
    ")->fetchAll();
    $stats['service_fees_by_currency'] = $pdo->query("
        SELECT currency, SUM(service_fee_amount) AS total FROM orders WHERE status = 'PAID' GROUP BY currency ORDER BY total DESC
    ")->fetchAll();
    return $stats;
}

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

function update_user_role(int $userId, string $role): void
{
    $role = in_array($role, ['ATTENDEE', 'ORGANIZER', 'ADMIN'], true) ? $role : 'ATTENDEE';
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);

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
}

function get_all_events_admin(): array
{
    $stmt = db()->query('
        SELECT e.*, u.name AS organizer_name,
            (SELECT COALESCE(SUM(t.quantity_sold), 0) FROM ticket_types t WHERE t.event_id = e.id) AS tickets_sold
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        ORDER BY e.created_at DESC
    ');
    return $stmt->fetchAll();
}

function update_event_status_admin(int $eventId, string $status): void
{
    $status = in_array($status, ['DRAFT', 'PUBLISHED', 'CANCELLED'], true) ? $status : 'DRAFT';
    db()->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$status, $eventId]);
}

function get_all_orders_admin(int $limit = 200): array
{
    $stmt = db()->prepare('
        SELECT o.*, u.name AS buyer_name, u.email AS buyer_email, e.title AS event_title
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN events e ON e.id = o.event_id
        ORDER BY o.created_at DESC
        LIMIT ' . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

const ADMIN_TABS = [
    'overview' => ['label' => 'Overview', 'href' => '/admin.php'],
    'users' => ['label' => 'Users', 'href' => '/admin-users.php'],
    'events' => ['label' => 'Events', 'href' => '/admin-events.php'],
    'orders' => ['label' => 'Orders', 'href' => '/admin-orders.php'],
];

/** Shared tab strip at the top of every admin page, replacing the old one-off "Back to admin" links. */
function render_admin_tabs(string $active): void
{
    echo '<div class="admin-tabs">';
    foreach (ADMIN_TABS as $key => $tab) {
        $class = 'admin-tab' . ($key === $active ? ' active' : '');
        echo '<a class="' . $class . '" href="' . htmlspecialchars($tab['href']) . '">' . htmlspecialchars($tab['label']) . '</a>';
    }
    echo '</div>';
}
