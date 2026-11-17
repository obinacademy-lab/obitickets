<?php
declare(strict_types=1);

/**
 * Profile pictures and the details a user can edit about themselves (name, phone, password).
 * The avatar column is users.avatar_url: a public path like /uploads/avatars/<32 hex>.jpg, or null for "use initials".
 */

const AVATAR_MAX_BYTES = 5 * 1024 * 1024;   // 5MB
const AVATAR_SIZE = 320;                    // stored as a square this many pixels wide
const AVATAR_MAX_PIXELS = 40000000;         // refuse absurdly large images before decoding them (memory)
const AVATAR_PATH_PATTERN = '#^/uploads/avatars/[a-f0-9]{32}\.jpg$#';

/** The user's current picture path, or null. Cached for the request. */
function user_avatar_url(int $userId): ?string
{
    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        try {
            $stmt = db()->prepare('SELECT avatar_url FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $v = $stmt->fetchColumn();
            $cache[$userId] = ($v !== false && is_string($v) && $v !== '') ? $v : null;
        } catch (Throwable $e) {
            $cache[$userId] = null;
        }
    }
    return $cache[$userId];
}

/** A round picture, or the person's initials when they have none. */
function render_avatar_html(?string $url, string $name, int $size = 36, string $extraClass = ''): string
{
    $cls = 'pf-av' . ($extraClass !== '' ? ' ' . $extraClass : '');
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * 0.38) . 'px';
    if ($url !== null && $url !== '') {
        return '<img class="' . $cls . '" src="' . htmlspecialchars($url) . '" alt="" width="' . $size . '" height="' . $size . '" style="' . $style . '" loading="lazy">';
    }
    return '<span class="' . $cls . ' pf-av-i" aria-hidden="true" style="' . $style . '">' . htmlspecialchars(initials_from_name($name)) . '</span>';
}

/**
 * Validates an uploaded picture and stores it as a 320px square JPEG. The real type comes from the file's own
 * bytes, the picture is decoded and re-encoded (so nothing hidden in it survives, including location data), and the
 * stored name is random.
 * @param array $file one entry of $_FILES
 * @return array{0:bool,1:?string,2:?string} [ok, publicPath, error]
 */
function handle_avatar_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [false, null, 'Choose a picture first.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [false, null, 'That picture is too large. Please use one under 5MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, 'There was a problem uploading that picture. Please try again.'];
    }
    if ($file['size'] > AVATAR_MAX_BYTES) {
        return [false, null, 'That picture is too large. Please use one under 5MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'That upload could not be verified. Please try again.'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return [false, null, 'Pictures cannot be processed on this server right now.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return [false, null, 'Please upload a JPG, PNG, WEBP or GIF picture.'];
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > AVATAR_MAX_PIXELS) {
        return [false, null, 'That picture has too many pixels. Please use a smaller one.'];
    }
    if ($info[0] < 80 || $info[1] < 80) {
        return [false, null, 'That picture is too small. Please use one at least 80 pixels wide and tall.'];
    }

    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        return [false, null, "That file doesn't look like a valid picture."];
    }

    // phones save photos sideways with an orientation tag: turn them upright first
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $orient = (int) ($exif['Orientation'] ?? 1);
        $rotate = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
        if ($rotate !== 0) {
            $rotated = imagerotate($src, $rotate, 0);
            if ($rotated) {
                imagedestroy($src);
                $src = $rotated;
            }
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $side = min($w, $h);
    $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNGs get a white background
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    imagedestroy($src);

    $destDir = __DIR__ . '/../uploads/avatars';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        imagedestroy($dst);
        return [false, null, 'Could not prepare storage for the picture. Please try again.'];
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = imagejpeg($dst, $destDir . '/' . $name, 86);
    imagedestroy($dst);
    if (!$ok) {
        return [false, null, 'Could not save the picture. Please try again.'];
    }
    return [true, '/uploads/avatars/' . $name, null];
}

/** Removes a stored picture file. Only ever deletes files this feature wrote (the path pattern is checked). */
function delete_avatar_file(?string $publicPath): void
{
    if ($publicPath === null || !preg_match(AVATAR_PATH_PATTERN, $publicPath)) {
        return;
    }
    $full = __DIR__ . '/../public' . $publicPath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Saves a new picture for the user (and removes the old file). @return array{0:bool,1:?string} [ok, error] */
function set_user_avatar(int $userId, array $file): array
{
    [$ok, $path, $err] = handle_avatar_upload($file);
    if (!$ok) {
        return [false, $err];
    }
    $old = user_avatar_url($userId);
    db()->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$path, $userId]);
    delete_avatar_file($old);
    return [true, null];
}

function remove_user_avatar(int $userId): void
{
    $old = user_avatar_url($userId);
    db()->prepare('UPDATE users SET avatar_url = NULL WHERE id = ?')->execute([$userId]);
    delete_avatar_file($old);
}

