<?php
// Moments: 9:16 photos and videos on an event. A moment is a community post
// (event_posts.post_type = 'MOMENT') plus an event_moments row holding the media,
// so comments, reactions, reports and moderation all reuse the feed's own tables.
// Only the organizer (or their team / an admin) can add moments; everyone can
// watch, and any logged-in account can react and comment.

const MOMENT_CAPTION_MAX = 300;
const MOMENT_MAX_VIDEOS = 30;
const MOMENT_MAX_PHOTOS = 60;
const MOMENT_MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const MOMENT_VIDEO_CAP_BYTES = 50 * 1024 * 1024;
const MOMENT_MAX_SECONDS = 90;
const MOMENT_POSTER_MAX_BYTES = 600 * 1024;
const MOMENT_RATE_LIMIT = 20; // uploads per hour per person

const MOMENT_IMAGE_EXTENSIONS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
const MOMENT_VIDEO_EXTENSIONS = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

/** php.ini sizes ("40M", "2G", "512K") as bytes; 0 or empty means no limit. */
function moment_ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    $n = (int) $v;
    if ($v === '' || $n <= 0) {
        return PHP_INT_MAX;
    }
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/**
 * The biggest video this server will actually accept: our own 50 MB cap, held under
 * both php.ini limits (a request over post_max_size is dropped before PHP sees it).
 */
function moment_video_max_bytes(): int
{
    $limit = min(MOMENT_VIDEO_CAP_BYTES, moment_ini_bytes('upload_max_filesize'), moment_ini_bytes('post_max_size') - 2 * 1024 * 1024);
    return max(1024 * 1024, $limit);
}

/** True once migration 017 has been run (the event page falls back to the old gallery without it). */
function moments_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM event_moments LIMIT 0');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function moment_select_sql(): string
{
    return "
        SELECT p.id, p.event_id, p.author_id, p.body AS caption, p.comments_enabled, p.created_at,
               m.media_type, m.media_path, m.poster_path, m.focus_x, m.fit_mode, m.duration_seconds, m.view_count, m.share_count,
               (SELECT COUNT(*) FROM post_reactions r WHERE r.post_id = p.id) AS reaction_count,
               (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id AND c.status = 'PUBLISHED') AS comment_count,
               (SELECT r.reaction FROM post_reactions r WHERE r.post_id = p.id AND r.user_id = :viewer) AS my_reaction
        FROM event_moments m
        JOIN event_posts p ON p.id = m.post_id
        JOIN users u ON u.id = p.author_id
        WHERE p.status = 'PUBLISHED' AND m.media_path <> '' AND u.account_status = 'ACTIVE'";
}

