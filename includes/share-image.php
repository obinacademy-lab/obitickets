<?php
declare(strict_types=1);

/**
 * The picture shown when an event link is shared (WhatsApp, Facebook, X, LinkedIn, Telegram, iMessage, Slack...).
 *
 * Flyers are usually portrait and often several megabytes. Platforms only show the big preview card for a wide
 * (about 1.91:1) picture, and WhatsApp skips pictures over roughly 300 KB. So for each event we make one
 * 1200x630 JPEG under about 280 KB: the whole flyer, sharp and centred, on a soft blurred copy of itself.
 * It is made the first time the event page is opened, kept in uploads/share/, and rebuilt on its own when the
 * flyer changes. If anything goes wrong the page falls back to the original flyer.
 *
 * @return array{0:string,1:int,2:int}|null [absolute url, width, height]
 */
function event_share_image(array $event): ?array
{
    $banner = (string) ($event['banner_image'] ?? '');
    if ($banner === '' || !function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $name = basename($banner);
    $src = __DIR__ . '/../uploads/events/' . $name;
    if (!is_file($src) || filesize($src) > 30 * 1024 * 1024) {
        return null;
    }
    $id = (int) ($event['id'] ?? 0);
    $key = substr(md5($name . '|' . filemtime($src) . '|v3'), 0, 12);
    $dir = __DIR__ . '/../uploads/share';
    $base = 'ev-' . $id . '-' . $key . '.jpg';
    $url = rtrim(APP_URL, '/') . '/uploads/share/' . $base;
    if (is_file($dir . '/' . $base)) {
        return [$url, 1200, 630];
    }
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $data = @file_get_contents($src);
        $img = $data === false ? false : @imagecreatefromstring($data);
        if (!$img) {
            return null;
        }
        $sw = imagesx($img);
        $sh = imagesy($img);
        if ($sw < 2 || $sh < 2) {
            return null;
        }
        $W = 1200;
        $H = 630;
        $canvas = imagecreatetruecolor($W, $H);

        // soft background: the flyer, scaled to cover, shrunk, blurred, dimmed, then stretched back up
        $tw = 160;
        $th = 84;
        $tiny = imagecreatetruecolor($tw, $th);
        imagefill($tiny, 0, 0, imagecolorallocate($tiny, 255, 255, 255));
        $cover = max($tw / $sw, $th / $sh);
        $dw = (int) ceil($sw * $cover);
        $dh = (int) ceil($sh * $cover);
        imagecopyresampled($tiny, $img, (int) (($tw - $dw) / 2), (int) (($th - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        for ($i = 0; $i < 3; $i++) {
            imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagefilter($tiny, IMG_FILTER_BRIGHTNESS, -45);
        // enlarge in two steps: one big jump looks blocky, two smaller ones stay smooth (and avoid GD's big-image blur bugs)
        $mid = imagecreatetruecolor(480, 252);
        imagecopyresampled($mid, $tiny, 0, 0, 0, 0, 480, 252, $tw, $th);
        imagedestroy($tiny);
        imagecopyresampled($canvas, $mid, 0, 0, 0, 0, $W, $H, 480, 252);
        imagedestroy($mid);

        // the flyer itself, whole, with a soft shadow
        $fit = min(1120 / $sw, 574 / $sh);
        $fw = max(1, (int) round($sw * $fit));
        $fh = max(1, (int) round($sh * $fit));
        $fx = (int) (($W - $fw) / 2);
        $fy = (int) (($H - $fh) / 2);
        imagealphablending($canvas, true);
        for ($s = 14; $s >= 2; $s -= 4) {
            $shade = imagecolorallocatealpha($canvas, 0, 0, 0, 118 + (int) (($s) / 2));
            imagefilledrectangle($canvas, $fx - $s + 6, $fy - $s + 12, $fx + $fw + $s + 6, $fy + $fh + $s + 12, $shade);
        }
        imagecopyresampled($canvas, $img, $fx, $fy, 0, 0, $fw, $fh, $sw, $sh);
        imagerectangle($canvas, $fx - 1, $fy - 1, $fx + $fw, $fy + $fh, imagecolorallocatealpha($canvas, 255, 255, 255, 96));
        imagedestroy($img);

        $bytes = '';
        foreach ([84, 76, 68, 60, 52] as $q) {
            ob_start();
            imagejpeg($canvas, null, $q);
            $bytes = (string) ob_get_clean();
            if (strlen($bytes) <= 280000) {
                break;
            }
        }
        imagedestroy($canvas);
        if ($bytes === '') {
            return null;
        }
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) === false || !@rename($tmp, $dir . '/' . $base)) {
            @unlink($tmp);
            return null;
        }
        // drop the pictures made for earlier versions of this flyer
        foreach (glob($dir . '/ev-' . $id . '-*.jpg') ?: [] as $old) {
            if (basename($old) !== $base) {
                @unlink($old);
            }
        }
        return [$url, 1200, 630];
    } catch (Throwable $e) {
        return null;
    }
}
