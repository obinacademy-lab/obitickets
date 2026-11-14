<?php
declare(strict_types=1);

/**
 * obitickets Socials: the event video community.
 *
 * - Watching is open to everyone, signed in or not. Liking, commenting and following need an account.
 * - Posting is a paid plan for organizers: one free month, then SOCIALS_PRICE_UGX for every SOCIALS_PERIOD_DAYS,
 *   paid by mobile money (the same ioTec collection the ticket checkout uses). A plan belongs to the organizer's
 *   own account and covers their team.
 * - The posts themselves are Moments (includes/moments.php): this file adds the one feed across all events,
 *   the plan, and the payments for it. Everything degrades to "not switched on" until migration 029 has run.
 *
 * Times are compared in SQL (NOW()), never with PHP's clock, so the plan dates and the checks always agree.
 */

const SOCIALS_PRICE_UGX = 25000;
const SOCIALS_PERIOD_DAYS = 30;
const SOCIALS_TRIAL_DAYS = 30;
const SOCIALS_FEED_PAGE = 8;
/** A payment prompt that nobody approved is treated as failed after this many minutes. */
const SOCIALS_PENDING_EXPIRY_MINUTES = 30;

/** True once migration 029 has run (and Moments exist to post into). */
function socials_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM social_subscriptions LIMIT 1');
            db()->query('SELECT 1 FROM social_payments LIMIT 1');
            $ready = moments_ready();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function socials_price_label(): string
{
    return 'UGX ' . number_format(SOCIALS_PRICE_UGX);
}

/**
 * Where an organizer's plan stands right now.
 *
 * @return array{state:string, can_post:bool, until:?string, days_left:int, trial_used:bool, trial_ends_at:?string, paid_until:?string}
 *         state: none (never started) | trial | active | expired
 */
function socials_status(int $organizerId): array
{
    $none = ['state' => 'none', 'can_post' => false, 'until' => null, 'days_left' => 0, 'trial_used' => false, 'trial_ends_at' => null, 'paid_until' => null];
    if (!socials_ready() || $organizerId <= 0) {
        return $none;
    }
    $stmt = db()->prepare('
        SELECT trial_started_at, trial_ends_at, paid_until,
               (paid_until IS NOT NULL AND paid_until > NOW()) AS is_paid,
               (trial_ends_at IS NOT NULL AND trial_ends_at > NOW()) AS in_trial,
               GREATEST(COALESCE(paid_until, "1970-01-01"), COALESCE(trial_ends_at, "1970-01-01")) AS until_at,
               CEIL(TIMESTAMPDIFF(MINUTE, NOW(), GREATEST(COALESCE(paid_until, "1970-01-01"), COALESCE(trial_ends_at, "1970-01-01"))) / 1440) AS days_left
        FROM social_subscriptions WHERE user_id = ?');
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $none;
    }
    $paid = (int) $row['is_paid'] === 1;
    $trial = (int) $row['in_trial'] === 1;
    return [
        'state' => $paid ? 'active' : ($trial ? 'trial' : 'expired'),
        'can_post' => $paid || $trial,
        'until' => ($paid || $trial) ? (string) $row['until_at'] : null,
        'days_left' => ($paid || $trial) ? max(0, (int) $row['days_left']) : 0,
        'trial_used' => $row['trial_started_at'] !== null,
        'trial_ends_at' => $row['trial_ends_at'],
        'paid_until' => $row['paid_until'],
    ];
}

