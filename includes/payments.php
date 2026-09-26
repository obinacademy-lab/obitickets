<?php
declare(strict_types=1);

require_once __DIR__ . '/iotec.php';

class InsufficientTicketsException extends RuntimeException
{
}

/**
 * obitickets' cut of every ticket sold, owed by the organizer (not the
 * buyer — see the buyer-facing SERVICE_FEE_PER_TICKET below for that
 * separate fee). Applied to subtotal_amount at the moment of purchase and
 * stored on the order, so a future rate change never rewrites past orders.
 */
const PLATFORM_COMMISSION_RATE = 0.10;

/**
 * Flat fee charged to the BUYER on top of every ticket, in UGX (obitickets
 * currently operates in Uganda only — see TICKET_CURRENCIES in events.php).
 * Unlike PLATFORM_COMMISSION_RATE above, this is per ticket, not a
 * percentage of the order — see checkout.php for where it's applied.
 */
const SERVICE_FEE_PER_TICKET = 700;

function generate_ticket_code(): string
{
    return 'OT-' . strtoupper(bin2hex(random_bytes(5)));
}

/**
 * Rebuilds a checkout cart from the session's pending_checkout selection —
 * shared by checkout.php's own page render and api/initiate-payment.php's
 * POST action, so the two can never disagree about the same cart's total.
 * Only tier ids and requested quantities come from $pending; price and
 * availability are always re-read from the DB right here, never trusted
 * from anything client-supplied.
 * @return array{event:array,lineItems:list<array>,subtotal:float,fee:float,total:float,currency:string}|array{error:string}
 */
function resolve_checkout_cart(array $pending): array
{
    $event = get_event_by_slug((string) ($pending['event_slug'] ?? ''));
    if (!$event) {
        return ['error' => 'event_not_found'];
    }

    $tiersById = [];
    foreach (get_ticket_types_for_event((int) $event['id']) as $t) {
        $tiersById[(int) $t['id']] = $t;
    }

    $lineItems = [];
    $subtotal = 0.0;
    $ticketCount = 0;
    $currency = null;
    foreach (($pending['qty'] ?? []) as $tierId => $qty) {
        $qty = (int) $qty;
        $tierId = (int) $tierId;
        if ($qty < 1 || !isset($tiersById[$tierId])) {
            continue;
        }
        $tier = $tiersById[$tierId];
        $available = !empty($tier['sales_paused']) ? 0 : max(0, (int) $tier['quantity_total'] - (int) $tier['quantity_sold']);
        $qty = min($qty, $available); // never let a stale cart request more than is actually left (paused tiers show 0 available)
        if ($qty < 1) {
            continue;
        }
        $lineTotal = $qty * (float) $tier['price'];
        $subtotal += $lineTotal;
        $ticketCount += $qty;
        $currency = $currency ?? $tier['currency'];
        $lineItems[] = [
            'tier_id' => $tierId,
            'name' => $tier['name'],
            'quantity' => $qty,
            'unit_price' => (float) $tier['price'],
            'line_total' => $lineTotal,
        ];
    }

    if (!$lineItems) {
        return ['error' => 'no_tickets_selected'];
    }

    $currency = $currency ?? 'UGX';
    $fee = $ticketCount * SERVICE_FEE_PER_TICKET;

    return [
        'event' => $event,
        'lineItems' => $lineItems,
        'subtotal' => $subtotal,
        'fee' => $fee,
        'total' => $subtotal + $fee,
        'currency' => $currency,
    ];
}

/**
 * Reserves stock and creates a PENDING order, then kicks off a real iotec
 * mobile money collection request against the buyer's phone. Reservation
 * happens now — not when the payment later confirms — inside the same
 * row-locked (SELECT ... FOR UPDATE) transaction the old simulated-payment
 * version used, so two attendees racing for the last ticket still can't
 * both succeed even though confirmation is now async (one of them approving
 * a prompt on their phone for the next several seconds). If the payment
 * later fails, release_order_or_noop() below releases this same reservation.
 *
 * Known gap, flagged rather than silently left unhandled: an attendee who
 * simply abandons the mobile money prompt (closes the tab, never approves
 * or declines) leaves their order PENDING and its stock reserved forever —
 * there's no cron sweep yet to expire a stale PENDING order and release it.
 * Worth building once this is deployed somewhere a real cron can run.
 *
 * @param list<array{tier_id:int,name:string,quantity:int,unit_price:float,line_total:float}> $lineItems
 * @return int the new order's id — check its `status` afterwards, since an
 *             immediate iotec failure (bad credentials, network error) is
 *             reported by leaving the order FAILED rather than by throwing.
 * @throws InsufficientTicketsException if any line item no longer has enough stock
 */
