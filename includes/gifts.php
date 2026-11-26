<?php
// OBI GIFTS core (migration 045, ADR-001). Viewers pay UGX through ioTec mobile money to send a virtual gift to an event
// organizer; the organizer gets 60% and obitickets 40% (read from gift_commission_config at purchase time).
//
// HARD RULES (owner, 11/24):
//  * Gifts are NOT tickets. Nothing here reads or writes orders, order_items, tickets, ticket_types, `payouts` or the ticket
//    balance (get_organizer_balance). Gift money lives in gift_transactions / gift_ledger / gift_balances / gift_withdrawals.
//  * Money is whole UGX integers only. The split is stored on every gift at purchase time and never recomputed.
//  * A gift is delivered and credited exactly once, and only after OUR server has asked ioTec and heard "Success"
//    (the browser's word is never trusted). gift_settle() locks the row first, so a second poll or sweep does nothing.
//  * ioTec's collection fee is paid by obitickets out of its 40% (owner decision D1): the organizer always gets exactly 60%.
//  * A refunded gift is a REVERSAL in the ledger. If the organizer already withdrew it, their gift balance goes negative and is
//    paid back from FUTURE GIFT earnings only (owner decision D4), never from ticket money.

const GIFT_MIN_UGX = 500;                 // smallest payment for gifts
const GIFT_MAX_PAYMENT_UGX = 500000;      // largest single payment
const GIFT_DAILY_CAP_UGX = 1000000;       // most one sender can start in 24 hours
const GIFT_MAX_QUANTITY = 99;
const GIFT_PENDING_EXPIRY_MINUTES = 10;   // an unpaid prompt expires after this
const GIFT_OPEN_PAYMENT_LIMIT = 3;        // payment prompts one sender may have waiting at once
const GIFT_PHONE_HOURLY_LIMIT = 6;        // payment prompts any one phone number may receive per hour (nobody can spam a stranger's phone)
const GIFT_HOLD_HOURS = 0;                // pending -> available delay; 0 = no holding (owner rule), kept configurable
const GIFT_BIG_GIFT_UGX = 50000;          // organizer gets a "big gift" notification from this value

/** Migration 045 has run. */
function gifts_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'gift_transactions'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The Admin switch. Off by default: until it is on nobody sees a Gift button and no gift can be started. */
function gifts_enabled(): bool
{
    return gifts_ready() && get_setting('gifts_enabled', '0') === '1' && payouts_iotec_configured();
}

function gift_set_enabled(int $adminId, bool $on): void
{
    set_setting($adminId, 'gifts_enabled', $on ? '1' : '0');
}

// ---------------------------------------------------------------------------------------------------- split and commission

/** The split in force right now: ['version', 'platform_bps', 'organizer_bps']. */
function gift_commission_current(): array
{
    $st = db()->prepare('SELECT version, platform_bps, organizer_bps FROM gift_commission_config WHERE effective_from <= ? ORDER BY effective_from DESC, version DESC LIMIT 1');
    $st->execute([date('Y-m-d H:i:s')]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('The gift commission is not configured.');
    }
    return ['version' => (int) $row['version'], 'platform_bps' => (int) $row['platform_bps'], 'organizer_bps' => (int) $row['organizer_bps']];
}

/**
 * Splits a gross amount. The organizer's share is rounded DOWN to a whole shilling and obitickets gets the rest, so the two
 * always add up to the gross exactly.
 * @return array{platform:int, organizer:int}
 */
function gift_split(int $gross, int $platformBps, int $organizerBps): array
{
    if ($gross <= 0 || $platformBps < 0 || $organizerBps < 0 || $platformBps + $organizerBps !== 10000) {
        throw new InvalidArgumentException('Invalid gift split.');
    }
    $organizer = intdiv($gross * $organizerBps, 10000);
    return ['platform' => $gross - $organizer, 'organizer' => $organizer];
}

