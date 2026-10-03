<?php
// <head> tags that make the site installable as an app (manifest, icons,
// iOS "Add to Home Screen" metadata) plus service-worker registration.
// Included from every layout's <head> so any page can be the install point.
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#DC2626">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="obitickets">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
  });
}
</script>
