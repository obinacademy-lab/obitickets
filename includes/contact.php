<?php
declare(strict_types=1);

const CONTACT_TOPICS = ['General', 'Organizer support', 'Report an issue', 'Press'];

// The platform's WhatsApp line for visitors with questions. Digits only, country code first (no + or spaces);
// a server config.php may define it first to change the number without a code change.
if (!defined('SUPPORT_WHATSAPP_NUMBER')) {
    define('SUPPORT_WHATSAPP_NUMBER', '256745055130');
}

/** The number the way people read it: +256 745 055 130. */
function support_whatsapp_display(): string
{
    $d = SUPPORT_WHATSAPP_NUMBER;
    return '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6, 3) . ' ' . substr($d, 9);
}

/** Opens a WhatsApp chat with the platform, with the first message already typed so visitors only press send. */
function support_whatsapp_url(string $message = 'Hello obitickets, I have a question about the platform.'): string
{
    return 'https://wa.me/' . SUPPORT_WHATSAPP_NUMBER . '?text=' . rawurlencode($message);
}

function create_contact_message(string $name, string $email, string $topic, string $message): int
{
    $stmt = db()->prepare('INSERT INTO contact_messages (name, email, topic, message) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $email, $topic, $message]);
    return (int) db()->lastInsertId();
}
