<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Notifications for every signed-in person (the bell). Phase 3 (11/18): the dark app look from the owner's mockup:
// who did it (their picture or initials), what they did, when, and the event's picture; filters for likes,
// comments, follows and other updates. Same notifications table as the organizer dashboard (includes/organizer.php).
$user = require_login();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    verify_csrf();
    mark_all_notifications_read($uid);
    header('Location: /notifications.php');
    exit;
}
// Opening one marks it read, then goes where it points (only to pages on this site).
if (isset($_GET['open'])) {
    $openId = (int) $_GET['open'];
    $go = '/notifications.php';
    foreach (get_notifications($uid, 200) as $n) {
        if ((int) $n['id'] === $openId) {
            mark_notification_read($uid, $openId);
            $link = notification_target($n);   // the exact place: that comment, that video, that person (includes/community.php)
            if ($link !== '' && $link[0] === '/' && !str_starts_with($link, '//') && !str_contains($link, '\\')) {
                $go = $link;
            }
            break;
        }
    }
    header('Location: ' . $go);
    exit;
}

$groups = [
    'likes'    => ['label' => 'Likes',    'types' => ['like', 'comment_like', 'boost']],
    'comments' => ['label' => 'Comments', 'types' => ['post_comment', 'comment_reply']],
    'follows'  => ['label' => 'Follows',  'types' => ['follow']],
];
$filter = (string) ($_GET['f'] ?? 'all');
if ($filter !== 'updates' && !isset($groups[$filter])) {
    $filter = 'all';
}
$social = array_merge(...array_column($groups, 'types'));
$all = get_notifications($uid, 120);
$notifications = array_values(array_filter($all, static function ($n) use ($filter, $groups, $social) {
    if ($filter === 'all') { return true; }
    if ($filter === 'updates') { return !in_array($n['type'], $social, true); }
    return in_array($n['type'], $groups[$filter]['types'], true);
}));
$unread = count_unread_notifications($uid);

// who did it (needs migration 034; without it rows show an icon)
$actors = [];
$actorIds = array_values(array_unique(array_filter(array_map(static fn ($n) => (int) ($n['actor_id'] ?? 0), $notifications))));
if ($actorIds) {
    $st = db()->prepare('SELECT id, name, avatar_url FROM users WHERE id IN (' . implode(',', array_fill(0, count($actorIds), '?')) . ')');
    $st->execute($actorIds);
    foreach ($st->fetchAll() as $a) { $actors[(int) $a['id']] = $a; }
}
// the event's picture for notifications about a video or post
$thumbs = [];
$postIds = [];
foreach ($notifications as $n) {
    if (preg_match('#^/socials\.php\?v=(\d+)$#', (string) ($n['link'] ?? ''), $m)) { $postIds[] = (int) $m[1]; }
}
if ($postIds) {
    $postIds = array_values(array_unique($postIds));
    $st = db()->prepare('SELECT p.id, e.banner_image FROM event_posts p JOIN events e ON e.id = p.event_id WHERE p.id IN (' . implode(',', array_fill(0, count($postIds), '?')) . ')');
    $st->execute($postIds);
    foreach ($st->fetchAll() as $r) {
        if (preg_match('#^/(uploads|assets)/[A-Za-z0-9/_.\-]+$#', (string) $r['banner_image'])) { $thumbs[(int) $r['id']] = (string) $r['banner_image']; }
    }
}

/** "2m", "3h", "Yesterday", "12 Nov". */
$ago = static function (string $at): string {
    $d = time() - strtotime($at);
    if ($d < 60) { return 'Just now'; }
    if ($d < 3600) { return floor($d / 60) . 'm'; }
    if ($d < 86400) { return floor($d / 3600) . 'h'; }
    if ($d < 172800) { return 'Yesterday'; }
    return date('j M', strtotime($at));
};
$bucket = static function (string $at): string {
    $t = strtotime($at);
    if ($t >= strtotime('today')) { return 'Today'; }
    if ($t >= strtotime('yesterday')) { return 'Yesterday'; }
    if ($t >= strtotime('-7 days')) { return 'This week'; }
    return 'Earlier';
};
$initials = static function (string $name): string {
    $p = preg_split('/\s+/', trim($name)) ?: [];
    $s = mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . (count($p) > 1 ? mb_substr((string) end($p), 0, 1) : ''));
    return $s !== '' ? $s : '?';
};
$badge = [
    'like' => ['heart', '#F0313F'], 'comment_like' => ['heart', '#F0313F'], 'boost' => ['boost', '#F59E0B'],
    'post_comment' => ['chat', '#3B82F6'], 'comment_reply' => ['chat', '#3B82F6'], 'follow' => ['user', '#22C55E'], 'thank_you' => ['heart', '#F0313F'],
];
$icons = [
    'heart' => '<path d="M12 20.5s-7.5-4.6-9.3-9.4C1.5 7.6 3.9 4.5 7.2 4.5c1.9 0 3.6 1 4.8 2.6 1.2-1.6 2.9-2.6 4.8-2.6 3.3 0 5.7 3.1 4.5 6.6-1.8 4.8-9.3 9.4-9.3 9.4z" fill="currentColor" stroke="none"/>',
    'chat'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z" fill="currentColor" stroke="none"/>',
    'user'  => '<circle cx="12" cy="8" r="4" fill="currentColor" stroke="none"/><path d="M4 21a8 8 0 0 1 16 0z" fill="currentColor" stroke="none"/>',
    'boost' => '<path d="M12 3l7 8h-4v10H9V11H5z" fill="currentColor" stroke="none"/>',
    'bell'  => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'ticket'=> '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/>',
    'cash'  => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
];
$plainIcon = static fn (string $type): string => in_array($type, ['sale', 'ticket', 'event'], true) ? 'ticket' : (in_array($type, ['payout', 'refund', 'socials_plan'], true) ? 'cash' : 'bell');
$svg = static fn (string $k, int $s = 20): string => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$k] . '</svg>';