/** @return list<array<string,mixed>> newest first */
function get_event_moments(int $eventId, ?int $viewerId = null, int $limit = 60): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(moment_select_sql() . " AND m.event_id = :event ORDER BY p.id DESC LIMIT $limit");
    $stmt->bindValue(':viewer', $viewerId ?? 0, PDO::PARAM_INT);
    $stmt->bindValue(':event', $eventId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_moment(int $postId, ?int $viewerId = null): ?array
{
    $stmt = db()->prepare(moment_select_sql() . ' AND p.id = :id');
    $stmt->bindValue(':viewer', $viewerId ?? 0, PDO::PARAM_INT);
    $stmt->bindValue(':id', $postId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

/** The shape the viewer script (and the share previews) use for one moment. */
function moment_public(array $m): array
{
    return [
        'id' => (int) $m['id'],
        'type' => $m['media_type'] === 'VIDEO' ? 'video' : 'image',
        'src' => (string) $m['media_path'],
        'poster' => $m['poster_path'] ?: null,
        'focus' => (int) $m['focus_x'],
        'fit' => $m['fit_mode'] === 'FIT' ? 'fit' : 'fill',
        'caption' => (string) ($m['caption'] ?? ''),
        'duration' => $m['duration_seconds'] !== null ? (int) $m['duration_seconds'] : null,
        'views' => (int) $m['view_count'],
        'shares' => (int) $m['share_count'],
        'reactions' => (int) $m['reaction_count'],
        'comments' => (int) $m['comment_count'],
        'mine' => $m['my_reaction'] ?: null,
        'commentsOn' => (int) $m['comments_enabled'] === 1,
        'when' => community_time_ago((string) $m['created_at']),
    ];
}

/** "1.2K" style counts for tiles and the viewer rail. */
function moment_compact(int $n): string
{
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string) $n;
}

/**
 * Validates and stores one moment file. Never trusts the browser's filename, extension or
 * MIME type: photos are sniffed with getimagesize(), videos by magic bytes through finfo.
 * $isUpload=false is the test seam (a plain file on disk instead of an HTTP upload).
 *
 * @return array{ok:bool, type?:string, path?:string, error?:string}
 */
function moment_store_media(array $file, bool $isUpload = true): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Choose a photo or video first.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That file is too large for this server. Videos can be up to ' . round(moment_video_max_bytes() / 1048576) . ' MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => "We couldn't upload that file. Please try again."];
    }
    $tmp = (string) $file['tmp_name'];
    if ($isUpload && !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => "That upload couldn't be verified. Please try again."];
    }
    $size = (int) $file['size'];

    $info = @getimagesize($tmp);
    if ($info !== false) {
        $ext = MOMENT_IMAGE_EXTENSIONS[$info[2]] ?? null;
        if ($ext === null) {
            return ['ok' => false, 'error' => 'Please use a JPG, PNG or WEBP photo.'];
        }
        if ($size > MOMENT_MAX_IMAGE_BYTES) {
            return ['ok' => false, 'error' => 'Photos must be under 5 MB.'];
        }
        if ($info[0] > 8000 || $info[1] > 8000) {
            return ['ok' => false, 'error' => 'That photo is too large in pixels. Please use a smaller one.'];
        }
        $type = 'IMAGE';
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $tmp);
        finfo_close($finfo);
        $ext = MOMENT_VIDEO_EXTENSIONS[$mime] ?? null;
        if ($ext === null) {
            return ['ok' => false, 'error' => 'Please use an MP4 or WebM video, or a JPG, PNG or WEBP photo.'];
        }
        if ($size > moment_video_max_bytes()) {
            return ['ok' => false, 'error' => 'Videos must be under ' . round(moment_video_max_bytes() / 1048576) . ' MB.'];
        }
        $type = 'VIDEO';
    }

    $dir = __DIR__ . '/../uploads/moments';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => "We couldn't prepare storage. Please try again."];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $isUpload ? move_uploaded_file($tmp, $dir . '/' . $name) : rename($tmp, $dir . '/' . $name);
    if (!$moved) {
        return ['ok' => false, 'error' => "We couldn't save that file. Please try again."];
    }
    return ['ok' => true, 'type' => $type, 'path' => '/uploads/moments/' . $name];
}

/** A small JPEG cover frame the browser captured from the video. Optional; returns null path on any problem. */
function moment_store_poster(?array $file, bool $isUpload = true): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) $file['tmp_name'];
    if (($isUpload && !is_uploaded_file($tmp)) || (int) $file['size'] > MOMENT_POSTER_MAX_BYTES) {
        return null;
    }
    $info = @getimagesize($tmp);
    $ext = $info !== false ? (MOMENT_IMAGE_EXTENSIONS[$info[2]] ?? null) : null;
    if ($ext === null || $info[0] > 2000 || $info[1] > 2000) {
        return null;
    }
    $dir = __DIR__ . '/../uploads/moments';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $name = bin2hex(random_bytes(16)) . '-cover.' . $ext;
    $moved = $isUpload ? move_uploaded_file($tmp, $dir . '/' . $name) : rename($tmp, $dir . '/' . $name);
    return $moved ? '/uploads/moments/' . $name : null;
}

/** Deletes a stored moment file, only ever inside the moments folder. */
function moment_delete_file(?string $publicPath): void
{
    if (!$publicPath) {
        return;
    }
    $full = __DIR__ . '/../uploads/moments/' . basename($publicPath);
    if (is_file($full)) {
        unlink($full);
    }
}

/**
 * Adds a moment to an event. $file is the $_FILES entry; $poster an optional cover frame.
 * @return array{ok:bool, id?:int, error?:string}
 */
