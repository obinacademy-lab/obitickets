<?php
declare(strict_types=1);

/**
 * IP blocker. An address that does more than IPGUARD_LIMIT RISKY things within an hour is blocked: the first time for
 * an hour, and for 24 hours if it was already blocked within the last day.
 *
 * "Risky" means failed or abusive actions only, never ordinary browsing (a page load is many requests, so counting
 * every request would block every visitor):
 *   login_fail, signup_fail, reset (password reset requests), contact (contact form sends), pay_refused (a payment
 *   the payment guard refused), human_fail (failed bot check), csrf_fail (a forged API call), and probe (a request for
 *   a file only attackers ask for, like /wp-login.php: blocked at once).
 *
 * Never blocked: this machine (127.0.0.1 / ::1), any address in IPGUARD_ALLOW (comma-separated, set in the .env), and
 * a logged-in admin. Needs migration 027; without it nothing is recorded or blocked. Every database problem fails OPEN
 * (the visitor is let through) so the blocker can never take the site down.
 *
 * Mobile networks share one public address between many people, which is why blocks are short and only failures count.
 * An admin can lift a block at /admin-ip-blocks.php.
 */

const IPGUARD_LIMIT = 3;                    // MORE than this many risky actions in the window => blocked
const IPGUARD_WINDOW_MINUTES = 60;
const IPGUARD_FIRST_BLOCK_MINUTES = 60;
const IPGUARD_REPEAT_BLOCK_MINUTES = 1440;
const IPGUARD_REPEAT_WITHIN_HOURS = 24;

function ipguard_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'ip_blocks'")->fetch() && (bool) db()->query("SHOW TABLES LIKE 'ip_events'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The visitor's address. Only REMOTE_ADDR is trusted unless TRUSTED_IP_HEADER names the one header a proxy sets. */
function ipguard_client_ip(): string
{
    $header = defined('TRUSTED_IP_HEADER') ? (string) TRUSTED_IP_HEADER : '';
    if ($header !== '' && !empty($_SERVER[$header])) {
        $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function ipguard_exempt(string $ip): bool
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return true;                          // nothing sensible to block
    }
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if (defined('IPGUARD_ALLOW') && IPGUARD_ALLOW !== '') {
        foreach (explode(',', (string) IPGUARD_ALLOW) as $allowed) {
            if (trim($allowed) === $ip) {
                return true;
            }
        }
    }
    return false;
}

/** @return array{minutes:int,level:int,reason:string}|null set while the address is blocked */
function ipguard_block_info(string $ip): ?array
{
    if (!ipguard_ready() || ipguard_exempt($ip)) {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT level, reason, GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), blocked_until) / 60)) AS minutes FROM ip_blocks WHERE ip = ? AND blocked_until > NOW()');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        return $row ? ['minutes' => (int) $row['minutes'], 'level' => (int) $row['level'], 'reason' => (string) $row['reason']] : null;
    } catch (Throwable $e) {
        error_log('[ipguard] block lookup failed: ' . $e->getMessage());
        return null;
    }
}

