<?php
/**
 * OBI GIFTS: an email to the organizer every time they receive a gift.
 *
 * HOW. When a gift is delivered it is only put in a small queue (a gift_events row, kind org_email_queued): the supporter's gift never waits for an email
 * service. The 5-minute background job (cron/process-gifts.php) sends the queued emails. One email per gift, as asked. To protect the organizer's inbox
 * (and our sending reputation) on a very busy night: at most GIFT_EMAIL_PER_HOUR single emails an hour, and when many gifts arrive together they come
 * as ONE summary email instead (never more than one summary every GIFT_EMAIL_DIGEST_EVERY_MIN minutes). Nothing is lost: every gift is in one or the other.
 *
 * WHO. The organizer account's own email address. A supporter who chose to stay anonymous is "Anonymous supporter" here too: the email never names them.
 * The organizer can switch these emails off (and send themselves a test) on OBI GIFTS & earnings. On by default.
 *
 * States of a gift's row: org_email_queued -> org_emailed | org_email_digested | org_email_skipped (reserved/demo address) | org_email_failed (after
 * GIFT_EMAIL_ATTEMPTS tries). The record lives in gift_events, so no new table is needed.
 */

const GIFT_EMAIL_PER_HOUR = 15;
const GIFT_EMAIL_MAX_SINGLE_PER_RUN = 3;    // more than this waiting at once for one organizer -> one summary email
const GIFT_EMAIL_DIGEST_EVERY_MIN = 10;
const GIFT_EMAIL_ATTEMPTS = 3;
const GIFT_EMAIL_KINDS = "'org_email_queued','org_emailed','org_email_digested','org_email_skipped','org_email_failed'";

/** Gift emails are on unless the organizer switched them off. */
function gift_emails_enabled_for(int $organizerId): bool
{
    return get_setting('org_gift_email_off_' . $organizerId, '0') !== '1';
}

function gift_emails_set(int $organizerId, bool $on): void
{
    coin_quiet_setting('org_gift_email_off_' . $organizerId, $on ? '0' : '1');
}

/** Mail to reserved and demo addresses can never arrive (the same rule resend_send uses). */
function gift_email_deliverable(string $to): bool
{
    $to = trim($to);
    return $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && !preg_match('/@(example\.(com|org|net)|obitickets\.demo|[^@]+\.(test|invalid|localhost|example))$/i', $to);
}

/**
 * Called once when a gift has been delivered: puts it in the queue (if the organizer wants the emails). Never throws, never sends, never touches money.
 */
function gift_email_queue(int $txId): bool
{
    try {
        if (!gifts_ready()) {
            return false;
        }
        $pdo = db();
        $st = $pdo->prepare("SELECT t.id, t.recipient_organizer_id AS org FROM gift_transactions t WHERE t.id = ? AND t.payment_status = 'SUCCESSFUL' AND t.delivery_status = 'DELIVERED'");
        $st->execute([$txId]);
        $tx = $st->fetch();
        if (!$tx || !gift_emails_enabled_for((int) $tx['org'])) {
            return false;
        }
        $has = $pdo->prepare('SELECT 1 FROM gift_events WHERE gift_tx_id = ? AND kind IN (' . GIFT_EMAIL_KINDS . ') LIMIT 1');
        $has->execute([$txId]);
        if ($has->fetchColumn()) {
            return false;   // already queued or handled: never twice
        }
        gift_log($txId, 'org_email_queued', 'attempt:0');
        return true;
    } catch (Throwable $e) {
        error_log('[gift] could not queue the organizer email for gift ' . $txId . ': ' . $e->getMessage());
        return false;
    }
}

/** Wraps the shared look of the email. */
function gift_email_shell(string $title, string $intro, string $body, string $button, string $href, string $foot): string
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#16111C">'
        . '<h2 style="margin:0 0 6px;font-size:20px">' . $title . '</h2><p style="margin:0 0 18px;color:#4A4455">' . $intro . '</p>' . $body
        . '<p style="margin:22px 0 0"><a href="' . $h($href) . '" style="display:inline-block;background:#E5383B;color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px">' . $h($button) . '</a></p>'
        . '<p style="margin:22px 0 0;font-size:12px;color:#8A8494">' . $foot . '</p></div>';
}

