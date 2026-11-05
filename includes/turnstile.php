<?php
declare(strict_types=1);

/**
 * Cloudflare Turnstile: a free, mostly invisible "are you a person" check on sign-up and Continue with Google, so
 * scripts cannot mass-create accounts. Inert until BOTH keys exist in the server's config/config.php:
 *
 *   define('TURNSTILE_SITE_KEY', '0x4AAAA...');   // Cloudflare dashboard > Turnstile > Add widget (hostname obitickets.site)
 *   define('TURNSTILE_SECRET_KEY', '0x4AAAA...');
 *
 * While the keys are missing nothing changes for visitors. Once set, the widget on the sign-up and login pages posts
 * its token to /api/human-check.php, which remembers "this browser passed" in the session for 30 minutes; sign-up and
 * /auth/google.php then require that.
 */

const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const TURNSTILE_PASS_SECONDS = 1800;

function turnstile_enabled(): bool
{
    return defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== '' && defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '';
}

/** True when this browser has passed the check recently, or the check is not switched on. */
function human_ok(): bool
{
    if (!turnstile_enabled()) {
        return true;
    }
    return (int) ($_SESSION['human_until'] ?? 0) > time();
}

function human_mark_passed(): void
{
    $_SESSION['human_until'] = time() + TURNSTILE_PASS_SECONDS;
}

/**
 * Asks Cloudflare whether the token is genuine. $http is injectable for tests: fn(string $url, array $post): ?array
 * (decoded JSON, or null when Cloudflare could not be reached). If Cloudflare itself is unreachable the check
 * lets the visitor through and logs it, so an outage at Cloudflare cannot lock everyone out of signing up.
 */
function turnstile_verify(string $token, ?callable $http = null): bool
{
    if (!turnstile_enabled()) {
        return true;
    }
    $token = trim($token);
    if ($token === '' || strlen($token) > 4096) {
        return false;
    }
    $post = ['secret' => TURNSTILE_SECRET_KEY, 'response' => $token];
    $res = $http ? $http(TURNSTILE_VERIFY_URL, $post) : turnstile_http(TURNSTILE_VERIFY_URL, $post);
    if ($res === null) {
        error_log('[turnstile] Cloudflare unreachable; letting the visitor through');
        return true;
    }
    return !empty($res['success']);
}

function turnstile_http(string $url, array $post): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) {
        return null;
    }
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

/** The widget plus the little script that reports a pass to the server. Renders nothing until Turnstile is configured. */
function render_human_check(): void
{
    if (!turnstile_enabled() || human_ok()) {
        return;
    }
    ?>
    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars((string) TURNSTILE_SITE_KEY, ENT_QUOTES) ?>" data-callback="obiHumanPassed" data-appearance="interaction-only" style="margin:0 0 14px"></div>
    <noscript><div class="alert alert-error">Please turn on JavaScript to sign up or log in with Google.</div></noscript>
    <script>
      window.obiHumanPassed = function (token) {
        var m = document.querySelector('meta[name="csrf-token"]');
        fetch('/api/human-check.php', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({token: token, csrf_token: m ? m.content : ''})
        }).catch(function () {});
      };
    </script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php
}
