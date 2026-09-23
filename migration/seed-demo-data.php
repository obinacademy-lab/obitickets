<?php
/**
 * Seeds demo organizers + events + ticket types so the homepage and event
 * page have real database-backed content to render.
 *
 * Safe to re-run: it deletes only rows it created before (organizers whose
 * email ends in @obitickets.demo, and their events cascade-delete via FK),
 * so running this again just refreshes the demo dataset rather than
 * duplicating it.
 *
 * Usage: php migration/seed-demo-data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = db();

echo "Removing any previous demo data...\n";
$pdo->exec("DELETE FROM users WHERE email LIKE '%@obitickets.demo'");

function create_organizer(PDO $pdo, string $orgName, string $email, ?string $bio = null): int
{
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$orgName, $email, password_hash('DemoPass123', PASSWORD_DEFAULT), 'ORGANIZER']);
    $userId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO organizer_profiles (user_id, org_name, bio, verified) VALUES (?, ?, ?, 1)');
    $stmt->execute([$userId, $orgName, $bio]);

    return $userId;
}

function create_admin(PDO $pdo, string $name, string $email): int
{
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "ADMIN")');
    $stmt->execute([$name, $email, password_hash('DemoPass123', PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
}

function create_event(PDO $pdo, int $organizerId, array $e): int
{
    // A real photo at public/uploads/events/<slug>.jpg (added after the initial
    // seed — see the "banner image upload" project step) is used automatically
    // if present, so re-running this script never reverts an event back to
    // its emoji fallback.
    $bannerPath = __DIR__ . '/../uploads/events/' . $e['slug'] . '.jpg';
    $bannerImage = is_file($bannerPath) ? '/uploads/events/' . $e['slug'] . '.jpg' : null;

    $stmt = $pdo->prepare('
        INSERT INTO events (organizer_id, title, slug, category, description, venue_name, venue_address, banner_emoji, banner_image, starts_at, ends_at, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PUBLISHED")
    ');
    $stmt->execute([
        $organizerId, $e['title'], $e['slug'], $e['category'], $e['description'],
        $e['venue_name'], $e['venue_address'], $e['banner_emoji'], $bannerImage, $e['starts_at'], $e['ends_at'],
    ]);
    $eventId = (int) $pdo->lastInsertId();

    $sort = 0;
    foreach ($e['tiers'] as $tier) {
        $stmt = $pdo->prepare('
            INSERT INTO ticket_types (event_id, name, description, price, currency, quantity_total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$eventId, $tier[0], $tier[1], $tier[2], $tier[3], $tier[4] ?? 500, $sort++]);
    }

    return $eventId;
}

echo "Creating admin...\n";
create_admin($pdo, 'Obi Admin', 'admin@obitickets.demo');

echo "Creating organizers...\n";
$nyegeNyege = create_organizer($pdo, 'Nyege Nyege Events Ltd.', 'organizer1@obitickets.demo', '42 events hosted. Verified organizer since 2014.');
$afroTech = create_organizer($pdo, 'AfroTech Media', 'organizer2@obitickets.demo', 'East Africa\'s largest technology and innovation event series.');
$kla = create_organizer($pdo, 'Kampala Live Entertainment', 'organizer3@obitickets.demo', 'Bringing Kampala\'s biggest nights to life.');
$kingdom = create_organizer($pdo, 'Kingdom Encounter Ministries', 'organizer4@obitickets.demo', 'A pan-African gathering of faith and worship.');
$lagosFW = create_organizer($pdo, 'Lagos Fashion Week Ltd.', 'organizer5@obitickets.demo', 'West Africa\'s premier fashion showcase.');
$afrochella = create_organizer($pdo, 'Afrochella Productions', 'organizer6@obitickets.demo', 'Celebrating African music and culture across the continent.');

echo "Creating events...\n";

create_event($pdo, $nyegeNyege, [
    'title' => 'Nyege Nyege Festival 2026',
    'slug' => 'nyege-nyege-festival-2026',
    'category' => 'Music',
    'description' => "Now in its 13th year, Nyege Nyege returns to the shores of the Nile for four nights of East Africa's boldest electronic, folk and experimental sound. Expect 200+ artists across six stages, a beach campsite, and food markets from across the region.\n\nWeekend and camping passes include full stage access from Thursday evening through Sunday's closing set. Day passes are available for Friday and Saturday only.",
    'venue_name' => 'Nyege Nyege Beach',
    'venue_address' => 'Jinja–Kampala Highway, Njeru, Jinja District, Uganda',
    'banner_emoji' => '🎵',
    'starts_at' => '2026-09-25 14:00:00',
    'ends_at' => '2026-09-28 23:59:00',
    'tiers' => [
        ['Day Pass', 'Fri or Sat only', 80000, 'UGX', 800],
        ['Weekend Pass', '4-day access', 180000, 'UGX', 1500],
        ['VIP Camping', '4-day + tent', 420000, 'UGX', 200],
    ],
]);

create_event($pdo, $nyegeNyege, [
    'title' => 'Bushfire Beats Weekender',
    'slug' => 'bushfire-beats-weekender',
    'category' => 'Music',
    'description' => "A lakeside weekender bringing together East Africa's best live bands and DJs on the shores of Lake Bunyonyi. Two stages, bonfire sessions after dark, and canoe trips by day.",
    'venue_name' => 'Lake Bunyonyi',
    'venue_address' => 'Lake Bunyonyi, Kabale District, Uganda',
    'banner_emoji' => '🎉',
    'starts_at' => '2026-11-07 12:00:00',
    'ends_at' => '2026-11-08 23:00:00',
    'tiers' => [
        ['Weekend Pass', '2-day access', 45000, 'UGX', 600],
    ],
]);

create_event($pdo, $afroTech, [
    'title' => 'AfroTech Summit Kampala',
    'slug' => 'afrotech-summit-kampala',
    'category' => 'Conference',
    'description' => "A one-day summit bringing together founders, engineers and investors building Africa's technology sector. Keynotes, panel discussions, and a startup showcase floor.",
    'venue_name' => 'Speke Resort, Munyonyo',
    'venue_address' => 'Munyonyo, Kampala, Uganda',
    'banner_emoji' => '💼',
    'starts_at' => '2026-10-08 09:00:00',
    'ends_at' => '2026-10-08 17:00:00',
    'tiers' => [
        ['Student Pass', 'Valid student ID required', 60000, 'UGX', 150],
        ['Standard Pass', 'Full-day access + lunch', 150000, 'UGX', 400],
    ],
]);

create_event($pdo, $afroTech, [
    'title' => 'Makerere Innovation Week',
    'slug' => 'makerere-innovation-week',
    'category' => 'Community',
    'description' => "A week of student-led showcases, hackathons and career talks hosted on campus, open to the public on the final day.",
    'venue_name' => 'Makerere University',
    'venue_address' => 'University Road, Kampala, Uganda',
    'banner_emoji' => '🎓',
    'starts_at' => '2026-10-21 09:00:00',
    'ends_at' => '2026-10-21 17:00:00',
    'tiers' => [
        ['Free Entry', 'Open to the public', 0, 'UGX', 2000],
    ],
]);

create_event($pdo, $kla, [
    'title' => 'Alex Muhangi Comedy Store',
    'slug' => 'alex-muhangi-comedy-store',
    'category' => 'Comedy',
    'description' => "Kampala's longest-running comedy night returns with Alex Muhangi and a lineup of the city's sharpest stand-up acts.",
    'venue_name' => 'Lugogo Cricket Oval',
    'venue_address' => 'Lugogo, Kampala, Uganda',
    'banner_emoji' => '🎤',
    'starts_at' => '2026-09-20 19:00:00',
    'ends_at' => '2026-09-20 22:30:00',
    'tiers' => [
        ['Regular', 'General seating', 30000, 'UGX', 500],
        ['VIP', 'Front rows + drink voucher', 60000, 'UGX', 100],
    ],
]);

create_event($pdo, $kla, [
    'title' => 'Blankets & Wine',
    'slug' => 'blankets-and-wine',
    'category' => 'Music',
    'description' => "The picnic-concert series returns with a full day of live African music on the lawns, blankets and picnic baskets welcome.",
    'venue_name' => 'Lugogo Cricket Oval',
    'venue_address' => 'Lugogo, Kampala, Uganda',
    'banner_emoji' => '🎵',
    'starts_at' => '2026-10-12 14:00:00',
    'ends_at' => '2026-10-12 21:00:00',
    'tiers' => [
        ['General', 'Lawn access', 60000, 'UGX', 1000],
        ['VIP', 'Reserved seating area', 120000, 'UGX', 250],
    ],
]);

create_event($pdo, $kla, [
    'title' => 'Uganda Cranes vs Kenya',
    'slug' => 'uganda-cranes-vs-kenya',
    'category' => 'Sports',
    'description' => "An AFCON qualifier at Mandela National Stadium as the Cranes take on Kenya in front of a home crowd.",
    'venue_name' => 'Mandela National Stadium',
    'venue_address' => 'Namboole, Kampala, Uganda',
    'banner_emoji' => '🏟️',
    'starts_at' => '2026-09-26 16:00:00',
    'ends_at' => '2026-09-26 18:00:00',
    'tiers' => [
        ['Terraces', 'General standing', 20000, 'UGX', 5000],
        ['VIP Stand', 'Covered seating', 80000, 'UGX', 500],
    ],
]);

create_event($pdo, $kla, [
    'title' => 'Kampala Art Market',
    'slug' => 'kampala-art-market',
    'category' => 'Community',
    'description' => "A monthly open-air market featuring paintings, sculpture and crafts from over 60 Ugandan artists, with live music and food stalls.",
    'venue_name' => 'National Theatre Grounds',
    'venue_address' => 'De Winton Road, Kampala, Uganda',
    'banner_emoji' => '🎨',
    'starts_at' => '2026-09-19 10:00:00',
    'ends_at' => '2026-09-19 18:00:00',
    'tiers' => [
        ['Free Entry', 'Open to the public', 0, 'UGX', 3000],
    ],
]);

create_event($pdo, $kla, [
    'title' => 'Family Fun Day at the Botanical Gardens',
    'slug' => 'family-fun-day-botanical-gardens',
    'category' => 'Community',
    'description' => "A day of games, face painting and guided nature walks for the whole family in Entebbe's Botanical Gardens.",
    'venue_name' => 'Entebbe Botanical Gardens',
    'venue_address' => 'Entebbe, Uganda',
    'banner_emoji' => '🧒',
    'starts_at' => '2026-09-27 09:00:00',
    'ends_at' => '2026-09-27 16:00:00',
    'tiers' => [
        ['Free Entry', 'Gardens access', 0, 'UGX', 1000],
        ['Adult Activity Pass', 'Includes all games & face painting', 10000, 'UGX', 400],
    ],
]);

create_event($pdo, $kingdom, [
    'title' => 'Kingdom Encounter Conference',
    'slug' => 'kingdom-encounter-conference',
    'category' => 'Faith',
    'description' => "A three-day gathering of worship, teaching and fellowship drawing attendees from across East Africa.",
    'venue_name' => 'KICC',
    'venue_address' => 'Harambee Avenue, Nairobi, Kenya',
    'banner_emoji' => '🙏',
    'starts_at' => '2026-09-24 09:00:00',
    'ends_at' => '2026-09-24 18:00:00',
    'tiers' => [
        ['Free Entry', 'General attendance', 0, 'UGX', 4000],
        ['Reserved Seating', 'Front-section seating', 15000, 'UGX', 300],
    ],
]);

create_event($pdo, $lagosFW, [
    'title' => 'Lagos Fashion Week',
    'slug' => 'lagos-fashion-week',
    'category' => 'Fashion',
    'description' => "West Africa's biggest fashion showcase returns with runway shows from over 40 designers across the continent.",
    'venue_name' => 'Federal Palace Hotel',
    'venue_address' => 'Victoria Island, Lagos, Nigeria',
    'banner_emoji' => '👗',
    'starts_at' => '2026-11-14 10:00:00',
    'ends_at' => '2026-11-14 20:00:00',
    'tiers' => [
        ['General', 'Standing room, all shows', 60000, 'UGX', 800],
        ['Front Row', 'Reserved runway seating', 150000, 'UGX', 100],
    ],
]);

create_event($pdo, $afrochella, [
    'title' => 'Afrochella Nights',
    'slug' => 'afrochella-nights',
    'category' => 'Music',
    'description' => "An open-air celebration of Afrobeats, highlife and dancehall on the outskirts of Accra, featuring some of Ghana's biggest acts.",
    'venue_name' => 'El Wak Stadium',
    'venue_address' => 'Ridge, Accra, Ghana',
    'banner_emoji' => '🎵',
    'starts_at' => '2026-12-12 18:00:00',
    'ends_at' => '2026-12-12 23:59:00',
    'tiers' => [
        ['General', 'Standing, all stages', 40000, 'UGX', 2000],
    ],
]);

$eventCount = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'PUBLISHED'")->fetchColumn();
$tierCount = (int) $pdo->query('SELECT COUNT(*) FROM ticket_types')->fetchColumn();
echo "Done. {$eventCount} published events, {$tierCount} ticket types.\n";
