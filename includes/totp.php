<?php
declare(strict_types=1);

/**
 * Minimal RFC 6238 TOTP implementation — no external dependency, matching
 * this codebase's pattern of small hand-rolled integrations (iotec.php,
 * email.php, sms.php) rather than pulling in a Composer package; there's no
 * vendor/ directory or build step anywhere in this project. 30-second step,
 * 6 digits, SHA1 — the universal defaults every authenticator app (Google
 * Authenticator, Authy, 1Password, etc) assumes when an otpauth:// URI
 * doesn't specify an algorithm/digits/period.
 */

function totp_generate_secret(): string
{
    // Base32 per RFC 4648 — what every authenticator app expects to scan/enter.
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    return $secret;
}

function totp_base32_decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper(rtrim($base32, '='));
    $bits = '';
    foreach (str_split($base32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $bytes .= chr((int) bindec($byte));
        }
    }
    return $bytes;
}

function totp_code_at(string $secret, int $timestamp): string
{
    $key = totp_base32_decode($secret);
    $counter = intdiv($timestamp, 30);
    $binCounter = pack('J', $counter); // 8-byte unsigned big-endian, per RFC 4226
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current 30s step and one step of drift either side, to tolerate normal clock skew between the phone and the server. */
function totp_verify(string $secret, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $now = time();
    foreach ([-30, 0, 30] as $drift) {
        if (hash_equals(totp_code_at($secret, $now + $drift), $code)) {
            return true;
        }
    }
    return false;
}

/** otpauth:// URI for the setup QR code — scanned once, never shown again after setup completes. */
function totp_provisioning_uri(string $secret, string $email): string
{
    $label = rawurlencode('obitickets:' . $email);
    return 'otpauth://totp/' . $label . '?' . http_build_query([
        'secret' => $secret,
        'issuer' => 'obitickets',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** @return list<string> 8 one-time recovery codes, shown to the admin exactly once at setup/regeneration time. */
function totp_generate_recovery_codes(int $count = 8): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

/** @param list<string> $codes */
function totp_hash_recovery_codes(array $codes): string
{
    return json_encode(array_map(static fn (string $c): string => password_hash($c, PASSWORD_DEFAULT), $codes));
}

/** Consumes (removes) the matching code on success, so it can never be reused. */
function totp_verify_and_consume_recovery_code(int $userId, string $code): bool
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT totp_recovery_codes FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!$raw) {
        return false;
    }

    $hashes = json_decode((string) $raw, true) ?: [];
    foreach ($hashes as $i => $hash) {
        if (password_verify($code, $hash)) {
            unset($hashes[$i]);
            db()->prepare('UPDATE users SET totp_recovery_codes = ? WHERE id = ?')
                ->execute([json_encode(array_values($hashes)), $userId]);
            return true;
        }
    }
    return false;
}