/** Blocks the address now (1 hour, or 24 hours if it was blocked in the last day). Returns the block. */
function ipguard_block(string $ip, string $reason): ?array
{
    if (!ipguard_ready() || ipguard_exempt($ip)) {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT level FROM ip_blocks WHERE ip = ? AND last_blocked_at >= (NOW() - INTERVAL ' . IPGUARD_REPEAT_WITHIN_HOURS . ' HOUR)');
        $stmt->execute([$ip]);
        $repeat = $stmt->fetch() !== false;
        $minutes = $repeat ? IPGUARD_REPEAT_BLOCK_MINUTES : IPGUARD_FIRST_BLOCK_MINUTES;
        db()->prepare('INSERT INTO ip_blocks (ip, blocked_until, level, reason) VALUES (?, NOW() + INTERVAL ' . $minutes . ' MINUTE, ?, ?)
                       ON DUPLICATE KEY UPDATE blocked_until = NOW() + INTERVAL ' . $minutes . ' MINUTE, level = VALUES(level), reason = VALUES(reason), hits = hits + 1, last_blocked_at = NOW()')
            ->execute([$ip, $repeat ? 2 : 1, mb_substr($reason, 0, 120)]);
        error_log('[ipguard] blocked ' . $ip . ' for ' . $minutes . ' minutes: ' . $reason);
        return ['minutes' => $minutes, 'level' => $repeat ? 2 : 1, 'reason' => $reason];
    } catch (Throwable $e) {
        error_log('[ipguard] could not block ' . $ip . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Records one risky action by this visitor. The action that takes the address over the limit blocks it.
 * $ip is for tests; normally the visitor's own address is used.
 * @return array|null the block, if this action caused one
 */
function ipguard_note(string $kind, ?string $ip = null): ?array
{
    $ip = $ip ?? ipguard_client_ip();
    if (!ipguard_ready() || ipguard_exempt($ip)) {
        return null;
    }
    try {
        db()->prepare('INSERT INTO ip_events (ip, kind) VALUES (?, ?)')->execute([$ip, mb_substr($kind, 0, 24)]);
        $stmt = db()->prepare('SELECT COUNT(*) FROM ip_events WHERE ip = ? AND created_at >= (NOW() - INTERVAL ' . IPGUARD_WINDOW_MINUTES . ' MINUTE)');
        $stmt->execute([$ip]);
        $n = (int) $stmt->fetchColumn();
        ipguard_housekeeping();
        if ($n > IPGUARD_LIMIT) {
            return ipguard_block($ip, $n . ' risky actions in an hour (latest: ' . $kind . ')');
        }
    } catch (Throwable $e) {
        error_log('[ipguard] could not record event: ' . $e->getMessage());
    }
    return null;
}

/** A request for a file only attackers ask for: block now. */
function ipguard_probe(?string $ip = null): ?array
{
    $ip = $ip ?? ipguard_client_ip();
    if (!ipguard_ready() || ipguard_exempt($ip)) {
        return null;
    }
    try {
        db()->prepare('INSERT INTO ip_events (ip, kind) VALUES (?, ?)')->execute([$ip, 'probe']);
    } catch (Throwable $e) {
        error_log('[ipguard] could not record probe: ' . $e->getMessage());
    }
    return ipguard_block($ip, 'probing for ' . mb_substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 0, 80));
}

function ipguard_unblock(string $ip): void
{
    if (!ipguard_ready()) {
        return;
    }
    db()->prepare('UPDATE ip_blocks SET blocked_until = NOW() WHERE ip = ?')->execute([$ip]);
    db()->prepare('DELETE FROM ip_events WHERE ip = ?')->execute([$ip]);
}

/** Old rows are removed now and then (about 1 in 50 recorded events), so the tables stay small. */
function ipguard_housekeeping(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    db()->exec('DELETE FROM ip_events WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    db()->exec('DELETE FROM ip_blocks WHERE blocked_until < (NOW() - INTERVAL 7 DAY)');
}

/** Stops the request with a 403 if this visitor is blocked (admins, this machine and allow-listed addresses never are). */
function ipguard_enforce(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $ip = ipguard_client_ip();
    $block = ipguard_block_info($ip);
    if (!$block) {
        return;
    }
    $user = function_exists('current_user') ? current_user() : null;
    if ($user && ($user['role'] ?? '') === 'ADMIN') {
        return;
    }
    http_response_code(403);
    header('Retry-After: ' . ($block['minutes'] * 60));
    header('Cache-Control: no-store');
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (str_starts_with($path, '/api/')) {
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Too many failed or suspicious requests from your network. Please try again in about ' . $block['minutes'] . ' minutes.']));
    }
    $mins = (int) $block['minutes'];
    $when = $mins >= 90 ? 'about ' . (int) round($mins / 60) . ' hours' : 'about ' . $mins . ' minutes';
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily blocked</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:30rem;margin:12vh auto;padding:0 20px;color:#1c1526">'
        . '<h1 style="font-size:1.4rem">Temporarily blocked</h1>'
        . '<p>We saw too many failed or suspicious requests from your network, so access is paused for ' . $when . '. '
        . 'If you are a real customer, please try again later, or message us on WhatsApp and we will help.</p></body>');
}

/** The body of every decoy file (wp-login.php, xmlrpc.php...): an address that asks for one of these is probing. */
function ipguard_decoy(): never
{
    ipguard_probe();
    http_response_code(404);
    exit('Not found');
}
