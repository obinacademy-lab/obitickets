<?php
declare(strict_types=1);

const BANNER_MAX_BYTES = 5 * 1024 * 1024; // 5MB

/**
 * Validates and stores an event banner upload. Never trusts the browser's
 * claimed filename or extension — the real type is sniffed from the file's
 * own bytes via getimagesize(), and the stored filename is a fresh random
 * one, so nothing about the destination path is attacker-controlled.
 *
 * @param array $file One entry from $_FILES (e.g. $_FILES['banner_image'])
 * @return array{0: bool, 1: ?string, 2: ?string} [success, publicPath, errorMessage]
 */
function handle_event_banner_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, null, null]; // nothing chosen — not an error, just nothing to do
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, 'There was a problem uploading that image. Please try again.'];
    }
    if ($file['size'] > BANNER_MAX_BYTES) {
        return [false, null, 'That image is too large — please use one under 5MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'That upload could not be verified. Please try again.'];
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return [false, null, "That file doesn't look like a valid image."];
    }

    $extensionByType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];
    $extension = $extensionByType[$imageInfo[2]] ?? null;
    if ($extension === null) {
        return [false, null, 'Please upload a JPG, PNG, WEBP or GIF image.'];
    }

    $destDir = __DIR__ . '/../uploads/events';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return [false, null, 'Could not prepare storage for the image. Please try again.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) {
        return [false, null, 'Could not save the uploaded image. Please try again.'];
    }

    return [true, '/uploads/events/' . $filename, null];
}

/** Deletes a previously-uploaded banner file (if any) — safe to call with null. */
function delete_event_banner(?string $publicPath): void
{
    if (!$publicPath) {
        return;
    }
    // Only ever delete inside the one directory uploads are written to —
    // never trust a stored path enough to unlink() it verbatim.
    $filename = basename($publicPath);
    $fullPath = __DIR__ . '/../uploads/events/' . $filename;
    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

// =====================================================================
// Event gallery — past-event photos/videos an organizer adds alongside the
// one banner_image, to help sell tickets. Kept deliberately simple: no
// reordering, no editing after upload, just add and remove.
// =====================================================================

const EVENT_MEDIA_MAX_IMAGE_BYTES = 5 * 1024 * 1024;  // 5MB, same cap as the banner
const EVENT_MEDIA_MAX_VIDEO_BYTES = 20 * 1024 * 1024; // 20MB — kept well under this server's post_max_size (40MB) so a couple of videos in one submission can't silently blow the whole request's post_max_size and wipe out $_POST
const EVENT_MEDIA_MAX_IMAGES = 10;
const EVENT_MEDIA_MAX_VIDEOS = 5;

const EVENT_MEDIA_IMAGE_EXTENSIONS = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
    IMAGETYPE_GIF => 'gif',
];

const EVENT_MEDIA_VIDEO_MIME_EXTENSIONS = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
];

/**
 * Validates and stores however many gallery files were submitted, skipping
 * (and reporting) any individual file that fails rather than rejecting the
 * whole batch — so one bad file doesn't cost the organizer the nine good
 * ones in the same submission. Never trusts the browser's claimed filename,
 * extension or MIME type: images are sniffed via getimagesize() (like the
 * banner), videos via the file's own magic bytes through finfo.
 *
 * @param array $files The raw $_FILES['gallery'] entry (multi-file shape: each of name/type/tmp_name/error/size is itself an array)
 * @param array{IMAGE: int, VIDEO: int} $existingCounts How many of each type this event already has, to enforce EVENT_MEDIA_MAX_IMAGES/EVENT_MEDIA_MAX_VIDEOS across the whole event, not just this one submission
 * @return array{0: list<array{media_type: string, file_path: string}>, 1: list<string>} [newly stored items, error messages]
 */
function handle_event_media_uploads(array $files, array $existingCounts): array
{
    $stored = [];
    $errors = [];
    $counts = ['IMAGE' => $existingCounts['IMAGE'] ?? 0, 'VIDEO' => $existingCounts['VIDEO'] ?? 0];

    $count = count($files['name'] ?? []);

    $destDir = __DIR__ . '/../uploads/events/gallery';
    if ($count > 0 && !is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return [[], ['Could not prepare storage for the gallery. Please try again.']];
    }

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // an empty extra file input slot — not an error
        }
        $label = $files['name'][$i] !== '' ? $files['name'][$i] : 'file ' . ($i + 1);

        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "\"{$label}\" failed to upload. Please try again.";
            continue;
        }
        if (!is_uploaded_file($files['tmp_name'][$i])) {
            $errors[] = "\"{$label}\" could not be verified. Please try again.";
            continue;
        }

        $tmpName = $files['tmp_name'][$i];
        $size = (int) $files['size'][$i];

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo !== false) {
            $extension = EVENT_MEDIA_IMAGE_EXTENSIONS[$imageInfo[2]] ?? null;
            if ($extension === null) {
                $errors[] = "\"{$label}\" isn't a supported image type (use JPG, PNG, WEBP or GIF).";
                continue;
            }
            if ($size > EVENT_MEDIA_MAX_IMAGE_BYTES) {
                $errors[] = "\"{$label}\" is too large — photos must be under 5MB.";
                continue;
            }
            if ($counts['IMAGE'] >= EVENT_MEDIA_MAX_IMAGES) {
                $errors[] = "\"{$label}\" was skipped — this event already has the maximum of " . EVENT_MEDIA_MAX_IMAGES . ' photos.';
                continue;
            }
            $mediaType = 'IMAGE';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            $extension = EVENT_MEDIA_VIDEO_MIME_EXTENSIONS[$mime] ?? null;
            if ($extension === null) {
                $errors[] = "\"{$label}\" isn't a supported file type (use JPG/PNG/WEBP/GIF for photos, or MP4/WEBM for video).";
                continue;
            }
            if ($size > EVENT_MEDIA_MAX_VIDEO_BYTES) {
                $errors[] = "\"{$label}\" is too large — videos must be under 20MB.";
                continue;
            }
            if ($counts['VIDEO'] >= EVENT_MEDIA_MAX_VIDEOS) {
                $errors[] = "\"{$label}\" was skipped — this event already has the maximum of " . EVENT_MEDIA_MAX_VIDEOS . ' videos.';
                continue;
            }
            $mediaType = 'VIDEO';
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file($tmpName, $destDir . '/' . $filename)) {
            $errors[] = "\"{$label}\" could not be saved. Please try again.";
            continue;
        }

        $stored[] = ['media_type' => $mediaType, 'file_path' => '/uploads/events/gallery/' . $filename];
        $counts[$mediaType]++;
    }

    return [$stored, $errors];
}

/** Deletes a previously-uploaded gallery file — safe to call with null/empty. */
function delete_event_media_file(?string $publicPath): void
{
    if (!$publicPath) {
        return;
    }
    // Same defense as delete_event_banner(): only ever delete inside the one
    // directory gallery uploads are written to, never the stored path verbatim.
    $filename = basename($publicPath);
    $fullPath = __DIR__ . '/../uploads/events/gallery/' . $filename;
    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}
