<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('ORGANIZER');

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
];
$tiers = [['name' => '', 'description' => '', 'price' => '', 'currency' => 'UGX', 'quantity_total' => '']];

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

    $tiers = parse_tier_submission($_POST);
    if (!$tiers) {
        $tiers = [['name' => '', 'description' => '', 'price' => '', 'currency' => 'UGX', 'quantity_total' => '']];
    }

    if ($event['title'] === '') {
        $errors[] = 'Please give your event a title.';
    }
    if ($event['venue_name'] === '') {
        $errors[] = 'Please add a venue name.';
    }
    if ($event['starts_at'] === '' || $event['ends_at'] === '') {
        $errors[] = 'Please set a start and end date/time.';
    } elseif (strtotime($event['ends_at']) < strtotime($event['starts_at'])) {
        $errors[] = 'The end date must be after the start date.';
    }

    [$validTiers, $tierErrors] = validate_tiers($tiers);
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
        $eventId = create_event((int) $user['id'], $eventFields, $validTiers);

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

$pageTitle = 'Create an event — obitickets';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">
  <section class="sec" style="max-width:720px; margin:0 auto">
    <h1>Create your event</h1>
    <p class="sub" style="text-align:left; margin-top:8px">Fill in the details below — you can save it as a draft and publish later.</p>

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php
    $formAction = '/event-create.php';
    $submitLabel = 'Save event';
    include __DIR__ . '/includes/organizer-event-form.php';
    ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
