<?php
declare(strict_types=1);

/**
 * Returns a stable, obitickets-hosted https:// URL for a ticket code's QR
 * PNG, generating and caching the file on first request (idempotent — same
 * ticket always resolves to the same file, so repeat calls just serve what's
 * already there). Previously this hotlinked api.qrserver.com directly from
 * the email; before that it inlined the PNG as a base64 data: URI. Both
 * still left the QR not rendering for some recipients — Gmail's own
 * image-loading proxy in particular still failed to fetch a third-party
 * host even after the recipient allowed remote images, for reasons outside
 * our control (rate limiting, proxy user-agent blocking, etc). Self-hosting
 * removes that dependency at view time entirely: api.qrserver.com is only
 * ever called once per ticket, right here, and every viewer after that
 * loads the PNG from obitickets' own domain like any other image on the
 * site. Returns null only if that one-time generation fails (network error,
 * unwritable disk), so callers can fall back to showing the ticket code as
 * plain text instead.
 */
function ticket_qr_image_url(string $ticketCode, int $size = 220): ?string
{
    // ticket_code is always 'OT-' + 10 hex chars (generate_ticket_code() in
    // includes/payments.php) — safe to use directly as a filename.
    $destDir = __DIR__ . '/../uploads/tickets';
    $fullPath = $destDir . '/' . $ticketCode . '.png';

    if (!is_file($fullPath)) {
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            error_log("[qr] Could not prepare storage for ticket $ticketCode");
            return null;
        }

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

        file_put_contents($fullPath, $bytes);
    }

    return rtrim(APP_URL, '/') . '/uploads/tickets/' . $ticketCode . '.png';
}
