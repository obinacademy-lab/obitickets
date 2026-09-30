<?php
declare(strict_types=1);

/**
 * SMS delivery via Africa's Talking (africastalking.com) — the same
 * "log and return, never throw" shape as resend_send() in email.php, so a
 * broken/unconfigured SMS gateway can never break checkout. Uses
 * defined()/empty() rather than a bare constant reference for AT_USERNAME
 * and AT_API_KEY, since a live config.php deployed before this feature
 * shipped won't define them yet — that must degrade to "SMS skipped", not a
 * fatal "undefined constant" error on every single request.
 */
function at_username(): string
{
    return defined('AT_USERNAME') ? AT_USERNAME : 'sandbox';
}

function at_api_key(): string
{
    return defined('AT_API_KEY') ? AT_API_KEY : '';
}

function send_sms(string $phone, string $message): void
{
    if (at_api_key() === '') {
        error_log("[sms] AT_API_KEY is not set — skipping SMS to $phone");
        return;
    }

    $to = '+' . iotec_normalize_phone($phone);
    $username = at_username();
    $host = $username === 'sandbox' ? 'api.sandbox.africastalking.com' : 'api.africastalking.com';

    $ch = curl_init("https://$host/version1/messaging");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'apiKey: ' . at_api_key(),
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $username,
            'to' => $to,
            'message' => $message,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("[sms] Africa's Talking rejected the SMS to $to ($status): $body");
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
