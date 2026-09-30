<?php
declare(strict_types=1);

/**
 * SMS delivery via ioTec Messaging (https://iotec.io/api-docs/messaging) —
 * a separate iotec product from the Pay wallet already used for mobile
 * money in includes/iotec.php, with its own credentials (Client-Id +
 * X-Api-Key from https://messaging.iotec.io, not the Pay client_id/secret)
 * and no OAuth token exchange, just static headers on every request.
 * Same "log and return, never throw" shape as resend_send() in email.php,
 * so a broken/unconfigured SMS gateway can never break checkout. Uses
 * defined()/empty() rather than a bare constant reference for the
 * credentials, since a live config.php deployed before this feature shipped
 * won't define them yet — that must degrade to "SMS skipped", not a fatal
 * "undefined constant" error on every single request.
 */
const IOTEC_MESSAGING_SEND_URL = 'https://messaging-api.iotec.io/api/msg/bulk/send';

function iotec_msg_client_id(): string
{
    return defined('IOTEC_MSG_CLIENT_ID') ? IOTEC_MSG_CLIENT_ID : '';
}

function iotec_msg_api_key(): string
{
    return defined('IOTEC_MSG_API_KEY') ? IOTEC_MSG_API_KEY : '';
}

function send_sms(string $phone, string $message): void
{
    if (iotec_msg_api_key() === '' || iotec_msg_client_id() === '') {
        error_log("[sms] IOTEC_MSG_CLIENT_ID/IOTEC_MSG_API_KEY not set — skipping SMS to $phone");
        return;
    }

    $recipient = preg_replace('/\D/', '', $phone) ?? '';

    $ch = curl_init(IOTEC_MESSAGING_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Client-Id: ' . iotec_msg_client_id(),
            'X-Api-Key: ' . iotec_msg_api_key(),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'recipients' => [$recipient],
            'body' => $message,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("[sms] ioTec Messaging rejected the SMS to $recipient ($status): $body");
    }
}

/**
 * Texts the buyer their ticket code(s) right after a successful payment —
 * called from finalize_order_success() alongside send_order_tickets_email().
 * No QR image over SMS (plain text only), so this leans on the ticket code
 * itself: checkin.php already has a manual-code-entry fallback for exactly
 * this case, so a code-only text is a fully usable ticket, not a lesser one.
 * Best-effort only: an order with no phone on file (orders placed before
 * migration 009) just silently skips, since the email already covers
 * delivery either way.
 */
function send_order_tickets_sms(int $orderId): void
{
    $order = get_order_ticket_details($orderId);
    if (!$order || empty($order['phone'])) {
        return;
    }

    $codes = [];
    foreach ($order['items'] as $item) {
        foreach ($item['tickets'] as $ticket) {
            $codes[] = $ticket['ticket_code'];
        }
    }
    if (!$codes) {
        return;
    }

    $dateRange = format_event_date_range($order['event_starts_at'], $order['event_ends_at']);
    $orderUrl = rtrim(APP_URL, '/') . '/order.php?id=' . $orderId;
    $message = "obitickets: Your ticket(s) for {$order['event_title']} ({$dateRange}) are confirmed!\n"
        . 'Code(s): ' . implode(', ', $codes) . "\n"
        . "View/download: $orderUrl";

    send_sms($order['phone'], $message);
}
