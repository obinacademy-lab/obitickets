<?php
declare(strict_types=1);

/**
 * OBI GIFTS, phase 6: what ADMINS see and do about gift money: every transaction, one transaction in full, reports, per-organizer
 * earnings, and the refund follow-up. Reading only, except the sender-refund note (gift_events + audit log). Reversing a gift itself
 * is gift_reverse() in includes/gifts.php.
 *
 * Admins see real names and e-mails (they need them for support and fraud checks), but a phone number is shown masked in lists
 * and in full only on the one transaction being looked at, so a refund can be sent back to the right number.
 */

// ---------------------------------------------------------------------------------------------------- small helpers

/** 256772123456 -> "+256 772 ***456" */
function gift_admin_mask_phone(?string $phone): string
{
    $d = iotec_normalize_phone((string) $phone);
    if (strlen($d) < 9) {
        return '';
    }
    return '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ***' . substr($d, -3);
}

/** 256772123456 -> "+256 772 123 456" (the full number, for the one transaction an admin is looking at) */
function gift_admin_format_phone(?string $phone): string
{
    $d = iotec_normalize_phone((string) $phone);
    return strlen($d) < 9 ? (string) $phone : '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6, 3) . ' ' . substr($d, 9);
}

/** A valid Y-m-d date or null. */
function gift_admin_date(?string $s): ?string
{
    $s = trim((string) $s);
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s ? $s : null;
}

/** The admin sub-menu shown on every OBI GIFTS admin page (HTML). */
function gift_admin_tabs(string $active): string
{
    $tabs = ['catalogue' => ['Gift catalogue', '/admin-gifts.php'], 'transactions' => ['Transactions', '/admin-gift-transactions.php'], 'reports' => ['Reports', '/admin-gift-reports.php'], 'coins' => ['OBI COINS', '/admin-coins.php'],
             'split' => ['Revenue split', '/admin-gifts.php?tab=split'], 'withdrawals' => ['Withdrawals', '/admin-gifts.php?tab=withdrawals']];
    $out = '<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap">';
    foreach ($tabs as $key => [$label, $href]) {
        $out .= '<a class="btn ' . ($key === $active ? 'btn-purple' : 'btn-line') . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</a>';
    }
    return $out . '</div>';
}

// ---------------------------------------------------------------------------------------------------- transactions

/**
 * Turns raw GET input into safe filters.
 * @return array{status:string, gift:int, organizer:int, q:string, from:?string, to:?string, min:int, before:int}
 */
function gift_admin_tx_filters(array $in): array
{
    return [
        'status' => in_array($in['status'] ?? '', ['paid', 'reversed', 'waiting', 'failed'], true) ? $in['status'] : 'all',
        'gift' => max(0, (int) ($in['gift'] ?? 0)),
        'organizer' => max(0, (int) ($in['organizer'] ?? 0)),
        'q' => mb_substr(trim((string) ($in['q'] ?? '')), 0, 80),
        'from' => gift_admin_date($in['from'] ?? null),
        'to' => gift_admin_date($in['to'] ?? null),
        'min' => max(0, (int) ($in['min'] ?? 0)),
        'before' => max(0, (int) ($in['before'] ?? 0)),
    ];
}

/** WHERE pieces and arguments for a filter set (shared by the list, the CSV and the report). */
function gift_admin_tx_where(array $f): array
{
    $where = ['1 = 1'];
    $args = [];
    switch ($f['status'] ?? 'all') {
        case 'paid': $where[] = "t.payment_status = 'SUCCESSFUL'"; break;
        case 'reversed': $where[] = "t.payment_status = 'REFUNDED'"; break;
        case 'waiting': $where[] = "t.payment_status IN ('PENDING','PROCESSING')"; break;
        case 'failed': $where[] = "t.payment_status IN ('FAILED','CANCELLED','EXPIRED')"; break;
    }
    if (!empty($f['gift'])) {
        $where[] = 't.gift_id = ?';
        $args[] = (int) $f['gift'];
    }
    if (!empty($f['organizer'])) {
        $where[] = 't.recipient_organizer_id = ?';
        $args[] = (int) $f['organizer'];
    }
    if (($f['q'] ?? '') !== '') {
        $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
        $where[] = "(t.reference LIKE ? OR su.name LIKE ? OR su.email LIKE ? OR ou.name LIKE ? OR op.org_name LIKE ?)";
        array_push($args, $like, $like, $like, $like, $like);
    }
    if (!empty($f['from'])) {
        $where[] = 't.created_at >= ?';
        $args[] = $f['from'] . ' 00:00:00';
    }
    if (!empty($f['to'])) {
        $where[] = 't.created_at < ? + INTERVAL 1 DAY';
        $args[] = $f['to'] . ' 00:00:00';
    }
    if (!empty($f['min'])) {
        $where[] = 't.gross_ugx >= ?';
        $args[] = (int) $f['min'];
    }
    if (!empty($f['before'])) {
        $where[] = 't.id < ?';
        $args[] = (int) $f['before'];
    }
    return [implode(' AND ', $where), $args];
}