// =====================================================================
// Cover photos (11/18): the wide picture at the top of a profile. users.cover_url (migration 035), a public path
// like /uploads/covers/<32 hex>.jpg, or null. Same checks as profile pictures; stored as a 1500 x 600 JPEG.
// =====================================================================
const COVER_MAX_BYTES = 8 * 1024 * 1024;   // 8MB
const COVER_W = 1500;
const COVER_H = 600;
const COVER_PATH_PATTERN = '#^/uploads/covers/[a-f0-9]{32}\.jpg$#';

/** True once migration 035 is in (cover photos, organizer headline and location). */
function profile_covers_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW COLUMNS FROM users LIKE 'cover_url'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function user_cover_url(int $userId): ?string
{
    if (!profile_covers_ready()) {
        return null;
    }
    $stmt = db()->prepare('SELECT cover_url FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return (is_string($v) && preg_match(COVER_PATH_PATTERN, $v)) ? $v : null;
}

/** @return array{0:bool,1:?string,2:?string} [ok, publicPath, error] */
function handle_cover_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [false, null, 'Choose a picture first.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > COVER_MAX_BYTES) {
        return [false, null, 'That picture is too large. Please use one under 8MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'There was a problem uploading that picture. Please try again.'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return [false, null, 'Pictures cannot be processed on this server right now.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return [false, null, 'Please upload a JPG, PNG, WEBP or GIF picture.'];
    }
    if ($info[0] * $info[1] > AVATAR_MAX_PIXELS) {
        return [false, null, 'That picture has too many pixels. Please use a smaller one.'];
    }
    if ($info[0] < 600 || $info[1] < 200) {
        return [false, null, 'That picture is too small for a cover. Please use one at least 600 pixels wide.'];
    }
    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        return [false, null, "That file doesn't look like a valid picture."];
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rotate = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($rotate !== 0 && ($r = imagerotate($src, $rotate, 0))) {
            imagedestroy($src);
            $src = $r;
        }
    }
    // centre crop to 5:2, then scale
    $w = imagesx($src);
    $h = imagesy($src);
    $want = COVER_W / COVER_H;
    if ($w / $h > $want) { $cw = (int) round($h * $want); $ch = $h; } else { $cw = $w; $ch = (int) round($w / $want); }
    $dst = imagecreatetruecolor(COVER_W, COVER_H);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 11, 7, 8));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), COVER_W, COVER_H, $cw, $ch);
    imagedestroy($src);
    $dir = __DIR__ . '/../uploads/covers';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($dst);
        return [false, null, 'Could not prepare storage for the picture. Please try again.'];
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = imagejpeg($dst, $dir . '/' . $name, 84);
    imagedestroy($dst);
    return $ok ? [true, '/uploads/covers/' . $name, null] : [false, null, 'Could not save the picture. Please try again.'];
}

