<?php
declare(strict_types=1);

/**
 * "Viewed but never bought". Every time a logged-in member opens an event page we remember it (event_views, once per
 * member per event, with a count and the last time). A guest is remembered once per browser session and can only be
 * counted, never contacted. On the organizer dashboard (org-interested.php) the organizer sees, for each of their
 * upcoming events, the members who looked at it and never bought, and can email them or open a WhatsApp chat with
 * them. Members agreed to this when they accepted the Terms of Use when they created their account.
 *
 * Limits (so nobody is pestered and the email domain keeps a clean name): at most 2 messages per channel to one person
 * about one event with 48 hours between them, at most 2 per channel to one person in a day from ALL organizers, and at
 * most 100 per channel per organizer per day. Every send is recorded in event_outreach.
 */
require_once __DIR__ . '/followup.php';

const AUDIENCE_RETENTION_DAYS = 90;
const AUDIENCE_MAX_PER_CHANNEL = 2;
const AUDIENCE_COOLDOWN_HOURS = 48;
const AUDIENCE_PERSON_PER_DAY = 2;
const AUDIENCE_ORGANIZER_PER_DAY = 100;
const AUDIENCE_MAX_MESSAGE = 1000;

/** Are both tables there? (The event page and the organizer pages must keep working before migration 028 is applied.) */
function audience_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $n = (int) db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('event_views','event_outreach')")->fetchColumn();
            $ready = $n === 2;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Link-preview fetchers, search engines, monitors and scripts are not people: they are never counted. */
function event_view_is_bot(string $userAgent): bool
{
    $ua = trim($userAgent);
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match('~bot|crawl|spider|slurp|facebookexternalhit|facebot|whatsapp|telegram|twitter|linkedin|preview|embed|curl|wget|python|java/|go-http|okhttp|headless|lighthouse|pingdom|uptime|monitor|scan|fetch|http-client|libwww|axios|postman~i', $ua);
}

/**
 * Remembers that this visitor looked at this event. Called from the event page; it never throws and never slows the
 * page down by more than one small query. Not counted: bots, non-GET requests, events that are not published, and the
 * organizer, their team and admins looking at their own event.
 */
function event_view_record(array $event, ?array $user): void
{
    try {
        if (!audience_ready() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || ($event['status'] ?? '') !== 'PUBLISHED') {
            return;
        }
        if (event_view_is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) || stripos((string) ($_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_SEC_PURPOSE'] ?? ''), 'prefetch') !== false) {
            return;
        }
        $userId = null;
        if ($user) {
            if (($user['role'] ?? '') === 'ADMIN') {
                return;
            }
            $ctx = resolve_organizer_context($user);
            if ($ctx && (int) $ctx['organizer_id'] === (int) $event['organizer_id']) {
                return;
            }
            $userId = (int) $user['id'];
            $key = 'u:' . $userId;
        } else {
            $sid = session_id();
            if ($sid === '') {
                return;
            }
            $key = 's:' . substr($sid, 0, 64);
        }
        db()->prepare('INSERT INTO event_views (event_id, viewer_key, user_id) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed_at = NOW()')
            ->execute([(int) $event['id'], $key, $userId]);
        if (random_int(1, 200) === 1) {
            db()->exec('DELETE FROM event_views WHERE last_viewed_at < NOW() - INTERVAL ' . AUDIENCE_RETENTION_DAYS . ' DAY LIMIT 1000');
        }
    } catch (Throwable $e) {
        error_log('[event_views] ' . $e->getMessage());
    }
}

/**
 * The people who count as "viewed but never bought": a member (not a guest), whose account is active, who is not the
 * organizer or an admin, who has no PAID order for the event, and who is not in the middle of paying right now.
 * $where is appended to the WHERE clause. Aliases: v = event_views, u = users.
 */
function audience_from_sql(): string
{
    return "FROM event_views v
        JOIN users u ON u.id = v.user_id AND u.account_status = 'ACTIVE' AND u.role <> 'ADMIN'
        WHERE v.user_id IS NOT NULL
          AND v.user_id <> (SELECT e0.organizer_id FROM events e0 WHERE e0.id = v.event_id)
          AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.event_id = v.event_id AND o.user_id = v.user_id AND o.status = 'PAID')
          AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.event_id = v.event_id AND o.user_id = v.user_id AND o.status = 'PENDING' AND o.created_at > NOW() - INTERVAL 30 MINUTE)";
}

/**
 * The organizer's upcoming and live published events, each with its audience numbers:
 *   viewed_known     members who looked at it
 *   bought           of those, members who bought
 *   never_bought     members who looked and never bought (the people you can reach)
 *   anonymous        guests who looked (counted only)
 */
