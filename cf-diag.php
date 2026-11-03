<?php
// TEMPORARY: shows which proxy headers reach the server, so the visitor-IP setting can be chosen safely.
// Needs a secret token, prints no secrets, and is deleted right after use.
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!hash_equals('bbff27639046927595a3b71d6693c1447cb308215bec115d0d648a5618ad9df4', hash('sha256', (string) ($_GET['k'] ?? '')))) {
    http_response_code(404);
    exit;
}
$want = ['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_RAY', 'HTTP_CF_IPCOUNTRY', 'HTTP_X_FORWARDED_PROTO', 'HTTPS', 'SERVER_SOFTWARE'];
$out = [];
foreach ($want as $k) {
    $out[$k] = $_SERVER[$k] ?? null;
}
$out['other_proxy_header_names'] = array_values(array_filter(array_keys($_SERVER), static fn ($k) => preg_match('/^HTTP_(CF_|X_|TRUE_|FORWARDED|VIA|CDN)/', $k) === 1));
echo json_encode($out, JSON_PRETTY_PRINT);