function delete_cover_file(?string $publicPath): void
{
    if ($publicPath !== null && preg_match(COVER_PATH_PATTERN, $publicPath)) {
        $full = __DIR__ . '/../public' . $publicPath;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/** @return array{0:bool,1:?string} [ok, error] */
function set_user_cover(int $userId, array $file): array
{
    if (!profile_covers_ready()) {
        return [false, 'Cover photos are not switched on yet.'];
    }
    [$ok, $path, $err] = handle_cover_upload($file);
    if (!$ok) {
        return [false, $err];
    }
    $old = user_cover_url($userId);
    db()->prepare('UPDATE users SET cover_url = ? WHERE id = ?')->execute([$path, $userId]);
    delete_cover_file($old);
    return [true, null];
}

function remove_user_cover(int $userId): void
{
    if (!profile_covers_ready()) {
        return;
    }
    $old = user_cover_url($userId);
    db()->prepare('UPDATE users SET cover_url = NULL WHERE id = ?')->execute([$userId]);
    delete_cover_file($old);
}

/** An organizer's short line under their name: what they are, and where. @return array{headline:string,location:string} */
function organizer_tagline(int $userId): array
{
    $out = ['headline' => '', 'location' => ''];
    if (!profile_covers_ready()) {
        return $out;
    }
    $stmt = db()->prepare('SELECT headline, location FROM organizer_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $r = $stmt->fetch();
    if ($r) {
        $out['headline'] = trim((string) ($r['headline'] ?? ''));
        $out['location'] = trim((string) ($r['location'] ?? ''));
    }
    return $out;
}

/** Saves the short line (each part up to 60 characters, plain text). */
function set_organizer_tagline(int $userId, string $headline, string $location): void
{
    if (!profile_covers_ready()) {
        return;
    }
    $clean = static fn (string $v): ?string => ($v = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', '', $v) ?? ''), 0, 60)) !== '' ? $v : null;
    db()->prepare('UPDATE organizer_profiles SET headline = ?, location = ? WHERE user_id = ?')->execute([$clean($headline), $clean($location), $userId]);
}

// =====================================================================
// @username and bio (Phase 4, 11/18; migration 036). Usernames: 3-24 characters, lowercase letters, numbers,
// dots and underscores, unique, not a reserved word.
// =====================================================================
const USERNAME_RESERVED = ['admin', 'administrator', 'obitickets', 'support', 'help', 'root', 'system', 'staff', 'moderator', 'official', 'null', 'me', 'settings', 'login', 'signup'];

function profile_about_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** @return array{username:string,bio:string} */
function user_about(int $userId): array
{
    if (!profile_about_ready()) {
        return ['username' => '', 'bio' => ''];
    }
    $st = db()->prepare('SELECT username, bio FROM users WHERE id = ?');
    $st->execute([$userId]);
    $r = $st->fetch() ?: [];
    return ['username' => (string) ($r['username'] ?? ''), 'bio' => (string) ($r['bio'] ?? '')];
}

/**
 * Checks and saves the @username and bio. An empty username clears it.
 * @return array<string,string> errors by field (empty when saved)
 */
function set_user_about(int $userId, string $username, string $bio): array
{
    if (!profile_about_ready()) {
        return ['username' => 'Usernames are not switched on yet.'];
    }
    $errors = [];
    $username = strtolower(trim(ltrim(trim($username), '@')));
    if ($username !== '') {
        if (!preg_match('/^[a-z0-9._]{3,24}$/', $username)) {
            $errors['username'] = 'Use 3 to 24 letters, numbers, dots or underscores (no spaces).';
        } elseif (str_starts_with($username, '.') || str_ends_with($username, '.') || str_contains($username, '..')) {
            $errors['username'] = 'A username cannot start or end with a dot, or have two dots together.';
        } elseif (in_array($username, USERNAME_RESERVED, true)) {
            $errors['username'] = 'That username is reserved. Please choose another.';
        } else {
            $st = db()->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
            $st->execute([$username, $userId]);
            if ($st->fetchColumn()) {
                $errors['username'] = 'That username is taken. Please choose another.';
            }
        }
    }
    $bio = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F<>]+/u', '', $bio) ?? '');   // single quotes: PCRE reads the escapes
    $bio = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $bio)) ?? '';
    if (mb_strlen($bio) > 160) {
        $errors['bio'] = 'Your bio can be at most 160 characters.';
    }
    if ($errors) {
        return $errors;
    }
    try {
        db()->prepare('UPDATE users SET username = ?, bio = ? WHERE id = ?')->execute([$username !== '' ? $username : null, $bio !== '' ? $bio : null, $userId]);
    } catch (PDOException $e) {
        return ['username' => 'That username is taken. Please choose another.'];   // a race on the unique index
    }
    return [];
}

/**
 * Checks the editable details. Name 2 to 80 characters; phone optional, 9 to 15 digits (a leading + and spaces,
 * dashes and brackets are allowed and tidied).
 * @return array{0:array<string,string>,1:array{name:string,phone:?string}} [errors by field, cleaned values]
 */
function validate_profile_details(string $name, string $phone): array
{
    $errors = [];
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $len = mb_strlen($name);
    if ($len < 2) {
        $errors['name'] = 'Please enter your name.';
    } elseif ($len > 80) {
        $errors['name'] = 'Your name can be at most 80 characters.';
    } elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
        $errors['name'] = 'Your name contains characters that are not allowed.';
    }

    $phone = trim($phone);
    $cleanPhone = null;
    if ($phone !== '') {
        if (!preg_match('/^\+?[0-9 ()\-]{9,24}$/', $phone)) {
            $errors['phone'] = 'Enter a phone number like 0772 123 456 or +256 772 123 456.';
        } else {
            $digits = preg_replace('/\D/', '', $phone) ?? '';
            if (strlen($digits) < 9 || strlen($digits) > 15) {
                $errors['phone'] = 'Enter a phone number like 0772 123 456 or +256 772 123 456.';
            } else {
                $cleanPhone = (str_starts_with($phone, '+') ? '+' : '') . $digits;
            }
        }
    }
    return [$errors, ['name' => $name, 'phone' => $cleanPhone]];
}

function update_own_profile(int $userId, string $name, ?string $phone): void
{
    db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $userId]);
}

/** The inside of a small round avatar badge in the admin and organizer layouts: the picture, or initials. */
function avatar_badge_inner(int $userId, string $name): string
{
    $url = user_avatar_url($userId);
    if ($url !== null) {
        return '<img src="' . htmlspecialchars($url) . '" alt="" width="32" height="32">';
    }
    return htmlspecialchars(initials_from_name($name));
}
