<?php
require_once __DIR__ . '/bootstrap.php';
$authUser = current_user();
$navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Expects $pageTitle and optionally $pageDescription to be set before including this file.
if (!isset($pageTitle)) {
    $pageTitle = 'obitickets — Ticketing Made Simple';
}
if (!isset($pageDescription)) {
    $pageDescription = 'Find concerts, conferences, comedy and festivals across Africa. Buy your ticket in under a minute.';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<?php if (str_contains($bodyClass ?? '', 'classic-page')): ?>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
</head>
<body<?= isset($bodyClass) ? ' class="' . htmlspecialchars($bodyClass) . '"' : '' ?>>
<?php include __DIR__ . '/icons.php'; ?>

<?php if (str_contains($bodyClass ?? '', 'classic-page')): ?>
<div class="masthead-bar">MTN MoMo &amp; Airtel Money accepted</div>
<?php endif; ?>
<nav class="site-nav">
  <div class="wrap nav-row">
    <a class="logo" href="/">
      <svg class="logo-mark" viewBox="0 0 34 34">
        <rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/>
        <circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/>
        <circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/>
      </svg>
      obitickets
    </a>
    <div class="nav-links">
      <a class="ghost" href="/">Browse events</a>
      <a class="ghost" href="/my-events.php">Sell tickets</a>
      <a class="ghost" href="/about.php">About Us</a>
      <a class="ghost" href="/contact.php">Contact Us</a>
      <?php if ($authUser): ?>
        <?php if ($authUser['role'] === 'ADMIN'): ?>
          <a class="ghost" href="/admin.php">Admin</a>
        <?php endif; ?>
        <a class="ghost" href="/dashboard.php">Hi, <?= htmlspecialchars(explode(' ', $authUser['name'])[0]) ?></a>
        <a class="btn btn-purple" href="/logout.php">Log out</a>
      <?php else: ?>
        <a class="ghost" href="/login.php">Log in</a>
        <a class="btn btn-purple" href="/signup.php">Sign up</a>
      <?php endif; ?>
    </div>
    <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="navDrawer">
      <span class="nav-burger"><span></span><span></span><span></span></span>
    </button>
  </div>
</nav>

<div class="nav-backdrop" id="navBackdrop"></div>
<aside class="nav-drawer" id="navDrawer" aria-hidden="true">
  <div class="nav-drawer-accent"></div>
  <div class="nav-drawer-head">
    <a class="logo" href="/">
      <svg class="logo-mark" viewBox="0 0 34 34">
        <rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/>
        <circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/>
        <circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/>
      </svg>
      obitickets
    </a>
    <button type="button" class="nav-drawer-close" id="navDrawerClose" aria-label="Close menu">
      <svg width="18" height="18"><use href="#ic-x"/></svg>
    </button>
  </div>
  <div class="nav-drawer-links">
    <a class="nav-drawer-row<?= $navPath === '/' ? ' current' : '' ?>" href="/">
      <span class="ic"><svg width="17" height="17"><use href="#ic-cal"/></svg></span>
      <span class="label">Browse events</span>
      <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
    </a>
    <a class="nav-drawer-row<?= $navPath === '/my-events.php' ? ' current' : '' ?>" href="/my-events.php">
      <span class="ic"><svg width="17" height="17"><use href="#ic-bolt"/></svg></span>
      <span class="label">Sell tickets</span>
      <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
    </a>
    <a class="nav-drawer-row<?= $navPath === '/about.php' ? ' current' : '' ?>" href="/about.php">
      <span class="ic"><svg width="17" height="17"><use href="#ic-info"/></svg></span>
      <span class="label">About Us</span>
      <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
    </a>
    <a class="nav-drawer-row<?= $navPath === '/contact.php' ? ' current' : '' ?>" href="/contact.php">
      <span class="ic"><svg width="17" height="17"><use href="#ic-mail"/></svg></span>
      <span class="label">Contact Us</span>
      <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
    </a>
    <div class="nav-drawer-div"></div>
    <?php if ($authUser): ?>
      <?php if ($authUser['role'] === 'ADMIN'): ?>
        <a class="nav-drawer-row<?= $navPath === '/admin.php' ? ' current' : '' ?>" href="/admin.php">
          <span class="ic"><svg width="17" height="17"><use href="#ic-shield"/></svg></span>
          <span class="label">Admin</span>
          <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
        </a>
      <?php endif; ?>
      <a class="nav-drawer-row<?= $navPath === '/dashboard.php' ? ' current' : '' ?>" href="/dashboard.php">
        <span class="ic"><svg width="17" height="17"><use href="#ic-user"/></svg></span>
        <span class="label">Hi, <?= htmlspecialchars(explode(' ', $authUser['name'])[0]) ?></span>
        <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
      </a>
      <a class="nav-drawer-cta" href="/logout.php">Log out</a>
    <?php else: ?>
      <a class="nav-drawer-row<?= $navPath === '/login.php' ? ' current' : '' ?>" href="/login.php">
        <span class="ic"><svg width="17" height="17"><use href="#ic-user"/></svg></span>
        <span class="label">Log in</span>
        <svg class="chev" width="16" height="16"><use href="#ic-chev"/></svg>
      </a>
      <a class="nav-drawer-cta" href="/signup.php">Sign up &mdash; it's free</a>
    <?php endif; ?>
  </div>
  <div class="nav-drawer-foot">OBITICKETS &middot; TICKETING MADE SIMPLE</div>
</aside>