const GIFT_ADMIN_TX_FROM = "FROM gift_transactions t
    JOIN gift_catalog g ON g.id = t.gift_id
    LEFT JOIN users su ON su.id = t.sender_id
    LEFT JOIN users ou ON ou.id = t.recipient_organizer_id
    LEFT JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id";

/**
 * One page of transactions, newest first (cursor = the last id seen, so it stays fast with millions of rows).
 * @return array{rows:list<array>, next:?int}
 */
function gift_admin_tx_list(array $f, int $limit = 30): array
{
    $limit = max(1, min(2000, $limit));
    [$w, $args] = gift_admin_tx_where($f);
    $stmt = db()->prepare("SELECT t.id, t.reference, t.created_at, t.paid_at, t.quantity, t.gross_ugx, t.platform_ugx, t.organizer_ugx, t.gateway_fee_ugx, t.payment_status, t.delivery_status,
                                  t.is_anonymous, t.payment_method, t.phone, t.sender_id, t.recipient_organizer_id, g.name AS gift_name, g.emoji,
                                  su.name AS sender_name, su.email AS sender_email, COALESCE(NULLIF(op.org_name, ''), ou.name) AS organizer_name
                           " . GIFT_ADMIN_TX_FROM . " WHERE $w ORDER BY t.id DESC LIMIT " . ($limit + 1));
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $next = (int) $rows[count($rows) - 1]['id'];
    }
    foreach ($rows as &$r) {
        $r['phone_masked'] = gift_admin_mask_phone($r['phone']);
        unset($r['phone']);   // the full number is only ever loaded for a single transaction (gift_admin_tx_detail)
    }
    unset($r);
    return ['rows' => $rows, 'next' => $next];
}

/** Plain-language status for admins. */
function gift_admin_status_label(array $r): array
{
    return match ($r['payment_status']) {
        'SUCCESSFUL' => ['Paid', 'admin-badge-success'],
        'REFUNDED' => ['Reversed', 'admin-badge-danger'],
        'PENDING', 'PROCESSING' => ['Waiting', 'admin-badge-warn'],
        'EXPIRED' => ['Expired', 'admin-badge-muted'],
        'CANCELLED' => ['Cancelled', 'admin-badge-muted'],
        default => ['Failed', 'admin-badge-muted'],
    };
}

/**
 * One transaction in full: the gift, who, the split, every ledger row, the events timeline, admin actions on it, the sender-refund follow-up,
 * and what reversing it would do to the organizer's balance. Includes the sender's FULL phone number (needed to send a refund back).
 */
