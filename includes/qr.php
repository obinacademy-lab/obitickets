<?php
declare(strict_types=1);

/**
 * Renders a ticket code as a scannable QR PNG (a data: URI, ready to drop
 * straight into an <img src>) via the free api.qrserver.com service — the
 * same no-dependency, no-Composer approach as the rest of the app's external
 * calls (iotec, Resend). Encodes exactly the ticket_code string, so a scan
 * decodes to the same value checkin.php already looks up by.
 *
 * Returns null on any failure (network, non-200, empty body) so callers can
 * fall back to showing the ticket code as plain text instead of breaking
 * the whole page/email over a QR image that didn't load.
 */
function fetch_ticket_qr_data_uri(string $ticketCode, int $size = 220): ?string
{
    $url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
        'size' => $size . 'x' . $size,
        'margin' => 0,
        'data' => $ticketCode,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $bytes = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !is_string($bytes) || $bytes === '') {
        error_log("[qr] Failed to fetch QR for ticket $ticketCode (HTTP $status)");
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($bytes);
}
