<?php
declare(strict_types=1);

/**
 * The organizer dashboard's own shell — sidebar + topbar — same pattern as
 * includes/admin-layout.php (and reuses the exact same CSS classes/tokens:
 * .admin-shell, .admin-sidebar, .admin-topbar, etc. were never actually
 * admin-specific, just admin-first — deliberately not duplicating a parallel
 * CSS system for a second dashboard shell).
 *
 * Usage: set $pageTitle and $orgContext (from require_organizer_access()),
 * then render_organizer_head('dashboard', $orgContext); ... render_organizer_foot();
 */
function render_organizer_head(string $active, array $orgContext): void
{
    $actor = current_user();
    $stmt = db()->prepare('SELECT org_name, verification_status FROM organizer_profiles WHERE user_id = ?');
    $stmt->execute([$orgContext['organizer_id']]);
    $profile = $stmt->fetch() ?: ['org_name' => $actor['name'], 'verification_status' => 'UNVERIFIED'];

    $unread = count_unread_notifications((int) $actor['id']);
    $pendingPayouts = get_pending_payouts_for_organizer($orgContext['organizer_id']);
    $pageTitleRaw = $GLOBALS['pageTitle'] ?? 'Dashboard';
    $pageTitle = $pageTitleRaw . ' — obitickets';

    $sections = [
        ['label' => null, 'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/org.php', 'icon' => 'ic-grid', 'perm' => 'dashboard.view'],
        ]],
        ['label' => 'Events', 'items' => [
            ['key' => 'my-events', 'label' => 'My Events', 'href' => '/my-events.php', 'icon' => 'ic-cal', 'perm' => 'events.view'],
            ['key' => 'create-event', 'label' => 'Create Event', 'href' => '/event-create.php', 'icon' => 'ic-bolt', 'perm' => 'events.manage'],
            ['key' => 'drafts', 'label' => 'Drafts', 'href' => '/my-events.php?status=DRAFT', 'icon' => 'ic-tag', 'perm' => 'events.view'],
        ]],
        ['label' => 'Ticketing', 'items' => [
            ['key' => 'tickets', 'label' => 'Tickets', 'href' => '/org-tickets.php', 'icon' => 'ic-ticket', 'perm' => 'tickets.view'],
            ['key' => 'orders', 'label' => 'Orders', 'href' => '/org-orders.php', 'icon' => 'ic-bolt', 'perm' => 'tickets.view'],
            ['key' => 'attendees', 'label' => 'Attendees', 'href' => '/org-attendees.php', 'icon' => 'ic-people', 'perm' => 'attendees.view'],
            ['key' => 'checkin', 'label' => 'Check-In', 'href' => '/checkin.php', 'icon' => 'ic-check', 'perm' => 'checkin.use'],
        ]],
        ['label' => 'Sales & Finance', 'items' => [
            ['key' => 'sales', 'label' => 'Sales', 'href' => '/org-sales.php', 'icon' => 'ic-bolt', 'perm' => 'sales.view'],
            ['key' => 'transactions', 'label' => 'Transactions', 'href' => '/org-transactions.php', 'icon' => 'ic-cash', 'perm' => 'transactions.view'],
            ['key' => 'earnings', 'label' => 'Earnings', 'href' => '/org-earnings.php', 'icon' => 'ic-shield', 'perm' => 'earnings.view'],
            ['key' => 'payouts', 'label' => 'Payouts', 'href' => '/org-payouts.php', 'icon' => 'ic-cash', 'perm' => 'payouts.view', 'badge' => $pendingPayouts ? count($pendingPayouts) : null],
            ['key' => 'refunds', 'label' => 'Refunds', 'href' => '/org-refunds.php', 'icon' => 'ic-x', 'perm' => 'refunds.view'],
        ]],
        ['label' => 'Marketing', 'items' => [
            ['key' => 'promo', 'label' => 'Promo Codes', 'href' => '/org-promo.php', 'icon' => 'ic-tag', 'perm' => 'promo.view'],
            ['key' => 'marketing', 'label' => 'Event Sharing', 'href' => '/org-marketing.php', 'icon' => 'ic-share', 'perm' => 'marketing.view'],
        ]],
        ['label' => 'Analytics', 'items' => [
            ['key' => 'analytics', 'label' => 'Analytics', 'href' => '/org-analytics.php', 'icon' => 'ic-briefcase', 'perm' => 'analytics.view'],
        ]],
        ['label' => 'Team', 'items' => [
            ['key' => 'team', 'label' => 'Team Members', 'href' => '/org-team.php', 'icon' => 'ic-people', 'perm' => 'team.manage'],
        ]],
        ['label' => 'Support', 'items' => [
            ['key' => 'support', 'label' => 'Support Tickets', 'href' => '/org-support.php', 'icon' => 'ic-mail', 'perm' => 'dashboard.view'],
        ]],
        ['label' => 'Settings', 'items' => [
            ['key' => 'settings', 'label' => 'Settings', 'href' => '/org-settings.php', 'icon' => 'ic-settings', 'perm' => 'dashboard.view'],
        ]],
    ];

    $sectionLabel = null;
    foreach ($sections as $section) {
        foreach ($section['items'] as $item) {
            if ($item['key'] === $active) {
                $sectionLabel = $item['label'];
                break 2;
            }
        }
    }
    $crumb = $sectionLabel && $sectionLabel !== $pageTitleRaw ? [$sectionLabel, $pageTitleRaw] : [$pageTitleRaw];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<script>(function(){try{if(localStorage.getItem('adminSidebarCollapsed')==='1'){document.documentElement.classList.add('admin-collapsed-pref');}}catch(e){}})();</script>