/** One gift. $g: sender, gift (e.g. "3 x Rose"), where, ugx (the organizer's share), coins (int|null), when. */
function gift_email_render_single(array $g): array
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    $base = rtrim(APP_URL, '/');
    $row = static fn (string $k, string $v): string => '<tr><td style="padding:6px 16px 6px 0;color:#6B6577;white-space:nowrap">' . $k . '</td><td style="padding:6px 0;font-weight:600">' . $v . '</td></tr>';
    $rows = [
        $row('From', $h($g['sender'])),
        $row('Gift', $h($g['gift']) . ($g['coins'] !== null ? ' <span style="font-weight:400;color:#6B6577">(' . number_format((int) $g['coins']) . ' OBI COINS)</span>' : '')),
        $row('On', $h($g['where'])),
        $row('Added to your earnings', 'UGX ' . number_format((int) $g['ugx'])),
        $row('When', $h($g['when'])),
    ];
    $subject = 'You received a gift: ' . $g['gift'] . ' from ' . $g['sender'];
    $html = gift_email_shell('You received an OBI GIFT', $h($g['sender']) . ' just sent you ' . $h($g['gift']) . '.', '<table style="border-collapse:collapse;font-size:15px">' . implode('', $rows) . '</table>',
        'See your gifts and say thanks', $base . '/org-gifts.php?tab=history',
        'obitickets &middot; you get this when someone sends you a gift. <a href="' . $h($base . '/org-gifts.php#giftEmails') . '" style="color:#8A8494">Turn these emails off</a>.');
    return [$subject, $html];
}

/** Many gifts in one email. $summary: count, ugx (the organizer's share, total), coins, top (list of "N x Gift" strings), since. */
function gift_email_render_digest(array $s): array
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    $base = rtrim(APP_URL, '/');
    $row = static fn (string $k, string $v): string => '<tr><td style="padding:6px 16px 6px 0;color:#6B6577;white-space:nowrap">' . $k . '</td><td style="padding:6px 0;font-weight:600">' . $v . '</td></tr>';
    $rows = [$row('Gifts', (int) $s['count']), $row('Added to your earnings', 'UGX ' . number_format((int) $s['ugx'])), $row('Most gifted', $h(implode(', ', $s['top']))), $row('Since', $h($s['since']))];
    $subject = 'You received ' . (int) $s['count'] . ' gifts (UGX ' . number_format((int) $s['ugx']) . ')';
    $html = gift_email_shell('Your fans have been busy', 'You received <b>' . (int) $s['count'] . ' gifts</b> in a short time, so here they are in one email.', '<table style="border-collapse:collapse;font-size:15px">' . implode('', $rows) . '</table>',
        'See every gift', $base . '/org-gifts.php?tab=history',
        'obitickets &middot; when many gifts arrive together we send one summary instead of many emails. <a href="' . $h($base . '/org-gifts.php#giftEmails') . '" style="color:#8A8494">Turn these emails off</a>.');
    return [$subject, $html];
}

/**
 * The background job calls this: sends what is waiting. $send($to, $subject, $html, $kind): bool is for tests; the real one is resend_send.
 * @return array{single:int, digest:int, skipped:int, failed:int, waiting:int}
 */
