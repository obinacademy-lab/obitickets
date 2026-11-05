<?php
declare(strict_types=1);

/**
 * The picture shown when an event link is shared (WhatsApp, Facebook, X, LinkedIn, Telegram, iMessage, Slack...).
 *
 * Flyers are usually portrait and often several megabytes. Platforms only show the big preview card for a wide
 * (about 1.91:1) picture, and WhatsApp skips pictures over roughly 300 KB. So for each event we make one
 * 1200x630 JPEG under about 280 KB: the whole flyer, sharp and as large as the frame allows (full height, nothing
 * around it), on a smooth glow made from its own colours.
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
    $key = substr(md5($name . '|' . filemtime($src) . '|v4'), 0, 12);
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

        // background: a smooth glow from the flyer's own colours (shrink to a handful of pixels, blur, dim, then
        // enlarge with bicubic smoothing so there are no visible blocks)
        $tw = 40;
        $th = 21;
        $tiny = imagecreatetruecolor($tw, $th);
        imagefill($tiny, 0, 0, imagecolorallocate($tiny, 255, 255, 255));
        $cover = max($tw / $sw, $th / $sh);
        $dw = (int) ceil($sw * $cover);
        $dh = (int) ceil($sh * $cover);
        imagecopyresampled($tiny, $img, (int) (($tw - $dw) / 2), (int) (($th - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        for ($i = 0; $i < 4; $i++) {
            imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagefilter($tiny, IMG_FILTER_BRIGHTNESS, -38);
        // enlarge in small steps with true bilinear smoothing (a single big jump, or the bicubic mode, leaves blocks or streaks),
        // blurring on the way so the glow is soft
        $cur = $tiny;
        foreach ([[100, 53], [240, 126], [500, 262], [1200, 630]] as [$nw, $nh]) {
            $next = function_exists('imagescale') ? @imagescale($cur, $nw, $nh, IMG_BILINEAR_FIXED) : false;
            if (!$next) {
                $next = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($next, $cur, 0, 0, 0, 0, $nw, $nh, imagesx($cur), imagesy($cur));
            }
            imagedestroy($cur);
            $cur = $next;
            if ($nw === 240 || $nw === 500) {
                for ($b = 0; $b < 3; $b++) {
                    imagefilter($cur, IMG_FILTER_GAUSSIAN_BLUR);
                }
            }
        }
        imagecopy($canvas, $cur, 0, 0, 0, 0, $W, $H);
        imagedestroy($cur);

        // the flyer itself: whole, full height (or full width for a wide flyer), no border, with a soft edge shadow either side
        $fit = min($W / $sw, $H / $sh);
        $fw = max(1, (int) round($sw * $fit));
        $fh = max(1, (int) round($sh * $fit));
        $fx = (int) (($W - $fw) / 2);
        $fy = (int) (($H - $fh) / 2);
        imagealphablending($canvas, true);
        if ($fw < $W - 8) {
            for ($i = 1; $i <= 26; $i++) {
                $alpha = 127 - (int) round(70 * (1 - $i / 27) ** 2); // darkest next to the flyer, fading out
                $shade = imagecolorallocatealpha($canvas, 0, 0, 0, max(0, min(127, $alpha)));
                imageline($canvas, $fx - $i, 0, $fx - $i, $H, $shade);
                imageline($canvas, $fx + $fw - 1 + $i, 0, $fx + $fw - 1 + $i, $H, $shade);
            }
        }
        imagecopyresampled($canvas, $img, $fx, $fy, 0, 0, $fw, $fh, $sw, $sh);
        imagedestroy($img);

        $bytes = '';
        foreach ([90, 84, 78, 72, 66, 60, 54, 48] as $q) {
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
