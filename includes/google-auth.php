<?php
declare(strict_types=1);

/**
 * "Continue with Google" — OAuth 2.0 authorization-code flow with PKCE and an
 * OpenID Connect id_token, in plain PHP (no libraries).
 *
 * Everything here is inert until GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are
 * defined in config/config.php: google_login_enabled() is false, the buttons
 * don't render and /auth/google.php just sends people back to the login page.
 * So this file can be deployed before the keys exist without changing anything.
 */

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_STATE_TTL = 600;      // seconds a started sign-in stays valid
const GOOGLE_LINK_TTL = 900;       // seconds a "log in with your password to connect Google" prompt stays valid

class GoogleAuthException extends RuntimeException
{
}

function google_login_enabled(): bool
{
    return defined('GOOGLE_CLIENT_ID') && defined('GOOGLE_CLIENT_SECRET')
        && GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

/** Must match, character for character, a redirect URI registered in Google Cloud Console. */
function google_redirect_uri(): string
{
    return rtrim(APP_URL, '/') . '/auth/google-callback.php';
}

function google_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * Starts a sign-in: stores one-time state/nonce/PKCE values in the session and
 * returns the Google URL to send the browser to.
 */
function google_begin(string $mode, ?string $next, string $role): string
{
    $verifier = google_base64url(random_bytes(48));
    $state = bin2hex(random_bytes(24));
    $nonce = bin2hex(random_bytes(16));

    $_SESSION['google_oauth'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'mode' => $mode === 'signup' ? 'signup' : 'login',
        'role' => $role === 'ORGANIZER' ? 'ORGANIZER' : 'ATTENDEE',
        'next' => safe_next_path($next),
        'started' => time(),
    ];

    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => google_base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** @return array{0:int,1:string} [HTTP status, raw body] */
function google_http_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new GoogleAuthException('Google token request failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, (string) $body];
}

/**
 * Trades the one-time code for tokens and returns the verified identity claims.
 * The id_token arrives straight from Google's token endpoint over TLS, so (per
 * Google's guidance) its signature need not be re-checked — but issuer,
 * audience, expiry and our nonce are.
 *
 * @param array{state:string,nonce:string,verifier:string} $session
 * @param ?callable $post test seam: fn(string $url, array $fields): array{0:int,1:string}
 * @return array{sub:string,email:string,name:string}
 */
function google_exchange_code(string $code, array $session, ?callable $post = null): array
{
    $post ??= 'google_http_post';
    [$status, $body] = $post(GOOGLE_TOKEN_URL, [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
        'code_verifier' => $session['verifier'],
    ]);

    $data = json_decode($body, true);
    if ($status !== 200 || !is_array($data) || empty($data['id_token'])) {
        throw new GoogleAuthException('Google rejected the sign-in (HTTP ' . $status . ').');
    }

    $parts = explode('.', (string) $data['id_token']);
    if (count($parts) !== 3) {
        throw new GoogleAuthException('Malformed id_token.');
    }
    $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!is_array($claims)) {
        throw new GoogleAuthException('Unreadable id_token.');
    }

    if (!in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
        throw new GoogleAuthException('id_token has the wrong issuer.');
    }
    if (($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
        throw new GoogleAuthException('id_token was issued for a different app.');
    }
    if ((int) ($claims['exp'] ?? 0) < time()) {
        throw new GoogleAuthException('id_token has expired.');
    }
    if (!hash_equals($session['nonce'], (string) ($claims['nonce'] ?? ''))) {
        throw new GoogleAuthException('id_token nonce mismatch.');
    }
    if (empty($claims['sub']) || empty($claims['email'])) {
        throw new GoogleAuthException('id_token is missing the account details.');
    }
    if (!filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        throw new GoogleAuthException('Your Google email address is not verified.');
    }

    $email = strtolower(trim((string) $claims['email']));
    $name = trim((string) ($claims['name'] ?? ''));
    return [
        'sub' => (string) $claims['sub'],
        'email' => $email,
        'name' => $name !== '' ? $name : ucfirst((string) strstr($email, '@', true)),
    ];
}

/**
 * Decides what a verified Google identity means for our users table.
 *
 *  - ok:                  signed in (existing Google-linked user, or a brand-new account)
 *  - needs_password_link: an email+password account already uses this email. We
 *                         DON'T merge automatically — sign-up never verified
 *                         that mailbox, so someone could have pre-registered
 *                         the victim's email with a password they know. The
 *                         person proves ownership by logging in with the
 *                         password once (see google_link_pending_account()).
 *  - error:               suspended, admin, or linked to a different Google account
 *
 * @param array{sub:string,email:string,name:string} $claims
 * @return array{status:string, user_id?:int, role?:string, created?:bool, message?:string, email?:string}
 */
function google_resolve_user(array $claims, string $role): array
{
    $adminMsg = 'Admin accounts must log in with their email and password.';
    $suspendedMsg = 'This account has been suspended. Contact support for help.';

    $stmt = db()->prepare('SELECT id, role, account_status, totp_enabled, google_id FROM users WHERE google_id = ?');
    $stmt->execute([$claims['sub']]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = db()->prepare('SELECT id, role, account_status, totp_enabled, google_id FROM users WHERE email = ?');
        $stmt->execute([$claims['email']]);
        $byEmail = $stmt->fetch();

        if (!$byEmail) {
            return ['status' => 'ok', 'created' => true] + google_create_user($claims, $role);
        }
        if ($byEmail['role'] === 'ADMIN' || (int) $byEmail['totp_enabled'] === 1) {
            return ['status' => 'error', 'message' => $adminMsg];
        }
        if ($byEmail['account_status'] === 'SUSPENDED') {
            return ['status' => 'error', 'message' => $suspendedMsg];
        }
        if ($byEmail['google_id'] !== null) {
            return ['status' => 'error', 'message' => 'This email is linked to a different Google account.'];
        }
        $_SESSION['google_link_pending'] = ['sub' => $claims['sub'], 'email' => $claims['email'], 'time' => time()];
        return ['status' => 'needs_password_link', 'email' => $claims['email']];
    }

    if ($user['role'] === 'ADMIN' || (int) $user['totp_enabled'] === 1) {
        return ['status' => 'error', 'message' => $adminMsg];
    }
    if ($user['account_status'] === 'SUSPENDED') {
        return ['status' => 'error', 'message' => $suspendedMsg];
    }
    return ['status' => 'ok', 'created' => false, 'user_id' => (int) $user['id'], 'role' => $user['role']];
}

/**
 * New account straight from Google. The password hash is a random value nobody
 * knows (the person can set a real one with "Forgot password" later); Google
 * has already verified the email address, so email_verified_at is set.
 * @param array{sub:string,email:string,name:string} $claims
 * @return array{user_id:int, role:string}
 */
function google_create_user(array $claims, string $role): array
{
    $role = $role === 'ORGANIZER' ? 'ORGANIZER' : 'ATTENDEE';
    $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, google_id, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        mb_substr($claims['name'], 0, 191),
        $claims['email'],
        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        $role,
        $claims['sub'],
    ]);
    $userId = (int) db()->lastInsertId();

    if ($role === 'ORGANIZER') {
        $stmt = db()->prepare('INSERT INTO organizer_profiles (user_id, org_name, slug) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $claims['name'], generate_unique_organizer_slug($claims['name'])]);
    }
    return ['user_id' => $userId, 'role' => $role];
}

/** Puts the browser in a logged-in session for $userId (new session id, login log row). */
function google_sign_in(int $userId, string $role, bool $created): void
{
    session_regenerate_id(true);
    unset($_SESSION['google_oauth']);
    if ($created) {
        $_SESSION['user_id'] = $userId;
        log_login_event($userId, $role, 'SIGNUP');
        return;
    }
    finalize_login($userId, $role);
}

/**
 * Called by login.php right after a correct email+password. If this person had
 * just come from Google and was told to prove ownership of the matching
 * account, the Google id is attached now — proof is the password they typed.
 */
function google_link_pending_account(string $email): void
{
    $pending = $_SESSION['google_link_pending'] ?? null;
    if (!$pending) {
        return;
    }
    unset($_SESSION['google_link_pending']);
    if (time() - (int) $pending['time'] > GOOGLE_LINK_TTL || strtolower(trim($email)) !== $pending['email']) {
        return;
    }
    try {
        db()->prepare('UPDATE users SET google_id = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE email = ? AND google_id IS NULL')
            ->execute([$pending['sub'], $pending['email']]);
    } catch (Throwable $e) {
        error_log('[google] could not link account: ' . $e->getMessage());
    }
}

/** The "Continue with Google" button + divider; renders nothing until Google is configured. */
function render_google_button(string $mode, string $next = '', string $role = 'ATTENDEE'): void
{
    if (!google_login_enabled()) {
        return;
    }
    $href = '/auth/google.php?' . http_build_query(array_filter([
        'mode' => $mode,
        'next' => $next !== '' && $next !== '/dashboard.php' ? $next : null,
        'role' => $mode === 'signup' && $role === 'ORGANIZER' ? 'ORGANIZER' : null,
    ]));
    ?>
    <a class="btn-google" id="googleBtn" href="<?= htmlspecialchars($href) ?>">
      <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.6 5.9c4.4-4.1 7-10.1 7-17.6z"/><path fill="#FBBC05" d="M10.5 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.4 0 20.1 0 24s.9 7.6 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.9 2.3-8.3 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
      Continue with Google
    </a>
    <div class="auth-or"><span>or</span></div>
    <?php
}
