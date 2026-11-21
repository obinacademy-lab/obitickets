<?php
// Shared video links: /watch/22 (rewritten here by watch/.htaccess) or /watch/?v=22 open that post in the feed,
// exactly like /socials.php?v=22, and give link previews the "Watch: <event>" title and the cover picture.
$_GET['v'] = (string) (int) ($_GET['v'] ?? 0);
if ($_GET['v'] === '0') {
    header('Location: /socials.php?play=1', true, 302);
    exit;
}
require __DIR__ . '/../socials.php';
