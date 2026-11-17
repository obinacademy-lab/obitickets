<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Create (the + in the tab bar; owner's mockup, Phase 4, 11/18). Organizers pick what to make; everyone else is
// shown how to become an organizer (only organizers post videos and events). Each choice opens a page that
// already does the work.
$user = require_login();
$isOrganizer = ($user['role'] ?? '') === 'ORGANIZER';

$ic = [
    'video' => '<rect x="3" y="5" width="13" height="14" rx="2.5"/><path d="M16 10l5-3v10l-5-3z"/>',
    'cal'   => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4M12 13v5M9.5 15.5h5"/>',
    'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
    'star'  => '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>',
    'tag'   => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
];
$svg = static fn (string $k): string => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$k] . '</svg>';
$choices = $isOrganizer ? [
    ['/org-socials.php', 'video', 'Post a video', 'Share a video or photos of your event on the home feed', 'r'],
    ['/event-create.php', 'cal', 'Create an event', 'Set up tickets, prices and your event page', 'b'],
    ['/org-promo-video.php', 'spark', 'Make a promo video', 'Turn your flyer into a short video in minutes', 'y'],
    ['/org-promo.php', 'tag', 'Promo codes', 'Give fans a discount code', 'g'],
] : [];

$pageTitle = 'Create — obitickets';
$bodyClass = 'classic-page app-dark cr-page';
$hideWhatsApp = true;
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/app-dark.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-dark.css') ?: time() ?>">
<div class="wrap cr">
  <div class="ad-top">
    <a class="ad-icon" href="/" data-back aria-label="Back"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>
    <h1>Create</h1>
  </div>

  <?php if ($isOrganizer): ?>
    <p class="cr-sub">What would you like to make?</p>
    <div class="cr-grid">
      <?php foreach ($choices as [$href, $icon, $title, $sub, $tone]): ?>
        <a class="cr-card cr-<?= $tone ?>" href="<?= htmlspecialchars($href) ?>">
          <i><?= $svg($icon) ?></i>
          <b><?= htmlspecialchars($title) ?></b>
          <small><?= htmlspecialchars($sub) ?></small>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="cr-hero">
      <i><?= $svg('star') ?></i>
      <h2>Host your own events</h2>
      <p>Organizers sell tickets on obitickets and post event videos to the home feed. It is free to start: you only pay a small fee when you sell a paid ticket.</p>
      <a class="cr-cta" href="/become-organizer.php">Become an organizer</a>
      <a class="cr-link" href="/discover.php">Or find events to go to</a>
    </div>
  <?php endif; ?>
</div>
<style>
  .cr{max-width:620px; padding-bottom:28px}
  .cr .ad-top h1{font-size:22px}
  .cr-sub{margin:4px 0 16px; color:var(--ad-mute)}
  .cr-grid{display:grid; grid-template-columns:1fr 1fr; gap:12px}
  body.cr-page a.cr-card{position:relative; display:grid; align-content:start; gap:8px; min-height:168px; padding:18px 16px; border-radius:20px; background:var(--ad-card); box-shadow:inset 0 0 0 1px var(--ad-line); color:#fff; overflow:hidden; transition:transform .2s, background .2s}
  body.cr-page a.cr-card:hover{transform:translateY(-2px); background:#1E181A; color:#fff}
  .cr-card i{width:52px; height:52px; border-radius:16px; display:grid; place-items:center; color:#fff}
  .cr-r i{background:linear-gradient(135deg,#F0313F,#A3101C)} .cr-b i{background:linear-gradient(135deg,#3B82F6,#1E40AF)}
  .cr-y i{background:linear-gradient(135deg,#F59E0B,#B45309)} .cr-g i{background:linear-gradient(135deg,#22C55E,#15803D)}
  .cr-card b{font-size:16px; font-weight:800; margin-top:6px}
  .cr-card small{font-size:12.5px; line-height:1.45; color:var(--ad-mute)}
  .cr-card:focus-visible{outline:3px solid #fff; outline-offset:3px}
  .cr-hero{margin-top:24px; padding:30px 20px; border-radius:24px; background:radial-gradient(120% 90% at 50% 0%, rgba(240,49,63,.25), transparent 70%), var(--ad-card); text-align:center}
  .cr-hero i{width:64px; height:64px; margin:0 auto 14px; border-radius:50%; background:var(--ad-red); color:#fff; display:grid; place-items:center}
  .cr-hero h2{margin:0 0 8px; color:#fff; font-family:'Outfit','Plus Jakarta Sans',sans-serif; font-size:22px}
  .cr-hero p{margin:0 auto 20px; max-width:400px; color:var(--ad-soft); font-size:14.5px; line-height:1.6}
  body.cr-page a.cr-cta{display:flex; justify-content:center; align-items:center; height:50px; border-radius:14px; background:var(--ad-red); color:#fff; font-weight:800; box-shadow:0 16px 32px -16px rgba(240,49,63,.95)}
  body.cr-page a.cr-cta:hover{background:#D72634; color:#fff}
  body.cr-page a.cr-link{display:inline-block; margin-top:14px; color:var(--ad-soft); font-weight:700; font-size:14px}
  @media (prefers-reduced-motion:reduce){ .cr-card{transition:none} }
</style>
<script src="/assets/js/app-dark.js?v=<?= @filemtime(__DIR__ . '/assets/js/app-dark.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
