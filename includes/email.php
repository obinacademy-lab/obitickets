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
 * gets its own obitickets-hosted QR image (ticket_qr_image_url() in
 * includes/qr.php); a ticket whose QR couldn't be generated still renders
 * with its code shown as plain text, so one flaky request never breaks the
 * whole email. Shared by the "your tickets are ready" email and the
 * day-before reminder below — neither carries its own copy of this markup.
 */
function render_ticket_cards_html(array $order): string
{
    $buyerName = htmlspecialchars($order['buyer_name']);

    $cards = '';
    foreach ($order['items'] as $item) {
        $tierName = htmlspecialchars($item['tier_name']);
        foreach ($item['tickets'] as $ticket) {
            $code = htmlspecialchars($ticket['ticket_code']);
            $qrUrl = ticket_qr_image_url($ticket['ticket_code']);
            $qrHtml = $qrUrl
                ? '<img src="' . htmlspecialchars($qrUrl) . '" width="180" height="180" alt="QR code for ' . $code . '" style="display:block; margin:0 auto; border-radius:8px;">'
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

    return $cards;
}

function render_ticket_email_html(array $order): string
{
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $dateRange = htmlspecialchars(format_event_date_range($order['event_starts_at'], $order['event_ends_at']));
    $venue = htmlspecialchars($order['venue_name'] . ($order['venue_address'] ? ', ' . $order['venue_address'] : ''));
    $ticketUrl = rtrim(APP_URL, '/') . '/order.php?id=' . (int) $order['id'];
    $cards = render_ticket_cards_html($order);

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

function render_ticket_reminder_email_html(array $order): string
{
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $dateRange = htmlspecialchars(format_event_date_range($order['event_starts_at'], $order['event_ends_at']));
    $venue = htmlspecialchars($order['venue_name'] . ($order['venue_address'] ? ', ' . $order['venue_address'] : ''));
    $ticketUrl = rtrim(APP_URL, '/') . '/order.php?id=' . (int) $order['id'];
    $cards = render_ticket_cards_html($order);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">See you soon? 🎟️</h2>
          <p>Hi {$buyerName}, just a reminder that {$eventTitle} is coming up &mdash; your tickets are below, ready to scan at the gate.</p>
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
 * Emails a "see you soon" reminder for a paid order — same ticket cards as
 * send_order_tickets_email(), different subject/intro. Called from
 * cron/send-event-reminders.php once per paid order on an event starting in
 * roughly 24 hours; never called from the checkout flow itself.
 */
function send_order_reminder_email(int $orderId): void
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        error_log("[email] send_order_reminder_email: order $orderId not found");
        return;
    }

    $ticketCount = 0;
    foreach ($order['items'] as $item) {
        $ticketCount += count($item['tickets']);
    }
    if ($ticketCount === 0) {
        error_log("[email] send_order_reminder_email: order $orderId has no tickets yet");
        return;
    }

    resend_send($order['buyer_email'], 'Reminder: ' . $order['event_title'] . ' is coming up', render_ticket_reminder_email_html($order));
}

/**
 * A "how was it?" nudge sent to every checked-in attendee once an event has
 * finished — see cron/send-review-prompts.php. Not tied to an order (an
 * attendee, not a purchase), so this takes plain values rather than the
 * get_order_ticket_details() shape the emails above use.
 */
function render_review_prompt_email_html(string $name, string $eventTitle, string $eventUrl): string
{
    $safeName = htmlspecialchars($name);
    $safeTitle = htmlspecialchars($eventTitle);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">How was {$safeTitle}? 🎉</h2>
          <p>Hi {$safeName}, thanks for coming out! We'd love to hear what you thought — it only takes a minute.</p>
          <p>
            <a href="{$eventUrl}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">
              Leave a review
            </a>
          </p>
          <p style="color:#726C7E; font-size:12px;">
            Your review helps other people discover great events like this one.
          </p>
        </div>
        HTML;
}

function send_review_prompt_email(string $email, string $name, string $eventTitle, string $eventUrl): void
{
    resend_send($email, 'How was ' . $eventTitle . '?', render_review_prompt_email_html($name, $eventTitle, $eventUrl));
}

/**
 * Sent by notify_waitlist_for_tier() (includes/waitlist.php) when a seat on a
 * sold-out tier opens up. Deliberately says it isn't held — anyone can buy
 * it until it's gone — so nobody thinks they've been promised a ticket.
 */
function render_waitlist_available_email_html(string $name, string $eventTitle, string $tierName, string $eventUrl): string
{
    $safeName = htmlspecialchars($name);
    $safeTitle = htmlspecialchars($eventTitle);
    $safeTier = htmlspecialchars($tierName);

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">A ticket just opened up 🎟️</h2>
          <p>Hi {$safeName}, good news &mdash; a <strong>{$safeTier}</strong> ticket for <strong>{$safeTitle}</strong> is available again.</p>
          <p>
            <a href="{$eventUrl}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">
              Get my ticket
            </a>
          </p>
          <p style="color:#726C7E; font-size:13px;">
            Tickets aren't held for you &mdash; it's first come, first served, so grab it soon if you still want to go.
          </p>
          <p style="color:#726C7E; font-size:12px;">You're getting this because you joined the waitlist for this ticket.</p>
        </div>
        HTML;
}

function send_waitlist_available_email(string $email, string $name, string $eventTitle, string $tierName, string $eventUrl): void
{
    resend_send($email, 'A ticket opened up for ' . $eventTitle, render_waitlist_available_email_html($name, $eventTitle, $tierName, $eventUrl));
}

/**
 * The order has no tickets to show (payment never went through), so this is
 * a much simpler email than the two above — just what they were trying to
 * buy, and a link back to the event to start a fresh checkout. Called from
 * cron/expire-stale-orders.php right after fail_order() releases the
 * abandoned reservation, so the "try again" link always points at tickets
 * that are actually available again.
 */
function render_abandoned_checkout_email_html(array $order): string
{
    $eventTitle = htmlspecialchars($order['event_title']);
    $buyerName = htmlspecialchars($order['buyer_name']);
    $eventUrl = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($order['event_slug']);

    $lines = '';
    foreach ($order['items'] as $item) {
        $lines .= '<p style="margin:4px 0; font-family:sans-serif; font-size:14px; color:#1C1526;">' . (int) $item['quantity'] . '&times; ' . htmlspecialchars($item['tier_name']) . '</p>';
    }

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto;">
          <h2 style="color:#991B1B;">Still want to go? 🎟️</h2>
          <p>Hi {$buyerName}, your ticket reservation for <strong>{$eventTitle}</strong> didn't go through &mdash; no charge was made, and those tickets have been released back to general sale.</p>
          <div style="background:#FEF2F2; border-radius:12px; padding:14px 16px; margin:16px 0;">
            {$lines}
          </div>
          <p>
            <a href="{$eventUrl}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">
              Try again
            </a>
          </p>
          <p style="color:#726C7E; font-size:12px;">
            If you no longer want to attend, you can safely ignore this email.
          </p>
        </div>
        HTML;
}

function send_abandoned_checkout_email(int $orderId): void
{
    $order = get_order_ticket_details($orderId);
    if (!$order) {
        error_log("[email] send_abandoned_checkout_email: order $orderId not found");
        return;
    }

    resend_send($order['buyer_email'], 'Still want to go to ' . $order['event_title'] . '?', render_abandoned_checkout_email_html($order));
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
