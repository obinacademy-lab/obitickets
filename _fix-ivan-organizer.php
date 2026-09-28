<?php
declare(strict_types=1);

/**
 * ONE-TIME repair for the "Remove admin" bug (fixed in admin-admins.php):
 * restores role = 'ORGANIZER' for a specific account that got flattened to
 * ATTENDEE, only if it truly has an existing organizer_profiles row. Visit
 * once with the correct ?key=, confirm the output, then DELETE THIS FILE —
 * it is not meant to stay on the server.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain');

if (($_GET['key'] ?? '') !== 'obi-fix-ivan-2026') {
    http_response_code(403);
    exit('Forbidden — missing or wrong ?key=');
}

$email = 'ivaninnocentobin@gmail.com';

$stmt = db()->prepare('SELECT id, name, email, role, admin_role FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    exit("No user found with email $email — nothing changed.");
}

echo "Found: {$user['name']} <{$user['email']}> (id {$user['id']})\n";
echo "Current role: {$user['role']}, admin_role: " . ($user['admin_role'] ?? 'NULL') . "\n\n";

$stmt = db()->prepare('SELECT id, org_name FROM organizer_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

if (!$profile) {
    exit("No organizer_profiles row for this user — nothing to restore. Their role was left as-is ({$user['role']}).");
}

echo "Organizer profile found: \"{$profile['org_name']}\"\n";

$stmt = db()->prepare('SELECT id, title, status FROM events WHERE organizer_id = ?');
$stmt->execute([$user['id']]);
$events = $stmt->fetchAll();
echo 'Events under this organizer: ' . count($events) . "\n";
foreach ($events as $e) {
    echo "  - #{$e['id']} \"{$e['title']}\" ({$e['status']})\n";
}
echo "\n";

if ($user['role'] === 'ORGANIZER') {
    exit("Role is already ORGANIZER — nothing to change.");
}

db()->prepare("UPDATE users SET role = 'ORGANIZER' WHERE id = ?")->execute([$user['id']]);
echo "Done — role changed from {$user['role']} to ORGANIZER. Delete this file (_fix-ivan-organizer.php) now.";