function audience_events(int $organizerId): array
{
    $stmt = db()->prepare("SELECT id, title, slug, starts_at, ends_at, venue_name FROM events
        WHERE organizer_id = ? AND status = 'PUBLISHED' AND COALESCE(ends_at, starts_at) >= NOW() ORDER BY starts_at ASC");
    $stmt->execute([$organizerId]);
    $events = $stmt->fetchAll();
    if (!$events) {
        return [];
    }
    $ids = array_map(static fn ($e) => (int) $e['id'], $events);
    $in = implode(',', $ids);   // integers only
    $never = $anon = $known = $bought = [];
    if (audience_ready()) {
        foreach (db()->query('SELECT v.event_id, COUNT(*) n ' . audience_from_sql() . " AND v.event_id IN ($in) GROUP BY v.event_id") as $r) {
            $never[(int) $r['event_id']] = (int) $r['n'];
        }
        foreach (db()->query("SELECT event_id, COUNT(*) n FROM event_views WHERE user_id IS NULL AND event_id IN ($in) GROUP BY event_id") as $r) {
            $anon[(int) $r['event_id']] = (int) $r['n'];
        }
        foreach (db()->query("SELECT event_id, COUNT(*) n FROM event_views WHERE user_id IS NOT NULL AND user_id <> (SELECT e0.organizer_id FROM events e0 WHERE e0.id = event_views.event_id) AND event_id IN ($in) GROUP BY event_id") as $r) {
            $known[(int) $r['event_id']] = (int) $r['n'];
        }
        foreach (db()->query("SELECT v.event_id, COUNT(*) n FROM event_views v WHERE v.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM orders o WHERE o.event_id = v.event_id AND o.user_id = v.user_id AND o.status = 'PAID') AND v.event_id IN ($in) GROUP BY v.event_id") as $r) {
            $bought[(int) $r['event_id']] = (int) $r['n'];
        }
    }
    foreach ($events as &$e) {
        $id = (int) $e['id'];
        $e['never_bought'] = $never[$id] ?? 0;
        $e['anonymous'] = $anon[$id] ?? 0;
        $e['viewed_known'] = $known[$id] ?? 0;
        $e['bought'] = $bought[$id] ?? 0;
    }
    unset($e);
    return $events;
}

/** WhatsApp number for a viewer: the one on their profile, else one they typed paying for this event. 2567XXXXXXXX or null. */
function audience_phone(array $row): ?string
{
    // followup_phone() tries 'pay_phone' first and then 'buyer_phone', so the profile number goes first here.
    return followup_phone(['pay_phone' => (string) ($row['phone'] ?? ''), 'buyer_phone' => (string) ($row['order_phone'] ?? '')]);
}

/**
 * Viewers who never bought, most promising first (people who tried to pay, then the most recent). Pass $userId to get
 * just one person (used to re-check someone right before a message goes out).
 */
function audience_list(int $eventId, int $limit = 30, int $offset = 0, ?int $userId = null): array
{
    if (!audience_ready()) {
        return [];
    }
    $sql = "SELECT v.user_id, v.view_count, v.first_viewed_at, v.last_viewed_at, u.name, u.email, u.phone,
        (SELECT o.phone FROM orders o WHERE o.user_id = u.id AND o.event_id = v.event_id AND o.phone IS NOT NULL AND o.phone <> '' ORDER BY o.id DESC LIMIT 1) AS order_phone,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.event_id = v.event_id AND o.status IN ('FAILED','PENDING')) AS tried_to_pay,
        (SELECT MAX(x.created_at) FROM event_outreach x WHERE x.event_id = v.event_id AND x.user_id = u.id AND x.channel = 'EMAIL' AND x.status = 'SENT') AS emailed_at,
        (SELECT MAX(x.created_at) FROM event_outreach x WHERE x.event_id = v.event_id AND x.user_id = u.id AND x.channel = 'WHATSAPP' AND x.status = 'OPENED') AS whatsapped_at "
        . audience_from_sql() . ' AND v.event_id = ?';
    $params = [$eventId];
    if ($userId !== null) {
        $sql .= ' AND v.user_id = ?';
        $params[] = $userId;
    }
    $sql .= ' ORDER BY (tried_to_pay > 0) DESC, v.last_viewed_at DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['whatsapp'] = audience_phone($r);
        $r['can_email'] = (bool) filter_var((string) $r['email'], FILTER_VALIDATE_EMAIL);
    }
    unset($r);
    return $rows;
}

function audience_count(int $eventId): int
{
    if (!audience_ready()) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) ' . audience_from_sql() . ' AND v.event_id = ?');
    $stmt->execute([$eventId]);
    return (int) $stmt->fetchColumn();
}

