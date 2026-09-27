<?php
declare(strict_types=1);

/**
 * Returns a hotlinked https:// URL for a ticket code's QR, via the free
 * api.qrserver.com service, for direct use as an <img src>. Deliberately
 * NOT fetched-and-inlined as a base64 data: URI (the previous approach) —
 * major email clients, Gmail in particular, strip or block data: URIs in
 * HTML email for security reasons, so the QR silently never rendered for
 * most recipients. A plain https URL is subject only to each client's
 * normal "load remote images?" prompt, which the recipient can approve.
 * Encodes exactly the ticket_code string, so a scan decodes to the same
 * value checkin.php already looks up by.
 */
function ticket_qr_image_url(string $ticketCode, int $size = 220): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
        'size' => $size . 'x' . $size,
        'margin' => 0,
        'data' => $ticketCode,
    ]);
}
