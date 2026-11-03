<?php
declare(strict_types=1);

/**
 * Free tickets and online events.
 *
 * An event is IN_PERSON, ONLINE or HYBRID (both). Online and hybrid events carry a meeting platform, a private
 * meeting link and optional joining notes. The link is only ever shown to someone who holds a paid or free
 * ticket (see user_holds_ticket()), never on the public event page and never in a listing.
 *
 * A ticket type priced at 0 is a free ticket. Free tickets skip payment entirely and are limited per person.
 *
 * Everything here degrades quietly until migration 021 has run (events_online_ready() is false): events then
 * behave exactly as in-person events.
 */

const ATTEND_MODES = ['IN_PERSON' => 'In person', 'ONLINE' => 'Online', 'HYBRID' => 'Both'];
const ONLINE_PLATFORMS = ['Zoom', 'Google Meet', 'Microsoft Teams', 'YouTube Live', 'Other'];
const FREE_TICKET_DEFAULT_LIMIT = 4;     // free tickets per person per ticket type when the organizer sets nothing
const FREE_TICKET_MAX_LIMIT = 20;
const ONLINE_JOIN_OPENS_MINUTES = 30;    // the Join button opens this long before the start

function events_online_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW COLUMNS FROM events LIKE 'attend_mode'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function online_joins_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'online_joins'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function event_attend_mode(array $event): string
{
    $m = (string) ($event['attend_mode'] ?? 'IN_PERSON');
    return isset(ATTEND_MODES[$m]) ? $m : 'IN_PERSON';
}

/** True for ONLINE and HYBRID events. */
function event_is_online(array $event): bool
{
    return event_attend_mode($event) !== 'IN_PERSON';
}

function event_is_online_only(array $event): bool
{
    return event_attend_mode($event) === 'ONLINE';
}

function ticket_is_free(array $tier): bool
{
    return (float) ($tier['price'] ?? 0) <= 0.0;
}

/** True when every ticket type of the event is free (an event with no tickets is not "free"). */
function event_is_free(array $tiers): bool
{
    if (!$tiers) {
        return false;
    }
    foreach ($tiers as $t) {
        if (!ticket_is_free($t)) {
            return false;
        }
    }
    return true;
}

/** "Online · Zoom", "Online + venue · Zoom" or "" for an in-person event. */
function event_online_label(array $event): string
{
    if (!event_is_online($event)) {
        return '';
    }
    $platform = trim((string) ($event['online_platform'] ?? ''));
    $base = event_attend_mode($event) === 'HYBRID' ? 'Online + venue' : 'Online';
    return $platform !== '' ? $base . ' · ' . $platform : $base;
}

/**
 * Accepts "https://zoom.us/j/123" or a bare "zoom.us/j/123". Returns the cleaned https URL, or null if it is not
 * a plausible public meeting link (no credentials, no spaces or quotes, a real dotted host, not an IP or localhost).
 */
function normalize_meeting_url(string $raw): ?string
{
    $u = trim($raw);
    if ($u === '' || strlen($u) > 500 || preg_match('/[\s<>"\'\\\\]/', $u)) {
        return null;
    }
    if (!preg_match('#^https://#i', $u)) {
        if (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?::\d+)?(/|\?|$)#i', $u)) {
            $u = 'https://' . $u;
        } else {
            return null;
        }
    }
    $p = parse_url($u);
    if (!$p || empty($p['host']) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    $host = strtolower($p['host']);
    if (!str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
        || filter_var($host, FILTER_VALIDATE_IP) !== false || !preg_match('/^[a-z0-9.-]+$/', $host)) {
        return null;
    }
    return $u;
}

