<?php
declare(strict_types=1);

/**
 * Settings and secrets come from a ".env" file, not from code and not from anything under the web root.
 *
 * Where the file is looked for, first match wins:
 *   1. the path in the OBITICKETS_ENV_FILE environment variable (optional)
 *   2. ONE FOLDER ABOVE the project folder, i.e. above public_html on the live server. Nothing outside public_html
 *      can be reached with a URL, so this is where the live .env belongs.
 *   3. the project folder itself (local development; .env is git-ignored and never copied to the server by the deploy tool)
 *
 * Format: KEY=value per line. Blank lines and lines starting with # are ignored. Wrap a value in "double" or 'single'
 * quotes if it has spaces. No inline comments. Every key becomes a PHP constant with the same name (APP_SECRET, DB_PASS...).
 *
 * Safety net: the .env is used only when it holds every key in ENV_REQUIRED. Otherwise the old config/config.php is
 * used exactly as before, so a half-written .env can never take the site down. env_status() reports which one is active.
 */

/** Keys the site cannot run without. A value may be empty (a local database often has no password) but the key must be there. */
const ENV_REQUIRED = ['APP_ENV', 'APP_URL', 'APP_SECRET', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];

/** Every setting the code reads, with what it falls back to when the .env does not mention it. */
const ENV_DEFAULTS = [
    'APP_ENV' => 'production',
    'APP_URL' => '',
    'APP_SECRET' => '',
    'DB_HOST' => 'localhost',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    'IOTEC_CLIENT_ID' => '',
    'IOTEC_CLIENT_SECRET' => '',
    'IOTEC_WALLET_ID' => '',
    'IOTEC_MSG_CLIENT_ID' => '',
    'IOTEC_MSG_API_KEY' => '',
    'RESEND_API_KEY' => '',
    'EMAIL_FROM' => 'obitickets <info@obitickets.site>',
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
    // TURNSTILE_SITE_KEY, TURNSTILE_SECRET_KEY, TRUSTED_IP_HEADER and IPGUARD_ALLOW are checked with defined(), so they
    // are simply left undefined unless the .env sets them (the "extras" loop in env_boot() handles them).
];

/** Settings whose values are secrets (the admin config check reports them as set or empty, and nothing else). */
const ENV_SECRET_KEYS = ['APP_SECRET', 'DB_PASS', 'IOTEC_CLIENT_SECRET', 'IOTEC_MSG_API_KEY', 'RESEND_API_KEY', 'GOOGLE_CLIENT_SECRET', 'TURNSTILE_SECRET_KEY', 'IOTEC_CLIENT_ID', 'IOTEC_WALLET_ID', 'IOTEC_MSG_CLIENT_ID', 'DB_USER'];

/** @return array<string,string> */
function env_parse(string $text): array
{
    $out = [];
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    foreach (preg_split('/\r\n|\n|\r/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $val = trim(substr($line, $eq + 1));
        $n = strlen($val);
        if ($n >= 2 && (($val[0] === '"' && $val[$n - 1] === '"') || ($val[0] === "'" && $val[$n - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        $out[$key] = $val;
    }
    return $out;
}

/** The .env files to try, in order. $root is the project folder (the one that contains config/ and includes/). */
function env_candidates(string $root): array
{
    $list = [];
    $custom = getenv('OBITICKETS_ENV_FILE');
    if (is_string($custom) && $custom !== '') {
        $list[] = $custom;
    }
    $list[] = dirname($root) . DIRECTORY_SEPARATOR . '.env';
    $list[] = $root . DIRECTORY_SEPARATOR . '.env';
    return $list;
}

/**
 * Finds and reads the .env without defining anything.
 * @return array{file:?string,values:array<string,string>,missing:list<string>,complete:bool}
 */
function env_read(string $root): array
{
    foreach (env_candidates($root) as $file) {
        if (@is_file($file) && @is_readable($file)) {
            $values = env_parse((string) @file_get_contents($file));
            $missing = array_values(array_filter(ENV_REQUIRED, static fn ($k) => !array_key_exists($k, $values)));
            return ['file' => $file, 'values' => $values, 'missing' => $missing, 'complete' => $missing === []];
        }
    }
    return ['file' => null, 'values' => [], 'missing' => ENV_REQUIRED, 'complete' => false];
}

/**
 * Defines every setting as a constant. Returns what happened, for env_status().
 * @return array{source:string,file:?string,missing:list<string>,from_env:list<string>}
 */
function env_boot(string $root): array
{
    $env = env_read($root);
    if ($env['complete']) {
        $fromEnv = [];
        foreach (ENV_DEFAULTS as $key => $default) {
            if (!defined($key)) {
                define($key, array_key_exists($key, $env['values']) ? $env['values'][$key] : $default);
            }
            if (array_key_exists($key, $env['values'])) {
                $fromEnv[] = $key;
            }
        }
        foreach ($env['values'] as $key => $value) {
            if (!defined($key)) {            // optional extras, e.g. SALES_NOTIFY_EMAIL, SUPPORT_WHATSAPP_NUMBER
                define($key, $value);
                $fromEnv[] = $key;
            }
        }
        return ['source' => 'env', 'file' => $env['file'], 'missing' => [], 'from_env' => $fromEnv];
    }

    $legacy = $root . '/config/config.php';
    if (is_file($legacy)) {
        require_once $legacy;
        return ['source' => 'config.php', 'file' => $env['file'], 'missing' => $env['file'] ? $env['missing'] : [], 'from_env' => []];
    }

    http_response_code(500);
    error_log('[config] No usable .env (missing: ' . implode(',', $env['missing']) . ') and no config/config.php');
    exit('The site is not configured yet.');
}

/** What the running site is using. Never contains a value. */
function env_status(): array
{
    return $GLOBALS['env_status'] ?? ['source' => 'unknown', 'file' => null, 'missing' => [], 'from_env' => []];
}
