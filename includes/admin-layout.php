<?php
declare(strict_types=1);

/**
 * The admin dashboard's own shell — sidebar + topbar — deliberately separate
 * from includes/header.php (the public marketing site's nav): a SaaS admin
 * reads as a distinct, denser, more utilitarian surface, not another page of
 * the storefront. Shares style.css (same tokens/utility classes) but never
 * the public <nav>/<footer>.
 *
 * Usage: set $pageTitle, then render_admin_head('events'); ... render_admin_foot();
 */
function render_admin_head(string $active): void
{
    $admin = current_user();
    $stats = get_platform_stats();
    $pageTitleRaw = $GLOBALS['pageTitle'] ?? 'Admin';
    $pageTitle = $pageTitleRaw . ' — obitickets';

    $sections = [
        ['label' => null, 'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/admin.php', 'icon' => 'ic-grid', 'perm' => 'dashboard.view'],
        ]],
        ['label' => 'Events', 'items' => [
            ['key' => 'events', 'label' => 'All Events', 'href' => '/admin-events.php', 'icon' => 'ic-cal', 'perm' => 'events.view', 'badge' => $stats['pending_events'] ?: null],
            ['key' => 'categories', 'label' => 'Categories', 'href' => '/admin-categories.php', 'icon' => 'ic-tag', 'perm' => 'categories.manage'],
        ]],
        ['label' => 'Users', 'items' => [
            ['key' => 'customers', 'label' => 'Customers', 'href' => '/admin-customers.php', 'icon' => 'ic-user', 'perm' => 'customers.view'],
            ['key' => 'organizers', 'label' => 'Organizers', 'href' => '/admin-organizers.php', 'icon' => 'ic-briefcase', 'perm' => 'organizers.view'],
        ]],
        ['label' => 'Ticketing', 'items' => [
            ['key' => 'tickets', 'label' => 'Tickets', 'href' => '/admin-tickets.php', 'icon' => 'ic-ticket', 'perm' => 'tickets.view'],
            ['key' => 'checkin', 'label' => 'Check-In', 'href' => '/admin-checkin.php', 'icon' => 'ic-check', 'perm' => 'tickets.checkin'],
            ['key' => 'orders', 'label' => 'Orders', 'href' => '/admin-orders.php', 'icon' => 'ic-bolt', 'perm' => 'orders.view'],
        ]],
        ['label' => 'Finance', 'items' => [
            ['key' => 'payouts', 'label' => 'Payouts', 'href' => '/admin-payouts.php', 'icon' => 'ic-cash', 'perm' => 'payouts.view', 'badge' => $stats['pending_payouts'] ?: null],
            ['key' => 'refunds', 'label' => 'Refunds', 'href' => '/admin-refunds.php', 'icon' => 'ic-x', 'perm' => 'orders.refund'],
        ]],
        ['label' => 'Marketing', 'items' => [
            ['key' => 'promo', 'label' => 'Promo Codes', 'href' => '/admin-promo.php', 'icon' => 'ic-tag', 'perm' => 'promo.view'],
        ]],
        ['label' => 'Support', 'items' => [
            ['key' => 'contact', 'label' => 'Contact Messages', 'href' => '/admin-contact.php', 'icon' => 'ic-mail', 'perm' => 'contact.view', 'badge' => $stats['new_contact_messages'] ?: null],
        ]],
        ['label' => 'Security', 'items' => [
            ['key' => 'admins', 'label' => 'Admin Users', 'href' => '/admin-admins.php', 'icon' => 'ic-shield', 'perm' => 'admins.manage'],
            ['key' => 'audit', 'label' => 'Audit Logs', 'href' => '/admin-audit.php', 'icon' => 'ic-clock', 'perm' => 'audit.view'],
        ]],
        ['label' => 'Settings', 'items' => [
            ['key' => 'settings', 'label' => 'General', 'href' => '/admin-settings.php', 'icon' => 'ic-settings', 'perm' => 'settings.view'],
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
      <a class="admin-logo" href="/admin.php">
        <svg class="logo-mark" viewBox="0 0 34 34" width="30" height="30"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/></svg>
        <span class="admin-logo-text">obitickets <em>admin</em></span>
      </a>
      <button type="button" class="admin-collapse-toggle" id="adminCollapseToggle" aria-label="Collapse sidebar">
        <svg width="15" height="15"><use href="#ic-chev" transform="rotate(180 12 12)"/></svg>
      </button>
    </div>
    <nav class="admin-nav">
      <?php foreach ($sections as $section): ?>
        <?php
        $visibleItems = array_filter($section['items'], static fn ($item) => admin_can($item['perm']));
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
      <form class="admin-search" action="/admin-search.php" method="get">
        <svg width="16" height="16"><use href="#ic-search"/></svg>
        <input type="text" name="q" placeholder="Search events, organizers, customers, orders, tickets…">
      </form>
      <div class="admin-profile" id="adminProfile">
        <button type="button" class="admin-profile-btn" id="adminProfileBtn">
          <span class="admin-avatar"><?= htmlspecialchars(initials_from_name($admin['name'])) ?></span>
          <span class="admin-profile-text">
            <span class="admin-topbar-name"><?= htmlspecialchars($admin['name']) ?></span>
            <span class="admin-topbar-role"><?= htmlspecialchars(ADMIN_ROLES[$admin['admin_role'] ?? ''] ?? 'Super Admin') ?></span>
          </span>
          <svg width="14" height="14" class="admin-profile-chev"><use href="#ic-chev" transform="rotate(90 12 12)"/></svg>
        </button>
        <div class="admin-profile-menu" id="adminProfileMenu">
          <a href="/">Back to site</a>
          <a href="/logout.php">Log out</a>
        </div>
      </div>
    </header>
    <main class="admin-content" id="adminContent">
    <?php
}

function render_admin_foot(): void
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
