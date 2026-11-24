<?php
// Add to calendar: /calendar.php?event=<slug> downloads an .ics file (iPhone, Outlook, Android calendars) for a published event.
require_once __DIR__ . '/includes/bootstrap.php';

$event = get_event_by_slug((string) ($_GET['event'] ?? ''));
if (!$event) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Event not found.';
    exit;
}
$name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $event['slug']), '-') ?: 'event';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '.ics"');
header('Cache-Control: no-store');
echo calendar_ics($event);
