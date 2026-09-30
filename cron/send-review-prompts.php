<?php
declare(strict_types=1);

/**
 * Run on a schedule (hourly is fine — same cadence as the other cron jobs)
 * via a Hostinger cron job:
 *   php /home/<user>/domains/obitickets.site/cron/send-review-prompts.php
 *
 * Emails every checked-in attendee of an event that ended roughly a day ago,
 * once per event (events.review_prompt_sent_at guards against a second cron
 * run re-prompting). The 24-48 hour window is deliberately wide so a late or
 * missed run still catches every event exactly once, and gives attendees a
 * day to get home and reflect before being asked for a review.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$stmt = db()->prepare("
    SELECT id, title, slug
    FROM events
    WHERE status = 'PUBLISHED'
      AND review_prompt_sent_at IS NULL
      AND ends_at BETWEEN (NOW() - INTERVAL 48 HOUR) AND (NOW() - INTERVAL 24 HOUR)
");
$stmt->execute();
$dueEvents = $stmt->fetchAll();

if (!$dueEvents) {
    echo "No events due for a review prompt right now.\n";
    exit;
}

foreach ($dueEvents as $event) {
    $attendees = get_checked_in_attendees_for_event((int) $event['id']);
    $eventUrl = rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode($event['slug']) . '#reviews';

    foreach ($attendees as $attendee) {
        send_review_prompt_email($attendee['email'], $attendee['name'], $event['title'], $eventUrl);
    }

    db()->prepare('UPDATE events SET review_prompt_sent_at = NOW() WHERE id = ?')->execute([$event['id']]);
    echo "Event #{$event['id']} \"{$event['title']}\": prompted " . count($attendees) . " attendee(s).\n";
}
