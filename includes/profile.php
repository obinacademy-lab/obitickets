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
