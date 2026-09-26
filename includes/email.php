<?php
declare(strict_types=1);

/**
 * Real email delivery via Resend (resend.com) — ported from Obin Academy's
 * own includes/email.php, which uses the same provider. obitickets only
 * needs two real emails right now (password reset, contact-form
 * notification), so this stays much smaller than OA's — add a new
 * send_*_email() wrapper here if a third one is ever needed, following the
 * same pattern.
 */
function resend_send(string $to, string $subject, string $html): void
{
    if (!RESEND_API_KEY) {
        error_log("[email] RESEND_API_KEY is not set — skipping send to $to. Subject: $subject");
        return;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => EMAIL_FROM,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("[email] Resend rejected the email to $to ($status): $body");
    }
}

function send_password_reset_email(string $to, string $name, string $resetUrl): void
{
    resend_send($to, 'Reset your obitickets password', <<<HTML
        <div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
          <h2 style="color: #991B1B;">Reset your password</h2>
          <p>Hi {$name}, we received a request to reset the password for your obitickets account.</p>
          <p>
            <a href="{$resetUrl}" style="display: inline-block; background: #DC2626; color: #fff; padding: 12px 24px; border-radius: 999px; text-decoration: none; font-weight: 600;">
              Reset Password
            </a>
          </p>
          <p style="color: #726C7E; font-size: 14px;">
            This link expires in 1 hour. If you didn't request this, you can safely ignore this email.
          </p>
          <p style="color: #726C7E; font-size: 12px;">
            Or copy and paste this link into your browser:<br>{$resetUrl}
          </p>
        </div>
        HTML);
}

/**
 * Renders one <table>-based ticket card per ticket in the order — inline
 * styles and table layout throughout, since flexbox/grid aren't reliably
 * supported by email clients (Outlook desktop in particular). Each ticket
 * gets its own QR (fetch_ticket_qr_data_uri() in includes/qr.php); a ticket
 * whose QR fails to fetch still renders with its code shown as plain text,
 * so one flaky request never breaks the whole email.
 */
function render_ticket_email_html(array $order): string
{
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $dateRange = htmlspecialchars(format_event_date_range($order['event_starts_at'], $order['event_ends_at']));
    $venue = htmlspecialchars($order['venue_name'] . ($order['venue_address'] ? ', ' . $order['venue_address'] : ''));
    $ticketUrl = rtrim(APP_URL, '/') . '/order.php?id=' . (int) $order['id'];

    $cards = '';
    foreach ($order['items'] as $item) {
        $tierName = htmlspecialchars($item['tier_name']);
        foreach ($item['tickets'] as $ticket) {
            $code = htmlspecialchars($ticket['ticket_code']);
            $qr = fetch_ticket_qr_data_uri($ticket['ticket_code']);
            $qrHtml = $qr
                ? '<img src="' . $qr . '" width="180" height="180" alt="QR code for ' . $code . '" style="display:block; margin:0 auto; border-radius:8px;">'
                : '<div style="width:180px; height:180px; margin:0 auto; border:1px dashed #D8D2E4; border-radius:8px; display:table-cell; text-align:center; vertical-align:middle; color:#726C7E; font-size:12px;">QR unavailable &mdash; use the code below</div>';

            $cards .= <<<HTML
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; margin:0 auto 24px; border:1px solid #E8E4EF; border-radius:16px; overflow:hidden;">
                <tr>
                  <td style="background:linear-gradient(135deg,#DC2626,#991B1B); background-color:#DC2626; padding:16px 24px;">
                    <span style="font-family:sans-serif; font-size:15px; font-weight:700; color:#ffffff;">obitickets</span>
                    <span style="float:right; font-family:sans-serif; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:rgba(255,255,255,0.85);">E-Ticket</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:24px;">
                    <p style="margin:0; font-family:sans-serif; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#948FA0;">Attendee</p>
                    <p style="margin:2px 0 14px; font-family:sans-serif; font-size:18px; font-weight:800; color:#1C1526;">{$buyerName}</p>
                    <p style="margin:0; font-family:sans-serif; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#948FA0;">Ticket</p>
                    <p style="margin:2px 0 20px; font-family:sans-serif; font-size:15px; font-weight:700; color:#991B1B;">{$tierName}</p>
                    {$qrHtml}
                    <p style="margin:16px 0 0; font-family:monospace; font-size:13px; letter-spacing:0.04em; color:#726C7E; text-align:center;">{$code}</p>
                    <p style="margin:6px 0 0; font-family:sans-serif; font-size:12px; color:#948FA0; text-align:center;">Show this QR code at the entrance</p>
                  </td>
                </tr>
              </table>
              HTML;
        }
    }

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">Your tickets are ready 🎉</h2>
          <p>Hi {$buyerName}, thanks for your order &mdash; your tickets to {$eventTitle} are below.</p>
          <p style="background:#FEF2F2; border-radius:12px; padding:14px 16px; color:#1C1526;">
            <strong>{$eventTitle}</strong><br>
            {$dateRange}<br>
            {$venue}
          </p>
          {$cards}
          <p style="font-size:14px; color:#726C7E;">
            You can also view or re-download these anytime from your
            <a href="{$ticketUrl}" style="color:#991B1B;">order page</a>.
          </p>
          <p style="color:#726C7E; font-size:12px;">
            Each QR code is unique to one ticket and can only be scanned in once &mdash; please don't share a screenshot publicly.
          </p>
        </div>
        HTML;
}

/**
 * Emails every ticket in a just-paid order to the buyer, one styled ticket
 * card per ticket. Called from finalize_order_success() right after an
 * order moves PENDING -> PAID; safe to call again for the same order (it
 * just re-sends the same tickets), since finalize_order_success() itself
 * only ever reaches this once per order.
 */
function send_order_tickets_email(int $orderId): void
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        error_log("[email] send_order_tickets_email: order $orderId not found");
        return;
    }

    $ticketCount = 0;
    foreach ($order['items'] as $item) {
        $ticketCount += count($item['tickets']);
    }
    if ($ticketCount === 0) {
        error_log("[email] send_order_tickets_email: order $orderId has no tickets yet");
        return;
    }

    $subject = 'Your ' . ($ticketCount === 1 ? 'ticket' : 'tickets') . ' for ' . $order['event_title'];
    resend_send($order['buyer_email'], $subject, render_ticket_email_html($order));
}

function send_contact_notification_email(string $to, string $name, string $fromEmail, string $topic, string $message): void
{
    // All four values come straight from an unauthenticated public form
    // (contact.php) — escape every one of them before embedding in HTML.
    $safeName = htmlspecialchars($name);
    $safeEmail = htmlspecialchars($fromEmail);
    $safeTopic = htmlspecialchars($topic);
    $safeMessage = nl2br(htmlspecialchars($message));
    resend_send($to, 'New contact message: ' . $topic, <<<HTML
        <div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
          <h2 style="color: #991B1B;">New contact message</h2>
          <p><strong>{$safeTopic}</strong></p>
          <p>From: {$safeName} &lt;{$safeEmail}&gt;</p>
          <div style="background: #FEF2F2; border-radius: 12px; padding: 16px 18px; margin-top: 12px; color: #1C1526;">
            {$safeMessage}
          </div>
        </div>
        HTML);
}
