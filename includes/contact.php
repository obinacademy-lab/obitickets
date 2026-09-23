<?php
declare(strict_types=1);

const CONTACT_TOPICS = ['General', 'Organizer support', 'Report an issue', 'Press'];

function create_contact_message(string $name, string $email, string $topic, string $message): int
{
    $stmt = db()->prepare('INSERT INTO contact_messages (name, email, topic, message) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $email, $topic, $message]);
    return (int) db()->lastInsertId();
}
