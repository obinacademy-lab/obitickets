<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Home is the video feed (owner's app mockup, 11/17): people land straight in For You, like the first tab of the
// app. The feed itself lives in socials.php; this file only says "you are the home page".
// Until there is at least one video (a fresh install, or Socials not set up), Discover stands in so the home
// page is never an empty screen.
if (!(function_exists('socials_ready') && moments_ready()) || socials_feed_count() === 0) {
    require __DIR__ . '/discover.php';
    return;
}
$socialsHome = true;
require __DIR__ . '/socials.php';
