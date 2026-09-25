<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('events.manage');
$organizerId = $ctx['organizer_id'];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = $id ? get_event_by_id($id) : null;

if (!$existing || (int) $existing['organizer_id'] !== $organizerId) {
    http_response_code(404);
    $pageTitle = 'Event not found';
    render_organizer_head('my-events', $ctx);
    ?>
    <div class="admin-empty">
      <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-x"/></svg></div>
      <h3>Event not found</h3>
      <p>This event doesn't exist, or isn't yours to edit.</p>
      <a class="btn btn-purple" href="/my-events.php">Back to my events</a>
    </div>
    <?php
    render_organizer_foot();
    exit;
}

$errors = [];
$event = [
    'title' => $existing['title'],
    'category' => $existing['category'],
    'description' => $existing['description'] ?? '',
    'venue_name' => $existing['venue_name'],
    'venue_address' => $existing['venue_address'] ?? '',
    'banner_emoji' => $existing['banner_emoji'],
    'banner_image' => $existing['banner_image'],
    'starts_at' => to_local_datetime_input($existing['starts_at']),
    'ends_at' => to_local_datetime_input($existing['ends_at']),
    'status' => $existing['status'],
];
$tiers = get_ticket_types_for_event($id);
if (!$tiers) {
    $tiers = [['name' => '', 'description' => '', 'price' => '', 'currency' => 'UGX', 'quantity_total' => '']];
}
$existingMedia = get_event_media($id);

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

    // Default to keeping whatever banner is already on the event; only
    // change it below once we know the rest of the form is valid, for the
    // same reason event-create.php waits — no orphaned uploads on a failed
    // submission.
    $bannerImage = $existing['banner_image'];
    if (!$errors) {
        [$uploadOk, $newBannerImage, $uploadError] = handle_event_banner_upload($_FILES['banner_image'] ?? []);
        if (!$uploadOk) {
            $errors[] = $uploadError;
        } elseif ($newBannerImage !== null) {
            // A fresh upload replaces the old file entirely.
            delete_event_banner($existing['banner_image']);
            $bannerImage = $newBannerImage;
        } elseif (!empty($_POST['remove_banner_image'])) {
            delete_event_banner($existing['banner_image']);
            $bannerImage = null;
        }
    }

    // Same wait-until-valid philosophy as the banner above: only touch the
    // gallery once the rest of the form is confirmed good. Gallery problems
    // (e.g. one unsupported file among several) are collected separately
    // from $errors, since they should never block saving the rest of the
    // event — the title/venue/tiers/banner have already validated fine.
    $mediaWarnings = [];
    if (!$errors) {
        foreach ($_POST['remove_media'] ?? [] as $mediaId) {
            $deletedPath = delete_event_media((int) $mediaId, $id);
            delete_event_media_file($deletedPath);
        }
        $remainingCounts = count_event_media_by_type($id);
        [$newMedia, $mediaWarnings] = handle_event_media_uploads($_FILES['gallery'] ?? ['name' => []], $remainingCounts);
        add_event_media($id, $newMedia);
        $existingMedia = get_event_media($id);
    }

    if (!$errors) {
        $eventFields = $event;
        $eventFields['starts_at'] = to_mysql_datetime($event['starts_at']);
        $eventFields['ends_at'] = to_mysql_datetime($event['ends_at']);
        $eventFields['banner_image'] = $bannerImage;
        update_event($id, $eventFields);
        replace_ticket_types($id, $validTiers);
        log_organizer_action($organizerId, $ctx['actor_id'], 'event.update', 'event', $id);

        if (!$mediaWarnings) {
            header('Location: /my-events.php?updated=1');
            exit;
        }
        // Everything saved, but at least one gallery file was skipped — fall
        // through and re-render this page (with the event's new state
        // already reflected) so the organizer actually sees why, instead of
        // it silently disappearing behind the usual redirect.
    }
}

$pageTitle = 'Edit ' . $existing['title'];
render_organizer_head('my-events', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Edit event</h1><p><a class="link" href="/event.php?slug=<?= urlencode($existing['slug']) ?>" target="_blank" rel="noopener">View live page &rarr;</a></p></div>
</div>

<?php if (isset($_GET['duplicated'])): ?><span data-flash="Duplicated as a new draft — update the details below." hidden></span><?php endif; ?>

<div class="admin-card" style="max-width:760px">
  <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
  <?php if (!empty($mediaWarnings)): ?>
    <div class="alert alert-success">Your event was saved.</div>
    <?php foreach ($mediaWarnings as $w): ?>
      <div class="alert alert-warn"><?= htmlspecialchars($w) ?></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php
  $formAction = '/event-edit.php?id=' . $id;
  $submitLabel = 'Save changes';
  include __DIR__ . '/includes/organizer-event-form.php';
  ?>
</div>

<?php render_organizer_foot(); ?>