/** Starts the one free month. Does nothing (and says so) if this organizer has already had it. */
function socials_start_trial(int $organizerId): array
{
    if (!socials_ready()) {
        return ['ok' => false, 'error' => 'Socials is not switched on yet.'];
    }
    $stmt = db()->prepare('
        INSERT INTO social_subscriptions (user_id, trial_started_at, trial_ends_at)
        VALUES (?, NOW(), NOW() + INTERVAL ' . (int) SOCIALS_TRIAL_DAYS . ' DAY)
        ON DUPLICATE KEY UPDATE
            trial_ends_at = IF(trial_started_at IS NULL, NOW() + INTERVAL ' . (int) SOCIALS_TRIAL_DAYS . ' DAY, trial_ends_at),
            trial_started_at = IF(trial_started_at IS NULL, NOW(), trial_started_at)');
    // The order above matters: trial_ends_at is set while trial_started_at is still NULL.
    $before = socials_status($organizerId);
    if ($before['trial_used']) {
        return ['ok' => false, 'error' => 'Your free month has already been used.'];
    }
    $stmt->execute([$organizerId]);
    return ['ok' => true, 'status' => socials_status($organizerId)];
}

/**
 * May this person post to this event's Socials? The plan is the event organizer's; their team posts under it.
 * Before migration 029 the answer is yes (posting behaves as it did before Socials existed). Admins always may.
 */
function socials_can_post_event(array $event, ?array $user): bool
{
    if (!socials_ready()) {
        return true;
    }
    if ($user && ($user['role'] ?? '') === 'ADMIN') {
        return true;
    }
    return socials_status((int) ($event['organizer_id'] ?? 0))['can_post'];
}

function socials_paywall_message(): string
{
    return 'Posting to Socials needs an active Socials plan (' . socials_price_label() . ' a month, first month free). Open Socials in your dashboard to start.';
}

// ------------------------------------------------------------------ payments

/** The newest payment for an organizer, or null. */
function socials_latest_payment(int $organizerId): ?array
{
    $stmt = db()->prepare('SELECT * FROM social_payments WHERE organizer_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$organizerId]);
    return $stmt->fetch() ?: null;
}

/**
 * Sends the mobile money prompt for one period. A prompt that is still waiting (under three minutes old) is
 * reused instead of sending a second one to the same phone.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function socials_payment_start(array $payer, int $organizerId, string $phone, ?callable $collect = null): array
{
    if (!socials_ready()) {
        return ['ok' => false, 'error' => 'Socials is not switched on yet.'];
    }
    if (strlen((string) preg_replace('/\D/', '', $phone)) < 9) {
        return ['ok' => false, 'error' => 'Enter a valid mobile money number.'];
    }
    $pdo = db();
    $waiting = $pdo->prepare("SELECT id FROM social_payments WHERE organizer_id = ? AND status = 'PENDING' AND created_at > (NOW() - INTERVAL 3 MINUTE) ORDER BY id DESC LIMIT 1");
    $waiting->execute([$organizerId]);
    if ($existing = $waiting->fetchColumn()) {
        return ['ok' => true, 'id' => (int) $existing, 'reused' => true];
    }

    $pdo->prepare("INSERT INTO social_payments (organizer_id, paid_by, amount, currency, days, phone, status) VALUES (?, ?, ?, 'UGX', ?, ?, 'PENDING')")
        ->execute([$organizerId, (int) $payer['id'], SOCIALS_PRICE_UGX, SOCIALS_PERIOD_DAYS, $phone]);
    $paymentId = (int) $pdo->lastInsertId();

    try {
        $note = 'obitickets Socials, ' . SOCIALS_PERIOD_DAYS . ' days (payment S' . $paymentId . ')';
        $result = ($collect ?? 'iotec_initiate_collection')((float) SOCIALS_PRICE_UGX, $phone, 'SOC-' . $paymentId, $note);
        $pdo->prepare('UPDATE social_payments SET payment_reference = ? WHERE id = ?')->execute([$result['transactionId'], $paymentId]);
    } catch (Throwable $e) {
        error_log('[socials] initiate collection failed for payment ' . $paymentId . ': ' . $e->getMessage());
        socials_fail_payment($paymentId, "We couldn't start the mobile money payment. Please try again.");
        return ['ok' => false, 'error' => "We couldn't start the mobile money payment. Please try again."];
    }
    return ['ok' => true, 'id' => $paymentId];
}

function socials_fail_payment(int $paymentId, string $message): void
{
    db()->prepare("UPDATE social_payments SET status = 'FAILED', status_message = ? WHERE id = ? AND status = 'PENDING'")
        ->execute([mb_substr($message, 0, 250), $paymentId]);
}

/**
 * Marks a payment PAID and adds its days to the plan. Locks the payment row first and does nothing if it has
 * already left PENDING, so a second call (a slow poll arriving after another one) can never add the days twice.
 * The new period starts where the current plan (or the free month) ends, so paying early never loses days.
 */
function socials_activate_payment(int $paymentId, ?string $message): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM social_payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $pay = $stmt->fetch();
        if (!$pay || $pay['status'] !== 'PENDING') {
            $pdo->rollBack();
            return false;
        }
        $org = (int) $pay['organizer_id'];
        $days = max(1, (int) $pay['days']);
        $pdo->prepare('INSERT IGNORE INTO social_subscriptions (user_id) VALUES (?)')->execute([$org]);
        $lock = $pdo->prepare('SELECT user_id FROM social_subscriptions WHERE user_id = ? FOR UPDATE');
        $lock->execute([$org]);
        $pdo->prepare("
            UPDATE social_subscriptions
            SET paid_until = GREATEST(NOW(), COALESCE(paid_until, '1970-01-01'), COALESCE(trial_ends_at, '1970-01-01')) + INTERVAL $days DAY
            WHERE user_id = ?")->execute([$org]);
        $pdo->prepare("
            UPDATE social_payments p JOIN social_subscriptions s ON s.user_id = p.organizer_id
            SET p.status = IF(p.amount > 0, 'PAID', 'GRANTED'), p.status_message = ?, p.period_end = s.paid_until, p.period_start = s.paid_until - INTERVAL $days DAY
            WHERE p.id = ?")->execute([$message !== null ? mb_substr($message, 0, 250) : null, $paymentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    try {
        $status = socials_status($org);
        create_notification($org, 'socials_plan', 'Your Socials plan is active', 'You can post until ' . date('j M Y', strtotime((string) $status['until'])) . '.', '/org-socials.php');
    } catch (Throwable $e) {
        error_log('[socials] notification failed: ' . $e->getMessage());
    }
    return true;
}

/**
 * Asks ioTec about a waiting payment and settles it. Returns the payment's status for the page.
 *
 * @return array{status:string, statusMessage:?string}
 */
function socials_payment_poll(int $organizerId, int $paymentId, ?callable $statusCheck = null): array
{
    $stmt = db()->prepare('SELECT *, (created_at < (NOW() - INTERVAL ' . (int) SOCIALS_PENDING_EXPIRY_MINUTES . ' MINUTE)) AS is_stale FROM social_payments WHERE id = ? AND organizer_id = ?');
    $stmt->execute([$paymentId, $organizerId]);
    $pay = $stmt->fetch();
    if (!$pay) {
        throw new RuntimeException('Payment not found.');
    }
    if ($pay['status'] !== 'PENDING') {
        return ['status' => $pay['status'], 'statusMessage' => $pay['status_message']];
    }
    if (!$pay['payment_reference']) {
        return ['status' => 'PENDING', 'statusMessage' => null];
    }
    try {
        $result = ($statusCheck ?? 'iotec_check_collection_status')((string) $pay['payment_reference']);
    } catch (Throwable $e) {
        error_log('[socials] status check failed for payment ' . $paymentId . ': ' . $e->getMessage());
        return ['status' => 'PENDING', 'statusMessage' => null];
    }
    if ($result['status'] === 'Success') {
        socials_activate_payment($paymentId, $result['statusMessage'] ?? null);
        return ['status' => 'PAID', 'statusMessage' => $result['statusMessage'] ?? null];
    }
    if ($result['status'] === 'Failed') {
        socials_fail_payment($paymentId, (string) ($result['statusMessage'] ?? 'The payment was not completed.'));
        return ['status' => 'FAILED', 'statusMessage' => $result['statusMessage'] ?? 'The payment was not completed.'];
    }
    if ((int) $pay['is_stale'] === 1) {
        socials_fail_payment($paymentId, 'The payment request expired. Please try again.');
        return ['status' => 'FAILED', 'statusMessage' => 'The payment request expired. Please try again.'];
    }
    return ['status' => 'PENDING', 'statusMessage' => $result['statusMessage'] ?? null];
}

/** An admin adds days to an organizer's plan without a payment (a courtesy, a fix, a partnership). */
function socials_admin_grant(int $organizerId, int $days, int $adminId): bool
{
    if (!socials_ready() || $days < 1 || $days > 366) {
        return false;
    }
    $pdo = db();
    $pdo->prepare("INSERT INTO social_payments (organizer_id, paid_by, amount, currency, days, status) VALUES (?, ?, 0, 'UGX', ?, 'PENDING')")
        ->execute([$organizerId, $adminId, $days]);
    return socials_activate_payment((int) $pdo->lastInsertId(), 'Added by an admin');
}

/** An admin ends an organizer's plan now (their posts stay; they can no longer add new ones). */
function socials_admin_end(int $organizerId): void
{
    db()->prepare('UPDATE social_subscriptions SET paid_until = IF(paid_until > NOW(), NOW(), paid_until), trial_ends_at = IF(trial_ends_at > NOW(), NOW(), trial_ends_at) WHERE user_id = ?')
        ->execute([$organizerId]);
}

/** Every organizer with a plan row, newest activity first, for the admin page. */
function socials_admin_list(int $limit = 200): array
{
    if (!socials_ready()) {
        return [];
    }
    return db()->query('
        SELECT s.*, u.name, u.email, op.org_name,
               (s.paid_until IS NOT NULL AND s.paid_until > NOW()) AS is_paid,
               (s.trial_ends_at IS NOT NULL AND s.trial_ends_at > NOW()) AS in_trial,
               (SELECT COALESCE(SUM(p.amount), 0) FROM social_payments p WHERE p.organizer_id = s.user_id AND p.status = "PAID") AS total_paid,
               (SELECT COUNT(*) FROM event_posts ep JOIN events e ON e.id = ep.event_id WHERE e.organizer_id = s.user_id AND ep.post_type = "MOMENT" AND ep.status = "PUBLISHED") AS post_count
        FROM social_subscriptions s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN organizer_profiles op ON op.user_id = s.user_id
        ORDER BY s.updated_at DESC
        LIMIT ' . max(1, min(500, $limit)))->fetchAll();
}

/** Totals for the admin page: who is on what, and the money in. */
function socials_admin_totals(): array
{
    if (!socials_ready()) {
        return ['active' => 0, 'trial' => 0, 'expired' => 0, 'revenue' => 0.0, 'revenue_30' => 0.0];
    }
    $row = db()->query('
        SELECT SUM(paid_until IS NOT NULL AND paid_until > NOW()) AS active,
               SUM((paid_until IS NULL OR paid_until <= NOW()) AND trial_ends_at IS NOT NULL AND trial_ends_at > NOW()) AS trial,
               SUM((paid_until IS NULL OR paid_until <= NOW()) AND (trial_ends_at IS NULL OR trial_ends_at <= NOW())) AS expired
        FROM social_subscriptions')->fetch();
    $money = db()->query('
        SELECT COALESCE(SUM(amount), 0) AS revenue,
               COALESCE(SUM(IF(updated_at > NOW() - INTERVAL 30 DAY, amount, 0)), 0) AS revenue_30
        FROM social_payments WHERE status = "PAID"')->fetch();
    return [
        'active' => (int) ($row['active'] ?? 0), 'trial' => (int) ($row['trial'] ?? 0), 'expired' => (int) ($row['expired'] ?? 0),
        'revenue' => (float) $money['revenue'], 'revenue_30' => (float) $money['revenue_30'],
    ];
}

/** Recent payments (all organizers) for the admin page. */
function socials_admin_payments(int $limit = 60): array
{
    if (!socials_ready()) {
        return [];
    }
    return db()->query('
        SELECT p.*, u.name, op.org_name
        FROM social_payments p JOIN users u ON u.id = p.organizer_id
        LEFT JOIN organizer_profiles op ON op.user_id = p.organizer_id
        ORDER BY p.id DESC LIMIT ' . max(1, min(300, $limit)))->fetchAll();
}

// ------------------------------------------------------------------ the feed

/**
 * One page of the Socials feed across all published events.
 *   tab 'foryou'    everything, ranked by how lively and how fresh a post is (upcoming events first)
 *   tab 'following' only organizers this viewer follows, newest first
 * $startId puts one post first (a shared link). Posts stay in the feed after a plan ends; only new posts need a plan.
 *
 * @return list<array<string,mixed>> rows ready for socials_item_public()
 */
function socials_feed(?int $viewerId, string $tab = 'foryou', int $offset = 0, int $limit = SOCIALS_FEED_PAGE, ?int $startId = null, ?int $organizerId = null): array
{
    if (!moments_ready()) {
        return [];
    }
    $limit = max(1, min(24, $limit));
    $offset = max(0, $offset);
    $viewer = $viewerId ?? 0;

    $where = "e.status = 'PUBLISHED'";
    $params = [];
    if ($tab === 'following') {
        if (!$viewer) {
            return [];
        }
        $where .= ' AND e.organizer_id IN (SELECT f.organizer_user_id FROM organizer_follows f WHERE f.user_id = ' . (int) $viewer . ')';
    }
    if ($organizerId) {
        $where .= ' AND e.organizer_id = ' . (int) $organizerId;
    }
    if ($viewer) {
        // People the viewer blocked never show up in their feed.
        $where .= ' AND x.author_id NOT IN (SELECT b.blocked_id FROM user_blocks b WHERE b.blocker_id = ' . (int) $viewer . ')';
    }
    $order = ($tab === 'following' || $organizerId)
        ? 'x.id DESC'
        : '((x.reaction_count * 3 + x.comment_count * 4 + x.share_count * 5 + LEAST(x.view_count, 600) / 20 + 4)
              * IF(e.ends_at >= NOW(), 1, 0.45)
              / POW(TIMESTAMPDIFF(HOUR, x.created_at, NOW()) + 6, 1.15)) DESC, x.id DESC';
    $first = $startId ? '(x.id = ' . (int) $startId . ') DESC, ' : '';

    $sql = '
        SELECT x.*, e.title AS event_title, e.slug AS event_slug, e.starts_at AS event_starts_at, e.ends_at AS event_ends_at,
               e.venue_name AS event_venue, e.attend_mode AS event_mode, e.organizer_id, e.banner_image AS event_banner,
               (e.ends_at < NOW()) AS event_ended,
               COALESCE(NULLIF(op.org_name, ""), ou.name) AS org_name, op.slug AS org_slug, (op.verification_status = "VERIFIED") AS org_verified,
               (SELECT MIN(t.price) FROM ticket_types t WHERE t.event_id = e.id) AS min_price,
               (SELECT COUNT(*) FROM organizer_follows f2 WHERE f2.organizer_user_id = e.organizer_id AND f2.user_id = ' . (int) $viewer . ') AS i_follow
        FROM (' . moment_select_sql() . ') x
        JOIN events e ON e.id = x.event_id
        JOIN users ou ON ou.id = e.organizer_id
        LEFT JOIN organizer_profiles op ON op.user_id = e.organizer_id
        WHERE ' . $where . '
        ORDER BY ' . $first . $order . '
        LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':viewer', $viewer, PDO::PARAM_INT);
    $stmt->execute();
    return moment_attach_slides($stmt->fetchAll());
}

/** How many posts the feed has for this tab (for "nothing here yet" states). */
function socials_feed_count(): int
{
    if (!moments_ready()) {
        return 0;
    }
    return (int) db()->query("
        SELECT COUNT(*) FROM event_moments m JOIN event_posts p ON p.id = m.post_id JOIN events e ON e.id = m.event_id
        WHERE p.status = 'PUBLISHED' AND e.status = 'PUBLISHED' AND m.media_path <> ''" . (moments_draft_ready() ? ' AND m.is_draft = 0' : ''))->fetchColumn();
}

/** The shape the feed script uses for one post: the moment itself plus its event and organizer. */
function socials_item_public(array $row): array
{
    $item = moment_public($row);
    $ts = strtotime((string) $row['event_starts_at']);
    $ended = (int) $row['event_ended'] === 1;
    $min = $row['min_price'];
    $price = $min === null ? '' : ((float) $min <= 0.0 ? 'Free' : 'From UGX ' . number_format((float) $min));
    $orgId = (int) $row['organizer_id'];
    $item['event'] = [
        'id' => (int) $row['event_id'],
        'title' => (string) $row['event_title'],
        'url' => ($ended && function_exists('past_event_url')) ? past_event_url((string) $row['event_slug']) : '/event.php?slug=' . rawurlencode((string) $row['event_slug']),
        'when' => date('D j M Y', $ts),
        'where' => ($row['event_mode'] ?? '') === 'ONLINE' ? 'Online event' : (string) $row['event_venue'],
        'price' => $price,
        'ended' => $ended,
    ];
    $item['org'] = [
        'id' => $orgId,
        'name' => (string) $row['org_name'],
        'initials' => community_initials((string) $row['org_name']),
        'avatar' => function_exists('user_avatar_url') ? user_avatar_url($orgId) : null,
        'verified' => (int) ($row['org_verified'] ?? 0) === 1,
        'url' => !empty($row['org_slug']) ? '/organizer.php?slug=' . rawurlencode((string) $row['org_slug']) : null,
        'following' => (int) ($row['i_follow'] ?? 0) > 0,
    ];
    $item['share'] = rtrim(APP_URL, '/') . '/socials.php?v=' . (int) $row['id'];
    $item['likes'] = moment_compact((int) $row['reaction_count']);
    return $item;
}

/** The organizer's own posts with their numbers, newest first, for the dashboard page. */
function socials_organizer_posts(int $organizerId, int $limit = 30): array
{
    if (!moments_ready()) {
        return [];
    }
    return socials_feed(null, 'following-none', 0, min(24, $limit), null, $organizerId);
}

/** Events this organizer can post to (published ones), soonest first, for the "post a video" picker. */
function socials_postable_events(int $organizerId): array
{
    $stmt = db()->prepare("SELECT id, title, starts_at, ends_at, (ends_at < NOW()) AS ended FROM events WHERE organizer_id = ? AND status = 'PUBLISHED' ORDER BY (ends_at < NOW()), starts_at ASC LIMIT 60");
    $stmt->execute([$organizerId]);
    return $stmt->fetchAll();
}
