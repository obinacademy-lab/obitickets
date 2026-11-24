<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('events.manage');
$organizerId = $ctx['organizer_id'];

$errors = [];
$event = [
    'title' => '',
    'category' => EVENT_CATEGORIES[0],
    'description' => '',
    'venue_name' => '',
    'venue_address' => '',
    'banner_emoji' => EVENT_EMOJIS[0],
    'starts_at' => '',
    'ends_at' => '',
    'status' => 'DRAFT',
    'attend_mode' => 'IN_PERSON',
    'online_platform' => 'Zoom',
    'online_url' => '',
    'online_notes' => '',
    'show_tickets_left' => 0,
    'allow_transfers' => 1,
    'referral_friends' => null,
    'referral_reward' => null,
    'district' => '',
];
$tiers = [['name' => '', 'description' => '', 'price' => '', 'currency' => 'UGX', 'quantity_total' => '', 'max_per_person' => '']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $event['title'] = trim($_POST['title'] ?? '');
    $event['category'] = in_array($_POST['category'] ?? '', EVENT_CATEGORIES, true) ? $_POST['category'] : EVENT_CATEGORIES[0];
    $event['description'] = trim($_POST['description'] ?? '');
    $event['venue_name'] = trim($_POST['venue_name'] ?? '');
    $event['venue_address'] = trim($_POST['venue_address'] ?? '');
    $event['banner_emoji'] = in_array($_POST['banner_emoji'] ?? '', EVENT_EMOJIS, true) ? $_POST['banner_emoji'] : EVENT_EMOJIS[0];
    $event['starts_at'] = $_POST['starts_at'] ?? '';
    $event['ends_at'] = $_POST['ends_at'] ?? '';
    $event['status'] = ($_POST['status'] ?? 'DRAFT') === 'PUBLISHED' ? 'PUBLISHED' : 'DRAFT';
    $event['show_tickets_left'] = !empty($_POST['show_tickets_left']) ? 1 : 0;
    $event['allow_transfers'] = !empty($_POST['allow_transfers']) ? 1 : 0;
    $event['referral_friends'] = !empty($_POST['referral_on']) ? max(1, min(50, (int) ($_POST['referral_friends'] ?? 0))) : null;
    $event['referral_reward'] = !empty($_POST['referral_on']) ? max(0, (float) ($_POST['referral_reward'] ?? 0)) : null;
    $event['district'] = is_string($_POST['district'] ?? null) && is_uganda_district($_POST['district']) ? $_POST['district'] : '';

    $tiers = parse_tier_submission($_POST);
    if (!$tiers) {
        $tiers = [['name' => '', 'description' => '', 'price' => '', 'currency' => 'UGX', 'quantity_total' => '']];
    }

    if ($event['title'] === '') {
        $errors[] = 'Please give your event a title.';
    }
    $event = array_merge($event, parse_online_fields($_POST));
    if (!events_online_ready()) {
        $event['attend_mode'] = 'IN_PERSON'; // migration 021 not run yet: the form has no format question
    }
    if ($event['attend_mode'] !== 'ONLINE' && $event['venue_name'] === '') {
        $errors[] = 'Please add a venue name.';
    }
    $errors = array_merge($errors, validate_online_fields($event));
    if ($event['starts_at'] === '' || $event['ends_at'] === '') {
        $errors[] = 'Please set a start and end date/time.';
    } elseif (strtotime($event['ends_at']) < strtotime($event['starts_at'])) {
        $errors[] = 'The end date must be after the start date.';
    }

    [$validTiers, $tierErrors] = validate_tiers($tiers);
    [$qRows, $qErrors] = parse_question_submission($_POST);   // questions for buyers (includes/questions.php)
    $tierErrors = array_merge($tierErrors, $qErrors);
    $errors = array_merge($errors, $tierErrors);

    // Only touch the filesystem once every other field has already passed —
    // otherwise a typo elsewhere in the form would leave an orphaned upload
    // behind with nothing in the database pointing at it.
    $bannerImage = null;
    if (!$errors) {
        [$uploadOk, $bannerImage, $uploadError] = handle_event_banner_upload($_FILES['banner_image'] ?? []);
        if (!$uploadOk) {
            $errors[] = $uploadError;
        }
    }

    if (!$errors) {
        $eventFields = $event;
        $eventFields['starts_at'] = to_mysql_datetime($event['starts_at']);
        $eventFields['ends_at'] = to_mysql_datetime($event['ends_at']);
        $eventFields['banner_image'] = $bannerImage;
        $eventId = create_event($organizerId, $eventFields, $validTiers);
        set_event_show_tickets_left($eventId, (bool) $event['show_tickets_left']);
        set_event_allow_transfers($eventId, (bool) $event['allow_transfers']);
        set_event_referrals($eventId, $event['referral_friends'], $event['referral_reward'] !== null && $event['referral_reward'] > 0 ? (float) $event['referral_reward'] : null);
        save_event_questions($eventId, $qRows);
        set_event_district($eventId, ($event['attend_mode'] ?? '') === 'ONLINE' ? null : ($event['district'] !== '' ? $event['district'] : detect_district($event['venue_address'], $event['venue_name'])));
        log_organizer_action($organizerId, $ctx['actor_id'], 'event.create', 'event', $eventId, ['title' => $eventFields['title']]);

        // The event now exists, so a gallery file that fails to validate here
        // just doesn't get attached — it can never leave an orphaned upload
        // with nothing in the database pointing at it, the way a failure
        // earlier in the form would have.
        [$mediaItems, ] = handle_event_media_uploads($_FILES['gallery'] ?? ['name' => []], ['IMAGE' => 0, 'VIDEO' => 0]);
        add_event_media($eventId, $mediaItems);

        header('Location: /my-events.php?created=1');
        exit;
    }
}

$pageTitle = 'Create Event';
// the Focus Flow takes over the whole workspace: slim sidebar rail, no top bar (see event-flow.css)
$GLOBALS['orgFocusMode'] = true;
$GLOBALS['orgExtraCss'] = ['event-flow.css'];
render_organizer_head('create-event', $ctx);

$formAction = '/event-create.php';
include __DIR__ . '/includes/organizer-event-flow.php';

render_organizer_foot();