</head>
<body class="admin-body">
<?php include __DIR__ . '/icons.php'; ?>
<div class="admin-progress" id="adminProgress"></div>

<div class="admin-shell">
  <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-logo-row">
      <a class="admin-logo" href="/org.php">
        <svg class="logo-mark" viewBox="0 0 34 34" width="30" height="30"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/></svg>
        <span class="admin-logo-text">obitickets <em>organizer</em></span>
      </a>
      <button type="button" class="admin-collapse-toggle" id="adminCollapseToggle" aria-label="Collapse sidebar">
        <svg width="15" height="15"><use href="#ic-chev" transform="rotate(180 12 12)"/></svg>
      </button>
    </div>
    <nav class="admin-nav">
      <?php foreach ($sections as $section): ?>
        <?php
        $visibleItems = array_filter($section['items'], static fn ($item) => organizer_can($orgContext, $item['perm']));
        if (!$visibleItems) {
            continue;
        }
        ?>
        <?php if ($section['label']): ?><div class="admin-nav-label"><span><?= htmlspecialchars($section['label']) ?></span></div><?php endif; ?>
        <?php foreach ($visibleItems as $item): ?>
          <a class="admin-nav-item<?= $active === $item['key'] ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>" title="<?= htmlspecialchars($item['label']) ?>">
            <svg width="17" height="17"><use href="#<?= $item['icon'] ?>"/></svg>
            <span class="admin-nav-item-label"><?= htmlspecialchars($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?><span class="admin-nav-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <a class="admin-nav-item admin-nav-exit" href="/" title="Back to site">
      <svg width="17" height="17"><use href="#ic-arrow" transform="rotate(180 12 12)"/></svg>
      <span class="admin-nav-item-label">Back to site</span>
    </a>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="Toggle menu">
        <svg width="20" height="20"><use href="#ic-menu"/></svg>
      </button>
      <div class="admin-breadcrumb">
        <?php foreach ($crumb as $i => $part): ?>
          <?php if ($i > 0): ?><svg width="12" height="12" class="admin-breadcrumb-sep"><use href="#ic-chev"/></svg><?php endif; ?>
          <span class="<?= $i === count($crumb) - 1 ? 'current' : '' ?>"><?= htmlspecialchars($part) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($profile['verification_status'] === 'VERIFIED'): ?>
        <span class="admin-badge admin-badge-success" style="margin-right:10px"><svg width="11" height="11"><use href="#ic-check"/></svg> Verified</span>
      <?php endif; ?>
      <a class="admin-profile-btn" href="/org-notifications.php" style="position:relative; padding:8px; margin-right:4px" title="Notifications">
        <svg width="19" height="19"><use href="#ic-bell"/></svg>
        <?php if ($unread > 0): ?><span class="admin-nav-badge" style="position:absolute; top:2px; right:2px"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
      </a>
      <div class="admin-profile" id="adminProfile">
        <button type="button" class="admin-profile-btn" id="adminProfileBtn">
          <span class="admin-avatar"><?= htmlspecialchars(initials_from_name($actor['name'])) ?></span>
          <span class="admin-profile-text">
            <span class="admin-topbar-name"><?= htmlspecialchars($profile['org_name'] ?: $actor['name']) ?></span>
            <span class="admin-topbar-role"><?= $orgContext['is_owner'] ? 'Owner' : htmlspecialchars(ORGANIZER_ROLES[$orgContext['role']] ?? 'Team member') ?></span>
          </span>
          <svg width="14" height="14" class="admin-profile-chev"><use href="#ic-chev" transform="rotate(90 12 12)"/></svg>
        </button>
        <div class="admin-profile-menu" id="adminProfileMenu">
          <a href="/org-settings.php">Settings</a>
          <a href="/">Back to site</a>
          <a href="/logout.php">Log out</a>
        </div>
      </div>
    </header>
    <main class="admin-content" id="adminContent">
    <?php
}

function render_organizer_foot(): void
{
    ?>
    </main>
  </div>
</div>

<div class="admin-toast-stack" id="adminToastStack" aria-live="polite"></div>
<div class="admin-modal-backdrop" id="adminModalBackdrop">
  <div class="admin-modal" role="alertdialog" aria-modal="true">
    <h3 id="adminModalTitle">Are you sure?</h3>
    <p id="adminModalBody"></p>
    <div class="admin-modal-actions">
      <button type="button" class="btn btn-line" id="adminModalCancel">Cancel</button>
      <button type="button" class="btn" id="adminModalConfirm" style="background:var(--danger); color:#fff">Confirm</button>
    </div>
  </div>
</div>

<script src="/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: time() ?>"></script>
<script src="/assets/js/admin.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin.js') ?: time() ?>"></script>
</body>
</html>
<?php
}
