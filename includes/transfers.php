<?php
// Send a ticket to a friend (11/24, migration 039).
// The holder types the friend's email or phone; the friend gets a claim link (email or SMS, and the sender can also
// share it on WhatsApp). The friend logs in or signs up and accepts: the ticket gets a NEW code, so the old QR (and any
// screenshot of it) stops working, and it moves to the friend's My Tickets. Allowed while the ticket is paid, unused,
// the event has not started, and the organizer has not switched sending off.

function transfers_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'ticket_transfers'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** One ticket with everything the send / claim rules need. */
function transfer_ticket_row(int $ticketId): ?array
{
    $stmt = db()->prepare('
        SELECT t.id, t.ticket_code, t.status, t.holder_user_id, t.checked_in_at,
               o.id AS order_id, o.user_id AS buyer_id, o.status AS order_status,
               tt.name AS tier_name,
               e.id AS event_id, e.title AS event_title, e.slug AS event_slug, e.starts_at AS event_starts_at,
               e.ends_at AS event_ends_at, e.venue_name, e.venue_address, e.banner_image, e.allow_transfers
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN orders o ON o.id = oi.order_id
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = o.event_id
        WHERE t.id = ?
    ');
    $stmt->execute([$ticketId]);
    return $stmt->fetch() ?: null;
}

/** Who holds the ticket now: whoever it was sent to, else the buyer. */
function transfer_holder_id(array $row): int
{
    return (int) ($row['holder_user_id'] ?: $row['buyer_id']);
}

/** Why this person cannot send this ticket now, or null when they can. */
function transfer_block_reason(array $row, int $userId): ?string
{
    if (!transfers_ready()) {
        return 'Sending tickets is not available yet.';
    }
    if (transfer_holder_id($row) !== $userId) {
        return 'This ticket is not yours to send.';
    }
    if ($row['order_status'] !== 'PAID' || $row['status'] !== 'VALID' || $row['checked_in_at']) {
        return 'Only an unused ticket can be sent.';
    }
    if ((int) $row['allow_transfers'] !== 1) {
        return 'The organizer has switched off sending tickets for this event.';
    }
    if (strtotime((string) $row['event_starts_at']) <= time()) {
        return 'The event has already started, so this ticket can no longer be sent.';
    }
    return null;
}

/** The open (not yet accepted) send for a ticket, if any. */
function transfer_pending_for_ticket(int $ticketId): ?array
{
    if (!transfers_ready()) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM ticket_transfers WHERE ticket_id = ? AND status = 'PENDING' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$ticketId]);
    return $stmt->fetch() ?: null;
}

function transfer_claim_url(string $token): string
{
    return rtrim(APP_URL, '/') . '/claim.php?t=' . $token;
}

/** "name@x.com" -> email, "0772 123456" / "+256772123456" -> phone (2567XXXXXXXX), anything else -> null. */
function transfer_parse_contact(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 191) {
        return null;
    }
    if (str_contains($raw, '@')) {
        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? ['email', strtolower($raw)] : null;
    }
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (preg_match('/^(?:256|0)?(7\d{8})$/', $digits, $m)) {
        return ['phone', '256' . $m[1]];
    }
    return null;
}

/**
 * Starts sending a ticket. Returns ['ok' => true, 'url' => claim link, 'via' => 'email'|'sms'|'link'] or ['error' => ...].
 * A new send replaces an older open one for the same ticket (its link stops working).
 */