function create_moment(array $event, array $user, array $file, ?array $poster, string $caption, int $focusX = 50, string $fit = 'FILL', ?int $duration = null, bool $isUpload = true): array
{
    if (($event['status'] ?? '') !== 'PUBLISHED') {
        return ['ok' => false, 'error' => 'Moments can be added once the event is published.'];
    }
    if (!can_moderate_event($user, $event)) {
        return ['ok' => false, 'error' => 'Only the organizer and their team can add moments.'];
    }
    $caption = community_clean_text($caption);
    if (mb_strlen($caption) > MOMENT_CAPTION_MAX) {
        return ['ok' => false, 'error' => 'That caption is too long (limit ' . MOMENT_CAPTION_MAX . ' characters).'];
    }
    $recent = db()->prepare("SELECT COUNT(*) FROM event_posts WHERE author_id = ? AND post_type = 'MOMENT' AND created_at >= (NOW() - INTERVAL 1 HOUR)");
    $recent->execute([(int) $user['id']]);
    if ((int) $recent->fetchColumn() >= MOMENT_RATE_LIMIT) {
        return ['ok' => false, 'error' => "You're adding moments too quickly. Please wait a few minutes."];
    }

    $stored = moment_store_media($file, $isUpload);
    if (!$stored['ok']) {
        return $stored;
    }
    $counts = db()->prepare("SELECT m.media_type, COUNT(*) FROM event_moments m JOIN event_posts p ON p.id = m.post_id WHERE m.event_id = ? AND p.status <> 'DELETED' GROUP BY m.media_type");
    $counts->execute([(int) $event['id']]);
    $have = $counts->fetchAll(PDO::FETCH_KEY_PAIR);
    $isVideo = $stored['type'] === 'VIDEO';
    if ((int) ($have[$stored['type']] ?? 0) >= ($isVideo ? MOMENT_MAX_VIDEOS : MOMENT_MAX_PHOTOS)) {
        moment_delete_file($stored['path']);
        return ['ok' => false, 'error' => 'This event already has the maximum of ' . ($isVideo ? MOMENT_MAX_VIDEOS . ' videos' : MOMENT_MAX_PHOTOS . ' photos') . '. Delete one to add another.'];
    }
    if ($duration !== null && $duration > MOMENT_MAX_SECONDS && $isVideo) {
        moment_delete_file($stored['path']);
        return ['ok' => false, 'error' => 'Videos can be up to ' . MOMENT_MAX_SECONDS . ' seconds long.'];
    }
    $posterPath = $isVideo ? moment_store_poster($poster, $isUpload) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO event_posts (event_id, author_id, post_type, body, comments_enabled) VALUES (?, ?, 'MOMENT', ?, 1)")
            ->execute([(int) $event['id'], (int) $user['id'], $caption !== '' ? $caption : null]);
        $postId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO event_moments (post_id, event_id, media_type, media_path, poster_path, focus_x, fit_mode, duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$postId, (int) $event['id'], $stored['type'], $stored['path'], $posterPath, max(0, min(100, $focusX)), $fit === 'FIT' ? 'FIT' : 'FILL', $duration !== null ? max(0, min(65000, $duration)) : null]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        moment_delete_file($stored['path']);
        moment_delete_file($posterPath);
        throw $e;
    }

    try {
        notify_event_followers_of_moment($event, $caption);
    } catch (Throwable $e) {
        error_log('[moments] follower notification failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'id' => $postId];
}

/** At most one "new moment" notification per event every 3 hours. */
function notify_event_followers_of_moment(array $event, string $caption): void
{
    $link = '/event.php?slug=' . urlencode($event['slug']) . '#moments';
    $recent = db()->prepare("SELECT 1 FROM notifications WHERE type = 'event_moment' AND link = ? AND created_at >= (NOW() - INTERVAL 3 HOUR) LIMIT 1");
    $recent->execute([$link]);
    if ($recent->fetchColumn()) {
        return;
    }
    $snippet = mb_substr(preg_replace('/\s+/', ' ', $caption) ?? '', 0, 200);
    db()->prepare("
        INSERT INTO notifications (user_id, type, title, body, link)
        SELECT f.user_id, 'event_moment', ?, ?, ?
        FROM event_follows f WHERE f.event_id = ? AND f.user_id <> ?
        LIMIT 5000
    ")->execute(['New moment: ' . mb_substr((string) $event['title'], 0, 120), $snippet, $link, (int) $event['id'], (int) $event['organizer_id']]);
}

/** Caption, framing and comments switch. @return array{ok:bool, error?:string} */
function update_moment(array $user, int $postId, string $caption, int $focusX, string $fit, bool $commentsEnabled): array
{
    $post = get_post($postId);
    if (!$post || $post['post_type'] !== 'MOMENT' || $post['status'] === 'DELETED') {
        return ['ok' => false, 'error' => 'Moment not found.'];
    }
    if (!can_moderate_event($user, get_event_for_post($post))) {
        return ['ok' => false, 'error' => "You can't change this moment."];
    }
    $caption = community_clean_text($caption);
    if (mb_strlen($caption) > MOMENT_CAPTION_MAX) {
        return ['ok' => false, 'error' => 'That caption is too long (limit ' . MOMENT_CAPTION_MAX . ' characters).'];
    }
    db()->prepare('UPDATE event_posts SET body = ?, comments_enabled = ? WHERE id = ?')->execute([$caption !== '' ? $caption : null, $commentsEnabled ? 1 : 0, $postId]);
    db()->prepare('UPDATE event_moments SET focus_x = ?, fit_mode = ? WHERE post_id = ?')->execute([max(0, min(100, $focusX)), $fit === 'FIT' ? 'FIT' : 'FILL', $postId]);
    return ['ok' => true];
}

/**
 * Removes a moment from view (logged like any moderation action) and frees its files.
 * @return array{ok:bool, error?:string}
 */
function delete_moment(array $user, int $postId): array
{
    $post = get_post($postId);
    if (!$post || $post['post_type'] !== 'MOMENT') {
        return ['ok' => false, 'error' => 'Moment not found.'];
    }
    $row = db()->prepare('SELECT media_path, poster_path, legacy_media_id FROM event_moments WHERE post_id = ?');
    $row->execute([$postId]);
    $media = $row->fetch();
    $result = moderate_content($user, 'POST', $postId, 'DELETE');
    if (!$result['ok']) {
        return $result;
    }
    if ($media) {
        moment_delete_file($media['media_path']);
        moment_delete_file($media['poster_path']);
        db()->prepare("UPDATE event_moments SET media_path = '', poster_path = NULL, legacy_media_id = NULL WHERE post_id = ?")->execute([$postId]);
        if ($media['legacy_media_id']) {
            db()->prepare('DELETE FROM event_media WHERE id = ?')->execute([(int) $media['legacy_media_id']]); // keep the event form's gallery list in step
        }
    }
    return ['ok' => true];
}

/** Counts a view or a share. Callers throttle per visitor; guests count too. */
function record_moment_stat(int $postId, string $stat): bool
{
    $column = $stat === 'share' ? 'share_count' : 'view_count';
    $stmt = db()->prepare("UPDATE event_moments m JOIN event_posts p ON p.id = m.post_id SET m.$column = m.$column + 1 WHERE m.post_id = ? AND p.status = 'PUBLISHED'");
    $stmt->execute([$postId]);
    return $stmt->rowCount() > 0;
}

// ---- keeping the old event gallery (event_media) and moments in step ----

/** The event form still adds gallery files; each one also becomes a moment. */
function moment_sync_legacy_add(int $eventId, int $mediaId, string $type, string $path): void
{
    if (!moments_ready()) {
        return;
    }
    $organizer = db()->prepare('SELECT organizer_id FROM events WHERE id = ?');
    $organizer->execute([$eventId]);
    $organizerId = (int) $organizer->fetchColumn();
    if (!$organizerId) {
        return;
    }
    $pdo = db();
    $pdo->prepare("INSERT INTO event_posts (event_id, author_id, post_type, body, comments_enabled) VALUES (?, ?, 'MOMENT', NULL, 1)")->execute([$eventId, $organizerId]);
    $postId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO event_moments (post_id, event_id, media_type, media_path, legacy_media_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$postId, $eventId, $type === 'VIDEO' ? 'VIDEO' : 'IMAGE', $path, $mediaId]);
}

function moment_sync_legacy_remove(int $mediaId): void
{
    if (!moments_ready()) {
        return;
    }
    db()->prepare('DELETE p FROM event_posts p JOIN event_moments m ON m.post_id = p.id WHERE m.legacy_media_id = ?')->execute([$mediaId]);
}

// ---- the event page shelf ----

/** "0:38" for a video length. */
function moment_clock(?int $seconds): string
{
    if ($seconds === null) {
        return '';
    }
    return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
}

/** Inline style that frames a 9:16 crop around the chosen horizontal point. */
function moment_frame_style(array $m): string
{
    return 'object-position:' . (int) $m['focus_x'] . '% 50%';
}

/**
 * The Moments shelf: a row of tall 9:16 cards. Tapping one opens the viewer (assets/js/moments.js),
 * which reads the same moments from the JSON block rendered next to the shelf.
 *
 * @param list<array<string,mixed>> $moments rows from get_event_moments()
 */
function moments_shelf_html(array $moments, string $orgName, string $orgInitials): string
{
    $videos = 0;
    $photos = 0;
    foreach ($moments as $m) {
        $m['media_type'] === 'VIDEO' ? $videos++ : $photos++;
    }
    $summary = [];
    if ($videos) {
        $summary[] = $videos . ' video' . ($videos === 1 ? '' : 's');
    }
    if ($photos) {
        $summary[] = $photos . ' photo' . ($photos === 1 ? '' : 's');
    }

    $h = '<div class="mv-band"><div class="mv-head"><div><h2 class="ev-h2">Moments</h2><p>' . ev_h(implode(' and ', $summary)) . '</p></div>';
    if ($videos && $photos) {
        $h .= '<div class="mv-chips" role="group" aria-label="Filter moments"><button type="button" data-mv-filter="all" aria-pressed="true">All</button><button type="button" data-mv-filter="video" aria-pressed="false">Videos</button><button type="button" data-mv-filter="image" aria-pressed="false">Photos</button></div>';
    }
    $h .= '</div><div class="mv-stripwrap">'
        . '<button type="button" class="mv-nav mv-prev" aria-label="Scroll left"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg></button>'
        . '<div class="mv-strip" id="mvStrip">';
    foreach ($moments as $m) {
        $video = $m['media_type'] === 'VIDEO';
        $fit = $m['fit_mode'] === 'FIT';
        $thumb = $video ? ($m['poster_path'] ?: null) : $m['media_path'];
        $caption = trim((string) ($m['caption'] ?? ''));
        $h .= '<button type="button" class="mv-card' . ($fit ? ' is-fit' : '') . '" data-moment="' . (int) $m['id'] . '" data-kind="' . ($video ? 'video' : 'image') . '"'
            . ' aria-label="Open ' . ($video ? 'video' : 'photo') . ($caption !== '' ? ': ' . ev_h(mb_substr($caption, 0, 80)) : '') . '"'
            . ($fit && $thumb ? ' style="--mv-bg:url(\'' . ev_h($thumb) . '\')"' : '') . '>';
        if ($thumb) {
            $h .= '<img src="' . ev_h($thumb) . '" alt="" loading="lazy" style="' . ev_h(moment_frame_style($m)) . '">';
        } else {
            $h .= '<video src="' . ev_h($m['media_path']) . '#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true" style="' . ev_h(moment_frame_style($m)) . '"></video>';
        }
        $h .= '<span class="mv-shade"></span>'
            . '<span class="mv-tag' . ($video ? '' : ' is-photo') . '">' . ($video
                ? '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg> ' . ev_h(moment_compact((int) $m['view_count']))
                : 'Photo') . '</span>'
            . '<span class="mv-info">'
            . ($caption !== '' ? '<span class="mv-cap">' . ev_h($caption) . '</span>' : '')
            . '<span class="mv-by"><i>' . ev_h($orgInitials) . '</i>' . ev_h($orgName) . '</span>'
            . '<span class="mv-stats"><span>&#10084;&#65039; ' . ev_h(moment_compact((int) $m['reaction_count'])) . '</span><span>&#128172; ' . ev_h(moment_compact((int) $m['comment_count'])) . '</span><span>&#8599; ' . ev_h(moment_compact((int) $m['share_count'])) . '</span></span>'
            . '</span></button>';
    }
    $h .= '</div><button type="button" class="mv-nav mv-next" aria-label="Scroll right"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg></button></div></div>';
    return $h;
}
