<?php
declare(strict_types=1);

/**
 * The real client IP, checking common proxy/CDN headers before falling back
 * to REMOTE_ADDR — needed since a CDN or load balancer in front of the app
 * would otherwise leave every login attributed to that proxy's own IP.
 */
function get_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Resolves an IP to its country code + city via the free ip-api.com service
 * (no API key required, no account to set up). Private/reserved IPs
 * (localhost, LAN) are rejected before the HTTP call fires, since they'd
 * never resolve to anything real anyway — this also means local dev logins
 * never trigger a network call. Returns null on any failure (private IP,
 * network error, non-success response), so callers can just leave
 * country/city blank instead of failing whatever triggered the lookup.
 *
 * Called synchronously (not deferred to a cron sweep) since obitickets has
 * no cron infrastructure yet and login/signup volume is low enough that the
 * extra ~100-300ms is an acceptable trade for now — revisit with a deferred
 * batch sweep + throttling if volume grows enough to risk ip-api.com's free
 * tier rate limit (45 requests/minute).
 */
function geo_lookup_ip(string $ip): ?array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,city';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? null) !== 'success') {
        return null;
    }

    return [
        'country' => !empty($data['countryCode']) ? substr((string) $data['countryCode'], 0, 2) : null,
        'city' => !empty($data['city']) ? substr((string) $data['city'], 0, 100) : null,
    ];
}

/**
 * Cheap regex-based User-Agent parse for device/browser/OS — no library, no
 * network call, "good enough for an admin activity list" rather than
 * exhaustive. Order matters: Edge and Opera both contain "Chrome" in their
 * UA string, so they're checked first.
 * @return array{0: string, 1: ?string, 2: ?string} [device_type, browser, os]
 */
function parse_user_agent(string $ua): array
{
    $device = 'desktop';
    if (preg_match('/tablet|ipad/i', $ua)) {
        $device = 'tablet';
    } elseif (preg_match('/mobile|android|iphone/i', $ua)) {
        $device = 'mobile';
    }

    $browser = match (true) {
        (bool) preg_match('/Edg\//i', $ua) => 'Edge',
        (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
        (bool) preg_match('/Chrome\//i', $ua) => 'Chrome',
        (bool) preg_match('/Firefox\//i', $ua) => 'Firefox',
        (bool) preg_match('/Safari\//i', $ua) => 'Safari',
        default => null,
    };

    $os = match (true) {
        (bool) preg_match('/Windows/i', $ua) => 'Windows',
        (bool) preg_match('/Android/i', $ua) => 'Android',
        (bool) preg_match('/iPhone|iPad|iOS/i', $ua) => 'iOS',
        (bool) preg_match('/Mac OS X/i', $ua) => 'macOS',
        (bool) preg_match('/Linux/i', $ua) => 'Linux',
        default => null,
    };

    return [$device, $browser, $os];
}

/** Small hardcoded ISO-alpha-2 -> name lookup for the countries obitickets actually sees; falls back to the raw code. */
function country_name(?string $code): ?string
{
    if (!$code) {
        return null;
    }
    $names = [
        'UG' => 'Uganda', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'RW' => 'Rwanda',
        'NG' => 'Nigeria', 'GH' => 'Ghana', 'ZA' => 'South Africa', 'US' => 'United States',
        'GB' => 'United Kingdom', 'CA' => 'Canada', 'AE' => 'United Arab Emirates', 'IN' => 'India',
    ];
    return $names[$code] ?? $code;
}