$pageTitle = 'Notifications — obitickets';
$bodyClass = 'classic-page app-dark nt-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/notifications-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/notifications-app.css') ?: time() ?>">

<div class="wrap nt">
  <div class="ad-top">
    <a class="ad-icon" href="/" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Notifications</h1>
    <?php if ($unread > 0): ?>
      <form method="post" class="nt-all"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="nt-all-btn">Mark all read</button></form>
    <?php endif; ?>
  </div>
  <p class="nt-sub"><?= $unread === 0 ? 'You are all caught up' : $unread . ' unread' ?></p>

  <nav class="nt-tabs" aria-label="Show">
    <?php foreach (['all' => 'All'] + array_map(static fn ($g) => $g['label'], $groups) + ['updates' => 'Updates'] as $k => $label): ?>
      <a href="/notifications.php<?= $k === 'all' ? '' : '?f=' . $k ?>"<?= $filter === $k ? ' class="on" aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$notifications): ?>
    <div class="nt-empty">
      <i><?= $svg('bell', 28) ?></i>
      <h2><?= $filter === 'all' ? 'No notifications yet' : 'Nothing here yet' ?></h2>
      <p>Likes, comments, new followers, your tickets and event reminders will show up here.</p>
      <a class="nt-cta" href="/">Watch event videos</a>
    </div>
  <?php else: ?>
    <?php $lastBucket = null; foreach ($notifications as $n):
        $isNew = empty($n['read_at']);
        $b = $bucket((string) $n['created_at']);
        $actor = $actors[(int) ($n['actor_id'] ?? 0)] ?? null;
        $postId = preg_match('#^/socials\.php\?v=(\d+)$#', (string) ($n['link'] ?? ''), $mm) ? (int) $mm[1] : 0;
        $thumb = $thumbs[$postId] ?? '';
        $av = $actor && preg_match('#^(/(uploads|assets)/[A-Za-z0-9/_.\-]+|https://[A-Za-z0-9.\-]+/[^\s"\'<>]*)$#', (string) $actor['avatar_url']) ? (string) $actor['avatar_url'] : '';
        if ($b !== $lastBucket): if ($lastBucket !== null): ?></div><?php endif; $lastBucket = $b; ?>
      <h2 class="nt-h"><?= htmlspecialchars($b) ?></h2>
      <div class="nt-list">
    <?php endif; ?>
      <a class="nt-row<?= $isNew ? ' is-new' : '' ?>" href="/notifications.php?open=<?= (int) $n['id'] ?>">
        <span class="nt-who">
          <?php if ($actor): ?>
            <?php if ($av !== ''): ?><img src="<?= htmlspecialchars($av) ?>" alt="" loading="lazy"><?php else: ?><b><?= htmlspecialchars($initials((string) $actor['name'])) ?></b><?php endif; ?>
          <?php else: ?>
            <b class="nt-plain"<?= isset($badge[$n['type']]) ? ' style="color:' . $badge[$n['type']][1] . '"' : '' ?>><?= $svg($badge[$n['type']][0] ?? $plainIcon((string) $n['type'])) ?></b>
          <?php endif; ?>
          <?php if (isset($badge[$n['type']]) && $actor): ?><i style="--c:<?= $badge[$n['type']][1] ?>"><?= $svg($badge[$n['type']][0], 11) ?></i><?php endif; ?>
        </span>
        <span class="nt-tx">
          <b><?= htmlspecialchars(html_entity_decode((string) $n['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?><?php if ($isNew): ?> <span class="sr-only">(unread)</span><?php endif; ?></b>
          <?php if (!empty($n['body'])): ?><span><?= htmlspecialchars(html_entity_decode(strip_tags((string) $n['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></span><?php endif; ?>
          <small><?= htmlspecialchars($ago((string) $n['created_at'])) ?></small>
        </span>
        <?php if ($thumb !== ''): ?><img class="nt-thumb" src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php endif; ?>
        <?php if ($isNew): ?><i class="nt-dot" aria-hidden="true"></i><?php endif; ?>
      </a>
    <?php endforeach; ?>
      </div>
  <?php endif; ?>
</div>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
