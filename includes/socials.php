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
/** Video tiles per load on the Socials list (two across on phones, so keep it even). */
const SOCIALS_SHELF_PAGE = 12;
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
function socials_feed(?int $viewerId, string $tab = 'foryou', int $offset = 0, int $limit = SOCIALS_FEED_PAGE, ?int $startId = null, ?int $organizerId = null, ?string $category = null): array
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
    $category = trim((string) $category);
    if ($category !== '') {
        $where .= ' AND e.category = :cat';
    }
    if ($viewer) {
        // People the viewer blocked never show up in their feed.
        $where .= ' AND x.author_id NOT IN (SELECT b.blocked_id FROM user_blocks b WHERE b.blocker_id = ' . (int) $viewer . ')';
    }
    if ($tab === 'trending') {
        // Trending: the posts people engaged with most in the last 14 days, without the freshness pull of For You.
        $where .= ' AND x.created_at >= NOW() - INTERVAL 14 DAY';
        $order = '(x.reaction_count * 3 + x.comment_count * 4 + x.share_count * 5 + LEAST(x.view_count, 5000) / 10) DESC, x.id DESC';
    } elseif ($tab === 'following' || $organizerId) {
        $order = 'x.id DESC';
    } else {
        $order = '((x.reaction_count * 3 + x.comment_count * 4 + x.share_count * 5 + LEAST(x.view_count, 600) / 20 + 4)
              * IF(e.ends_at >= NOW(), 1, 0.45)
              / POW(TIMESTAMPDIFF(HOUR, x.created_at, NOW()) + 6, 1.15)) DESC, x.id DESC';
    }
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
    if ($category !== '') {
        $stmt->bindValue(':cat', $category);
    }
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
    // "4 – 5 Dec 2026", "30 Nov – 2 Dec 2026", "12 Dec 2026": short, like the template.
    $te = strtotime((string) ($row['event_ends_at'] ?? $row['event_starts_at']));
    if (date('Y-m-d', $ts) === date('Y-m-d', $te) || $te < $ts) {
        $when = date('j M Y', $ts);
    } elseif (date('M Y', $ts) === date('M Y', $te)) {
        $when = date('j', $ts) . ' – ' . date('j M Y', $te);
    } elseif (date('Y', $ts) === date('Y', $te)) {
        $when = date('j M', $ts) . ' – ' . date('j M Y', $te);
    } else {
        $when = date('j M Y', $ts) . ' – ' . date('j M Y', $te);
    }
    $item['event'] = [
        'id' => (int) $row['event_id'],
        'title' => (string) $row['event_title'],
        'url' => ($ended && function_exists('past_event_url')) ? past_event_url((string) $row['event_slug']) : '/event.php?slug=' . rawurlencode((string) $row['event_slug']),
        'when' => $when,
        'where' => ($row['event_mode'] ?? '') === 'ONLINE' ? 'Online event' : (string) $row['event_venue'],
        'price' => $price,
        'ended' => $ended,
        'start_ts' => $ts,
    ];
    $item['org'] = [
        'id' => $orgId,
        'name' => (string) $row['org_name'],
        // "@KumiFestival": the organizer's name without spaces or punctuation (their profile address stays the slug)
        'handle' => '@' . (preg_replace('/[^\p{L}\p{N}_]+/u', '', ucwords(strtolower((string) $row['org_name']))) ?: (string) ($row['org_slug'] ?? 'organizer')),
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

/**
 * The Socials list: one card per organizer, holding their posts. Ranked like the feed tabs:
 *   foryou    organizers whose posts are liked, commented and shared most, with the freshest first
 *   following only organizers this viewer follows, newest post first
 *   trending  most engagement on posts from the last 14 days
 * Each row: the organizer, how many posts they have, and their newest posts (up to 4) for the cover and thumbnails.
 *
 * @return list<array<string,mixed>>
 */
function socials_organizer_cards(?int $viewerId, string $tab = 'foryou', int $offset = 0, int $limit = 8): array
{
    if (!moments_ready()) {
        return [];
    }
    $viewer = (int) ($viewerId ?? 0);
    $limit = max(1, min(24, $limit));
    $offset = max(0, $offset);
    $where = "e.status = 'PUBLISHED'";
    if ($tab === 'following') {
        if (!$viewer) {
            return [];
        }
        $where .= " AND e.organizer_id IN (SELECT f.organizer_user_id FROM organizer_follows f WHERE f.user_id = $viewer)";
    }
    if ($viewer) {
        $where .= " AND x.author_id NOT IN (SELECT b.blocked_id FROM user_blocks b WHERE b.blocker_id = $viewer)";
    }
    $engage = '(x.reaction_count * 3 + x.comment_count * 4 + x.share_count * 5 + LEAST(x.view_count, 5000) / 10)';
    if ($tab === 'trending') {
        $where .= ' AND x.created_at >= NOW() - INTERVAL 14 DAY';
        $order = "SUM($engage) DESC, MAX(x.id) DESC";
    } elseif ($tab === 'following') {
        $order = 'MAX(x.id) DESC';
    } else {
        $order = "((SUM($engage) + 4) / POW(TIMESTAMPDIFF(HOUR, MAX(x.created_at), NOW()) + 6, 1.1)) DESC, MAX(x.id) DESC";
    }
    $stmt = db()->prepare('
        SELECT e.organizer_id, COUNT(*) AS post_count, SUM(x.reaction_count) AS likes, SUM(x.view_count) AS views,
               MAX(x.id) AS latest_id, MAX(x.created_at) AS latest_at
        FROM (' . moment_select_sql() . ') x
        JOIN events e ON e.id = x.event_id
        WHERE ' . $where . '
        GROUP BY e.organizer_id
        ORDER BY ' . $order . '
        LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->bindValue(':viewer', $viewer, PDO::PARAM_INT);
    $stmt->execute();
    $groups = $stmt->fetchAll();
    $cards = [];
    foreach ($groups as $g) {
        $orgId = (int) $g['organizer_id'];
        $posts = array_map('socials_item_public', socials_feed($viewerId, 'profile', 0, 8, null, $orgId));
        if (!$posts) {
            continue;
        }
        $cards[] = [
            'org' => $posts[0]['org'],
            'count' => (int) $g['post_count'],
            'likes' => moment_compact((int) $g['likes']),
            'views' => moment_compact((int) $g['views']),
            'when' => community_time_ago((string) $g['latest_at']),
            'posts' => $posts,
        ];
    }
    return $cards;
}

/** The picture that stands for a post on a card (the video's cover, a photo, or a slideshow's first photo). */
function socials_post_thumb(array $it): string
{
    $thumb = $it['type'] === 'video' ? ($it['poster'] ?? '') : ($it['type'] === 'slideshow' ? ($it['slides'][0] ?? '') : $it['src']);
    return preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', (string) $thumb) ? (string) $thumb : '';
}

/**
 * One organizer card: their photo, name, tick and how many posts; a big cover from the newest post with a play
 * button; and a row of their next posts. The card opens the player on this organizer's posts (newest first);
 * a small picture opens it on that post. assets/js/socials-list.js builds the same markup for later cards.
 */
function socials_org_card_html(array $c): string
{
    // TikTok-profile style (chosen by the owner 11/14): a header row, then the organizer's videos 3 across as tall
    // thumbnails with the views on each; the last one says "+N" when there are more. Tapping opens the player.
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $o = $c['org'];
    $posts = array_slice($c['posts'], 0, 6);
    $count = (int) $c['count'];
    $orgUrl = '/socials.php?' . http_build_query(['org' => (int) $o['id']]);
    $tick = !empty($o['verified'])
        ? '<span class="sl-ver" title="Verified organizer"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><span class="sr-only">Verified organizer</span></span>'
        : '';
    $me = current_user();
    $isMe = $me && (int) $me['id'] === (int) $o['id'];

    $out = '<section class="tg-org" aria-label="' . $h($o['name']) . '">'
        . '<header class="tg-head">'
        . '<a class="tg-av" href="' . $h($orgUrl) . '" tabindex="-1" aria-hidden="true">' . (!empty($o['avatar']) ? '<img src="' . $h($o['avatar']) . '" alt="">' : $h($o['initials'])) . '</a>'
        . '<a class="tg-who" href="' . $h($orgUrl) . '"><b><span class="tg-name">' . $h($o['name']) . '</span>' . $tick . '</b>'
        . '<small><strong>' . $count . '</strong> ' . ($count === 1 ? 'video' : 'videos') . ' &middot; <strong>' . $h($c['views']) . '</strong> views</small></a>';
    if (!$isMe) {
        $on = !empty($o['following']);
        $out .= '<button type="button" class="pc-follow tg-follow' . ($on ? ' is-on' : '') . '" data-pc-follow="' . (int) $o['id'] . '" aria-pressed="' . ($on ? 'true' : 'false') . '" aria-label="' . $h(($on ? 'Following ' : 'Follow ') . $o['name']) . '">' . ($on ? 'Following' : 'Follow') . '</button>';
    }
    $out .= '</header><div class="tg-grid">';
    foreach ($posts as $i => $p) {
        $t = socials_post_thumb($p);
        $isLast = $i === count($posts) - 1;
        $extra = $isLast ? $count - count($posts) : 0;
        $href = $extra > 0 ? $orgUrl : '/socials.php?' . http_build_query(['org' => (int) $o['id'], 'v' => (int) $p['id']]);
        $label = $extra > 0 ? 'See all ' . $count . ' videos by ' . $o['name'] : (($p['type'] === 'video' ? 'Watch: ' : 'Open: ') . $p['event']['title'] . ($p['caption'] !== '' ? '. ' . mb_substr($p['caption'], 0, 80) : ''));
        $out .= '<a class="tg-tile" href="' . $h($href) . '" aria-label="' . $h($label) . '">'
            . ($t !== '' ? '<img src="' . $h($t) . '" alt="" loading="lazy">' : ($p['type'] === 'video' ? '<video src="' . $h($p['src']) . '#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>' : ''))
            . ($p['type'] === 'slideshow' ? '<span class="tg-kind" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="7" y="7" width="13" height="13" rx="2"/><path d="M4 16V5a1 1 0 0 1 1-1h11"/></svg></span>' : '')
            . '<span class="tg-views"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round" aria-hidden="true"><path d="M7 5v14l12-7z"/></svg>' . $h(moment_compact((int) $p['views'])) . '</span>'
            . ($extra > 0 ? '<span class="tg-more"><strong>+' . $extra . '</strong>more</span>' : '')
            . '</a>';
    }
    return $out . '</div></section>';
}

/**
 * One card of the Socials list (the template's first screen): the post's picture with a play button and its length,
 * the event title, the organizer's photo, handle and tick, and the likes. Tapping it opens the player on that post.
 * assets/js/socials-list.js builds the same markup for the cards it loads later; keep the two in step.
 */
function socials_card_html(array $it, string $tab = 'foryou'): string
{
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $q = ['v' => (int) $it['id']];
    if ($tab !== 'foryou') {
        $q['tab'] = $tab;
    }
    $href = '/socials.php?' . http_build_query($q);
    $thumb = $it['type'] === 'video' ? ($it['poster'] ?? '') : ($it['type'] === 'slideshow' ? ($it['slides'][0] ?? '') : $it['src']);
    $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', (string) $thumb) ? (string) $thumb : '';
    $badge = $it['type'] === 'video' ? moment_clock($it['duration']) : ($it['type'] === 'slideshow' ? count($it['slides']) . ' photos' : 'Photo');
    $o = $it['org'];
    $out = '<a class="sl-card" href="' . $h($href) . '" aria-label="' . $h(($it['type'] === 'video' ? 'Watch: ' : 'Open: ') . $it['event']['title'] . ', by ' . $o['name']) . '">'
        . '<span class="sl-media">';
    if ($thumb !== '') {
        $out .= '<img src="' . $h($thumb) . '" alt="" loading="lazy">';
    } elseif ($it['type'] === 'video') {
        $out .= '<video src="' . $h($it['src']) . '#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>';
    }
    $out .= '<span class="sl-play" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>';
    if ($badge !== '') {
        $out .= '<span class="sl-dur">' . $h($badge) . '</span>';
    }
    $out .= '<span class="sl-info"><b class="sl-title">' . $h($it['event']['title']) . '</b>'
        . '<span class="sl-by"><span class="sl-av">' . (!empty($o['avatar']) ? '<img src="' . $h($o['avatar']) . '" alt="">' : $h($o['initials'])) . '</span>'
        . '<span class="sl-handle">' . $h($o['handle']) . '</span>'
        . (!empty($o['verified']) ? '<span class="sl-ver" title="Verified organizer"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>' : '')
        . '<span class="sl-likes"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.4C1.5 7.6 3.9 4.5 7.2 4.5c1.9 0 3.6 1 4.8 2.6 1.2-1.6 2.9-2.6 4.8-2.6 3.3 0 5.7 3.1 4.5 6.6-1.8 4.8-9.3 9.4-9.3 9.4z"/></svg>' . $h($it['likes']) . '</span>'
        . '</span></span></span></a>';
    return $out;
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

// ------------------------------------------------------------------ reminders before a plan runs out

/** True once the reminder columns exist (they are part of migration 029). */
function socials_reminders_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT remind_3d_for FROM social_subscriptions LIMIT 0');
            $ready = socials_ready();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Emails organizers whose plan (free month or paid) ends in about 3 days, in about 1 day, or has just ended.
 * Each email is sent once per plan period: the period's end is stored next to the plan, so paying again (which
 * moves the end) starts fresh, and two cron runs at the same moment cannot both send (the row is claimed first).
 * Runs from cron/send-event-reminders.php every 15 minutes.
 *
 * @return array{sent:int, skipped:int, failed:int}
 */
function socials_send_plan_reminders(?callable $send = null): array
{
    $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    if (!socials_reminders_ready()) {
        return $counts;
    }
    $send = $send ?? 'resend_send';
    $until = "GREATEST(COALESCE(s.paid_until, '1970-01-01'), COALESCE(s.trial_ends_at, '1970-01-01'))";
    $stages = [
        // column that remembers it was sent, which plans are due, subject, how the email words it
        ['remind_3d_for', "$until > NOW() + INTERVAL 1 DAY AND $until <= NOW() + INTERVAL 3 DAY", 'Your Socials plan ends in 3 days', 'ends in 3 days'],
        ['remind_1d_for', "$until > NOW() AND $until <= NOW() + INTERVAL 1 DAY", 'Your Socials plan ends tomorrow', 'ends within a day'],
        ['ended_notice_for', "$until <= NOW() AND $until > NOW() - INTERVAL 2 DAY", 'Your Socials plan has ended', 'has ended'],
    ];
    foreach ($stages as [$col, $when, $subject, $lead]) {
        $rows = db()->query("
            SELECT s.user_id, $until AS until_at, (s.paid_until IS NULL OR s.paid_until < s.trial_ends_at) AS was_trial,
                   u.email, u.name, COALESCE(NULLIF(op.org_name, ''), u.name) AS org_name
            FROM social_subscriptions s
            JOIN users u ON u.id = s.user_id AND u.account_status = 'ACTIVE'
            LEFT JOIN organizer_profiles op ON op.user_id = s.user_id
            WHERE $when AND (s.$col IS NULL OR s.$col <> $until)
            LIMIT 200")->fetchAll();
        foreach ($rows as $r) {
            $claim = db()->prepare("UPDATE social_subscriptions SET $col = ? WHERE user_id = ? AND ($col IS NULL OR $col <> ?)");
            $claim->execute([$r['until_at'], (int) $r['user_id'], $r['until_at']]);
            if ($claim->rowCount() !== 1) {
                $counts['skipped']++;
                continue;
            }
            $ended = $col === 'ended_notice_for';
            $html = socials_reminder_email_html((string) $r['name'], (string) $r['org_name'], $lead, (string) $r['until_at'],
                (int) $r['was_trial'] === 1, $ended, rtrim(APP_URL, '/') . '/org-socials.php');
            try {
                $ok = (bool) $send((string) $r['email'], $subject, $html, 'socials_' . $col);
            } catch (Throwable $e) {
                error_log('[socials] reminder failed for ' . (int) $r['user_id'] . ': ' . $e->getMessage());
                $ok = false;
            }
            $counts[$ok ? 'sent' : 'failed']++;
            try {
                create_notification((int) $r['user_id'], 'socials_plan', $subject, $ended
                    ? 'Renew for ' . socials_price_label() . ' to post again. Your posts stay up.'
                    : 'Renew now to keep posting. The paid days start after your current period.', '/org-socials.php');
            } catch (Throwable $e) {
                error_log('[socials] reminder notification failed: ' . $e->getMessage());
            }
        }
    }
    return $counts;
}

function socials_reminder_email_html(string $name, string $orgName, string $lead, string $untilAt, bool $wasTrial, bool $ended, string $url): string
{
    $first = htmlspecialchars(explode(' ', trim($name))[0] ?: 'there');
    $plan = $wasTrial ? 'free month on obitickets Socials' : 'obitickets Socials plan';
    $date = htmlspecialchars(date('l j F Y, g:ia', strtotime($untilAt)));
    $price = htmlspecialchars(socials_price_label());
    $days = (int) SOCIALS_PERIOD_DAYS;
    $org = htmlspecialchars($orgName);
    $safeUrl = htmlspecialchars($url);
    $body = $ended
        ? "<p>Your {$plan} for <strong>{$org}</strong> ended on {$date}. Your videos and photos are still up, but you can't post new ones until you renew.</p>"
        : "<p>Your {$plan} for <strong>{$org}</strong> {$lead}, on {$date}. Renew now to keep posting; the new {$days} days start when the current period ends, so you lose nothing by paying early.</p>";
    $button = $ended ? 'Renew my plan' : 'Keep posting';
    return '<div style="font-family:sans-serif; max-width:560px; margin:0 auto; color:#1A1010;">'
        . '<h2 style="color:#991B1B;">Hi ' . $first . ',</h2>' . $body
        . '<p>It costs <strong>' . $price . '</strong> for ' . $days . ' days, paid by MTN MoMo or Airtel Money.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">' . $button . '</a></p>'
        . '<p style="color:#645A5A; font-size:12px;">You are getting this because you post on obitickets Socials. Nothing is charged automatically.</p>'
        . '</div>';
}

// ------------------------------------------------------------------ stickers

/** The sticker set for comments: name => what a screen reader says. The pictures are assets/stickers/<name>.svg. */
const SOCIALS_STICKERS = [
    'lets-go' => "Let's go!",
    'see-you-there' => 'See you there',
    'fire' => 'Fire',
    'vip' => 'VIP',
    'ticket-bought' => 'Ticket bought',
    'cant-wait' => "Can't wait",
    'vibes' => 'Vibes',
    'on-my-way' => 'On my way',
    'big-up' => 'Big up',
    'wow' => 'Wow',
    'party' => 'Party time',
    'love-it' => 'Love it',
];

/** A comment that is one sticker is stored as "[sticker:fire]". Returns 'fire', or null for any other text. */
function socials_sticker_of(string $body): ?string
{
    if (preg_match('/^\[sticker:([a-z0-9-]{1,30})\]$/', trim($body), $m) && isset(SOCIALS_STICKERS[$m[1]])) {
        return $m[1];
    }
    return null;
}

// ------------------------------------------------------------------ organizer profile

/** The numbers at the top of an organizer's profile: posts, and how many organizers they follow. */
function socials_profile_counts(int $organizerId): array
{
    $posts = 0;
    if (moments_ready()) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM event_moments m JOIN event_posts p ON p.id = m.post_id JOIN events e ON e.id = m.event_id
            WHERE e.organizer_id = ? AND p.status = 'PUBLISHED' AND e.status = 'PUBLISHED' AND m.media_path <> ''" . (moments_draft_ready() ? ' AND m.is_draft = 0' : ''));
        $stmt->execute([$organizerId]);
        $posts = (int) $stmt->fetchColumn();
    }
    $following = 0;
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM organizer_follows WHERE user_id = ?');
        $stmt->execute([$organizerId]);
        $following = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $following = 0;
    }
    return ['posts' => $posts, 'following' => $following];
}

/* ---------------------------------------------------------------------------------------------------------------
   The Socials list, "Shorts Shelf" (chosen by the owner 11/14 from five mockups): category chips, one featured
   event, then every post as a tall tile two across. public/socials.php draws it; api/socials.php `shorts` adds more.
   --------------------------------------------------------------------------------------------------------------- */

/** Event categories that have Socials posts, most posts first (the list's chips). */
function socials_categories(int $limit = 6): array
{
    if (!moments_ready()) {
        return [];
    }
    try {
        $rows = db()->query("
            SELECT e.category, COUNT(*) AS n
            FROM event_moments m JOIN event_posts p ON p.id = m.post_id JOIN events e ON e.id = m.event_id
            WHERE p.status = 'PUBLISHED' AND e.status = 'PUBLISHED' AND m.media_path <> '' AND e.category <> ''"
            . (moments_draft_ready() ? ' AND m.is_draft = 0' : '') . '
            GROUP BY e.category ORDER BY n DESC, e.category LIMIT ' . max(1, min(12, $limit)))->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    return array_map(static fn ($r) => (string) $r['category'], $rows);
}

/**
 * The event at the top of the list: of the events that have not ended, the one whose Socials posts people engage
 * with most (then the one with most posts, then the soonest). Null when no upcoming event has posts.
 */
function socials_featured(): ?array
{
    if (!moments_ready()) {
        return null;
    }
    try {
        $stmt = db()->prepare('
            SELECT e.id, e.title, e.slug, e.banner_image, e.venue_name, e.starts_at, e.ends_at, COUNT(*) AS posts,
                   SUM(x.reaction_count * 3 + x.comment_count * 4 + x.share_count * 5 + LEAST(x.view_count, 5000) / 10) AS score,
                   (SELECT MIN(t.price) FROM ticket_types t WHERE t.event_id = e.id) AS min_price
            FROM (' . moment_select_sql() . ') x
            JOIN events e ON e.id = x.event_id
            WHERE e.status = "PUBLISHED" AND e.ends_at >= NOW()
            GROUP BY e.id
            ORDER BY score DESC, posts DESC, e.starts_at ASC
            LIMIT 1');
        $stmt->bindValue(':viewer', 0, PDO::PARAM_INT);
        $stmt->execute();
        $r = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$r) {
        return null;
    }
    $img = (string) ($r['banner_image'] ?? '');
    $ts = strtotime((string) $r['starts_at']);
    $te = strtotime((string) $r['ends_at']);
    $when = date('Y-m-d', $ts) === date('Y-m-d', $te) || $te < $ts ? date('D j M', $ts)
        : (date('M', $ts) === date('M', $te) ? date('j', $ts) . '–' . date('j M', $te) : date('j M', $ts) . ' – ' . date('j M', $te));
    $min = $r['min_price'];
    return [
        'id' => (int) $r['id'],
        'title' => (string) $r['title'],
        'url' => '/event.php?slug=' . rawurlencode((string) $r['slug']),
        'image' => preg_match('#^/(uploads|assets)/[A-Za-z0-9/_.\-]+$#', $img) ? $img : '',
        'where' => (string) $r['venue_name'],
        'when' => $when,
        'posts' => (int) $r['posts'],
        'price' => $min === null ? '' : ((float) $min <= 0.0 ? 'Free' : 'From UGX ' . number_format((float) $min)),
    ];
}

/** One tall tile on the list: the post's picture, a title (the caption's first line, else the event) and views. */
function socials_short_tile_html(array $it, string $tab = 'foryou'): string
{
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $q = ['v' => (int) $it['id']];
    if ($tab !== 'foryou') {
        $q['tab'] = $tab;
    }
    $href = '/socials.php?' . http_build_query($q);
    $thumb = socials_post_thumb($it);
    $caption = trim(strtok(str_replace("\r", '', (string) $it['caption']), "\n") ?: '');
    $title = $caption !== '' ? (mb_strlen($caption) > 80 ? mb_substr($caption, 0, 78) . '…' : $caption) : $it['event']['title'];
    $views = (int) $it['views'];
    $label = ($it['type'] === 'video' ? 'Watch: ' : 'Open: ') . $title . ($caption !== '' ? ', ' . $it['event']['title'] : '') . ', by ' . $it['org']['name'];
    $out = '<a class="ss-tile" href="' . $h($href) . '" aria-label="' . $h($label) . '">';
    if ($thumb !== '') {
        $out .= '<img src="' . $h($thumb) . '" alt="" loading="lazy">';
    } elseif ($it['type'] === 'video') {
        $out .= '<video src="' . $h($it['src']) . '#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>';
    }
    if ($it['type'] === 'slideshow') {
        $out .= '<span class="ss-kind" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="7" y="7" width="13" height="13" rx="2"/><path d="M4 16V5a1 1 0 0 1 1-1h11"/></svg></span>';
    } elseif ($it['type'] === 'video' && !empty($it['duration'])) {
        $out .= '<span class="ss-kind ss-dur">' . $h(moment_clock((int) $it['duration'])) . '</span>';
    }
    return $out . '<span class="ss-cap"><b>' . $h($title) . '</b><small>' . $h(moment_compact($views)) . ' ' . ($views === 1 ? 'view' : 'views') . '</small></span></a>';
}
