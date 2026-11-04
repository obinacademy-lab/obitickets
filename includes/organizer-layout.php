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

    // The menu: eight destinations. A destination with several tabs is one place in the menu, while each tab
    // keeps its own page and address, so old links and bookmarks still open the right thing.
    // A tab is [active key, label, address, permission]. Each role only sees the tabs it may open.
    $hubs = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'ic-grid', 'group' => 'Main', 'tabs' => [
            ['dashboard', 'Overview', '/org.php', 'dashboard.view'],
        ]],
        ['key' => 'events', 'label' => 'Events', 'icon' => 'ic-cal', 'group' => 'Main', 'tabs' => [
            ['my-events', 'All events', '/my-events.php', 'events.view'],
            ['events-live', 'Live', '/my-events.php?when=live', 'events.view'],
            ['drafts', 'Drafts', '/my-events.php?status=DRAFT', 'events.view'],
            ['events-past', 'Past', '/my-events.php?when=past', 'events.view'],
            ['tickets', 'Ticket types', '/org-tickets.php', 'tickets.view'],
        ]],
        ['key' => 'orders', 'label' => 'Orders', 'icon' => 'ic-receipt', 'group' => 'Main', 'tabs' => [
            ['orders', 'Orders', '/org-orders.php', 'tickets.view'],
            ['transactions', 'Payments', '/org-transactions.php', 'transactions.view'],
            ['refunds', 'Refunds', '/org-refunds.php', 'refunds.view'],
        ]],
        ['key' => 'attendees', 'label' => 'Attendees', 'icon' => 'ic-people', 'group' => 'Main', 'tabs' => [
            ['attendees', 'Attendees', '/org-attendees.php', 'attendees.view'],
        ]],
        ['key' => 'checkin', 'label' => 'Check-in', 'icon' => 'ic-scan', 'group' => 'Main', 'tabs' => [
            ['checkin', 'Check-in', '/checkin.php', 'checkin.use'],
        ]],
        ['key' => 'money', 'label' => 'Earnings & payouts', 'icon' => 'ic-wallet', 'group' => 'Money', 'tabs' => [
            ['payouts', 'Payouts', '/org-payouts.php', 'payouts.view'],
            ['earnings', 'How it is calculated', '/org-earnings.php', 'earnings.view'],
        ]],
        ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'ic-chart', 'group' => 'Money', 'tabs' => [
            ['analytics', 'Performance', '/org-analytics.php', 'analytics.view'],
            ['sales', 'Sales', '/org-sales.php', 'sales.view'],
        ]],
        ['key' => 'team', 'label' => 'Team', 'icon' => 'ic-team', 'group' => 'Workspace', 'tabs' => [
            ['team', 'Team', '/org-team.php', 'team.manage'],
        ]],
    ];
    $footLinks = [
        ['settings', 'Settings', '/org-settings.php', 'ic-settings'],
        ['support', 'Help', '/org-support.php', 'ic-help'],
    ];

    if ($active === 'my-events') {
        $when = $_GET['when'] ?? '';
        if (($_GET['status'] ?? '') === 'DRAFT') {
            $active = 'drafts';
        } elseif ($when === 'live') {
            $active = 'events-live';
        } elseif ($when === 'past') {
            $active = 'events-past';
        }
    }

    $visibleHubs = [];
    $activeHub = null;
    foreach ($hubs as $hub) {
        $hub['tabs'] = array_values(array_filter($hub['tabs'], static fn ($t) => organizer_can($orgContext, $t[3])));
        if (!$hub['tabs']) {
            continue;
        }
        $hub['href'] = $hub['tabs'][0][2];
        $hub['badge'] = $hub['key'] === 'money' && $pendingPayouts && organizer_can($orgContext, 'payouts.view') ? count($pendingPayouts) : null;
        if (in_array($active, array_column($hub['tabs'], 0), true) || ($active === 'create-event' && $hub['key'] === 'events')) {
            $activeHub = $hub;
        }
        $visibleHubs[] = $hub;
    }

    $crumb = [$pageTitleRaw];
    $activeTabLabel = null;
    if ($activeHub) {
        foreach ($activeHub['tabs'] as $t) {
            if ($t[0] === $active) {
                $activeTabLabel = $t[1];
            }
        }
        if (count($activeHub['tabs']) > 1 && $activeTabLabel !== null) {
            $crumb = [$activeHub['label'], $activeTabLabel];
        }
    }
    $showTabs = $activeHub && count($activeHub['tabs']) > 1 && empty($GLOBALS['orgNoTabs']) && empty($GLOBALS['orgFocusMode']);

    // the phone's bottom bar: four main places, and everything else under More
    $tabbarKeys = ['overview', 'events', 'orders', 'checkin'];
    $GLOBALS['orgMobileNav'] = [
        'active' => $activeHub['key'] ?? ($active === 'settings' || $active === 'support' ? 'more' : ''),
        'bar' => array_values(array_filter($visibleHubs, static fn ($h) => in_array($h['key'], $tabbarKeys, true))),
        'more' => array_values(array_filter($visibleHubs, static fn ($h) => !in_array($h['key'], $tabbarKeys, true))),
        'foot' => $footLinks,
    ];
    $orgDisplayName = $profile['org_name'] ?: $actor['name'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
<link rel="icon" href="data:,">
<?php include __DIR__ . '/pwa-head.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/night.css?v=<?= @filemtime(__DIR__ . '/../assets/css/night.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/css/org-dashboard.css?v=<?= @filemtime(__DIR__ . '/../assets/css/org-dashboard.css') ?: time() ?>">
<?php foreach ($GLOBALS['orgExtraCss'] ?? [] as $extraCss): ?>
<link rel="stylesheet" href="/assets/css/<?= htmlspecialchars($extraCss) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/' . $extraCss) ?: time() ?>">
<?php endforeach; ?>
<script>(function(){try{if(localStorage.getItem('adminSidebarCollapsed')==='1'){document.documentElement.classList.add('admin-collapsed-pref');}}catch(e){}})();</script>
</head>
<body class="admin-body org-shell<?= !empty($GLOBALS['orgFocusMode']) ? ' org-focus' : '' ?>">
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
    <nav class="admin-nav" aria-label="Organizer dashboard">
      <?php $lastGroup = null; foreach ($visibleHubs as $hub): ?>
        <?php if ($hub['group'] !== $lastGroup): $lastGroup = $hub['group']; ?><div class="admin-nav-label"><span><?= htmlspecialchars($hub['group']) ?></span></div><?php endif; ?>
        <?php $isOn = $activeHub && $activeHub['key'] === $hub['key']; ?>
        <a class="admin-nav-item<?= $isOn ? ' active' : '' ?>" href="<?= htmlspecialchars($hub['href']) ?>" title="<?= htmlspecialchars($hub['label']) ?>"<?= $isOn ? ' aria-current="page"' : '' ?>>
          <svg width="17" height="17"><use href="#<?= $hub['icon'] ?>"/></svg>
          <span class="admin-nav-item-label"><?= htmlspecialchars($hub['label']) ?></span>
          <?php if (!empty($hub['badge'])): ?><span class="admin-nav-badge"><?= (int) $hub['badge'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="org-side-foot">
      <?php foreach ($footLinks as $link): ?>
        <a class="admin-nav-item<?= $active === $link[0] ? ' active' : '' ?>" href="<?= $link[2] ?>" title="<?= $link[1] ?>"<?= $active === $link[0] ? ' aria-current="page"' : '' ?>>
          <svg width="17" height="17"><use href="#<?= $link[3] ?>"/></svg>
          <span class="admin-nav-item-label"><?= $link[1] ?></span>
        </a>
      <?php endforeach; ?>
      <div class="admin-profile org-card" id="adminProfile">
        <button type="button" class="admin-profile-btn" id="adminProfileBtn" aria-label="Account menu">
          <span class="admin-avatar"><?= htmlspecialchars(initials_from_name($orgDisplayName)) ?></span>
          <span class="admin-profile-text">
            <span class="admin-topbar-name"><?= htmlspecialchars($orgDisplayName) ?></span>
            <span class="admin-topbar-role"><?= $orgContext['is_owner'] ? 'Owner' : htmlspecialchars(ORGANIZER_ROLES[$orgContext['role']] ?? 'Team member') ?></span>
          </span>
        </button>
        <div class="admin-profile-menu" id="adminProfileMenu">
          <a href="/org-settings.php">Settings</a>
          <a href="/">Back to site</a>
          <a href="/logout.php">Log out</a>
        </div>
      </div>
    </div>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <a class="org-mlogo" href="/org.php" aria-label="obitickets organizer home"><svg viewBox="0 0 34 34" width="30" height="30" aria-hidden="true"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/></svg></a>
      <div class="admin-breadcrumb">
        <?php foreach ($crumb as $i => $part): ?>
          <?php if ($i > 0): ?><svg width="12" height="12" class="admin-breadcrumb-sep"><use href="#ic-chev"/></svg><?php endif; ?>
          <span class="<?= $i === count($crumb) - 1 ? 'current' : '' ?>"><?= htmlspecialchars($part) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="org-top-right">
        <?php if ($profile['verification_status'] === 'VERIFIED'): ?>
          <span class="admin-badge admin-badge-success org-verified"><svg width="11" height="11"><use href="#ic-check"/></svg> Verified</span>
        <?php endif; ?>
        <a class="org-bell" href="/org-notifications.php" title="Notifications" aria-label="Notifications<?= $unread > 0 ? ' (' . (int) $unread . ' unread)' : '' ?>">
          <svg width="19" height="19"><use href="#ic-bell"/></svg>
          <?php if ($unread > 0): ?><span class="admin-nav-badge"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
        </a>
        <?php if (organizer_can($orgContext, 'events.manage')): ?>
          <a class="org-create" href="/event-create.php"><svg width="15" height="15" aria-hidden="true"><use href="#ic-plus"/></svg><span>Create event</span></a>
        <?php endif; ?>
      </div>
    </header>
    <?php if ($showTabs): ?>
      <nav class="org-tabs" aria-label="<?= htmlspecialchars($activeHub['label']) ?>">
        <?php foreach ($activeHub['tabs'] as $t): ?>
          <a href="<?= htmlspecialchars($t[2]) ?>" class="<?= $t[0] === $active ? 'is-on' : '' ?>"<?= $t[0] === $active ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($t[1]) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <main class="admin-content" id="adminContent">
    <?php
}

function render_organizer_foot(): void
{
    ?>
    </main>
  </div>
</div>

<?php $mobileNav = $GLOBALS['orgMobileNav'] ?? null; if ($mobileNav): ?>
<nav class="org-tabbar" aria-label="Main">
  <?php foreach ($mobileNav['bar'] as $hub): $isOn = $mobileNav['active'] === $hub['key']; ?>
    <a href="<?= htmlspecialchars($hub['href']) ?>" class="<?= $isOn ? 'is-on' : '' ?>"<?= $isOn ? ' aria-current="page"' : '' ?>><svg width="21" height="21" aria-hidden="true"><use href="#<?= $hub['icon'] ?>"/></svg><span><?= $hub['key'] === 'overview' ? 'Home' : htmlspecialchars($hub['label']) ?></span></a>
  <?php endforeach; ?>
  <button type="button" id="orgMoreBtn" class="<?= !in_array($mobileNav['active'], array_column($mobileNav['bar'], 'key'), true) && $mobileNav['active'] !== '' ? 'is-on' : '' ?>" aria-haspopup="dialog" aria-expanded="false"><svg width="21" height="21" aria-hidden="true"><use href="#ic-more"/></svg><span>More</span></button>
</nav>
<div class="org-sheet" id="orgSheet" role="dialog" aria-modal="true" aria-label="More" hidden>
  <div class="org-sheet-in">
    <p class="org-sheet-h">More</p>
    <?php foreach ($mobileNav['more'] as $hub): ?>
      <a href="<?= htmlspecialchars($hub['href']) ?>"><svg width="19" height="19" aria-hidden="true"><use href="#<?= $hub['icon'] ?>"/></svg><?= htmlspecialchars($hub['label']) ?><?php if (!empty($hub['badge'])): ?><span class="admin-nav-badge"><?= (int) $hub['badge'] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
    <?php foreach ($mobileNav['foot'] as $link): ?>
      <a href="<?= $link[2] ?>"><svg width="19" height="19" aria-hidden="true"><use href="#<?= $link[3] ?>"/></svg><?= $link[1] ?></a>
    <?php endforeach; ?>
    <a href="/"><svg width="19" height="19" aria-hidden="true"><use href="#ic-arrow" transform="rotate(180 12 12)"/></svg>Back to site</a>
    <a href="/logout.php"><svg width="19" height="19" aria-hidden="true"><use href="#ic-x"/></svg>Log out</a>
  </div>
</div>
<?php endif; ?>

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
<script src="/assets/js/org-shell.js?v=<?= @filemtime(__DIR__ . '/../assets/js/org-shell.js') ?: time() ?>"></script>
</body>
</html>
<?php
}