/** Admin changes the split for FUTURE gifts. History keeps the version each gift was bought under. */
function gift_set_commission(int $adminId, int $platformBps, string $reason, ?string $effectiveFrom = null): int
{
    $organizerBps = 10000 - $platformBps;
    if ($platformBps < 0 || $platformBps > 10000) {
        throw new InvalidArgumentException('The obitickets share must be between 0% and 100%.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('Give a reason for the change.');
    }
    $before = gift_commission_current();
    $version = (int) db()->query('SELECT COALESCE(MAX(version), 0) + 1 FROM gift_commission_config')->fetchColumn();
    db()->prepare('INSERT INTO gift_commission_config (version, platform_bps, organizer_bps, effective_from, changed_by, reason) VALUES (?, ?, ?, COALESCE(?, NOW()), ?, ?)')
        ->execute([$version, $platformBps, $organizerBps, $effectiveFrom, $adminId, mb_substr($reason, 0, 255)]);
    log_admin_action($adminId, 'gift.commission', 'gift_commission', $version, [
        'from' => $before['platform_bps'] . '/' . $before['organizer_bps'], 'to' => $platformBps . '/' . $organizerBps, 'reason' => $reason, 'effective_from' => $effectiveFrom,
    ]);
    return $version;
}

// ---------------------------------------------------------------------------------------------------- catalogue

/** @return list<array> active gifts, in display order (the only ones a viewer ever sees) */
function gift_catalog_active(): array
{
    return db()->query('SELECT * FROM gift_catalog WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

// ---------------------------------------------------------------------------------------------------- 36 gifts (ADR-004)

const GIFT_CATEGORIES = ['EVERYDAY' => 'Everyday', 'POPULAR' => 'Popular', 'PREMIUM' => 'Premium', 'ELITE' => 'Elite'];
const GIFT_CATEGORY_MS = ['EVERYDAY' => 2000, 'POPULAR' => 3200, 'PREMIUM' => 5000, 'ELITE' => 8000];   // animation length when a gift has none of its own
// built-in sounds, made by the browser itself (assets/js/gift-sound.js): nothing to license, nothing to download
const GIFT_SOUNDS = [
    'pop' => 'Soft pop', 'heart' => 'Warm pop', 'clap' => 'Applause', 'chime' => 'Bright chime', 'whoosh' => 'Flame whoosh', 'sparkle' => 'Sparkle',
    'balloon' => 'Balloon pop', 'sweet' => 'Sweet bells', 'unwrap' => 'Surprise', 'melody' => 'Melody', 'crystal' => 'Crystal shimmer', 'mic' => 'Ta-da',
    'popper' => 'Party popper', 'medal' => 'Medal ding', 'royal' => 'Royal fanfare', 'fanfare' => 'Victory fanfare', 'boom' => 'Fireworks',
    'launch' => 'Rocket launch', 'crowd' => 'Cheering crowd', 'party' => 'Party tune', 'drum' => 'Drum roll', 'dj' => 'DJ beat', 'kick' => 'Goal kick',
    'engine' => 'Engine rev', 'shutter' => 'Camera shutter', 'tick' => 'Ticking watch', 'jet' => 'Jet take-off', 'horn' => 'Ship horn',
    'roar' => 'Dragon roar', 'tour' => 'World tour', 'nova' => 'Supernova', 'finale' => 'Grand finale', 'cosmic' => 'Cosmic (signature)',
];
const GIFT_SOUND_MAX_BYTES = 400 * 1024;   // an uploaded sound file (MP3, OGG or WAV)

/** True once migration 049 ran (category, sound, length, signature). The code works with or without it. */
function gifts_v49(): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool) db()->query("SHOW COLUMNS FROM gift_catalog LIKE 'is_signature'")->fetch(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** The gift's category: its own, or (before migration 049) from its price. */
function gift_category_of(array $g): string
{
    if (!empty($g['category']) && isset(GIFT_CATEGORIES[$g['category']])) {
        return $g['category'];
    }
    $c = (int) ($g['price_coins'] ?? $g['price_ugx'] ?? 0);
    return $c >= 125000 ? 'ELITE' : ($c >= 20000 ? 'PREMIUM' : ($c >= 2500 ? 'POPULAR' : 'EVERYDAY'));
}

/** How long the gift's animation runs, in milliseconds (1 to 12 seconds). */
function gift_duration_ms(array $g): int
{
    $ms = (int) ($g['duration_ms'] ?? 0);
    return $ms >= 1000 && $ms <= 12000 ? $ms : GIFT_CATEGORY_MS[gift_category_of($g)];
}

/** Keeps the gift's name on the transaction, so history shows what was sent even after a rename. */
function gift_snapshot_name(PDO $pdo, int $txId, string $name): void
{
    if (gifts_v49()) {
        $pdo->prepare('UPDATE gift_transactions SET gift_name_snapshot = ? WHERE id = ?')->execute([mb_substr($name, 0, 60), $txId]);
    }
}

/**
 * The gifts a viewer may pick. With OBI COINS: every active gift that has a coin price. With the old direct mobile money payment:
 * only gifts of UGX 500 or more (smaller ones are coins only, ADR-004).
 * @return list<array>
 */
function gift_catalog_for(bool $coinsMode): array
{
    return array_values(array_filter(gift_catalog_active(), static fn ($g) => $coinsMode ? (int) ($g['price_coins'] ?? 0) > 0 : (int) $g['price_ugx'] >= GIFT_MIN_UGX));
}

function gift_find(int $giftId, bool $activeOnly = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM gift_catalog WHERE id = ?' . ($activeOnly ? ' AND is_active = 1' : ''));
    $stmt->execute([$giftId]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------------------------------------- who can send / receive

/** Why this sender cannot gift this organizer (or this video), or null when it is fine. */
function gift_recipient_error(int $organizerId, int $senderId, ?int $postId = null): ?string
{
    $stmt = db()->prepare("SELECT role, account_status FROM users WHERE id = ?");
    $stmt->execute([$organizerId]);
    $org = $stmt->fetch();
    $override = gift_recipient_status($organizerId);   // SUSPENDED | APPROVED | null: set by an admin on the eligibility screen
    if ($override === 'SUSPENDED' || !$org || $org['account_status'] !== 'ACTIVE' || ($org['role'] !== 'ORGANIZER' && $override !== 'APPROVED')) {
        return 'This organizer cannot receive gifts right now.';
    }
    if ($senderId === $organizerId) {
        return "You can't send a gift to your own account.";
    }
    $team = db()->prepare("SELECT 1 FROM organizer_team_members WHERE organizer_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1");
    $team->execute([$organizerId, $senderId]);
    if ($team->fetchColumn()) {
        return "You can't send a gift to an organizer you work for.";
    }
    if ($postId !== null) {
        $own = db()->prepare('SELECT 1 FROM event_posts p JOIN events e ON e.id = p.event_id WHERE p.id = ? AND e.organizer_id = ?');
        $own->execute([$postId, $organizerId]);
        if (!$own->fetchColumn()) {
            return 'That video does not belong to this organizer.';
        }
    }
    // eligible = any organizer who has a live (published, not draft) video
    $live = db()->prepare("SELECT 1 FROM event_moments m JOIN event_posts p ON p.id = m.post_id JOIN events e ON e.id = p.event_id
                           WHERE e.organizer_id = ? AND e.status = 'PUBLISHED' AND p.status = 'PUBLISHED' AND m.is_draft = 0 LIMIT 1");
    $live->execute([$organizerId]);
    if ($override !== 'APPROVED' && !$live->fetchColumn()) {
        return 'This organizer cannot receive gifts yet.';
    }
    return null;
}

/** MTN_MOMO / AIRTEL_MONEY from the number's prefix, or null when it is neither. */
function gift_detect_method(string $phone): ?string
{
    $d = iotec_normalize_phone($phone);
    if (preg_match('/^256(77|78|76|39)\d{7}$/', $d)) {
        return 'MTN_MOMO';
    }
    if (preg_match('/^256(70|75|74|20)\d{7}$/', $d)) {
        return 'AIRTEL_MONEY';
    }
    return null;
}

function gift_new_reference(): string
{
    return 'GFT-' . strtoupper(base_convert((string) time(), 10, 36)) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function gift_log(?int $txId, string $kind, ?string $details = null): void
{
    try {
        db()->prepare('INSERT INTO gift_events (gift_tx_id, kind, details) VALUES (?, ?, ?)')->execute([$txId, $kind, $details !== null ? mb_substr($details, 0, 500) : null]);
    } catch (Throwable $e) {
        error_log('[gift] event log failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------------------------------- start a gift (pending + ioTec prompt)

/**
 * Creates a PENDING gift and asks ioTec to prompt the sender's phone. Nothing is delivered or credited here.
 * Price and split are read from the database now and stored on the gift for good.
 *
 * @param callable|null $collect test hook with the signature of iotec_initiate_collection()
 * @return array{ok:bool, error?:string, id?:int, reference?:string, gross?:int, reused?:bool}
 */
function gift_start(array $sender, int $organizerId, int $giftId, int $quantity, bool $anonymous, string $phone, ?int $postId = null, ?string $idempotencyKey = null, ?string $method = null, ?callable $collect = null): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e];
    if (!gifts_ready()) {
        return $fail('Gifts are not available yet.');
    }
    if (function_exists('coins_enabled') && coins_enabled()) {
        return $fail('OBI GIFTS are paid with OBI COINS now. Add OBI COINS to your wallet, then send the gift.');   // owner decision 1 (ADR-002): coins replace the direct payment
    }
    // A developer's computer holds real ioTec keys: it must NEVER start a real payment request (it could prompt a stranger's phone).
    // Without an injected collector or the pretend mode (gift_collect_fn), a development computer refuses before anything is created.
    if ($collect === null && gift_is_development()) {
        return $fail('Real payments are switched off on this development computer. Turn on GIFTS_FAKE_PAYMENTS=1 in .env to try gifts with pretend payments.');
    }
    $senderId = (int) $sender['id'];
    if ($quantity < 1 || $quantity > GIFT_MAX_QUANTITY) {
        return $fail('Choose between 1 and ' . GIFT_MAX_QUANTITY . ' gifts.');
    }
    if (!payout_valid_phone($phone)) {
        return $fail('Enter a valid MTN or Airtel number, like 0772 123 456.');
    }
    $method = gift_detect_method($phone) ?? (in_array($method, ['MTN_MOMO', 'AIRTEL_MONEY'], true) ? $method : null);
    if ($method === null) {
        return $fail('Use an MTN or Airtel mobile money number.');
    }
    $gift = gift_find($giftId, true);
    if (!$gift) {
        return $fail('That gift is not available.');
    }
    if ($err = gift_recipient_error($organizerId, $senderId, $postId)) {
        return $fail($err);
    }
    $unit = (int) $gift['price_ugx'];
    $gross = $unit * $quantity;
    if ($unit < GIFT_MIN_UGX) {
        return $fail('This gift can only be sent with OBI COINS.');   // ADR-004: gifts under UGX 500 are coins only, never a direct payment
    }
    if ($gross < GIFT_MIN_UGX) {
        return $fail('The smallest gift payment is UGX ' . number_format(GIFT_MIN_UGX) . '.');
    }
    if ($gross > GIFT_MAX_PAYMENT_UGX) {
        return $fail('A single gift payment can be at most UGX ' . number_format(GIFT_MAX_PAYMENT_UGX) . '.');
    }
    $idem = $idempotencyKey !== null && $idempotencyKey !== '' ? mb_substr($idempotencyKey, 0, 64) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$senderId]);   // one gift start at a time per sender: limits cannot be raced
        if ($idem !== null) {
            $dup = $pdo->prepare('SELECT id, reference, gross_ugx FROM gift_transactions WHERE sender_id = ? AND idempotency_key = ?');
            $dup->execute([$senderId, $idem]);
            if ($row = $dup->fetch()) {
                $pdo->commit();
                return ['ok' => true, 'id' => (int) $row['id'], 'reference' => $row['reference'], 'gross' => (int) $row['gross_ugx'], 'reused' => true];
            }
        }
        $open = $pdo->prepare("SELECT COUNT(*) FROM gift_transactions WHERE sender_id = ? AND payment_status IN ('PENDING','PROCESSING') AND created_at > NOW() - INTERVAL " . (int) GIFT_PENDING_EXPIRY_MINUTES . ' MINUTE');
        $open->execute([$senderId]);
        if ((int) $open->fetchColumn() >= GIFT_OPEN_PAYMENT_LIMIT) {
            $pdo->rollBack();
            return $fail('You have gift payments waiting for approval on your phone. Approve or wait a few minutes.');
        }
        $day = $pdo->prepare("SELECT COALESCE(SUM(gross_ugx), 0) FROM gift_transactions WHERE sender_id = ? AND created_at >= NOW() - INTERVAL 24 HOUR AND payment_status IN ('PENDING','PROCESSING','SUCCESSFUL')");
        $day->execute([$senderId]);
        if ((int) $day->fetchColumn() + $gross > GIFT_DAILY_CAP_UGX) {
            $pdo->rollBack();
            return $fail('You have reached the daily gift limit of UGX ' . number_format(GIFT_DAILY_CAP_UGX) . '. Try again tomorrow.');
        }
        $phoneN = iotec_normalize_phone($phone);
        $byPhone = $pdo->prepare('SELECT COUNT(*) FROM gift_transactions WHERE phone = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $byPhone->execute([$phoneN]);
        if ((int) $byPhone->fetchColumn() >= GIFT_PHONE_HOURLY_LIMIT) {
            $pdo->rollBack();
            return $fail('Too many gift payments were started for this number in the last hour. Please try again later.');
        }
        $cfg = gift_commission_current();
        $split = gift_split($gross, $cfg['platform_bps'], $cfg['organizer_bps']);
        $reference = gift_new_reference();
        $pdo->prepare('INSERT INTO gift_transactions
              (reference, idempotency_key, sender_id, recipient_organizer_id, gift_id, post_id, quantity, unit_price_ugx, gross_ugx, platform_ugx, organizer_ugx,
               config_version, payment_method, phone, is_anonymous)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $idem, $senderId, $organizerId, $giftId, $postId, $quantity, $unit, $gross, $split['platform'], $split['organizer'],
                $cfg['version'], $method, $phoneN, $anonymous ? 1 : 0]);
        $txId = (int) $pdo->lastInsertId();
        gift_snapshot_name($pdo, $txId, (string) $gift['name']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[gift] could not create gift: ' . $e->getMessage());
        return $fail('Something went wrong. Please try again.');
    }

    try {
        $note = 'obitickets gift ' . $reference;
        $res = ($collect ?? 'iotec_initiate_collection')((float) $gross, $phone, 'GIFT-' . $txId, $note);
        $pdo->prepare("UPDATE gift_transactions SET provider_reference = ?, payment_status = 'PROCESSING' WHERE id = ? AND payment_status = 'PENDING'")
            ->execute([(string) $res['transactionId'], $txId]);
    } catch (Throwable $e) {
        error_log('[gift] initiate collection failed for gift ' . $txId . ': ' . $e->getMessage());
        gift_fail($txId, "We couldn't start the mobile money payment. Please try again.");
        return $fail("We couldn't start the mobile money payment. Please try again.");
    }
    gift_log($txId, 'started', 'UGX ' . $gross . ' via ' . $method);
    return ['ok' => true, 'id' => $txId, 'reference' => $reference, 'gross' => $gross];
}

/** The payment did not happen: no delivery, no earnings, no commission. Only a waiting gift can fail. */
function gift_fail(int $txId, string $message, string $status = 'FAILED'): bool
{
    if (!in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
        $status = 'FAILED';
    }
    $u = db()->prepare("UPDATE gift_transactions SET payment_status = ?, delivery_status = 'NONE', status_message = ? WHERE id = ? AND payment_status IN ('PENDING','PROCESSING')");
    $u->execute([$status, mb_substr($message, 0, 250), $txId]);
    if ($u->rowCount() === 1) {
        gift_log($txId, strtolower($status), $message);
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------------------------------- settle (payment verified)

function gift_ledger_add(PDO $pdo, ?int $txId, string $account, string $entry, int $amount, string $type, ?int $reverses = null, ?string $note = null): int
{
    $pdo->prepare('INSERT INTO gift_ledger (gift_tx_id, account, entry, amount_ugx, entry_type, reverses_id, note) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$txId, $account, $entry, $amount, $type, $reverses, $note]);
    return (int) $pdo->lastInsertId();
}

/**
 * The money rows of a delivered gift, written inside the caller's transaction: the gift ledger (the payer's account is debited, obitickets and the
 * organizer are credited, plus the ioTec fee rows when known) and the organizer's UGX gift balance. $debitAccount is SENDER_CLEARING for a gift paid
 * directly in UGX and COIN_LIABILITY for a gift paid with OBI COINS.
 */
function gift_post_delivery(PDO $pdo, int $txId, int $orgId, int $gross, int $platform, int $organizer, ?int $fee, string $debitAccount): void
{
    $hold = (int) GIFT_HOLD_HOURS;
    gift_ledger_add($pdo, $txId, $debitAccount, 'DEBIT', $gross, 'GIFT');
    gift_ledger_add($pdo, $txId, 'PLATFORM_COMMISSION', 'CREDIT', $platform, 'GIFT');
    gift_ledger_add($pdo, $txId, 'ORGANIZER:' . $orgId, 'CREDIT', $organizer, 'GIFT');
    if ($fee !== null) {
        gift_ledger_add($pdo, $txId, 'PLATFORM_COMMISSION', 'DEBIT', $fee, 'FEE', null, 'ioTec collection fee, paid by obitickets');
        gift_ledger_add($pdo, $txId, 'GATEWAY_FEE', 'CREDIT', $fee, 'FEE');
    }
    gift_balance_row($pdo, $orgId);
    $pdo->prepare('UPDATE gift_balances SET ' . ($hold > 0 ? 'pending_ugx = pending_ugx + ?' : 'available_ugx = available_ugx + ?') . ', total_earned_ugx = total_earned_ugx + ? WHERE organizer_id = ?')
        ->execute([$organizer, $organizer, $orgId]);
}

function gift_balance_row(PDO $pdo, int $organizerId): void
{
    $pdo->prepare('INSERT INTO gift_balances (organizer_id) VALUES (?) ON DUPLICATE KEY UPDATE organizer_id = organizer_id')->execute([$organizerId]);
}

/**
 * Marks a gift paid, delivers it and records the money, all in ONE database transaction, exactly once. The row is locked first
 * and nothing happens unless it is still waiting (or expired a moment ago and the money arrived late), so a second call (a slow
 * poll, the cron sweep, a repeated callback) cannot deliver or credit again.
 *
 * @param int|null $gatewayFee ioTec's collection fee in UGX when known; obitickets pays it out of its share
 */
function gift_settle(int $txId, ?string $message = null, ?int $gatewayFee = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gift_transactions WHERE id = ? FOR UPDATE');
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();
        if (!$tx || !in_array($tx['payment_status'], ['PENDING', 'PROCESSING', 'EXPIRED'], true)) {
            $pdo->rollBack();
            return false;
        }
        $wasExpired = $tx['payment_status'] === 'EXPIRED';
        $gross = (int) $tx['gross_ugx'];
        $platform = (int) $tx['platform_ugx'];
        $organizer = (int) $tx['organizer_ugx'];
        $orgId = (int) $tx['recipient_organizer_id'];
        $fee = $gatewayFee !== null && $gatewayFee > 0 ? min($gatewayFee, $platform) : null;   // never more than obitickets' own share

        $hold = (int) GIFT_HOLD_HOURS;
        $pdo->prepare("UPDATE gift_transactions SET payment_status = 'SUCCESSFUL', delivery_status = 'DELIVERED', status_message = ?, gateway_fee_ugx = ?,
                           paid_at = NOW(), delivered_at = NOW(), available_at = NOW() + INTERVAL $hold HOUR, released_at = " . ($hold > 0 ? 'NULL' : 'NOW()') . ' WHERE id = ?')
            ->execute([$message !== null ? mb_substr($message, 0, 250) : null, $fee, $txId]);

        gift_post_delivery($pdo, $txId, $orgId, $gross, $platform, $organizer, $fee, 'SENDER_CLEARING');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    gift_log($txId, $wasExpired ? 'settled_after_expiry' : 'settled', 'UGX ' . $gross . ' = platform ' . $platform . ' + organizer ' . $organizer . ($fee !== null ? ', fee ' . $fee : ''));
    gift_notify_delivered($txId);
    return true;
}

/** Who the viewers see as the sender. Anonymous gifts never show the name (the real account stays in the protected records). */
function gift_sender_label(array $tx): string
{
    if (!empty($tx['is_anonymous'])) {
        return 'Anonymous supporter';
    }
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([(int) $tx['sender_id']]);
    $name = trim((string) $stmt->fetchColumn());
    return $name !== '' ? (preg_split('/\s+/', $name)[0] ?? $name) : 'Someone';
}

/** The line shown with the animation, e.g. "Mike sent a Diamond 💎 to Cloud9 Events!" */
function gift_public_message(int $txId): ?string
{
    $stmt = db()->prepare('SELECT t.*, g.name AS gift_name, g.emoji, COALESCE(NULLIF(op.org_name, \'\'), u.name) AS org_name
                           FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id JOIN users u ON u.id = t.recipient_organizer_id
                           LEFT JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id WHERE t.id = ?');
    $stmt->execute([$txId]);
    $t = $stmt->fetch();
    if (!$t) {
        return null;
    }
    $what = ((int) $t['quantity'] > 1 ? (int) $t['quantity'] . ' x ' : 'a ') . $t['gift_name'] . ($t['emoji'] ? ' ' . $t['emoji'] : '');
    return gift_sender_label($t) . ' sent ' . $what . ' to ' . $t['org_name'] . '!';
}

function gift_notify_delivered(int $txId): void
{
    try {
        $stmt = db()->prepare('SELECT t.*, g.name AS gift_name, g.price_coins, g.price_ugx' . (gifts_v49() ? ', g.category, g.is_signature' : '') . ' FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id WHERE t.id = ?');
        $stmt->execute([$txId]);
        $t = $stmt->fetch();
        if (!$t) {
            return;
        }
        $label = ((int) $t['quantity'] > 1 ? (int) $t['quantity'] . ' x ' : '') . $t['gift_name'];
        $paidText = ($t['payment_source'] ?? 'DIRECT') === 'COINS' ? number_format((int) $t['coins_spent']) . ' OBI COINS' : 'UGX ' . number_format((int) $t['gross_ugx']);
        create_notification((int) $t['sender_id'], 'gift', 'Gift sent', 'Your ' . $label . ' (' . $paidText . ') was delivered. Thank you for supporting the organizer.', ($t['payment_source'] ?? 'DIRECT') === 'COINS' ? '/wallet.php' : null);
        $big = (int) $t['gross_ugx'] >= GIFT_BIG_GIFT_UGX;
        $cat = gift_category_of($t);
        // the bigger the gift, the louder the title (ADR-004): the signature gift and Elite gifts get their own
        $title = !empty($t['is_signature']) ? 'You received ' . $t['gift_name'] . '!' : ($cat === 'ELITE' ? 'You received an Elite OBI GIFT: ' . $t['gift_name'] . '!' : ($big ? 'You received a big OBI GIFT!' : 'You received an OBI GIFT'));
        create_notification((int) $t['recipient_organizer_id'], 'gift', $title,
            gift_sender_label($t) . ' sent you ' . $label . (($t['payment_source'] ?? 'DIRECT') === 'COINS' ? ' (' . number_format((int) $t['coins_spent']) . ' OBI COINS)' : '') . '. UGX ' . number_format((int) $t['organizer_ugx']) . ' was added to your gift earnings.', '/org-gifts.php?tab=history');
    } catch (Throwable $e) {
        error_log('[gift] notification failed: ' . $e->getMessage());
    }
    // the organizer's automatic thank-you, if they switched it on (includes/gift-fans.php); never blocks or changes the gift
    if (function_exists('gift_auto_thank')) {
        gift_auto_thank($txId);
    }
    // the organizer's email about this gift: only queued here, the background job sends it (includes/gift-emails.php)
    if (function_exists('gift_email_queue')) {
        gift_email_queue($txId);
    }
}

// ---------------------------------------------------------------------------------------------------- ask ioTec and settle

/**
 * Asks ioTec about one waiting gift and settles, fails or expires it. Safe to call from a page poll AND the cron sweep.
 * @param callable|null $statusCheck test hook with the signature of iotec_check_collection_status()
 * @return array{status:string, message:?string, delivered:bool}   status: PENDING | PAID | FAILED
 */
function gift_resolve(array $tx, ?callable $statusCheck = null): array
{
    $out = static fn (string $s, ?string $m = null, bool $d = false): array => ['status' => $s, 'message' => $m, 'delivered' => $d];
    $state = (string) $tx['payment_status'];
    if ($state === 'SUCCESSFUL' || $state === 'REFUNDED') {
        return $out('PAID', null, $tx['delivery_status'] === 'DELIVERED');
    }
    if (in_array($state, ['FAILED', 'CANCELLED'], true)) {
        return $out('FAILED', $tx['status_message']);
    }
    $stale = strtotime((string) $tx['created_at']) < time() - GIFT_PENDING_EXPIRY_MINUTES * 60;
    if (empty($tx['provider_reference'])) {
        if ($stale && $state !== 'EXPIRED') {
            gift_fail((int) $tx['id'], 'The payment request expired. Please try again.', 'EXPIRED');
            return $out('FAILED', 'The payment request expired. Please try again.');
        }
        return $state === 'EXPIRED' ? $out('FAILED', $tx['status_message']) : $out('PENDING');
    }
    if ($skip = gift_skip_real_check($statusCheck, (string) $tx['provider_reference'], gift_is_development(), gift_fake_allowed())) {
        if ($skip === 'fake_reference') {
            gift_log((int) $tx['id'], 'fake_reference_ignored', 'a pretend payment reference was seen where pretend payments are not allowed');
        }
        return $out($state === 'EXPIRED' ? 'FAILED' : 'PENDING', $state === 'EXPIRED' ? $tx['status_message'] : null);
    }
    try {
        $r = ($statusCheck ?? 'iotec_check_collection_status')((string) $tx['provider_reference']);
    } catch (Throwable $e) {
        error_log('[gift] status check failed for gift ' . $tx['id'] . ': ' . $e->getMessage());
        return $state === 'EXPIRED' ? $out('FAILED', $tx['status_message']) : $out('PENDING');
    }
    $status = (string) ($r['status'] ?? '');
    if ($status === 'Success') {
        // the amount ioTec says it collected must be the amount of the gift; otherwise a person looks at it first
        if (isset($r['amount']) && $r['amount'] !== null && (int) round((float) $r['amount']) !== (int) $tx['gross_ugx']) {
            gift_log((int) $tx['id'], 'amount_mismatch', 'ioTec collected ' . $r['amount'] . ', the gift is ' . $tx['gross_ugx']);
            return $out('PENDING', 'This payment is being checked.');
        }
        $fee = isset($r['charge']) && $r['charge'] !== null ? (int) round((float) $r['charge']) : null;
        gift_settle((int) $tx['id'], $r['statusMessage'] ?? null, $fee);
        return $out('PAID', $r['statusMessage'] ?? null, true);
    }
    if ($state === 'EXPIRED') {
        return $out('FAILED', $tx['status_message']);
    }
    if ($status === 'Failed') {
        $msg = (string) ($r['statusMessage'] ?? 'The payment was not completed.');
        gift_fail((int) $tx['id'], $msg);
        return $out('FAILED', $msg);
    }
    if ($stale) {
        gift_fail((int) $tx['id'], 'The payment request expired. Please try again.', 'EXPIRED');
        return $out('FAILED', 'The payment request expired. Please try again.');
    }
    return $out('PENDING', $r['statusMessage'] ?? null);
}

/** The sender's page asks how their gift is doing (only their own gift). */
function gift_poll(int $txId, int $senderId, ?callable $statusCheck = null): array
{
    $stmt = db()->prepare('SELECT * FROM gift_transactions WHERE id = ? AND sender_id = ?');
    $stmt->execute([$txId, $senderId]);
    $tx = $stmt->fetch();
    if (!$tx) {
        throw new RuntimeException('Gift not found.');
    }
    $res = gift_resolve($tx, $statusCheck);
    if ($res['status'] === 'PAID') {
        $res['public_message'] = gift_public_message($txId);
    }
    return $res;
}

/**
 * Cron (every 5 minutes): settles gifts whose sender closed the app before the payment finished, expires prompts nobody approved,
 * re-checks gifts that expired in the last 24 hours (money can arrive late), and releases earnings whose hold is over.
 * @return array{checked:int, settled:int, released:int}
 */
function gift_sweep(int $limit = 50, ?callable $statusCheck = null): array
{
    if (!gifts_ready()) {
        return ['checked' => 0, 'settled' => 0, 'released' => 0];
    }
    $rows = db()->query("SELECT * FROM gift_transactions
                         WHERE payment_status IN ('PENDING','PROCESSING') OR (payment_status = 'EXPIRED' AND provider_reference IS NOT NULL AND created_at > NOW() - INTERVAL 24 HOUR)
                         ORDER BY id LIMIT " . (int) $limit)->fetchAll();
    $checked = 0;
    $settled = 0;
    foreach ($rows as $tx) {
        $before = $tx['payment_status'];
        $res = gift_resolve($tx, $statusCheck);
        $checked++;
        if ($res['status'] === 'PAID' && $before !== 'SUCCESSFUL') {
            $settled++;
        }
    }
    return ['checked' => $checked, 'settled' => $settled, 'released' => gift_release_due()];
}

/** With a hold configured: moves an organizer's pending earnings to available once the hold is over. */
function gift_release_due(): int
{
    $ids = db()->query("SELECT id FROM gift_transactions WHERE payment_status = 'SUCCESSFUL' AND released_at IS NULL AND available_at <= NOW() ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM gift_transactions WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $id]);
            $tx = $stmt->fetch();
            if ($tx && $tx['payment_status'] === 'SUCCESSFUL' && $tx['released_at'] === null) {
                $org = (int) $tx['organizer_ugx'];
                $pdo->prepare('UPDATE gift_balances SET pending_ugx = pending_ugx - ?, available_ugx = available_ugx + ? WHERE organizer_id = ?')->execute([$org, $org, (int) $tx['recipient_organizer_id']]);
                $pdo->prepare('UPDATE gift_transactions SET released_at = NOW() WHERE id = ?')->execute([(int) $id]);
                $n++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[gift] release failed for ' . $id . ': ' . $e->getMessage());
        }
    }
    return $n;
}

// ---------------------------------------------------------------------------------------------------- reversal (refund)

/**
 * An authorized admin reverses a delivered gift. The ledger gets mirror REVERSAL rows (the originals are never edited) and the
 * organizer's gift balance goes down by their share. If they already withdrew it, the balance goes negative and is paid back
 * from future gift earnings only. ioTec's collection fee is not returned. Sending the money back to the sender's phone is
 * done by hand outside this system and is not recorded as a payout here.
 */
function gift_reverse(int $txId, int $adminId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Give a reason for the reversal.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gift_transactions WHERE id = ? FOR UPDATE');
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();
        if (!$tx || $tx['payment_status'] !== 'SUCCESSFUL') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Only a delivered gift can be reversed, and only once.'];
        }
        $orgId = (int) $tx['recipient_organizer_id'];
        $organizer = (int) $tx['organizer_ugx'];
        $orig = $pdo->prepare("SELECT * FROM gift_ledger WHERE gift_tx_id = ? AND entry_type = 'GIFT' ORDER BY id");
        $orig->execute([$txId]);
        foreach ($orig->fetchAll() as $row) {
            gift_ledger_add($pdo, $txId, (string) $row['account'], $row['entry'] === 'DEBIT' ? 'CREDIT' : 'DEBIT', (int) $row['amount_ugx'], 'REVERSAL', (int) $row['id'], mb_substr($reason, 0, 255));
        }
        gift_balance_row($pdo, $orgId);
        $pdo->prepare('UPDATE gift_balances SET ' . ($tx['released_at'] !== null ? 'available_ugx = available_ugx - ?' : 'pending_ugx = pending_ugx - ?') . ', total_earned_ugx = total_earned_ugx - ? WHERE organizer_id = ?')
            ->execute([$organizer, $organizer, $orgId]);
        $pdo->prepare("UPDATE gift_transactions SET payment_status = 'REFUNDED', delivery_status = 'REVERSED', refunded_at = NOW() WHERE id = ?")->execute([$txId]);
        if (($tx['payment_source'] ?? 'DIRECT') === 'COINS') {
            coin_wallet_apply($pdo, (int) $tx['sender_id'], 'GIFT_REFUND', (int) $tx['coins_spent'], 'CRF-' . $txId, 'gift', $txId, 'Gift reversed: ' . mb_substr($reason, 0, 120), $adminId);
        }
        $debt = (int) $pdo->query('SELECT available_ugx FROM gift_balances WHERE organizer_id = ' . $orgId)->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    log_admin_action($adminId, 'gift.reverse', 'gift_transaction', $txId, ['reason' => $reason, 'gross' => (int) $tx['gross_ugx'], 'organizer_share' => $organizer]);
    gift_log($txId, 'reversed', $reason);
    if ($debt < 0) {
        gift_log($txId, 'organizer_gift_debt', 'organizer ' . $orgId . ' gift balance is now UGX ' . $debt . ', paid back from future gifts');
    }
    try {
        if (($tx['payment_source'] ?? 'DIRECT') === 'COINS') {
            create_notification((int) $tx['sender_id'], 'gift', 'Gift refunded', 'Your gift was reversed and ' . number_format((int) $tx['coins_spent']) . ' OBI COINS were returned to your wallet.', '/wallet.php');
        } else {
            create_notification((int) $tx['sender_id'], 'gift', 'Gift refunded', 'Your gift of UGX ' . number_format((int) $tx['gross_ugx']) . ' was refunded. Our team will send the money back to your phone.', null);
        }
        create_notification($orgId, 'gift', 'A gift was reversed', 'A gift of UGX ' . number_format((int) $tx['gross_ugx']) . ' was reversed (' . $reason . '). UGX ' . number_format($organizer) . ' was taken off your gift earnings.', '/org-gifts.php?tab=history');
    } catch (Throwable $e) {
        error_log('[gift] reversal notification failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'balance_after' => $debt];
}

// ---------------------------------------------------------------------------------------------------- balances and integrity

/** @return array{pending:int, available:int, reserved:int, total_earned:int, total_withdrawn:int} */
function gift_balance(int $organizerId): array
{
    $stmt = db()->prepare('SELECT * FROM gift_balances WHERE organizer_id = ?');
    $stmt->execute([$organizerId]);
    $r = $stmt->fetch();
    return [
        'pending' => (int) ($r['pending_ugx'] ?? 0), 'available' => (int) ($r['available_ugx'] ?? 0), 'reserved' => (int) ($r['reserved_ugx'] ?? 0),
        'total_earned' => (int) ($r['total_earned_ugx'] ?? 0), 'total_withdrawn' => (int) ($r['total_withdrawn_ugx'] ?? 0),
    ];
}

/**
 * Proves the books are consistent. Returns a list of problems (empty = all good):
 *  - every delivered or refunded gift: platform + organizer = gross = price x quantity
 *  - every gift's ledger rows balance (debits = credits)
 *  - every delivered gift has its ledger rows; no waiting/failed gift has any
 *  - every organizer: total earned = their shares of delivered gifts; earned - withdrawn - reserved = available + pending
 * @return list<string>
 */
function gift_reconcile(): array
{
    $problems = [];
    $pdo = db();
    foreach ($pdo->query("SELECT id, reference, gross_ugx, platform_ugx, organizer_ugx, unit_price_ugx, quantity FROM gift_transactions WHERE payment_status IN ('SUCCESSFUL','REFUNDED')")->fetchAll() as $t) {
        if ((int) $t['platform_ugx'] + (int) $t['organizer_ugx'] !== (int) $t['gross_ugx'] || (int) $t['gross_ugx'] !== (int) $t['unit_price_ugx'] * (int) $t['quantity']) {
            $problems[] = 'gift ' . $t['reference'] . ': the split does not add up to the gross';
        }
    }
    foreach ($pdo->query("SELECT gift_tx_id, SUM(CASE entry WHEN 'DEBIT' THEN amount_ugx ELSE -amount_ugx END) AS diff FROM gift_ledger WHERE gift_tx_id IS NOT NULL GROUP BY gift_tx_id HAVING diff <> 0")->fetchAll() as $r) {
        $problems[] = 'gift #' . $r['gift_tx_id'] . ': ledger debits and credits differ by ' . $r['diff'];
    }
    foreach ($pdo->query("SELECT t.id, t.reference, t.payment_status, (SELECT COUNT(*) FROM gift_ledger l WHERE l.gift_tx_id = t.id AND l.entry_type = 'GIFT') AS n FROM gift_transactions t")->fetchAll() as $r) {
        $n = (int) $r['n'];
        if (in_array($r['payment_status'], ['SUCCESSFUL', 'REFUNDED'], true) && $n < 3) {
            $problems[] = 'gift ' . $r['reference'] . ': delivered but its ledger rows are missing';
        }
        if (in_array($r['payment_status'], ['PENDING', 'PROCESSING', 'FAILED', 'CANCELLED', 'EXPIRED'], true) && $n > 0) {
            $problems[] = 'gift ' . $r['reference'] . ': ' . $r['payment_status'] . ' but money was recorded';
        }
    }
    $earned = $pdo->query("SELECT recipient_organizer_id AS id, SUM(organizer_ugx) AS s FROM gift_transactions WHERE payment_status = 'SUCCESSFUL' GROUP BY recipient_organizer_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($pdo->query('SELECT * FROM gift_balances')->fetchAll() as $b) {
        $id = (int) $b['organizer_id'];
        $expected = (int) ($earned[$id] ?? 0);
        if ((int) $b['total_earned_ugx'] !== $expected) {
            $problems[] = 'organizer ' . $id . ': total earned ' . $b['total_earned_ugx'] . ' but delivered gifts add up to ' . $expected;
        }
        if ((int) $b['total_earned_ugx'] - (int) $b['total_withdrawn_ugx'] - (int) $b['reserved_ugx'] !== (int) $b['available_ugx'] + (int) $b['pending_ugx']) {
            $problems[] = 'organizer ' . $id . ': earned - withdrawn - reserved does not equal available + pending';
        }
    }
    foreach ($earned as $id => $s) {
        $has = $pdo->prepare('SELECT 1 FROM gift_balances WHERE organizer_id = ?');
        $has->execute([(int) $id]);
        if (!$has->fetchColumn()) {
            $problems[] = 'organizer ' . $id . ' has delivered gifts but no balance row';
        }
    }
    // withdrawals (phase 5): reserved money = open withdrawals, withdrawn money = paid ones, every paid one has balanced ledger rows
    $open = $pdo->query("SELECT organizer_id AS id, SUM(amount_ugx) AS s FROM gift_withdrawals WHERE status IN ('REQUESTED','PENDING_REVIEW','APPROVED','PROCESSING') GROUP BY organizer_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $paid = $pdo->query("SELECT organizer_id AS id, SUM(amount_ugx) AS s FROM gift_withdrawals WHERE status = 'PAID' GROUP BY organizer_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($pdo->query('SELECT organizer_id, reserved_ugx, total_withdrawn_ugx FROM gift_balances')->fetchAll() as $b) {
        $id = (int) $b['organizer_id'];
        if ((int) $b['reserved_ugx'] !== (int) ($open[$id] ?? 0)) {
            $problems[] = 'organizer ' . $id . ': reserved ' . $b['reserved_ugx'] . ' but open withdrawals add up to ' . (int) ($open[$id] ?? 0);
        }
        if ((int) $b['total_withdrawn_ugx'] !== (int) ($paid[$id] ?? 0)) {
            $problems[] = 'organizer ' . $id . ': withdrawn ' . $b['total_withdrawn_ugx'] . ' but paid withdrawals add up to ' . (int) ($paid[$id] ?? 0);
        }
    }
    foreach ($pdo->query("SELECT w.id, w.reference, w.status, w.amount_ugx, (SELECT COUNT(*) FROM gift_ledger l WHERE l.withdrawal_id = w.id) AS n,
                                 (SELECT COALESCE(SUM(CASE l.entry WHEN 'DEBIT' THEN l.amount_ugx ELSE -l.amount_ugx END), 0) FROM gift_ledger l WHERE l.withdrawal_id = w.id) AS diff,
                                 (SELECT COALESCE(SUM(CASE l.entry WHEN 'DEBIT' THEN l.amount_ugx ELSE 0 END), 0) FROM gift_ledger l WHERE l.withdrawal_id = w.id) AS debits
                          FROM gift_withdrawals w")->fetchAll() as $r) {
        $ref = 'withdrawal ' . $r['reference'];
        if ($r['status'] === 'PAID' && ((int) $r['n'] !== 2 || (int) $r['diff'] !== 0 || (int) $r['debits'] !== (int) $r['amount_ugx'])) {
            $problems[] = $ref . ': paid but its ledger rows are missing or wrong';
        }
        if ($r['status'] !== 'PAID' && (int) $r['n'] > 0) {
            $problems[] = $ref . ': ' . $r['status'] . ' but money was recorded in the ledger';
        }
    }
    if (function_exists('coin_reconcile')) {
        $problems = array_merge($problems, coin_reconcile());   // wallets, purchases and gifts sent with OBI COINS (includes/coins.php)
    }
    return $problems;
}

// ---------------------------------------------------------------------------------------------------- admin: catalogue (phase 2)

const GIFT_ANIMATIONS = [
    'float' => 'Floating (rises up the screen)', 'pop' => 'Pop in', 'sparkle' => 'Sparkle', 'glow' => 'Glow',
    'fly' => 'Fly across', 'confetti' => 'Confetti', 'drive' => 'Drive across', 'jet' => 'Jet fly-by',
    // ADR-004
    'clap' => 'Clapping', 'flame' => 'Flames and embers', 'unbox' => 'Gift box opens', 'spin' => 'Spin and shine', 'fireworks' => 'Fireworks',
    'stage' => 'Spotlights', 'beat' => 'Beat pulses', 'bounce' => 'Bounce across', 'flash' => 'Camera flashes', 'sail' => 'Sail across',
    'castle' => 'Rise with fireworks', 'dragon' => 'Dragon fly-by', 'globe' => 'Globe and orbit', 'throne' => 'Descend in light',
    'nova' => 'Supernova blast', 'finale' => 'Grand finale show', 'universe' => 'Cosmic (signature)',
];
const GIFT_IMAGE_MAX_BYTES = 1024 * 1024;       // 1 MB for the picture
const GIFT_ANIM_MAX_BYTES = 2 * 1024 * 1024;    // 2 MB for an animated file

/** Every gift (hidden ones too) with how many were delivered and their gross value. */
function gift_catalog_all(): array
{
    return db()->query("SELECT g.*, COALESCE(s.n, 0) AS sent_count, COALESCE(s.gross, 0) AS sent_gross
                        FROM gift_catalog g
                        LEFT JOIN (SELECT gift_id, COUNT(*) n, SUM(gross_ugx) gross FROM gift_transactions WHERE payment_status = 'SUCCESSFUL' GROUP BY gift_id) s ON s.gift_id = g.id
                        ORDER BY g.sort_order, g.id")->fetchAll();
}

/**
 * Stores a gift picture or animated file. The real type is read from the file's own bytes (never the browser's claim), the stored
 * name is random, and SVG is refused on purpose (it can carry scripts).
 * @param string $kind 'image' (png, webp, gif, jpg, 1 MB) or 'animation' (webp, gif, png, 2 MB)
 * @param callable|null $mover test hook replacing is_uploaded_file()/move_uploaded_file()
 * @return array{0: bool, 1: ?string, 2: ?string} [ok, public path or null, error]
 */
function gift_handle_upload(array $file, string $kind = 'image', ?callable $mover = null): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, 'There was a problem uploading that file. Please try again.'];
    }
    $max = $kind === 'animation' ? GIFT_ANIM_MAX_BYTES : GIFT_IMAGE_MAX_BYTES;
    if ((int) $file['size'] > $max) {
        return [false, null, 'That file is too large. Please use one under ' . ($max / 1024 / 1024) . ' MB.'];
    }
    if ($mover === null && !is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'That upload could not be verified. Please try again.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [false, null, "That file doesn't look like a valid image."];
    }
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg'][$info[2]] ?? null;
    if ($ext === null || ($kind === 'animation' && $ext === 'jpg')) {
        return [false, null, $kind === 'animation' ? 'Please upload a WEBP, GIF or PNG file.' : 'Please upload a PNG, WEBP, GIF or JPG picture.'];
    }
    $dir = __DIR__ . '/../uploads/gifts';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [false, null, 'Could not prepare storage for the file. Please try again.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $mover !== null ? $mover($file['tmp_name'], $dir . '/' . $name) : move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
    if (!$moved) {
        return [false, null, 'Could not save the file. Please try again.'];
    }
    return [true, '/uploads/gifts/' . $name, null];
}

/** Writes the ADR-004 fields. Only one gift can be the signature gift: marking one clears the others. */
function gift_save_v49(PDO $pdo, int $giftId, string $category, string $soundStyle, ?string $soundAsset, ?int $durationMs, int $signature, int $featured): void
{
    if ($signature === 1) {
        $pdo->prepare('UPDATE gift_catalog SET is_signature = 0 WHERE id <> ? AND is_signature = 1')->execute([$giftId]);
    }
    $pdo->prepare('UPDATE gift_catalog SET category = ?, sound_style = ?, sound_asset = ?, duration_ms = ?, is_signature = ?, is_featured = ? WHERE id = ?')
        ->execute([$category, $soundStyle !== '' ? $soundStyle : null, $soundAsset, $durationMs, $signature, $featured, $giftId]);
}

/**
 * Stores an uploaded gift sound. The real type is read from the file's first bytes (never the browser's claim): MP3, OGG or WAV only,
 * at most GIFT_SOUND_MAX_BYTES, under a random name.
 * @return array{0: bool, 1: ?string, 2: ?string} [ok, public path or null, error]
 */
function gift_handle_sound_upload(array $file, ?callable $mover = null): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, 'There was a problem uploading the sound. Please try again.'];
    }
    if ((int) $file['size'] > GIFT_SOUND_MAX_BYTES || (int) $file['size'] < 64) {
        return [false, null, 'Please use a sound file under ' . (GIFT_SOUND_MAX_BYTES / 1024) . ' KB.'];
    }
    if ($mover === null && !is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'That upload could not be verified. Please try again.'];
    }
    $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 12);
    $ext = null;
    if (str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
        $ext = 'mp3';
    } elseif (str_starts_with($head, 'OggS')) {
        $ext = 'ogg';
    } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
        $ext = 'wav';
    }
    if ($ext === null) {
        return [false, null, 'Please upload an MP3, OGG or WAV sound.'];
    }
    $dir = __DIR__ . '/../uploads/gifts';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [false, null, 'Could not prepare storage for the file. Please try again.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $mover !== null ? $mover($file['tmp_name'], $dir . '/' . $name) : move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
    if (!$moved) {
        return [false, null, 'Could not save the sound. Please try again.'];
    }
    return [true, '/uploads/gifts/' . $name, null];
}

function gift_delete_upload(?string $publicPath): void
{
    if ($publicPath && preg_match('#^/uploads/gifts/[a-f0-9]{32}\.(png|webp|gif|jpg|mp3|ogg|wav)$#', $publicPath)) {
        $full = __DIR__ . '/../public' . $publicPath;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

function gift_slugify(string $name): string
{
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    return $s !== '' ? mb_substr($s, 0, 36) : 'gift';
}

/**
 * Creates or edits a gift. Prices are whole UGX between the smallest gift and the largest single payment. A new price applies to
 * FUTURE gifts only (every sent gift keeps the price it was bought at). Gifts are never deleted (history refers to them): hide them.
 *
 * @param array $in name, description, emoji, price_ugx, animation_type, sort_order, is_active
 * @return array{ok:bool, error?:string, id?:int}
 */
function gift_admin_save(int $adminId, ?int $giftId, array $in, ?array $imageFile = null, ?array $animFile = null, bool $removeImage = false, bool $removeAnim = false, ?callable $mover = null, ?array $soundFile = null, bool $removeSound = false): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e];
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) {
        return $fail('Give the gift a name (up to 60 characters).');
    }
    $priceRaw = trim((string) ($in['price_ugx'] ?? ''));
    if (!preg_match('/^\d{1,9}$/', $priceRaw)) {
        return $fail('The price must be a whole number of shillings.');
    }
    $price = (int) $priceRaw;
    // ADR-004: a price under UGX 500 is allowed, but such a gift is coins only (never offered for a direct mobile money payment)
    if ($price < 1 || $price > GIFT_MAX_PAYMENT_UGX) {
        return $fail('The price must be between UGX 1 and UGX ' . number_format(GIFT_MAX_PAYMENT_UGX) . '. Under UGX ' . number_format(GIFT_MIN_UGX) . ' the gift can only be sent with OBI COINS.');
    }
    // ADR-004 fields. Each one is only changed when it was sent; otherwise the gift keeps what it had (a new gift gets a sensible default).
    // The admin form sends v49_form=1, so an unticked box there means "no" (a missing box elsewhere means "unchanged").
    $v49 = gifts_v49();
    $prev = $giftId !== null ? gift_find($giftId) : null;
    $category = array_key_exists('category', $in) ? strtoupper(trim((string) $in['category'])) : ($prev ? gift_category_of($prev) : gift_category_of(['price_coins' => $in['price_coins'] ?? null, 'price_ugx' => $price]));
    $soundStyle = array_key_exists('sound_style', $in) ? trim((string) $in['sound_style']) : (string) ($prev['sound_style'] ?? ($prev ? '' : 'pop'));
    $durRaw = array_key_exists('duration_s', $in) ? trim((string) $in['duration_s']) : (!empty($prev['duration_ms']) ? (string) round((int) $prev['duration_ms'] / 1000, 1) : '');
    $boxes = !empty($in['v49_form']);
    $signature = $boxes || array_key_exists('is_signature', $in) ? (!empty($in['is_signature']) ? 1 : 0) : (int) ($prev['is_signature'] ?? 0);
    $featured = $boxes || array_key_exists('is_featured', $in) ? (!empty($in['is_featured']) ? 1 : 0) : (int) ($prev['is_featured'] ?? 0);
    if ($v49) {
        if (!isset(GIFT_CATEGORIES[$category])) {
            return $fail('Pick a category: Everyday, Popular, Premium or Elite.');
        }
        if ($soundStyle !== '' && !isset(GIFT_SOUNDS[$soundStyle])) {
            return $fail('Pick one of the sounds.');
        }
        if ($durRaw !== '' && (!preg_match('/^\d{1,2}(\.\d)?$/', $durRaw) || (float) $durRaw < 1 || (float) $durRaw > 12)) {
            return $fail('The animation length must be between 1 and 12 seconds.');
        }
    }
    $durationMs = $durRaw !== '' ? (int) round((float) $durRaw * 1000) : null;
    // the price in OBI COINS (only when the coin tables exist and the form sent it); empty = no coin price, so the gift is not offered while OBI COINS is on
    $coinsGiven = array_key_exists('price_coins', $in) && function_exists('coins_ready') && coins_ready();
    $priceCoins = null;
    if ($coinsGiven) {
        $pc = trim((string) $in['price_coins']);
        if ($pc !== '') {
            if (!preg_match('/^\d{1,7}$/', $pc) || (int) $pc < 1) {
                return $fail('The price in OBI COINS must be a whole number above zero.');
            }
            $priceCoins = (int) $pc;
        }
    }
    $anim = (string) ($in['animation_type'] ?? 'pop');
    if (!isset(GIFT_ANIMATIONS[$anim])) {
        return $fail('Pick one of the animation styles.');
    }
    $emoji = trim((string) ($in['emoji'] ?? ''));
    if (mb_strlen($emoji) > 8) {
        return $fail('Use a single emoji (or leave it empty when you upload a picture).');
    }
    $desc = trim((string) ($in['description'] ?? ''));
    if (mb_strlen($desc) > 255) {
        return $fail('The description can be at most 255 characters.');
    }
    $active = !empty($in['is_active']) ? 1 : 0;

    $old = null;
    if ($giftId !== null) {
        $old = gift_find($giftId);
        if (!$old) {
            return $fail('That gift does not exist.');
        }
    }
    $sort = isset($in['sort_order']) && $in['sort_order'] !== '' && preg_match('/^-?\d{1,6}$/', (string) $in['sort_order'])
        ? (int) $in['sort_order'] : ($old ? (int) $old['sort_order'] : (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM gift_catalog')->fetchColumn());

    [$okI, $newImage, $errI] = $imageFile !== null ? gift_handle_upload($imageFile, 'image', $mover) : [true, null, null];
    if (!$okI) {
        return $fail((string) $errI);
    }
    [$okA, $newAnim, $errA] = $animFile !== null ? gift_handle_upload($animFile, 'animation', $mover) : [true, null, null];
    if (!$okA) {
        gift_delete_upload($newImage);
        return $fail((string) $errA);
    }
    [$okS, $newSound, $errS] = $soundFile !== null && $v49 ? gift_handle_sound_upload($soundFile, $mover) : [true, null, null];
    if (!$okS) {
        gift_delete_upload($newImage);
        gift_delete_upload($newAnim);
        return $fail((string) $errS);
    }
    $soundAsset = $newSound ?? ($removeSound ? null : ($old['sound_asset'] ?? null));
    $image = $newImage ?? ($removeImage ? null : ($old['image_path'] ?? null));
    $animAsset = $newAnim ?? ($removeAnim ? null : ($old['animation_asset'] ?? null));
    if ($emoji === '' && $image === null) {
        gift_delete_upload($newImage);
        gift_delete_upload($newAnim);
        gift_delete_upload($newSound);
        return $fail('Add an emoji or upload a picture so viewers can see the gift.');
    }

    $pdo = db();
    if ($old === null) {
        $base = gift_slugify($name);
        $slug = $base;
        for ($i = 2; (int) $pdo->query('SELECT COUNT(*) FROM gift_catalog WHERE slug = ' . $pdo->quote($slug))->fetchColumn() > 0; $i++) {
            $slug = mb_substr($base, 0, 32) . '-' . $i;
        }
        $pdo->prepare('INSERT INTO gift_catalog (slug, name, description, emoji, image_path, animation_type, animation_asset, price_ugx, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$slug, $name, $desc !== '' ? $desc : null, $emoji !== '' ? $emoji : null, $image, $anim, $animAsset, $price, $active, $sort]);
        $id = (int) $pdo->lastInsertId();
        if ($coinsGiven) {
            $pdo->prepare('UPDATE gift_catalog SET price_coins = ? WHERE id = ?')->execute([$priceCoins, $id]);
        }
        if ($v49) {
            gift_save_v49($pdo, $id, $category, $soundStyle, $soundAsset, $durationMs, $signature, $featured);
        }
        log_admin_action($adminId, 'gift.catalog.create', 'gift_catalog', $id, ['name' => $name, 'price' => $price, 'price_coins' => $priceCoins, 'active' => $active, 'category' => $category, 'signature' => $signature]);
        return ['ok' => true, 'id' => $id];
    }
    $pdo->prepare('UPDATE gift_catalog SET name = ?, description = ?, emoji = ?, image_path = ?, animation_type = ?, animation_asset = ?, price_ugx = ?, is_active = ?, sort_order = ? WHERE id = ?')
        ->execute([$name, $desc !== '' ? $desc : null, $emoji !== '' ? $emoji : null, $image, $anim, $animAsset, $price, $active, $sort, $giftId]);
    if (($newImage !== null || $removeImage) && ($old['image_path'] ?? null) !== $image) {
        gift_delete_upload($old['image_path'] ?? null);
    }
    if (($newAnim !== null || $removeAnim) && ($old['animation_asset'] ?? null) !== $animAsset) {
        gift_delete_upload($old['animation_asset'] ?? null);
    }
    $changes = [];
    if ($v49) {
        gift_save_v49($pdo, (int) $giftId, $category, $soundStyle, $soundAsset, $durationMs, $signature, $featured);
        if (($newSound !== null || $removeSound) && ($old['sound_asset'] ?? null) !== $soundAsset) {
            gift_delete_upload($old['sound_asset'] ?? null);
            $changes['sound_asset'] = 'changed';
        }
        foreach (['category' => $category, 'sound_style' => $soundStyle !== '' ? $soundStyle : null, 'duration_ms' => $durationMs, 'is_signature' => $signature, 'is_featured' => $featured] as $k => $v) {
            if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) {
                $changes[$k] = [$old[$k] ?? null, $v];
            }
        }
    }
    if ($coinsGiven) {
        $pdo->prepare('UPDATE gift_catalog SET price_coins = ? WHERE id = ?')->execute([$priceCoins, $giftId]);
        if (($old['price_coins'] ?? null) !== null ? (int) $old['price_coins'] !== $priceCoins : $priceCoins !== null) {
            $changes['price_coins'] = [$old['price_coins'] ?? null, $priceCoins];
        }
    }
    foreach (['name' => $name, 'price_ugx' => $price, 'is_active' => $active, 'animation_type' => $anim, 'sort_order' => $sort] as $k => $v) {
        if ((string) $old[$k] !== (string) $v) {
            $changes[$k] = [$old[$k], $v];
        }
    }
    if ($newImage !== null || $removeImage) {
        $changes['image'] = 'changed';
    }
    if ($newAnim !== null || $removeAnim) {
        $changes['animation_asset'] = 'changed';
    }
    log_admin_action($adminId, 'gift.catalog.update', 'gift_catalog', $giftId, $changes ?: ['no_change' => true]);
    return ['ok' => true, 'id' => $giftId];
}

/** Show or hide a gift. Hidden gifts disappear from the catalogue viewers see; their history stays. */
function gift_admin_set_active(int $adminId, int $giftId, bool $active): bool
{
    $u = db()->prepare('UPDATE gift_catalog SET is_active = ? WHERE id = ?');
    $u->execute([$active ? 1 : 0, $giftId]);
    if ($u->rowCount() === 1) {
        log_admin_action($adminId, 'gift.catalog.' . ($active ? 'show' : 'hide'), 'gift_catalog', $giftId, null);
        return true;
    }
    return false;
}

/** Moves a gift one place up or down in the catalogue (the order viewers see). */
function gift_admin_move(int $adminId, int $giftId, string $dir): bool
{
    $ids = db()->query('SELECT id FROM gift_catalog ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
    $pos = array_search((string) $giftId, array_map('strval', $ids), true);
    if ($pos === false) {
        return false;
    }
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($ids)) {
        return false;
    }
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare('UPDATE gift_catalog SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $u->execute([($i + 1) * 10, (int) $id]);   // renumbered 10, 20, 30 ... so the order is always clean
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    log_admin_action($adminId, 'gift.catalog.move', 'gift_catalog', $giftId, ['direction' => $dir]);
    return true;
}

// ---------------------------------------------------------------------------------------------------- admin: revenue split (phase 2)

/** Every version of the split, newest first, with who changed it. Marks the one in force and any scheduled for later. */
function gift_commission_history(): array
{
    $rows = db()->query('SELECT c.*, u.name AS admin_name FROM gift_commission_config c LEFT JOIN users u ON u.id = c.changed_by ORDER BY c.effective_from DESC, c.version DESC')->fetchAll();
    $current = gift_commission_current()['version'];
    foreach ($rows as &$r) {
        $r['state'] = (int) $r['version'] === $current ? 'IN_FORCE' : (strtotime((string) $r['effective_from']) > time() ? 'SCHEDULED' : 'PAST');
    }
    return $rows;
}

/**
 * Admin form wrapper: the obitickets share as a percentage with up to 2 decimals (the organizer gets the rest). Takes effect now or
 * at a future moment, never in the past. Changes only FUTURE gifts; every sent gift keeps the split it was bought under.
 * @return array{ok:bool, error?:string, version?:int}
 */
function gift_admin_set_commission(int $adminId, string $platformPercent, string $reason, ?string $effectiveFrom = null): array
{
    $platformPercent = str_replace(',', '.', trim($platformPercent));
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $platformPercent) || (float) $platformPercent > 100) {
        return ['ok' => false, 'error' => 'Enter the obitickets share as a percentage between 0 and 100.'];
    }
    $bps = (int) round((float) $platformPercent * 100);
    $when = null;
    if ($effectiveFrom !== null && trim($effectiveFrom) !== '') {
        $ts = strtotime(str_replace('T', ' ', trim($effectiveFrom)));
        if ($ts === false || $ts < time() - 120) {
            return ['ok' => false, 'error' => 'The change must start now or in the future, never in the past.'];
        }
        $when = date('Y-m-d H:i:s', $ts);
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Give a reason for the change.'];
    }
    $current = gift_commission_current();
    if ($when === null && $bps === $current['platform_bps']) {
        return ['ok' => false, 'error' => 'That is already the current split.'];
    }
    try {
        return ['ok' => true, 'version' => gift_set_commission($adminId, $bps, $reason, $when)];
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** The latest gift-related admin actions (who did what and when), for the admin page. */
function gift_audit_recent(int $limit = 20): array
{
    $stmt = db()->prepare("SELECT a.*, u.name AS admin_name FROM audit_logs a LEFT JOIN users u ON u.id = a.admin_id WHERE a.action LIKE 'gift.%' ORDER BY a.id DESC LIMIT " . (int) $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------------------------------- viewer side (phase 3)

/**
 * Everything the screen needs to play a delivered gift: the artwork, the animation style, how many, and the line of text.
 * Anonymous gifts never carry the sender's name.
 * @return array|null null until the gift is delivered
 */
function gift_delivery_payload(int $txId): ?array
{
    $stmt = db()->prepare("SELECT t.*, g.*, t.id AS tx_id, g.name AS gift_name, COALESCE(NULLIF(op.org_name, ''), u.name) AS org_name
                           FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id JOIN users u ON u.id = t.recipient_organizer_id
                           LEFT JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id WHERE t.id = ?");
    $stmt->execute([$txId]);
    $t = $stmt->fetch();
    if (!$t || $t['payment_status'] !== 'SUCCESSFUL') {
        return null;
    }
    $coins = ($t['payment_source'] ?? 'DIRECT') === 'COINS' ? (int) $t['coins_spent'] : null;
    return [
        'gift' => ['slug' => $t['slug'], 'name' => $t['gift_name'], 'emoji' => $t['emoji'], 'image' => $t['image_path'], 'anim' => $t['animation_type'], 'animAsset' => $t['animation_asset'],
                   'category' => gift_category_of($t), 'ms' => gift_duration_ms($t), 'sound' => $t['sound_style'] ?? null, 'soundAsset' => $t['sound_asset'] ?? null,
                   'signature' => !empty($t['is_signature'])],
        'sender' => gift_sender_label($t),
        'recipient' => $t['org_name'],
        'coins' => $coins,
        'quantity' => (int) $t['quantity'],
        'gross' => (int) $t['gross_ugx'],
        'anonymous' => (int) $t['is_anonymous'] === 1,
        'message' => gift_public_message($txId),
        'reference' => $t['reference'],
    ];
}

/** Last used (or saved) phone of this user, shown as 07xx xxx xxx in the pay step. */
function gift_phone_hint(array $user): string
{
    $phone = trim((string) ($user['phone'] ?? ''));
    if ($phone === '') {
        $stmt = db()->prepare('SELECT phone FROM gift_transactions WHERE sender_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $phone = (string) $stmt->fetchColumn();
    }
    $d = $phone !== '' ? iotec_normalize_phone($phone) : '';
    return preg_match('/^256(\d{9})$/', $d, $m) ? '0' . $m[1] : $phone;
}

/** What the viewer's catalogue shows for one gift. */
function gift_public_shape(array $g, bool $coins = false): array
{
    return ['id' => (int) $g['id'], 'slug' => $g['slug'], 'name' => $g['name'], 'description' => $g['description'], 'emoji' => $g['emoji'], 'image' => $g['image_path'],
            'anim' => $g['animation_type'], 'animAsset' => $g['animation_asset'],
            'category' => gift_category_of($g), 'ms' => gift_duration_ms($g), 'sound' => $g['sound_style'] ?? null, 'soundAsset' => $g['sound_asset'] ?? null,
            'signature' => !empty($g['is_signature']), 'featured' => !empty($g['is_featured']),
            // with OBI COINS on a gift costs coins (never a UGX price beside it); the old direct payment shows the UGX price
            'price' => $coins ? (int) $g['price_coins'] : (int) $g['price_ugx'], 'unit' => $coins ? 'coins' : 'ugx'];
}

// ---- pretend payments for testing on a developer's own computer ONLY.
// They need all four: the constant GIFTS_FAKE_PAYMENTS set to true in config/config.php (which is never deployed), APP_ENV
// 'development', a request from this very computer, and a reference made by the fake collector. On the live site none holds.

function gift_fake_allowed(): bool
{
    return defined('GIFTS_FAKE_PAYMENTS') && in_array(GIFTS_FAKE_PAYMENTS, [true, 1, '1', 'true'], true) && gift_is_development()
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/**
 * When must a gift NOT be checked with the real ioTec? 'development' on a developer's computer (it holds real keys and never
 * contacts ioTec for gifts), and 'fake_reference' when a pretend payment reference shows up anywhere pretend payments are not
 * allowed (nobody can get a free gift). Null = go ahead. Only applies when the real checker would be used ($statusCheck is null).
 */
function gift_skip_real_check(?callable $statusCheck, string $providerReference, bool $isDevelopment, bool $fakeAllowed): ?string
{
    if ($statusCheck !== null || $fakeAllowed) {
        return null;
    }
    if ($isDevelopment) {
        return 'development';
    }
    return str_starts_with($providerReference, 'FAKE-') ? 'fake_reference' : null;
}

/** True on a developer's own computer (APP_ENV is 'development'; the live site's default is 'production'). */
function gift_is_development(): bool
{
    return defined('APP_ENV') && APP_ENV === 'development';
}

/** Pretend ioTec prompt. The last digit of the number picks the story: 0 = declined, 9 = never approved, anything else = approved after 5 seconds. */
function gift_fake_collect(float $amount, string $phone, string $reference, string $note): array
{
    $last = substr(preg_replace('/\D/', '', $phone), -1);
    $outcome = $last === '0' ? 'fail' : ($last === '9' ? 'wait' : 'ok');
    return ['transactionId' => 'FAKE-' . time() . '-' . $outcome . '-' . bin2hex(random_bytes(3))];
}

function gift_fake_status(string $transactionId): array
{
    $p = explode('-', $transactionId);
    $age = time() - (int) ($p[1] ?? time());
    $outcome = $p[2] ?? 'wait';
    if ($outcome === 'ok' && $age >= 5) {
        return ['status' => 'Success', 'statusMessage' => 'Approved (pretend payment)', 'amount' => null, 'charge' => null];
    }
    if ($outcome === 'fail' && $age >= 4) {
        return ['status' => 'Failed', 'statusMessage' => 'The payment was declined (pretend payment).', 'amount' => null, 'charge' => null];
    }
    return ['status' => 'Pending', 'statusMessage' => null, 'amount' => null, 'charge' => null];
}

function gift_collect_fn(): ?callable
{
    return gift_fake_allowed() ? 'gift_fake_collect' : null;
}

function gift_status_fn(): ?callable
{
    return gift_fake_allowed() ? 'gift_fake_status' : null;
}
