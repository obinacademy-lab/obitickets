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

/** Of those, the ones allowed to be blank (a local database often has no password). Every other required key needs a value. */
const ENV_MAY_BE_EMPTY = ['DB_PASS'];

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
            // "missing" means absent, or present but blank (a blank template must never be mistaken for a finished file).
            $missing = array_values(array_filter(ENV_REQUIRED, static fn ($k) => !array_key_exists($k, $values) || ($values[$k] === '' && !in_array($k, ENV_MAY_BE_EMPTY, true))));
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
        return ['source' => 'env', 'file' => $env['file'], 'missing' => [], 'from_env' => $fromEnv, 'root' => $root];
    }

    $legacy = $root . '/config/config.php';
    if (is_file($legacy)) {
        require_once $legacy;
        if (!defined('DB_NAME') || !defined('APP_SECRET')) {   // an emptied config.php is not a configuration
            http_response_code(500);
            error_log('[config] config/config.php defines nothing and no usable .env was found');
            exit('The site is not configured yet.');
        }
        return ['source' => 'config.php', 'file' => $env['file'], 'missing' => $env['file'] ? $env['missing'] : [], 'from_env' => [], 'root' => $root];
    }

    http_response_code(500);
    error_log('[config] No usable .env (missing: ' . implode(',', $env['missing']) . ') and no config/config.php');
    exit('The site is not configured yet.');
}

/** What the running site is using. Never contains a value. */
function env_status(): array
{
    return $GLOBALS['env_status'] ?? ['source' => 'unknown', 'file' => null, 'missing' => [], 'from_env' => [], 'root' => ''];
}

/** Where a new .env belongs: one folder above the project folder (above public_html on the live server). */
function env_target_file(string $root): string
{
    return dirname($root) . DIRECTORY_SEPARATOR . '.env';
}

/**
 * Turns settings into the text of a .env file. Returns [text, problems]; a setting that cannot be written safely
 * (it contains a line break, or both kinds of quote) is listed in problems and left out, never half-written.
 * @param array<string,string> $values
 * @return array{0:string,1:list<string>}
 */
function env_compose(array $values): array
{
    $lines = ['# obitickets settings and secrets. Created from the site\'s previous settings. Keep this file outside public_html and NEVER commit it.'];
    $problems = [];
    foreach ($values as $key => $value) {
        $value = (string) $value;
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key) || preg_match('/[\r\n]/', $value)) {
            $problems[] = (string) $key;
            continue;
        }
        if ($value === '') {
            $lines[] = $key . '=';
        } elseif (strpos($value, "'") === false) {
            $lines[] = $key . "='" . $value . "'";
        } elseif (strpos($value, '"') === false) {
            $lines[] = $key . '="' . $value . '"';
        } else {
            $problems[] = (string) $key;
        }
    }
    return [implode("\n", $lines) . "\n", $problems];
}

/**
 * Writes a new .env from the given settings, reads it back, and keeps it only if it is complete and identical.
 * Never overwrites an existing file. Returns null on success, or a plain-language reason it did not happen.
 * @param array<string,string> $values
 */
function env_create_file(string $root, array $values): ?string
{
    $target = env_target_file($root);
    if (@is_file($target)) {
        return 'A .env file already exists there, so nothing was created.';
    }
    if (@is_dir($target)) {
        // A FOLDER named .env (made by mistake in File Manager) is in the way. An empty one holds nothing, so it is removed; a folder with anything in it is never touched.
        $inside = array_diff((array) @scandir($target), ['.', '..']);
        if ($inside || !@rmdir($target)) {
            return 'A folder named .env is in the way at ' . $target . ($inside ? ' and it is not empty, so it was left alone' : ' and could not be removed') . '. In File Manager, delete that folder, then try again.';
        }
    }
    if (!@is_writable(dirname($target))) {
        return 'The server does not let the website write to ' . dirname($target) . '. Create the .env by hand in File Manager instead (config/env.example is the template).';
    }
    [$text, $problems] = env_compose($values);
    if ($problems) {
        return 'These settings cannot be written safely and nothing was created: ' . implode(', ', $problems) . '. Create the .env by hand instead.';
    }
    // Some hosting filesystems refuse the locking flag, so try with it first and then plainly.
    if (@file_put_contents($target, $text, LOCK_EX) === false && @file_put_contents($target, $text) === false) {
        $why = (string) (error_get_last()['message'] ?? 'unknown');
        error_log('[env] could not write ' . $target . ': ' . $why);
        return 'The file could not be written (' . preg_replace('/^.*?: /', '', mb_substr($why, 0, 160)) . '). Create it by hand in File Manager instead.';
    }
    @chmod($target, 0600);
    $back = env_parse((string) @file_get_contents($target));
    $same = true;
    foreach ($values as $k => $v) {
        $same = $same && (($back[$k] ?? null) === (string) $v);
    }
    $missing = array_filter(ENV_REQUIRED, static fn ($k) => !array_key_exists($k, $back) || ($back[$k] === '' && !in_array($k, ENV_MAY_BE_EMPTY, true)));
    if (!$same || $missing) {
        @unlink($target);   // only ever the file this call just made
        return $missing ? 'The new file would be incomplete (' . implode(', ', $missing) . '), so it was removed. Nothing changed.' : 'The new file did not read back correctly, so it was removed. Nothing changed.';
    }
    return null;
}

/**
 * Reads the define('NAME', 'value') lines of an old config.php WITHOUT running it (so nothing is executed and no
 * constant is redefined). Only plain string and number values are understood.
 * @return array<string,string>
 */
function env_legacy_values(string $file): array
{
    $out = [];
    $src = @file_get_contents($file);
    if ($src === false) {
        return $out;
    }
    $tokens = array_values(array_filter(token_get_all($src), static fn ($t) => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
    $str = static function ($t): ?string {
        if (!is_array($t)) {
            return null;
        }
        if ($t[0] === T_LNUMBER || $t[0] === T_DNUMBER) {
            return (string) $t[1];
        }
        if ($t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $raw = $t[1];
        return $raw[0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($raw, 1, -1)) : stripcslashes(substr($raw, 1, -1));
    };
    for ($i = 0, $n = count($tokens); $i < $n - 5; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && strtolower($tokens[$i][1]) === 'define' && $tokens[$i + 1] === '(') {
            $k = $str($tokens[$i + 2]);
            $v = ($tokens[$i + 3] === ',' && $tokens[$i + 5] === ')') ? $str($tokens[$i + 4]) : null;   // only define('A', 'literal'): 'a' . 'b' is skipped, not half-read
            if ($k !== null && $v !== null) {
                $out[$k] = $v;
            }
        }
    }
    return $out;
}

/**
 * Compares two sets of settings key by key and says only whether each matches, never what the value is.
 * @param array<string,string> $a the .env
 * @param array<string,string> $b the old config.php
 * @return array<string,string> key => 'same' | 'differs' | 'only in .env' | 'only in config.php'
 */
function env_compare(array $a, array $b): array
{
    $out = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        $out[$k] = !array_key_exists($k, $b) ? 'only in .env' : (!array_key_exists($k, $a) ? 'only in config.php' : ($a[$k] === $b[$k] ? 'same' : 'differs'));
    }
    return $out;
}
