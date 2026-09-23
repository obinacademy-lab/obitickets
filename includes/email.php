<?php
declare(strict_types=1);

/**
 * TODO: wire a real provider (Resend, SES, etc.) before production — this
 * just logs the email so nothing silently disappears during development.
 */
function send_email(string $to, string $subject, string $bodyText): void
{
    $logDir = __DIR__ . '/../storage';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $line = sprintf("[%s] TO:%s SUBJECT:%s\n%s\n\n", date('c'), $to, $subject, $bodyText);
    file_put_contents($logDir . '/mail.log', $line, FILE_APPEND);
}
