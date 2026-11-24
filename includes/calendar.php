<?php
// "Add to calendar" (11/24): a Google Calendar link and an .ics file (iPhone, Outlook, Android calendar apps)
// for an event, so ticket holders get a reminder from their own phone. Event times are stored in Kampala time.

/** The event's start and end as UTC stamps (Ymd\THis\Z). An event with no end gets 3 hours. */
function calendar_times(array $e): array
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'Africa/Kampala');
    $utc = new DateTimeZone('UTC');
    $start = new DateTime((string) ($e['starts_at'] ?? $e['event_starts_at']), $tz);
    $endRaw = (string) ($e['ends_at'] ?? $e['event_ends_at'] ?? '');
    $end = $endRaw !== '' ? new DateTime($endRaw, $tz) : (clone $start)->modify('+3 hours');
    if ($end <= $start) {
        $end = (clone $start)->modify('+3 hours');
    }
    return [$start->setTimezone($utc)->format('Ymd\THis\Z'), $end->setTimezone($utc)->format('Ymd\THis\Z')];
}

/** Where the event happens, as one line (venue, address, or the online label). */
function calendar_where(array $e): string
{
    if (($e['attend_mode'] ?? '') === 'ONLINE') {
        return 'Online (join link in your obitickets ticket)';
    }
    return trim(implode(', ', array_filter([(string) ($e['venue_name'] ?? ''), (string) ($e['venue_address'] ?? '')])));
}

function calendar_event_url(array $e): string
{
    return rtrim(APP_URL, '/') . '/event.php?slug=' . rawurlencode((string) ($e['slug'] ?? $e['event_slug']));
}

/** Google Calendar "add event" link. */
function calendar_google_url(array $e): string
{
    [$s, $f] = calendar_times($e);
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => (string) ($e['title'] ?? $e['event_title']),
        'dates' => $s . '/' . $f,
        'details' => 'Your ticket: ' . rtrim(APP_URL, '/') . '/my-tickets.php' . "\n" . 'Event: ' . calendar_event_url($e),
        'location' => calendar_where($e),
    ]);
}

/** Our own .ics download (works on iPhone, Outlook and most Android calendars). */
function calendar_ics_url(array $e): string
{
    return '/calendar.php?event=' . rawurlencode((string) ($e['slug'] ?? $e['event_slug']));
}

function calendar_ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', ''], $s);
}

/** The .ics text for one event, with a reminder 1 day and 2 hours before. */
function calendar_ics(array $e): string
{
    [$s, $f] = calendar_times($e);
    $uid = 'event-' . (int) ($e['id'] ?? $e['event_id'] ?? 0) . '@' . (parse_url(APP_URL, PHP_URL_HOST) ?: 'obitickets.site');
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//obitickets//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $s,
        'DTEND:' . $f,
        'SUMMARY:' . calendar_ics_escape((string) ($e['title'] ?? '')),
        'LOCATION:' . calendar_ics_escape(calendar_where($e)),
        'DESCRIPTION:' . calendar_ics_escape('Your ticket: ' . rtrim(APP_URL, '/') . "/my-tickets.php\nEvent: " . calendar_event_url($e)),
        'URL:' . calendar_event_url($e),
        'BEGIN:VALARM', 'TRIGGER:-P1D', 'ACTION:DISPLAY', 'DESCRIPTION:Tomorrow: ' . calendar_ics_escape((string) ($e['title'] ?? '')), 'END:VALARM',
        'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:In 2 hours: ' . calendar_ics_escape((string) ($e['title'] ?? '')), 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR',
    ];
    // lines longer than 75 bytes are folded, as the format requires
    $out = '';
    foreach ($lines as $l) {
        while (strlen($l) > 75) {
            $cut = 75;
            while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) {
                $cut--;                                  // never split a multi-byte letter
            }
            $out .= substr($l, 0, $cut) . "\r\n";
            $l = ' ' . substr($l, $cut);
        }
        $out .= $l . "\r\n";
    }
    return $out;
}

/** The two buttons, ready to drop into a page. $e needs title/starts_at/ends_at/venue/slug (event or order row). */
function calendar_buttons_html(array $e, string $class = 'cal-btns'): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
    $ico = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 14v4M10 16h4"/></svg>';
    return '<div class="' . $h($class) . '" role="group" aria-label="Add this event to your calendar">'
        . '<a class="cal-btn" href="' . $h(calendar_google_url($e)) . '" target="_blank" rel="noopener">' . $ico . '<span>Google Calendar</span></a>'
        . '<a class="cal-btn" href="' . $h(calendar_ics_url($e)) . '" download>' . $ico . '<span>iPhone / Outlook</span></a>'
        . '</div>';
}