/** One event of this organizer that can still be marketed (published, not over). Null otherwise. */
function audience_event(int $eventId, int $organizerId): ?array
{
    $stmt = db()->prepare("SELECT e.id, e.title, e.slug, e.starts_at, e.ends_at, e.venue_name, e.attend_mode, e.organizer_id, op.org_name
        FROM events e LEFT JOIN organizer_profiles op ON op.user_id = e.organizer_id
        WHERE e.id = ? AND e.organizer_id = ? AND e.status = 'PUBLISHED' AND COALESCE(e.ends_at, e.starts_at) >= NOW()");
    $stmt->execute([$eventId, $organizerId]);
    return $stmt->fetch() ?: null;
}

function audience_event_url(array $event): string
{
    return rtrim(APP_URL, '/') . '/event.php?slug=' . urlencode((string) $event['slug']);
}

function audience_organizer_name(array $event): string
{
    return trim((string) ($event['org_name'] ?? '')) ?: 'the event team';
}

/** The message the organizer starts from. {name} becomes each person's first name when it is sent. */
function audience_default_message(array $event, array $sender): string
{
    $from = audience_organizer_name($event);
    $name = trim($sender['name']);
    $who = ($name !== '' && strcasecmp($name, $from) !== 0) ? $name . ' from ' . $from : $from;
    $who = rtrim($who, '. ');
    $where = event_is_online_only($event) ? 'online' : (trim((string) $event['venue_name']) !== '' ? 'at ' . $event['venue_name'] : '');
    $when = format_event_date_range((string) $event['starts_at'], (string) ($event['ends_at'] ?: $event['starts_at']));
    return "Hi {name}, this is {$who}. You recently looked at {$event['title']} on obitickets, and we would love to see you there.\n\n"
        . "It is on {$when}" . ($where !== '' ? " {$where}" : '') . ". Tickets are still available, and you can get yours in a minute here: " . audience_event_url($event) . "\n\n"
        . 'Any questions, just reply to this message.';
}

/** @return string|null An error message, or null when the text is fine to send. */
function audience_validate_message(string $message): ?string
{
    $message = trim($message);
    if (mb_strlen($message) < 10) {
        return 'Write a message first (at least a sentence).';
    }
    if (mb_strlen($message) > AUDIENCE_MAX_MESSAGE) {
        return 'That message is too long. Keep it under ' . AUDIENCE_MAX_MESSAGE . ' characters.';
    }
    $own = rtrim(APP_URL, '/');
    if (preg_match_all('~https?://[^\s<>"\']+~i', $message, $m)) {
        foreach ($m[0] as $url) {
            if (stripos($url, $own) !== 0) {
                return 'Only links to ' . preg_replace('~^https?://~', '', $own) . ' can be included in this message.';
            }
        }
    }
    return null;
}

function audience_personalise(string $message, string $fullName): string
{
    $first = trim(explode(' ', trim($fullName))[0]) ?: 'there';
    return str_replace('{name}', $first, trim($message));
}

/** @return array{0: bool, 1: string} [may send on this channel now, why not]. Only sends that went out count. */
function audience_channel_check(int $eventId, int $userId, int $organizerId, string $channel): array
{
    $label = $channel === 'EMAIL' ? 'emailed' : 'messaged on WhatsApp';
    $ok = "status IN ('SENT','OPENED')";
    $stmt = db()->prepare("SELECT COUNT(*) AS n, MAX(created_at) AS last FROM event_outreach WHERE event_id = ? AND user_id = ? AND channel = ? AND $ok");
    $stmt->execute([$eventId, $userId, $channel]);
    $row = $stmt->fetch();
    if ((int) $row['n'] >= AUDIENCE_MAX_PER_CHANNEL) {
        return [false, 'This person has already been ' . $label . ' ' . AUDIENCE_MAX_PER_CHANNEL . ' times about this event. That is the limit.'];
    }
    if ($row['last'] && strtotime((string) $row['last']) > time() - AUDIENCE_COOLDOWN_HOURS * 3600) {
        $next = date('d M, H:i', strtotime((string) $row['last']) + AUDIENCE_COOLDOWN_HOURS * 3600);
        return [false, 'This person was ' . $label . ' recently. You can do it again after ' . $next . '.'];
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM event_outreach WHERE user_id = ? AND channel = ? AND $ok AND created_at > NOW() - INTERVAL 1 DAY");
    $stmt->execute([$userId, $channel]);
    if ((int) $stmt->fetchColumn() >= AUDIENCE_PERSON_PER_DAY) {
        return [false, 'This person has already heard from event organizers today. Try again tomorrow.'];
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM event_outreach x JOIN events e ON e.id = x.event_id WHERE e.organizer_id = ? AND x.channel = ? AND x.$ok AND x.created_at > NOW() - INTERVAL 1 DAY");
    $stmt->execute([$organizerId, $channel]);
    if ((int) $stmt->fetchColumn() >= AUDIENCE_ORGANIZER_PER_DAY) {
        return [false, 'You have reached today\'s limit of ' . AUDIENCE_ORGANIZER_PER_DAY . ' messages on this channel. It resets tomorrow.'];
    }
    return [true, ''];
}

function audience_record(int $eventId, int $userId, array $sender, string $channel, string $status, ?string $note = null): void
{
    db()->prepare('INSERT INTO event_outreach (event_id, user_id, sent_by, sender_role, channel, status, note) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$eventId, $userId, $sender['id'] ?: null, $sender['role'], $channel, $status, $note !== null ? mb_substr($note, 0, 300) : null]);
}

function render_audience_email_html(array $event, string $personName, string $message): string
{
    $eventH = htmlspecialchars((string) $event['title']);
    $url = htmlspecialchars(audience_event_url($event));
    $body = nl2br(htmlspecialchars(audience_personalise($message, $personName)), false);
    $fromH = htmlspecialchars(audience_organizer_name($event));

    return <<<HTML
        <div style="font-family:sans-serif; max-width:560px; margin:0 auto; color:#1C1526;">
          <p style="font-size:15px; line-height:1.65;">{$body}</p>
          <p style="margin:24px 0;">
            <a href="{$url}" style="display:inline-block; background:#DC2626; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">Get tickets: {$eventH}</a>
          </p>
          <p style="color:#726C7E; font-size:12px; line-height:1.6;">
            You are receiving this because you have an account on obitickets and looked at {$eventH}. Sent by {$fromH} through obitickets. You can reply to this email.
          </p>
        </div>
        HTML;
}

/**
 * Does one outreach action, after the page has checked who the sender is. $action is 'email' or 'whatsapp'.
 *  ['ok' => true, 'flash' => text]            an email went out
 *  ['ok' => true, 'redirect' => wa.me link]   the WhatsApp chat should open
 *  ['ok' => false, 'error' => text]           nothing was sent
 * $ctx is the organizer context from require_organizer_access(). $emailSender is only for tests (same signature as resend_send).
 */
function audience_handle(int $eventId, int $userId, array $ctx, string $action, string $message, ?callable $emailSender = null): array
{
    if (!audience_ready()) {
        return ['ok' => false, 'error' => 'This feature is not set up on this server yet.'];
    }
    $organizerId = (int) $ctx['organizer_id'];
    $event = audience_event($eventId, $organizerId);
    if (!$event) {
        return ['ok' => false, 'error' => 'That event is not open for marketing: it is not yours, not published, or already over.'];
    }
    $people = audience_list($eventId, 1, 0, $userId);
    if (!$people) {
        return ['ok' => false, 'error' => 'This person is no longer in the list. They may have just bought a ticket.'];
    }
    $person = $people[0];
    if ($err = audience_validate_message($message)) {
        return ['ok' => false, 'error' => $err];
    }
    $sender = followup_sender((int) $ctx['actor_id'], $ctx['is_owner'] ? 'ORGANIZER' : 'TEAM');

    if ($action === 'email') {
        if (!$person['can_email']) {
            return ['ok' => false, 'error' => 'This person has no usable email address.'];
        }
        [$can, $why] = audience_channel_check($eventId, $userId, $organizerId, 'EMAIL');
        if (!$can) {
            return ['ok' => false, 'error' => $why];
        }
        $replyTo = filter_var($sender['email'], FILTER_VALIDATE_EMAIL) ? $sender['email'] : null;
        $send = $emailSender ?? 'resend_send';
        $sent = (bool) $send((string) $person['email'], 'About ' . $event['title'], render_audience_email_html($event, (string) $person['name'], $message), 'audience', null, $replyTo);
        audience_record($eventId, $userId, $sender, 'EMAIL', $sent ? 'SENT' : 'FAILED', $sent ? null : 'Not accepted by the email service (see Email log).');
        return $sent
            ? ['ok' => true, 'flash' => 'Email sent to ' . trim((string) $person['name']) . '.']
            : ['ok' => false, 'error' => 'The email was not accepted. An admin can see exactly why in the Email log.'];
    }

    if ($action === 'whatsapp') {
        if ($person['whatsapp'] === null) {
            return ['ok' => false, 'error' => 'This person has no mobile number we can use for WhatsApp.'];
        }
        [$can, $why] = audience_channel_check($eventId, $userId, $organizerId, 'WHATSAPP');
        if (!$can) {
            return ['ok' => false, 'error' => $why];
        }
        audience_record($eventId, $userId, $sender, 'WHATSAPP', 'OPENED', 'Chat opened from the sender\'s WhatsApp.');
        return ['ok' => true, 'redirect' => 'https://wa.me/' . $person['whatsapp'] . '?text=' . rawurlencode(audience_personalise($message, (string) $person['name']))];
    }

    return ['ok' => false, 'error' => 'Unknown action.'];
}
