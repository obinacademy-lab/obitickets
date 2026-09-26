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
