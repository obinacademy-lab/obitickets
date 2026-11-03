<?php
$pageTitle = 'obitickets — Ticketing Made Simple';
$pageDescription = 'Find concerts, conferences, comedy and festivals across Uganda, in person or online. Buy your ticket in under a minute.';
$bodyClass = 'classic-page stage stage-home';
include __DIR__ . '/includes/header.php';

// Everything below is real: a row with no events simply does not appear.
$featured = get_hero_carousel_events(3);
$popular = get_popular_events(10);
$weekend = get_weekend_events(10);
$soon = this_weekend_events(10);                 // the next two weeks
$free = free_community_events(10);
$online = get_online_events(10);
$music = trending_music_events(10);
$currentUser = current_user();
$next = $currentUser ? get_next_ticket_for_user((int) $currentUser['id']) : null;

$tileEmoji = ['Music' => '🎶', 'Conference' => '💼', 'Comedy' => '😂', 'Sports' => '⚽', 'Faith' => '🙏', 'Fashion' => '👗', 'Community' => '🤝'];
$tileColor = ['#a8242c', '#2f58c9', '#b8860b', '#1e8a63', '#4b2f9a', '#9b2fb0', '#c4622d'];
?>

<?php if ($featured): ?>
<section class="sg-bill" id="sgBill" aria-label="Featured events">
  <?php foreach ($featured as $i => $e):
      $ts = strtotime((string) $e['starts_at']);
      $full = get_event_by_id((int) $e['id']);
      $blurb = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($full['description'] ?? ''))));
      $blurb = mb_strlen($blurb) > 150 ? rtrim(mb_substr($blurb, 0, 147)) . '…' : $blurb;
      $url = '/event.php?slug=' . urlencode($e['slug']);
  ?>
  <div class="sg-slide<?= $i === 0 ? ' on' : '' ?>">
    <?php if (!empty($e['banner_image'])): ?>
      <div class="sg-bgimg" style="background-image:url('<?= htmlspecialchars($e['banner_image'], ENT_QUOTES) ?>')"></div>
    <?php else: ?>
      <div class="sg-bgimg" style="background:<?= htmlspecialchars(stage_gradient($e)) ?>"></div>
    <?php endif; ?>
    <div class="sg-glow"></div>
    <?php if (!empty($e['banner_image'])): ?>
      <a class="sg-poster" href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true"><img src="<?= htmlspecialchars($e['banner_image']) ?>" alt=""></a>
    <?php else: ?>
      <div class="sg-big" aria-hidden="true"><?= htmlspecialchars((string) $e['banner_emoji']) ?></div>
    <?php endif; ?>
    <div class="sg-txt">
      <div class="sg-kick">Featured <span class="sg-pill"><?= htmlspecialchars(date('D j M, g:ia', $ts)) ?></span><?php foreach (stage_tags($e) as [$label, $mod]): ?><span class="sg-pill<?= $mod ? ' ' . $mod : '' ?>"><?= htmlspecialchars($label) ?></span><?php endforeach; ?></div>
      <?php $ht = $i === 0 ? 'h1' : 'h2'; ?><<?= $ht ?>><?= htmlspecialchars($e['title']) ?></<?= $ht ?>>
      <div class="sg-meta"><span><?= htmlspecialchars($e['venue_name']) ?></span><span><?= htmlspecialchars(stage_price_label($e)) ?></span></div>
      <?php if ($blurb !== ''): ?><p class="sg-blurb"><?= htmlspecialchars($blurb) ?></p><?php endif; ?>
      <div class="sg-acts"><a class="sg-btn p" href="<?= htmlspecialchars($url) ?>#buyPanel">&#9654; Get tickets</a><a class="sg-btn s" href="<?= htmlspecialchars($url) ?>">More info</a></div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (count($featured) > 1): ?>
  <div class="sg-dots" role="group" aria-label="Choose a featured event">
    <?php foreach ($featured as $i => $e): ?><button type="button" class="<?= $i === 0 ? 'on' : '' ?>" aria-label="Show featured event <?= $i + 1 ?>"><i></i></button><?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="sg-empty"><div class="wrap"><h1>Events are on their way</h1><p>No events are on sale yet. Check back soon.</p></div></section>
<?php endif; ?>

<div class="sg-rows">
  <?php
  render_stage_row('Popular right now', array_slice($popular, 0, 10), count($popular) >= 3, '/search.php');
  render_stage_row('This weekend', $weekend, false, '/search.php');
  render_stage_row('Coming up in the next two weeks', $soon, false, '/search.php');
  render_stage_row('Free to attend', $free, false, '/search.php?free=1');
  render_stage_row('Online and hybrid', $online, false, '/search.php?online=1');
  render_stage_row('Music', $music, false, '/search.php?category=Music');
  ?>
</div>

<section class="sg-browse" aria-label="Browse by category">
  <h2>Browse by vibe</h2>
  <div class="sg-tiles">
    <?php foreach (EVENT_CATEGORIES as $i => $cat): ?>
      <a class="sg-t" style="background:<?= $tileColor[$i % count($tileColor)] ?>" href="/search.php?category=<?= urlencode($cat) ?>"><?= htmlspecialchars($cat) ?><em aria-hidden="true"><?= $tileEmoji[$cat] ?? '🎟️' ?></em></a>
    <?php endforeach; ?>
    <a class="sg-t" style="background:#1e8a63" href="/search.php?free=1">Free<em aria-hidden="true">🎟️</em></a>
    <?php if (events_online_ready()): ?><a class="sg-t" style="background:#5b3fd0" href="/search.php?online=1">Online<em aria-hidden="true">💻</em></a><?php endif; ?>
  </div>
</section>

<section class="sg-sell">
  <div><h2>Selling tickets? Start in minutes.</h2><p>Create a free or paid event, in person or online, and get paid straight to mobile money.</p></div>
  <a class="sg-btn p" href="/my-events.php">Create an event</a>
</section>

<?php if ($next):
    $nxTs = strtotime((string) $next['starts_at']);
    $nxEvent = events_online_ready() ? get_event_by_id((int) $next['id']) : null;
    $nxJoin = ($nxEvent && event_is_online($nxEvent) && online_join_window($nxEvent)['state'] === 'open');
?>
<div class="sg-now" id="sgNow" data-start="<?= $nxTs ?>" data-end="<?= (int) strtotime((string) $next['ends_at']) ?>">
  <span class="sg-nth"><?php if (!empty($next['banner_image'])): ?><img src="<?= htmlspecialchars($next['banner_image']) ?>" alt=""><?php else: ?><span style="background:<?= htmlspecialchars(stage_gradient($next)) ?>"></span><em><?= htmlspecialchars((string) $next['banner_emoji']) ?></em><?php endif; ?></span>
  <div class="sg-ntx"><b>Your next event: <?= htmlspecialchars($next['title']) ?></b><span><?= htmlspecialchars(date('D j M, g:ia', $nxTs)) ?> &middot; ticket ready</span></div>
  <div class="sg-cd" aria-live="off"><span id="sgCd">&nbsp;</span></div>
  <?php if ($nxJoin): ?><a class="sg-btn p sm" href="/join.php?event=<?= (int) $next['id'] ?>">Join now</a><?php endif; ?>
  <a class="sg-btn <?= $nxJoin ? 's' : 'p' ?> sm" href="/order.php?id=<?= (int) $next['order_id'] ?>">View ticket</a>
</div>
<?php endif; ?>

<script src="/assets/js/stage.js?v=<?= @filemtime(__DIR__ . '/assets/js/stage.js') ?: time() ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