function gift_email_send_queued(int $limit = 200, ?callable $send = null): array
{
    $out = ['single' => 0, 'digest' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0];
    if (!gifts_ready()) {
        return $out;
    }
    $send = $send ?? static fn (string $to, string $subject, string $html, string $kind): bool => resend_send($to, $subject, $html, $kind);
    $pdo = db();
    $rows = $pdo->query("SELECT e.id AS event_id, e.details, t.id AS tx_id, t.recipient_organizer_id AS org, t.sender_id, t.is_anonymous, t.quantity, t.organizer_ugx, t.coins_spent, t.payment_source, t.paid_at, t.post_id,
                                g.name AS gift_name, u.email AS org_email, ev.title AS event_title
                         FROM gift_events e JOIN gift_transactions t ON t.id = e.gift_tx_id JOIN gift_catalog g ON g.id = t.gift_id JOIN users u ON u.id = t.recipient_organizer_id
                         LEFT JOIN event_posts p ON p.id = t.post_id LEFT JOIN events ev ON ev.id = p.event_id
                         WHERE e.kind = 'org_email_queued' ORDER BY e.id LIMIT " . max(1, min(1000, $limit)))->fetchAll();
    $byOrg = [];
    foreach ($rows as $r) {
        $byOrg[(int) $r['org']][] = $r;
    }
    $mark = static function (int $eventId, string $kind, string $details) use ($pdo): void {
        $pdo->prepare('UPDATE gift_events SET kind = ?, details = ? WHERE id = ?')->execute([$kind, mb_substr($details, 0, 500), $eventId]);
    };
    foreach ($byOrg as $orgId => $list) {
        if (!gift_emails_enabled_for($orgId)) {
            foreach ($list as $r) { $mark((int) $r['event_id'], 'org_email_skipped', 'the organizer switched the emails off'); $out['skipped']++; }
            continue;
        }
        if (!gift_email_deliverable((string) $list[0]['org_email'])) {
            foreach ($list as $r) { $mark((int) $r['event_id'], 'org_email_skipped', 'reserved or invalid address'); $out['skipped']++; }
            continue;
        }
        $to = (string) $list[0]['org_email'];
        $sentHour = $pdo->prepare("SELECT COUNT(*) FROM gift_events e JOIN gift_transactions t ON t.id = e.gift_tx_id WHERE t.recipient_organizer_id = ? AND e.kind = 'org_emailed' AND e.created_at >= NOW() - INTERVAL 1 HOUR");
        $sentHour->execute([$orgId]);
        $room = max(0, GIFT_EMAIL_PER_HOUR - (int) $sentHour->fetchColumn());
        $line = static fn (array $r): string => ((int) $r['quantity'] > 1 ? (int) $r['quantity'] . ' x ' : '') . $r['gift_name'];
        if (count($list) <= GIFT_EMAIL_MAX_SINGLE_PER_RUN && count($list) <= $room) {
            foreach ($list as $r) {
                $attempt = (int) preg_replace('/\D/', '', (string) $r['details']);
                [$subject, $html] = gift_email_render_single([
                    'sender' => !empty($r['is_anonymous']) ? 'Anonymous supporter' : (function_exists('gift_first_name') ? gift_first_name((string) $pdo->query('SELECT name FROM users WHERE id = ' . (int) $r['sender_id'])->fetchColumn()) : 'A supporter'),
                    'gift' => $line($r), 'where' => $r['post_id'] ? ($r['event_title'] ?: 'one of your videos') : 'your profile', 'ugx' => (int) $r['organizer_ugx'],
                    'coins' => ($r['payment_source'] ?? 'DIRECT') === 'COINS' ? (int) $r['coins_spent'] : null, 'when' => date('D j M, g:ia', strtotime((string) $r['paid_at'])),
                ]);
                if ($send($to, $subject, $html, 'GIFT_ORGANIZER')) {
                    $mark((int) $r['event_id'], 'org_emailed', 'sent');
                    $out['single']++;
                } elseif ($attempt + 1 >= GIFT_EMAIL_ATTEMPTS) {
                    $mark((int) $r['event_id'], 'org_email_failed', 'could not be sent after ' . GIFT_EMAIL_ATTEMPTS . ' tries');
                    $out['failed']++;
                } else {
                    $mark((int) $r['event_id'], 'org_email_queued', 'attempt:' . ($attempt + 1));
                    $out['waiting']++;
                }
            }
            continue;
        }
        // many at once (or the hour's single emails are used up): ONE summary, at most every GIFT_EMAIL_DIGEST_EVERY_MIN minutes
        $recentDigest = $pdo->prepare("SELECT COUNT(*) FROM gift_events WHERE kind = 'org_email_digest' AND details LIKE ? AND created_at >= NOW() - INTERVAL " . (int) GIFT_EMAIL_DIGEST_EVERY_MIN . ' MINUTE');
        $recentDigest->execute(['org:' . $orgId . ' %']);
        if ((int) $recentDigest->fetchColumn() > 0) {
            $out['waiting'] += count($list);
            continue;
        }
        $counts = [];
        $ugx = 0;
        foreach ($list as $r) { $counts[$line($r)] = ($counts[$line($r)] ?? 0) + 1; $ugx += (int) $r['organizer_ugx']; }
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, 3, true) as $name => $n) { $top[] = $n . ' x ' . $name; }
        [$subject, $html] = gift_email_render_digest(['count' => count($list), 'ugx' => $ugx, 'top' => $top, 'since' => date('D j M, g:ia', strtotime((string) $list[0]['paid_at']))]);
        if ($send($to, $subject, $html, 'GIFT_DIGEST')) {
            foreach ($list as $r) { $mark((int) $r['event_id'], 'org_email_digested', 'in a summary email'); }
            gift_log(null, 'org_email_digest', 'org:' . $orgId . ' gifts:' . count($list));
            $out['digest']++;
        } else {
            $out['waiting'] += count($list);
        }
    }
    return $out;
}

/** A sample email to the organizer's own address, so they can see it arrives. @return array{ok:bool, error?:string, to?:string} */
function gift_email_send_test(int $organizerId, ?callable $send = null): array
{
    $st = db()->prepare('SELECT email FROM users WHERE id = ?');
    $st->execute([$organizerId]);
    $to = (string) $st->fetchColumn();
    if (!gift_email_deliverable($to)) {
        return ['ok' => false, 'error' => 'Your account email cannot receive mail.'];
    }
    $recent = db()->prepare("SELECT COUNT(*) FROM email_log WHERE kind = 'GIFT_TEST' AND to_email = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    try {
        $recent->execute([$to]);
        if ((int) $recent->fetchColumn() >= 3) {
            return ['ok' => false, 'error' => 'You already sent a few test emails. Try again in an hour.'];
        }
    } catch (Throwable $e) {
        // no email log yet: carry on
    }
    [$subject, $html] = gift_email_render_single(['sender' => 'Mary', 'gift' => '3 x Rose', 'where' => 'your event video', 'ugx' => 1800, 'coins' => 3000, 'when' => date('D j M, g:ia')]);
    $send = $send ?? static fn (string $to, string $subject, string $html, string $kind): bool => resend_send($to, $subject, $html, $kind);
    return $send($to, '[Test] ' . $subject, $html, 'GIFT_TEST') ? ['ok' => true, 'to' => $to] : ['ok' => false, 'error' => 'The test email could not be sent right now.'];
}