function transfer_create(int $ticketId, array $sender, string $contactRaw): array
{
    $row = transfer_ticket_row($ticketId);
    if (!$row) {
        return ['error' => 'Ticket not found.'];
    }
    if ($why = transfer_block_reason($row, (int) $sender['id'])) {
        return ['error' => $why];
    }
    $contact = transfer_parse_contact($contactRaw);
    if (!$contact) {
        return ['error' => 'Type your friend\'s email address or Ugandan phone number (07XX XXX XXX).'];
    }
    [$kind, $value] = $contact;
    if ($kind === 'email' && strcasecmp($value, (string) $sender['email']) === 0) {
        return ['error' => 'That is your own email. Type your friend\'s.'];
    }
    // a little brake on someone hammering the button: 10 sends an hour per person
    $n = db()->prepare('SELECT COUNT(*) FROM ticket_transfers WHERE from_user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $n->execute([(int) $sender['id']]);
    if ((int) $n->fetchColumn() >= 10) {
        return ['error' => 'You have sent a lot of tickets in the last hour. Please try again a bit later.'];
    }

    $token = bin2hex(random_bytes(32));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE ticket_transfers SET status = 'CANCELLED' WHERE ticket_id = ? AND status = 'PENDING'")->execute([$ticketId]);
        $pdo->prepare('INSERT INTO ticket_transfers (ticket_id, from_user_id, to_contact, token) VALUES (?, ?, ?, ?)')
            ->execute([$ticketId, (int) $sender['id'], $value, $token]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[transfer] create failed: ' . $e->getMessage());
        return ['error' => 'Something went wrong. Please try again.'];
    }

    $url = transfer_claim_url($token);
    $from = (string) $sender['name'];
    $when = date('D j M, g:i A', strtotime((string) $row['event_starts_at']));
    $via = 'link';
    try {
        if ($kind === 'email' && function_exists('resend_send')) {
            $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
            $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto">'
                . '<h2 style="color:#991B1B">' . $h($from) . ' sent you a ticket 🎟️</h2>'
                . '<p><strong>' . $h((string) $row['event_title']) . '</strong><br>' . $h($when) . '<br>' . $h((string) $row['venue_name']) . '</p>'
                . '<p>Ticket: ' . $h((string) $row['tier_name']) . '</p>'
                . '<p><a href="' . $h($url) . '" style="display:inline-block;background:#DC2626;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:bold">Accept your ticket</a></p>'
                . '<p style="color:#726C7E;font-size:13px">Log in or create a free obitickets account to accept it. The ticket then shows in My Tickets with its own QR code.</p></div>';
            if (resend_send($value, $from . ' sent you a ticket to ' . $row['event_title'], $html, 'ticket_transfer')) {
                $via = 'email';
            }
        } elseif ($kind === 'phone' && function_exists('send_sms')) {
            send_sms($value, $from . ' sent you a ticket to ' . $row['event_title'] . ' (' . $when . '). Accept it here: ' . $url);
            $via = 'sms';
        }
    } catch (Throwable $e) {
        error_log('[transfer] notify failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'url' => $url, 'via' => $via, 'contact' => $value];
}

/** The sender takes back an open send (the link stops working). */
function transfer_cancel(int $ticketId, int $userId): bool
{
    if (!transfers_ready()) {
        return false;
    }
    $stmt = db()->prepare("UPDATE ticket_transfers SET status = 'CANCELLED' WHERE ticket_id = ? AND from_user_id = ? AND status = 'PENDING'");
    $stmt->execute([$ticketId, $userId]);
    return $stmt->rowCount() > 0;
}

/** An open send by its link secret, with the ticket. */
function transfer_by_token(string $token): ?array
{
    if (!transfers_ready() || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT tr.*, u.name AS from_name FROM ticket_transfers tr JOIN users u ON u.id = tr.from_user_id WHERE tr.token = ?');
    $stmt->execute([$token]);
    $tr = $stmt->fetch();
    if (!$tr) {
        return null;
    }
    $tr['ticket'] = transfer_ticket_row((int) $tr['ticket_id']);
    return $tr['ticket'] ? $tr : null;
}

/**
 * The friend accepts. Everything is re-checked inside a locked transaction, the ticket gets a fresh code and the
 * friend becomes its holder. Returns ['ok' => true, 'ticket_id' => ...] or ['error' => ...].
 */
function transfer_claim(string $token, array $user): array
{
    $tr = transfer_by_token($token);
    if (!$tr || $tr['status'] !== 'PENDING') {
        return ['error' => 'This ticket link has already been used or was taken back by the sender.'];
    }
    if ((int) $tr['from_user_id'] === (int) $user['id']) {
        return ['error' => 'This is your own ticket. Share the link with your friend so they can accept it.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT status FROM ticket_transfers WHERE id = ? FOR UPDATE");
        $lock->execute([(int) $tr['id']]);
        $pdo->prepare('SELECT id FROM tickets WHERE id = ? FOR UPDATE')->execute([(int) $tr['ticket_id']]);
        $row = transfer_ticket_row((int) $tr['ticket_id']);
        if ($lock->fetchColumn() !== 'PENDING' || !$row) {
            $pdo->rollBack();
            return ['error' => 'This ticket link has already been used or was taken back by the sender.'];
        }
        // the sender must still be able to send it (still theirs, unused, event not started, sending still on)
        if ($why = transfer_block_reason($row, (int) $tr['from_user_id'])) {
            $pdo->rollBack();
            return ['error' => $why === 'This ticket is not yours to send.' ? 'This ticket has already gone to someone else.' : $why];
        }
        $newCode = generate_ticket_code();
        $pdo->prepare('UPDATE tickets SET ticket_code = ?, holder_user_id = ? WHERE id = ?')
            ->execute([$newCode, (int) $user['id'], (int) $tr['ticket_id']]);
        $pdo->prepare("UPDATE ticket_transfers SET status = 'CLAIMED', to_user_id = ?, claimed_at = NOW() WHERE id = ?")
            ->execute([(int) $user['id'], (int) $tr['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[transfer] claim failed: ' . $e->getMessage());
        return ['error' => 'Something went wrong. Please try again.'];
    }
    try {
        if (function_exists('create_notification')) {
            create_notification((int) $tr['from_user_id'], 'ticket_transfer', $user['name'] . ' accepted your ticket',
                (string) $tr['ticket']['event_title'], '/order.php?id=' . (int) $tr['ticket']['order_id'], (int) $user['id']);
        }
    } catch (Throwable $e) {
        error_log('[transfer] notify sender failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'ticket_id' => (int) $tr['ticket_id']];
}

/** For the buyer's order page: per ticket id, who it went to (name) or the open send. */
function transfer_states_for_order(int $orderId): array
{
    if (!transfers_ready()) {
        return [];
    }
    $out = [];
    $stmt = db()->prepare('
        SELECT t.id, t.holder_user_id, u.name AS holder_name
        FROM tickets t JOIN order_items oi ON oi.id = t.order_item_id LEFT JOIN users u ON u.id = t.holder_user_id
        WHERE oi.order_id = ?
    ');
    $stmt->execute([$orderId]);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['id']] = ['holder_id' => $r['holder_user_id'] ? (int) $r['holder_user_id'] : null, 'holder_name' => $r['holder_name'], 'pending' => transfer_pending_for_ticket((int) $r['id'])];
    }
    return $out;
}

/** Tickets other people sent to this user (newest event first), for My Tickets. */
function tickets_received_by_user(int $userId): array
{
    if (!transfers_ready()) {
        return [];
    }
    $stmt = db()->prepare('
        SELECT t.id, t.status, tt.name AS tier_name, o.user_id AS buyer_id, ub.name AS from_name,
               e.id AS event_id, e.title AS event_title, e.slug AS event_slug, e.starts_at AS event_starts_at, e.ends_at AS event_ends_at,
               e.venue_name, e.banner_image, (COALESCE(e.ends_at, e.starts_at) >= NOW()) AS event_upcoming
        FROM tickets t
        JOIN order_items oi ON oi.id = t.order_item_id
        JOIN orders o ON o.id = oi.order_id AND o.status = "PAID"
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        JOIN events e ON e.id = o.event_id
        LEFT JOIN users ub ON ub.id = (SELECT tr.from_user_id FROM ticket_transfers tr WHERE tr.ticket_id = t.id AND tr.status = "CLAIMED" ORDER BY tr.id DESC LIMIT 1)
        WHERE t.holder_user_id = ? AND o.user_id <> ?
        ORDER BY e.starts_at
    ');
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}