/** Reads the attend-mode fields from a submitted event form. Values are cleaned but not yet validated. */
function parse_online_fields(array $post): array
{
    $mode = (string) ($post['attend_mode'] ?? 'IN_PERSON');
    if (!isset(ATTEND_MODES[$mode])) {
        $mode = 'IN_PERSON';
    }
    $platform = (string) ($post['online_platform'] ?? '');
    return [
        'attend_mode' => $mode,
        'online_platform' => in_array($platform, ONLINE_PLATFORMS, true) ? $platform : '',
        'online_url' => trim((string) ($post['online_url'] ?? '')),
        'online_notes' => mb_substr(trim((string) ($post['online_notes'] ?? '')), 0, 500),
    ];
}

/**
 * Validates and tidies the online fields in place and returns the error messages.
 * An in-person event drops any online details; an online-only event stores "Online" as its venue so that every
 * listing, email and share text that prints a venue reads correctly without further changes.
 *
 * @param array $f an event array containing the keys from parse_online_fields() plus venue_name / venue_address
 * @return list<string>
 */
function validate_online_fields(array &$f): array
{
    $errors = [];
    $mode = $f['attend_mode'] ?? 'IN_PERSON';
    if ($mode === 'IN_PERSON') {
        $f['online_platform'] = null;
        $f['online_url'] = null;
        $f['online_notes'] = null;
        return $errors;
    }
    if (($f['online_platform'] ?? '') === '') {
        $errors[] = 'Please choose the meeting platform (Zoom, Google Meet, Teams, YouTube Live or Other).';
    }
    $url = normalize_meeting_url((string) ($f['online_url'] ?? ''));
    if ($url === null) {
        $errors[] = 'Please paste a valid meeting link that starts with https:// (copy it from Zoom, Google Meet or your meeting app).';
    } else {
        $f['online_url'] = $url;
    }
    if ($f['online_notes'] === '') {
        $f['online_notes'] = null;
    }
    if ($mode === 'ONLINE') {
        $f['venue_name'] = 'Online';
        $f['venue_address'] = '';
    }
    return $errors;
}

