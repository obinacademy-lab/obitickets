<?php
// Sounds for Moments. Two sources: the LIBRARY (tracks a platform admin has the rights to) and
// USER sounds organizers upload themselves after confirming they have permission to use them.
// The beat (bpm + where the first beat falls) is found in the uploader's browser (assets/js/beat.js)
// and stored here; the server only checks the file is genuinely audio and clamps the numbers.

const SOUND_MAX_BYTES = 12 * 1024 * 1024;
const SOUND_MAX_SECONDS = 600;
const SOUND_MIME_EXTENSIONS = [
    'audio/mpeg' => 'mp3',
    'audio/mp3' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/m4a' => 'm4a',
    'audio/aac' => 'aac',
    'audio/x-wav' => 'wav',
    'audio/wav' => 'wav',
    'audio/wave' => 'wav',
    'audio/ogg' => 'ogg',
    'application/ogg' => 'ogg',
];
const SOUND_USER_LIMIT = 40; // sounds one organizer can keep

/** True once migration 018 has been run. */
function sounds_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM sounds LIMIT 0');
            db()->query('SELECT sound_id, slide_beats FROM event_moments LIMIT 0');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Checks and stores an audio upload. $isUpload=false is the test seam.
 * @return array{ok:bool, path?:string, error?:string}
 */
function sound_store_file(array $file, bool $isUpload = true, string $originalName = ''): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Choose an audio file first.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That audio file is too large for this server (limit ' . round(SOUND_MAX_BYTES / 1048576) . ' MB).'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => "We couldn't upload that audio. Please try again."];
    }
    $tmp = (string) $file['tmp_name'];
    if ($isUpload && !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => "That upload couldn't be verified. Please try again."];
    }
    if ((int) $file['size'] > SOUND_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Audio files must be under ' . round(SOUND_MAX_BYTES / 1048576) . ' MB.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $ext = SOUND_MIME_EXTENSIONS[$mime] ?? null;
    // Some encoders label M4A as video/mp4: accept that only when the name says it is audio.
    if ($ext === null && $mime === 'video/mp4' && preg_match('/\.(m4a|aac)$/i', $originalName ?: (string) ($file['name'] ?? ''))) {
        $ext = 'm4a';
    }
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Please use an MP3, M4A, WAV or OGG audio file.'];
    }
    $dir = __DIR__ . '/../uploads/sounds';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => "We couldn't prepare storage. Please try again."];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $isUpload ? move_uploaded_file($tmp, $dir . '/' . $name) : rename($tmp, $dir . '/' . $name);
    if (!$moved) {
        return ['ok' => false, 'error' => "We couldn't save that audio. Please try again."];
    }
    return ['ok' => true, 'path' => '/uploads/sounds/' . $name];
}

function sound_delete_file(?string $publicPath): void
{
    if (!$publicPath) {
        return;
    }
    $full = __DIR__ . '/../uploads/sounds/' . basename($publicPath);
    if (is_file($full)) {
        unlink($full);
    }
}

/**
 * Adds a sound. LIBRARY needs an admin; USER needs the uploader to confirm they have permission.
 * bpm / offset / duration come from the browser's analysis and are only clamped here.
 * @return array{ok:bool, id?:int, error?:string}
 */