function gift_admin_tx_detail(int $id): ?array
{
    $stmt = db()->prepare("SELECT t.*, g.name AS gift_name, g.emoji, su.name AS sender_name, su.email AS sender_email, ou.email AS organizer_email,
                                  COALESCE(NULLIF(op.org_name, ''), ou.name) AS organizer_name, p.title AS video_event
                           FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id LEFT JOIN users su ON su.id = t.sender_id LEFT JOIN users ou ON ou.id = t.recipient_organizer_id
                           LEFT JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id
                           LEFT JOIN (SELECT ep.id, e.title FROM event_posts ep JOIN events e ON e.id = ep.event_id) p ON p.id = t.post_id
                           WHERE t.id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t) {
        return null;
    }
    $ledger = db()->prepare('SELECT * FROM gift_ledger WHERE gift_tx_id = ? ORDER BY id');
    $ledger->execute([$id]);
    $events = db()->prepare('SELECT * FROM gift_events WHERE gift_tx_id = ? ORDER BY id');
    $events->execute([$id]);
    $audit = db()->prepare("SELECT a.*, u.name AS admin_name FROM audit_logs a LEFT JOIN users u ON u.id = a.admin_id WHERE a.entity_type = 'gift_transaction' AND a.entity_id = ? ORDER BY a.id");
    $audit->execute([$id]);
    $bal = gift_balance((int) $t['recipient_organizer_id']);
    $t['ledger'] = $ledger->fetchAll();
    $t['events'] = $events->fetchAll();
    $t['audit'] = $audit->fetchAll();
    $t['organizer_balance'] = $bal;
    $t['sender_refund'] = $t['payment_status'] === 'REFUNDED' ? gift_admin_sender_refund($id) : null;
    $t['can_reverse'] = $t['payment_status'] === 'SUCCESSFUL';
    $t['balance_after_reversal'] = $t['payment_status'] === 'SUCCESSFUL' ? $bal['available'] - (int) $t['organizer_ugx'] : null;
    return $t;
}

// ---------------------------------------------------------------------------------------------------- refund follow-up

/** Has the money for a reversed gift been sent back to the sender? ['done'=>bool, 'at'=>?string, 'note'=>?string]. */
function gift_admin_sender_refund(int $txId): array
{
    $src = db()->prepare('SELECT payment_source, refunded_at FROM gift_transactions WHERE id = ?');
    $src->execute([$txId]);
    $srow = $src->fetch();
    if ($srow && ($srow['payment_source'] ?? 'DIRECT') === 'COINS') {
        return ['done' => true, 'at' => $srow['refunded_at'] !== null ? (string) $srow['refunded_at'] : null, 'note' => 'The OBI COINS were returned to the sender\'s wallet automatically.'];
    }
    $stmt = db()->prepare("SELECT details, created_at FROM gift_events WHERE gift_tx_id = ? AND kind = 'sender_refunded' ORDER BY id LIMIT 1");
    $stmt->execute([$txId]);
    $r = $stmt->fetch();
    return $r ? ['done' => true, 'at' => (string) $r['created_at'], 'note' => (string) $r['details']] : ['done' => false, 'at' => null, 'note' => null];
}

/**
 * An admin records that the sender got their money back (sent by hand, e.g. through the MoMo app). Only for a reversed gift, only once.
 * @return array{ok:bool, error?:string}
 */
