<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$organizer = is_string($slug) && $slug !== '' ? get_organizer_profile_by_slug($slug) : null;

if (!$organizer) {
    $pageTitle = 'Organizer not found — obitickets';
    $bodyClass = 'classic-page';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="wrap">
      <section class="auth-section">
        <div class="auth-card">
          <h1>Organizer not found</h1>
          <p class="sub">This organizer page may have moved, or the link is incorrect.</p>
          <a class="btn btn-purple btn-block btn-lg" href="/" style="margin-top:24px">Back to homepage</a>
        </div>
      </section>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$events = get_public_events_for_organizer((int) $organizer['user_id']);
$eventCount = count($events['upcoming']) + count($events['past']);
$ratingSummary = get_organizer_rating_summary((int) $organizer['user_id']);

$pageTitle = $organizer['org_name'] . ' — obitickets';
$pageDescription = ($organizer['bio'] ?: ('Events by ' . $organizer['org_name'] . ' on obitickets.'));
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap" style="padding:48px 0 8px;">
  <div class="organizer-row" style="align-items:flex-start; gap:20px;">
    <span class="organizer-avatar organizer-avatar-lg"><?= htmlspecialchars(initials_from_name($organizer['org_name'])) ?></span>
    <div>
      <h1 style="font-size:clamp(1.6rem,3vw,2.1rem); display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <?= htmlspecialchars($organizer['org_name']) ?>
        <?php if ((int) $organizer['verified'] === 1): ?>
          <span style="display:inline-flex; align-items:center; gap:5px; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--purple-deep); background:var(--purple-tint); border-radius:100px; padding:4px 12px;">
            <svg width="12" height="12"><use href="#ic-shield"/></svg> Verified
          </span>
        <?php endif; ?>
      </h1>
      <p class="muted" style="margin-top:6px; font-size:0.92rem; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <span><?= $eventCount ?> event<?= $eventCount === 1 ? '' : 's' ?> on obitickets</span>
        <?php if ($ratingSummary['count'] > 0): ?>
          <span style="display:inline-flex; align-items:center; gap:6px;">
            <?= render_star_rating($ratingSummary['average']) ?>
            <strong style="color:var(--ink)"><?= number_format($ratingSummary['average'], 1) ?></strong>
            <span>(<?= $ratingSummary['count'] ?> review<?= $ratingSummary['count'] === 1 ? '' : 's' ?>)</span>
          </span>
        <?php endif; ?>
      </p>
      <?php if ($organizer['bio']): ?>
        <p style="margin-top:14px; max-width:60ch; color:var(--muted-2);"><?= nl2br(htmlspecialchars($organizer['bio'])) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="wrap" style="padding:32px 0 64px;">
  <?php if ($events['upcoming']): ?>
    <section>
      <h2 style="margin-bottom:20px">Upcoming events</h2>
      <div class="poster-grid">
        <?php foreach ($events['upcoming'] as $e) render_poster_card($e); ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($events['past']): ?>
    <section style="margin-top:<?= $events['upcoming'] ? '48px' : '0' ?>">
      <h2 style="margin-bottom:20px">Past events</h2>
      <div class="poster-grid">
        <?php foreach ($events['past'] as $e) render_poster_card($e); ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!$events['upcoming'] && !$events['past']): ?>
    <div class="empty-state">
      <div class="empty-badge"><svg width="32" height="32"><use href="#ic-cal"/></svg></div>
      <h3>No published events yet</h3>
      <p>Check back soon — events from <?= htmlspecialchars($organizer['org_name']) ?> will show up here.</p>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