/** Does this person hold a paid or free ticket (a PAID order) for the event? */
function user_holds_ticket(int $eventId, int $userId): bool
{
    $stmt = db()->prepare("SELECT 1 FROM orders WHERE event_id = ? AND user_id = ? AND status = 'PAID' LIMIT 1");
    $stmt->execute([$eventId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * When can people join? opens = start minus 30 minutes, closes = the event's end.
 * @return array{state:string, opens:int, closes:int}  state is "before", "open" or "ended"
 */
function online_join_window(array $event, ?int $now = null): array
{
    $now = $now ?? time();
    $opens = strtotime((string) $event['starts_at']) - ONLINE_JOIN_OPENS_MINUTES * 60;
    $closes = strtotime((string) $event['ends_at']);
    $state = $now < $opens ? 'before' : ($now <= $closes ? 'open' : 'ended');
    return ['state' => $state, 'opens' => $opens, 'closes' => $closes];
}

/**
 * The online details of an event for someone who may see them, or null. Only a ticket holder (or the event's
 * organizer, who needs it to host) gets the link; everyone else, including admins browsing public pages, gets null.
 * @return array{platform:string,url:string,notes:string,window:array}|null
 */
function get_online_access(array $event, ?array $viewer): ?array
{
    if (!$viewer || !event_is_online($event) || empty($event['online_url'])) {
        return null;
    }
    $eventId = (int) $event['id'];
    $isHost = (int) $viewer['id'] === (int) ($event['organizer_id'] ?? 0);
    if (!$isHost && !user_holds_ticket($eventId, (int) $viewer['id'])) {
        return null;
    }
    return [
        'platform' => (string) ($event['online_platform'] ?? ''),
        'url' => (string) $event['online_url'],
        'notes' => (string) ($event['online_notes'] ?? ''),
        'window' => online_join_window($event),
    ];
}

/** Counts one press of Join. First press creates the row; later presses add to the count. */
function record_online_join(int $eventId, int $userId): void
{
    if (!online_joins_ready()) {
        return;
    }
    db()->prepare('
        INSERT INTO online_joins (event_id, user_id) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE join_count = join_count + 1, last_joined_at = NOW()
    ')->execute([$eventId, $userId]);
}

/** user_id => join_count for everyone who has pressed Join on the event. */
function get_online_joiners(int $eventId): array
{
    if (!online_joins_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT user_id, join_count FROM online_joins WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['user_id']] = (int) $r['join_count'];
    }
    return $out;
}

/**
 * Free tickets this person already holds (PAID or still PENDING orders) for a ticket type, so a per-person
 * limit counts reservations in progress too.
 */
function tickets_held_by_user_for_tier(int $userId, int $tierId): int
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0)
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.user_id = ? AND oi.ticket_type_id = ? AND o.status IN ('PAID', 'PENDING')
    ");
    $stmt->execute([$userId, $tierId]);
    return (int) $stmt->fetchColumn();
}

/** The per-person limit of a ticket type: the organizer's number, the default for a free ticket, or null (no limit). */
function tier_person_limit(array $tier): ?int
{
    $v = $tier['max_per_person'] ?? null;
    if ($v !== null && $v !== '' && (int) $v > 0) {
        return (int) $v;
    }
    return ticket_is_free($tier) ? FREE_TICKET_DEFAULT_LIMIT : null;
}

const PLATFORM_BADGES = [
    'Zoom' => ['Z', '#2D8CFF'],
    'Google Meet' => ['M', '#00897B'],
    'Microsoft Teams' => ['T', '#5B5FC7'],
    'YouTube Live' => ['▶', '#E62117'],
    'Other' => ['+', '#6E687A'],
];

/**
 * The Join card shown to a ticket holder (event page, ticket page, My Tickets). It never prints the meeting link:
 * the button goes through /join.php, which re-checks the ticket and the time window and counts the join.
 * Joining notes (passcode and so on) are shown here because only ticket holders ever receive this card.
 *
 * @param array{platform:string,url:string,notes:string,window:array} $access from get_online_access()
 */
function render_join_card_html(array $event, array $access): string
{
    $w = $access['window'];
    $platform = $access['platform'] !== '' ? $access['platform'] : 'the meeting';
    [$letter, $color] = PLATFORM_BADGES[$access['platform']] ?? PLATFORM_BADGES['Other'];
    $eventId = (int) $event['id'];
    $opensText = date('D j M, g:ia', $w['opens']);

    if ($w['state'] === 'open') {
        $status = 'Open now. Tap to join.';
        $button = '<a class="jn-btn jn-go" href="/join.php?event=' . $eventId . '" rel="noopener">Join now</a>';
    } elseif ($w['state'] === 'before') {
        $status = 'Opens ' . ONLINE_JOIN_OPENS_MINUTES . ' minutes before the start, ' . $opensText;
        $button = '<span class="jn-btn jn-wait" aria-disabled="true">Join opens soon</span>';
    } else {
        $status = 'This event has ended.';
        $button = '';
    }

    $notes = $access['notes'] !== ''
        ? '<div class="jn-notes"><span>Notes</span><b>' . htmlspecialchars($access['notes']) . '</b></div>'
        : '';

    return '<div class="jn-card" data-jn-opens="' . (int) $w['opens'] . '" data-jn-closes="' . (int) $w['closes'] . '" data-jn-event="' . $eventId . '">'
        . '<div class="jn-head"><span class="jn-logo" style="background:' . htmlspecialchars($color) . '" aria-hidden="true">' . htmlspecialchars($letter) . '</span>'
        . '<div><div class="jn-title">Join on ' . htmlspecialchars($platform) . '</div><div class="jn-status" data-jn-status>' . htmlspecialchars($status) . '</div></div></div>'
        . $notes
        . '<div data-jn-btn>' . $button . '</div>'
        . '<div class="jn-tz">Times are East Africa Time (EAT, UTC+3). Please do not share this link. It is for ticket holders only.</div>'
        . '</div>';
}