function gift_admin_mark_sender_refunded(int $txId, int $adminId, string $note): array
{
    $note = trim($note);
    if ($note === '') {
        return ['ok' => false, 'error' => 'Write how the money was sent back (for example the MoMo transaction number).'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT payment_status, gross_ugx, sender_id, payment_source FROM gift_transactions WHERE id = ? FOR UPDATE');
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();
        if (!$tx || $tx['payment_status'] !== 'REFUNDED') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Only a reversed gift has a refund to send back.'];
        }
        if ($tx['payment_source'] === 'COINS') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This gift was paid with OBI COINS: they were returned to the sender\'s wallet automatically, so there is no money to send back.'];
        }
        $done = $pdo->prepare("SELECT 1 FROM gift_events WHERE gift_tx_id = ? AND kind = 'sender_refunded' LIMIT 1");
        $done->execute([$txId]);
        if ($done->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This refund was already marked as sent.'];
        }
        $pdo->prepare("INSERT INTO gift_events (gift_tx_id, kind, details) VALUES (?, 'sender_refunded', ?)")->execute([$txId, mb_substr($note, 0, 480)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    log_admin_action($adminId, 'gift.sender_refunded', 'gift_transaction', $txId, ['amount' => (int) $tx['gross_ugx'], 'note' => $note]);
    try {
        create_notification((int) $tx['sender_id'], 'gift', 'Your gift refund was sent', 'UGX ' . number_format((int) $tx['gross_ugx']) . ' was sent back to your phone.', null);
    } catch (Throwable $e) {
        error_log('[gift] sender refund notification failed: ' . $e->getMessage());
    }
    return ['ok' => true];
}

/** Reversed gifts whose money has not been sent back to the sender yet. @return array{count:int, ugx:int, oldest:?string} */
function gift_admin_refunds_owed(?int $organizerId = null): array
{
    $sql = "SELECT COUNT(*) c, COALESCE(SUM(t.gross_ugx), 0) s, MIN(t.refunded_at) o FROM gift_transactions t
            WHERE t.payment_status = 'REFUNDED' AND t.payment_source = 'DIRECT' AND NOT EXISTS (SELECT 1 FROM gift_events e WHERE e.gift_tx_id = t.id AND e.kind = 'sender_refunded')"
         . ($organizerId ? ' AND t.recipient_organizer_id = ' . (int) $organizerId : '');
    $r = db()->query($sql)->fetch();
    return ['count' => (int) $r['c'], 'ugx' => (int) $r['s'], 'oldest' => $r['o'] !== null ? (string) $r['o'] : null];
}

// ---------------------------------------------------------------------------------------------------- reports

/**
 * The numbers for the Reports page between two dates (inclusive). Money is whole UGX.
 * Paid gifts are counted on the day they were paid; reversals on the day they were reversed. Obitickets' net income is its 40% minus the
 * ioTec collection fees it pays out of that share (payout fees are not tracked by ioTec's answer, so they are not in here).
 *
 * @return array<string,mixed>
 */
function gift_admin_report(string $from, string $to, ?int $organizerId = null): array
{
    $pdo = db();
    $org = $organizerId ? ' AND t.recipient_organizer_id = ' . (int) $organizerId : '';
    $a = $from . ' 00:00:00';
    $b = $to . ' 00:00:00';
    $q = static function (string $sql, array $args) use ($pdo): array {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll();
    };

    $paid = $q("SELECT COUNT(*) gifts, COALESCE(SUM(t.gross_ugx),0) gross, COALESCE(SUM(t.platform_ugx),0) platform, COALESCE(SUM(t.organizer_ugx),0) organizer,
                       COALESCE(SUM(COALESCE(t.gateway_fee_ugx,0)),0) fees, COUNT(DISTINCT t.sender_id) supporters
                FROM gift_transactions t WHERE t.payment_status = 'SUCCESSFUL' AND t.paid_at >= ? AND t.paid_at < ? + INTERVAL 1 DAY$org", [$a, $b])[0];
    $rev = $q("SELECT COUNT(*) gifts, COALESCE(SUM(t.gross_ugx),0) gross, COALESCE(SUM(t.organizer_ugx),0) organizer
               FROM gift_transactions t WHERE t.payment_status = 'REFUNDED' AND t.refunded_at >= ? AND t.refunded_at < ? + INTERVAL 1 DAY$org", [$a, $b])[0];
    $att = $q("SELECT COUNT(*) started,
                      SUM(t.payment_status IN ('SUCCESSFUL','REFUNDED')) succeeded,
                      SUM(t.payment_status IN ('FAILED','CANCELLED','EXPIRED')) failed,
                      SUM(t.payment_status IN ('PENDING','PROCESSING')) waiting
               FROM gift_transactions t WHERE t.created_at >= ? AND t.created_at < ? + INTERVAL 1 DAY$org", [$a, $b])[0];

    // per day, or per month when the range is long
    $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    $monthly = $days > 92;
    $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';
    $byBucket = [];
    foreach ($q("SELECT DATE_FORMAT(t.paid_at, '$fmt') d, COUNT(*) gifts, SUM(t.gross_ugx) gross, SUM(t.platform_ugx) platform
                 FROM gift_transactions t WHERE t.payment_status = 'SUCCESSFUL' AND t.paid_at >= ? AND t.paid_at < ? + INTERVAL 1 DAY$org GROUP BY d", [$a, $b]) as $r) {
        $byBucket[$r['d']] = ['gifts' => (int) $r['gifts'], 'gross' => (int) $r['gross'], 'platform' => (int) $r['platform']];
    }
    $series = [];
    if ($monthly) {
        for ($m = strtotime(date('Y-m-01', strtotime($from))); $m <= strtotime($to); $m = strtotime('+1 month', $m)) {
            $k = date('Y-m', $m);
            $series[] = ['label' => date('M Y', $m), 'gifts' => $byBucket[$k]['gifts'] ?? 0, 'gross' => $byBucket[$k]['gross'] ?? 0, 'platform' => $byBucket[$k]['platform'] ?? 0];
        }
    } else {
        for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
            $k = date('Y-m-d', $d);
            $series[] = ['label' => date('j M', $d), 'gifts' => $byBucket[$k]['gifts'] ?? 0, 'gross' => $byBucket[$k]['gross'] ?? 0, 'platform' => $byBucket[$k]['platform'] ?? 0];
        }
    }

    $byGift = $q("SELECT g.name, g.emoji, COUNT(*) gifts, SUM(t.quantity) units, SUM(t.gross_ugx) gross
                  FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id
                  WHERE t.payment_status = 'SUCCESSFUL' AND t.paid_at >= ? AND t.paid_at < ? + INTERVAL 1 DAY$org GROUP BY g.id, g.name, g.emoji ORDER BY gross DESC", [$a, $b]);
    $topOrganizers = $q("SELECT t.recipient_organizer_id id, COALESCE(NULLIF(op.org_name,''), ou.name) name, COUNT(*) gifts, SUM(t.gross_ugx) gross, SUM(t.organizer_ugx) organizer
                         FROM gift_transactions t LEFT JOIN users ou ON ou.id = t.recipient_organizer_id LEFT JOIN organizer_profiles op ON op.user_id = t.recipient_organizer_id
                         WHERE t.payment_status = 'SUCCESSFUL' AND t.paid_at >= ? AND t.paid_at < ? + INTERVAL 1 DAY$org GROUP BY t.recipient_organizer_id, name ORDER BY gross DESC LIMIT 10", [$a, $b]);
    $topSupporters = $q("SELECT t.sender_id id, MAX(su.name) name, MAX(su.email) email, COUNT(*) gifts, SUM(t.gross_ugx) gross, MAX(t.is_anonymous) anonymous_sometimes
                         FROM gift_transactions t LEFT JOIN users su ON su.id = t.sender_id
                         WHERE t.payment_status = 'SUCCESSFUL' AND t.paid_at >= ? AND t.paid_at < ? + INTERVAL 1 DAY$org GROUP BY t.sender_id ORDER BY gross DESC LIMIT 10", [$a, $b]);

    // withdrawals (everyone; not filtered by organizer unless asked)
    $wOrg = $organizerId ? ' AND organizer_id = ' . (int) $organizerId : '';
    $wd = $q("SELECT COALESCE(SUM(CASE WHEN status = 'PAID' AND completed_at >= ? AND completed_at < ? + INTERVAL 1 DAY THEN amount_ugx END), 0) paid_ugx,
                     COALESCE(SUM(CASE WHEN status = 'PAID' AND completed_at >= ? AND completed_at < ? + INTERVAL 1 DAY THEN 1 END), 0) paid_count,
                     COALESCE(SUM(CASE WHEN status = 'FAILED' AND completed_at >= ? AND completed_at < ? + INTERVAL 1 DAY THEN 1 END), 0) failed_count,
                     COALESCE(SUM(CASE WHEN status IN ('REQUESTED','PROCESSING') THEN amount_ugx END), 0) open_ugx,
                     COALESCE(SUM(CASE WHEN status IN ('REQUESTED','PROCESSING') THEN 1 END), 0) open_count
              FROM gift_withdrawals WHERE 1 = 1$wOrg", [$a, $b, $a, $b, $a, $b])[0];

    // right now: what obitickets owes organizers, who is in debt, refunds owed to senders
    $obOrg = $organizerId ? ' WHERE organizer_id = ' . (int) $organizerId : '';
    $liab = $pdo->query("SELECT COALESCE(SUM(CASE WHEN available_ugx > 0 THEN available_ugx ELSE 0 END), 0) available, COALESCE(SUM(reserved_ugx), 0) reserved, COALESCE(SUM(pending_ugx), 0) pending,
                                COALESCE(SUM(CASE WHEN available_ugx < 0 THEN -available_ugx ELSE 0 END), 0) debt, COALESCE(SUM(available_ugx < 0), 0) debtors
                         FROM gift_balances$obOrg")->fetch();

    return [
        'from' => $from, 'to' => $to, 'monthly' => $monthly,
        'gifts' => (int) $paid['gifts'], 'gross' => (int) $paid['gross'], 'platform' => (int) $paid['platform'], 'organizer' => (int) $paid['organizer'],
        'fees' => (int) $paid['fees'], 'net_platform' => (int) $paid['platform'] - (int) $paid['fees'], 'supporters' => (int) $paid['supporters'],
        'average_ugx' => (int) $paid['gifts'] > 0 ? intdiv((int) $paid['gross'], (int) $paid['gifts']) : 0,
        'reversed_gifts' => (int) $rev['gifts'], 'reversed_gross' => (int) $rev['gross'], 'reversed_organizer' => (int) $rev['organizer'],
        'started' => (int) $att['started'], 'succeeded' => (int) $att['succeeded'], 'failed' => (int) $att['failed'], 'waiting' => (int) $att['waiting'],
        'success_rate' => (int) $att['started'] > 0 ? (int) round((int) $att['succeeded'] / (int) $att['started'] * 100) : null,
        'series' => $series,
        'by_gift' => array_map(static fn ($r) => ['name' => $r['name'], 'emoji' => $r['emoji'], 'gifts' => (int) $r['gifts'], 'units' => (int) $r['units'], 'gross' => (int) $r['gross']], $byGift),
        'top_organizers' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'gifts' => (int) $r['gifts'], 'gross' => (int) $r['gross'], 'organizer' => (int) $r['organizer']], $topOrganizers),
        'top_supporters' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'gifts' => (int) $r['gifts'], 'gross' => (int) $r['gross'], 'anonymous_sometimes' => (bool) $r['anonymous_sometimes']], $topSupporters),
        'withdrawn_ugx' => (int) $wd['paid_ugx'], 'withdrawn_count' => (int) $wd['paid_count'], 'withdraw_failed' => (int) $wd['failed_count'],
        'withdraw_open_ugx' => (int) $wd['open_ugx'], 'withdraw_open_count' => (int) $wd['open_count'],
        'owed_available' => (int) $liab['available'], 'owed_reserved' => (int) $liab['reserved'], 'owed_pending' => (int) $liab['pending'],
        'debt_ugx' => (int) $liab['debt'], 'debtors' => (int) $liab['debtors'],
        'refunds_owed' => gift_admin_refunds_owed($organizerId),
    ];
}

/**
 * Per-organizer earnings and balances for everyone who has had gift activity, biggest earners first.
 * @return list<array{id:int, name:string, email:string, gifts:int, earned:int, withdrawn:int, available:int, reserved:int, last_gift:?string, in_debt:bool}>
 */
function gift_admin_organizers(int $limit = 100): array
{
    $stmt = db()->query("SELECT b.organizer_id id, COALESCE(NULLIF(op.org_name, ''), u.name) name, u.email, b.total_earned_ugx earned, b.total_withdrawn_ugx withdrawn, b.available_ugx available, b.reserved_ugx reserved,
                                (SELECT COUNT(*) FROM gift_transactions t WHERE t.recipient_organizer_id = b.organizer_id AND t.payment_status = 'SUCCESSFUL') gifts,
                                (SELECT MAX(t.paid_at) FROM gift_transactions t WHERE t.recipient_organizer_id = b.organizer_id AND t.payment_status IN ('SUCCESSFUL','REFUNDED')) last_gift
                         FROM gift_balances b JOIN users u ON u.id = b.organizer_id LEFT JOIN organizer_profiles op ON op.user_id = b.organizer_id
                         ORDER BY b.total_earned_ugx DESC, b.organizer_id LIMIT " . max(1, min(500, $limit)));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'gifts' => (int) $r['gifts'], 'earned' => (int) $r['earned'], 'withdrawn' => (int) $r['withdrawn'],
                  'available' => (int) $r['available'], 'reserved' => (int) $r['reserved'], 'last_gift' => $r['last_gift'] !== null ? (string) $r['last_gift'] : null, 'in_debt' => (int) $r['available'] < 0];
    }
    return $out;
}
