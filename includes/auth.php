<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_valid(string $token): bool
{
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !csrf_valid($token)) {
        http_response_code(403);
        exit('Your form session expired. Please go back and try again.');
    }
}

/** The logged-in user's row, or null. Cached for the life of the request. */
function current_user(): ?array
{
    static $cached = false; // false = "not looked up yet", null = "looked up, no user"
    if ($cached !== false) {
        return $cached;
    }
    if (empty($_SESSION['user_id'])) {
        return $cached = null;
    }
    $stmt = db()->prepare('SELECT id, name, email, phone, role, admin_role, account_status, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || $user['account_status'] === 'SUSPENDED') {
        unset($_SESSION['user_id']);
        return $cached = null;
    }
    return $cached = $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: /login.php?next=' . $next);
        exit;
    }
    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    if ($user['role'] !== $role && $user['role'] !== 'ADMIN') {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
    return $user;
}

/**
 * @return array{0: bool, 1: ?string} [success, errorMessage]
 */
function register_user(string $name, string $email, string $password, string $role, ?string $phone = null): array
{
    $email = strtolower(trim($email));

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [false, 'An account with this email already exists.'];
    }

    $role = $role === 'ORGANIZER' ? 'ORGANIZER' : 'ATTENDEE';
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = db()->prepare('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $email, $phone, $hash, $role]);
    $userId = (int) db()->lastInsertId();

    if ($role === 'ORGANIZER') {
        $stmt = db()->prepare('INSERT INTO organizer_profiles (user_id, org_name) VALUES (?, ?)');
        $stmt->execute([$userId, $name]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    return [true, null];
}

/**
 * @return array{0: bool, 1: ?string} [success, errorMessage]
 */
function attempt_login(string $email, string $password): array
{
    $stmt = db()->prepare('SELECT id, password_hash, account_status FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [false, 'Incorrect email or password.'];
    }
    if ($user['account_status'] === 'SUSPENDED') {
        return [false, 'This account has been suspended. Contact support for help.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

    return [true, null];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