function create_pending_order(
    int $userId,
    int $eventId,
    array $lineItems,
    float $subtotal,
    float $fee,
    float $total,
    string $currency,
    string $phone
): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($lineItems as $item) {
            $stmt = $pdo->prepare('SELECT quantity_total, quantity_sold, sales_paused FROM ticket_types WHERE id = ? FOR UPDATE');
            $stmt->execute([$item['tier_id']]);
            $row = $stmt->fetch();
            if (!$row || !empty($row['sales_paused']) || ($row['quantity_sold'] + $item['quantity']) > $row['quantity_total']) {
                throw new InsufficientTicketsException(
                    "Sorry, \"{$item['name']}\" no longer has enough tickets available. Please go back and adjust your order."
                );
            }
        }

        $commission = round($subtotal * PLATFORM_COMMISSION_RATE, 2);

        $stmt = $pdo->prepare('
            INSERT INTO orders (user_id, event_id, status, subtotal_amount, service_fee_amount, commission_amount, total_amount, currency, payment_method)
            VALUES (?, ?, "PENDING", ?, ?, ?, ?, ?, "MOBILE_MONEY")
        ');
        $stmt->execute([$userId, $eventId, $subtotal, $fee, $commission, $total, $currency]);
        $orderId = (int) $pdo->lastInsertId();

        foreach ($lineItems as $item) {
            $stmt = $pdo->prepare('INSERT INTO order_items (order_id, ticket_type_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
            $stmt->execute([$orderId, $item['tier_id'], $item['quantity'], $item['unit_price']]);

            $pdo->prepare('UPDATE ticket_types SET quantity_sold = quantity_sold + ? WHERE id = ?')
                ->execute([$item['quantity'], $item['tier_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    try {
        $result = iotec_initiate_collection($total, $phone, (string) $orderId, substr('obitickets order #' . $orderId, 0, 100));
        db()->prepare('UPDATE orders SET payment_reference = ? WHERE id = ?')->execute([$result['transactionId'], $orderId]);
    } catch (Throwable $e) {
        error_log('[iotec] initiateCollection failed for order ' . $orderId . ': ' . $e->getMessage());
        fail_order($orderId, "We couldn't start the mobile money payment. Please try again.");
    }

    return $orderId;
}

/**
 * Mints one scannable ticket per unit purchased and marks the order PAID —
 * called only once iotec has actually confirmed the charge. Row-locks the
 * order itself first and no-ops if it has already left PENDING, so a stray
 * duplicate call (a callback arriving after the browser's own poll already
 * resolved it, say) can never mint tickets twice for the same order.
 */
function finalize_order_success(int $orderId, ?string $statusMessage): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || $order['status'] !== 'PENDING') {
            $pdo->commit();
            return;
        }

        $stmt = $pdo->prepare('SELECT id, quantity FROM order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll() as $item) {
            $ticketStmt = $pdo->prepare('INSERT INTO tickets (order_item_id, ticket_code) VALUES (?, ?)');
            for ($i = 0; $i < (int) $item['quantity']; $i++) {
                $ticketStmt->execute([$item['id'], generate_ticket_code()]);
            }
        }

        $pdo->prepare("UPDATE orders SET status = 'PAID', status_message = ?, paid_at = NOW() WHERE id = ?")
            ->execute([$statusMessage, $orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    try {
        send_order_tickets_email($orderId);
    } catch (Throwable $e) {
        // A broken ticket email must never fail the payment itself — the
        // order is already PAID and the tickets already exist (visible on
        // my-tickets.php/order.php either way); just log it for follow-up.
        error_log('[email] send_order_tickets_email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

/**
 * Releases a PENDING order's ticket-type reservation and marks it FAILED.
 * Same no-op-if-already-resolved guard as finalize_order_success(), so it's
 * safe to call from both an immediate iotec error and a later "Failed"
 * status check without ever double-releasing the same reserved stock.
 */
function fail_order(int $orderId, string $message): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || $order['status'] !== 'PENDING') {
            $pdo->commit();
            return;
        }

        $stmt = $pdo->prepare('SELECT ticket_type_id, quantity FROM order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll() as $item) {
            $pdo->prepare('UPDATE ticket_types SET quantity_sold = quantity_sold - ? WHERE id = ?')
                ->execute([$item['quantity'], $item['ticket_type_id']]);
        }

        $pdo->prepare("UPDATE orders SET status = 'FAILED', status_message = ? WHERE id = ?")
            ->execute([$message, $orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reconciles one order against iotec's real transaction status and applies
 * the matching side effect. Safe to call repeatedly — every browser poll
 * calls this, and finalize_order_success()/fail_order() are themselves
 * idempotent — so calling it on an order that's already PAID/FAILED just
 * hands back that same status without touching anything.
 * @return array{status:string, statusMessage:?string}
 */
function resolve_order_with_iotec(array $order): array
{
    if ($order['status'] !== 'PENDING') {
        return ['status' => $order['status'], 'statusMessage' => $order['status_message']];
    }
    if (!$order['payment_reference']) {
        // iotec_initiate_collection() hasn't recorded a transaction id yet —
        // either the request is still in flight or it already failed and
        // fail_order() will have moved this order off PENDING by now.
        return ['status' => 'PENDING', 'statusMessage' => null];
    }

    try {
        $result = iotec_check_collection_status($order['payment_reference']);
    } catch (Throwable $e) {
        error_log('[iotec] checkCollectionStatus failed for order ' . $order['id'] . ': ' . $e->getMessage());
        return ['status' => 'PENDING', 'statusMessage' => null];
    }

    if ($result['status'] === 'Success') {
        finalize_order_success((int) $order['id'], $result['statusMessage']);
        return ['status' => 'PAID', 'statusMessage' => $result['statusMessage']];
    }

    if ($result['status'] === 'Failed') {
        fail_order((int) $order['id'], $result['statusMessage'] ?? 'The payment was not completed.');
        return ['status' => 'FAILED', 'statusMessage' => $result['statusMessage']];
    }

    // Pending / SentToVendor — still waiting on the payer's phone.
    return ['status' => 'PENDING', 'statusMessage' => $result['statusMessage']];
}

/**
 * @return array{status:string, statusMessage:?string}
 * @throws RuntimeException if the order doesn't exist or isn't this user's
 */
function poll_order_payment(int $userId, int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    return resolve_order_with_iotec($order);
}

function get_order_for_user(int $orderId, int $userId): ?array
{
    $stmt = db()->prepare('
        SELECT o.*, e.title AS event_title, e.slug AS event_slug, e.starts_at AS event_starts_at, e.venue_name
        FROM orders o
        JOIN events e ON e.id = o.event_id
        WHERE o.id = ? AND o.user_id = ?
    ');
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $stmt = db()->prepare('
        SELECT oi.*, tt.name AS tier_name
        FROM order_items oi
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ');
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll();

    foreach ($order['items'] as &$item) {
        $stmt = db()->prepare('SELECT * FROM tickets WHERE order_item_id = ? ORDER BY id');
        $stmt->execute([$item['id']]);
        $item['tickets'] = $stmt->fetchAll();
    }
    unset($item);

    return $order;
}

/**
 * Everything send_order_tickets_email() needs to render the ticket email —
 * same shape as get_order_for_user() above, minus the user_id scoping
 * (this runs server-side right after payment confirmation, not on behalf of
 * a logged-in request) and with the buyer's name/email joined in.
 */
function get_order_ticket_details(int $orderId): ?array
{
    $stmt = db()->prepare('
        SELECT o.*, e.title AS event_title, e.slug AS event_slug, e.category, e.banner_emoji, e.banner_image,
               e.starts_at AS event_starts_at, e.ends_at AS event_ends_at, e.venue_name, e.venue_address,
               u.name AS buyer_name, u.email AS buyer_email
        FROM orders o
        JOIN events e ON e.id = o.event_id
        JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $stmt = db()->prepare('
        SELECT oi.*, tt.name AS tier_name
        FROM order_items oi
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ');
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll();

    foreach ($order['items'] as &$item) {
        $stmt = db()->prepare('SELECT * FROM tickets WHERE order_item_id = ? ORDER BY id');
        $stmt->execute([$item['id']]);
        $item['tickets'] = $stmt->fetchAll();
    }
    unset($item);

    return $order;
}

function get_orders_for_user(int $userId): array
{
    $stmt = db()->prepare('
        SELECT o.*, e.title AS event_title, e.slug AS event_slug, e.starts_at AS event_starts_at
        FROM orders o
        JOIN events e ON e.id = o.event_id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