function create_sound(array $user, array $file, string $title, string $artist, float $bpm, float $offset, int $duration, string $source = 'USER', ?string $licenseNote = null, bool $rightsConfirmed = false, bool $isUpload = true): array
{
    $source = $source === 'LIBRARY' ? 'LIBRARY' : 'USER';
    if ($source === 'LIBRARY' && ($user['role'] ?? '') !== 'ADMIN') {
        return ['ok' => false, 'error' => 'Only an admin can add library tracks.'];
    }
    if ($source === 'USER' && !$rightsConfirmed) {
        return ['ok' => false, 'error' => 'Please confirm you own this sound or have permission to use it.'];
    }
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
    $artist = trim(preg_replace('/\s+/', ' ', $artist) ?? '');
    if ($title === '') {
        return ['ok' => false, 'error' => 'Give the sound a title.'];
    }
    if (mb_strlen($title) > 120 || mb_strlen($artist) > 120) {
        return ['ok' => false, 'error' => 'That title or artist name is too long.'];
    }
    if ($duration > SOUND_MAX_SECONDS) {
        return ['ok' => false, 'error' => 'Sounds can be up to ' . (SOUND_MAX_SECONDS / 60) . ' minutes long.'];
    }
    if ($source === 'USER') {
        $count = db()->prepare("SELECT COUNT(*) FROM sounds WHERE owner_user_id = ? AND source = 'USER' AND status = 'ACTIVE'");
        $count->execute([(int) $user['id']]);
        if ((int) $count->fetchColumn() >= SOUND_USER_LIMIT) {
            return ['ok' => false, 'error' => 'You have reached the limit of ' . SOUND_USER_LIMIT . ' uploaded sounds. Remove one first.'];
        }
    }
    $stored = sound_store_file($file, $isUpload);
    if (!$stored['ok']) {
        return $stored;
    }
    $bpm = ($bpm >= 40 && $bpm <= 220) ? round($bpm, 1) : 100.0;
    $offset = max(0.0, min(30.0, $offset));
    db()->prepare('INSERT INTO sounds (title, artist, file_path, duration_seconds, bpm, beat_offset, source, owner_user_id, license_note, rights_confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$title, $artist, $stored['path'], max(0, min(65000, $duration)), $bpm, round($offset, 3), $source, (int) $user['id'], $licenseNote !== null ? mb_substr(trim($licenseNote), 0, 255) : null, $rightsConfirmed || $source === 'LIBRARY' ? date('Y-m-d H:i:s') : null]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

function get_sound(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM sounds WHERE id = ? AND status = 'ACTIVE'");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** @return list<array<string,mixed>> */
function get_library_sounds(): array
{
    return db()->query("SELECT * FROM sounds WHERE source = 'LIBRARY' AND status = 'ACTIVE' ORDER BY id DESC")->fetchAll();
}

/** @return list<array<string,mixed>> */
function get_user_sounds(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM sounds WHERE source = 'USER' AND owner_user_id = ? AND status = 'ACTIVE' ORDER BY id DESC LIMIT 60");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** A person can pick a sound if it is a library track or one of their own (or they are an admin). */
function can_use_sound(array $user, array $sound): bool
{
    return $sound['source'] === 'LIBRARY' || (int) $sound['owner_user_id'] === (int) $user['id'] || ($user['role'] ?? '') === 'ADMIN';
}

/**
 * Takes a sound out of use everywhere (moments keep playing silently) and frees the file.
 * Admins can remove any sound; people can remove their own.
 * @return array{ok:bool, error?:string}
 */
function remove_sound(array $user, int $id): array
{
    $stmt = db()->prepare("SELECT * FROM sounds WHERE id = ? AND status = 'ACTIVE'");
    $stmt->execute([$id]);
    $sound = $stmt->fetch();
    if (!$sound) {
        return ['ok' => false, 'error' => 'Sound not found.'];
    }
    if (($user['role'] ?? '') !== 'ADMIN' && (int) $sound['owner_user_id'] !== (int) $user['id']) {
        return ['ok' => false, 'error' => "You can't remove this sound."];
    }
    db()->prepare("UPDATE sounds SET status = 'REMOVED' WHERE id = ?")->execute([$id]);
    sound_delete_file($sound['file_path']);
    return ['ok' => true];
}

/** The shape the viewer and the composer use for a sound. */
function sound_public(array $s): array
{
    return [
        'id' => (int) $s['id'],
        'title' => (string) $s['title'],
        'artist' => (string) $s['artist'],
        'src' => (string) $s['file_path'],
        'bpm' => (float) $s['bpm'],
        'offset' => (float) $s['beat_offset'],
        'duration' => (int) $s['duration_seconds'],
        'source' => $s['source'],
    ];
}
