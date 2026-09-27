<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('audit.view');

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'event_type' => $_GET['event_type'] ?? '',
    'role' => $_GET['role'] ?? '',
    'when' => $_GET['when'] ?? '',
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$total = count_login_log($filters);
$rows = get_login_log($filters, $page, $perPage);
$totalPages = max(1, (int) ceil($total / $perPage));
$stats = get_login_log_stats();
$topLocations = get_top_login_locations();

$eventMeta = ['LOGIN' => 'admin-badge-muted', 'SIGNUP' => 'admin-badge-success'];
$roleMeta = ['ATTENDEE' => 'admin-badge-muted', 'ORGANIZER' => 'admin-badge-purple', 'ADMIN' => 'admin-badge-danger'];

$pageTitle = 'Login Activity';
render_admin_head('logins');
?>

<div class="admin-page-head">
  <div><h1>Login activity</h1><p>Who's signing up and logging in, from where, and when.</p></div>
</div>

<div class="admin-mini-stat-row">
  <div class="admin-mini-stat"><span class="n"><?= $stats['logins_today'] ?></span><span class="l">Logins today</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $stats['signups_today'] ?></span><span class="l">Signups today</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $stats['unique_today'] ?></span><span class="l">Unique users today</span></div>
  <div class="admin-mini-stat"><span class="n"><?= $stats['total_week'] ?></span><span class="l">Last 7 days</span></div>
</div>

<?php if ($topLocations): ?>
  <div class="admin-card" style="margin-bottom:20px">
    <h3 style="margin-bottom:14px">Top locations <span class="muted" style="font-weight:400; font-size:0.8rem">&middot; last 30 days</span></h3>
    <div style="display:flex; flex-wrap:wrap; gap:10px">
      <?php foreach ($topLocations as $loc): ?>
        <span class="admin-badge admin-badge-muted">
          <?= htmlspecialchars($loc['city'] ?: 'Unknown city') ?>, <?= htmlspecialchars(country_name($loc['country'])) ?>
          <span class="mono" style="margin-left:6px; opacity:0.7"><?= (int) $loc['n'] ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<form class="admin-filter-bar" method="get">
  <input type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($filters['q']) ?>" style="min-width:220px">
  <select name="event_type">
    <option value="">Login or signup</option>
    <option value="LOGIN" <?= $filters['event_type'] === 'LOGIN' ? 'selected' : '' ?>>Login</option>
    <option value="SIGNUP" <?= $filters['event_type'] === 'SIGNUP' ? 'selected' : '' ?>>Signup</option>
  </select>
  <select name="role">
    <option value="">All roles</option>
    <option value="ATTENDEE" <?= $filters['role'] === 'ATTENDEE' ? 'selected' : '' ?>>Attendee</option>
    <option value="ORGANIZER" <?= $filters['role'] === 'ORGANIZER' ? 'selected' : '' ?>>Organizer</option>
    <option value="ADMIN" <?= $filters['role'] === 'ADMIN' ? 'selected' : '' ?>>Admin</option>
  </select>
  <select name="when">
    <option value="">All time</option>
    <option value="today" <?= $filters['when'] === 'today' ? 'selected' : '' ?>>Today</option>
    <option value="7d" <?= $filters['when'] === '7d' ? 'selected' : '' ?>>Last 7 days</option>
    <option value="30d" <?= $filters['when'] === '30d' ? 'selected' : '' ?>>Last 30 days</option>
  </select>
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Filter</button>
  <?php if ($filters['q'] !== '' || $filters['event_type'] !== '' || $filters['role'] !== '' || $filters['when'] !== ''): ?>
    <a class="btn btn-line" href="/admin-logins.php" style="padding:9px 18px">Clear</a>
  <?php endif; ?>
  <span class="muted" style="font-size:0.82rem; margin-left:auto"><?= $total ?> total</span>
</form>

<?php if (!$rows): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-pin"/></svg></div>
    <h3>No activity found</h3>
    <p>Try a different search, or clear your filters to see everything.</p>
    <a class="btn btn-line" href="/admin-logins.php">Clear filters</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>User</th><th>Type</th><th>Role</th><th>Location</th><th>Device</th><th>Date &amp; time</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name']) ?><br><span class="muted" style="font-size:0.78rem"><?= htmlspecialchars($r['email']) ?></span></td>
            <td><span class="admin-badge <?= $eventMeta[$r['event_type']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars(ucfirst(strtolower($r['event_type']))) ?></span></td>
            <td><span class="admin-badge <?= $roleMeta[$r['role']] ?? 'admin-badge-muted' ?>"><?= htmlspecialchars(ucfirst(strtolower($r['role']))) ?></span></td>
            <td class="muted"><?= $r['city'] || $r['country'] ? htmlspecialchars(trim(($r['city'] ?: '') . ($r['city'] && $r['country'] ? ', ' : '') . (string) country_name($r['country']))) : '—' ?></td>
            <td class="muted"><?= htmlspecialchars(ucfirst($r['device_type'])) ?><?= $r['browser'] ? ' · ' . htmlspecialchars($r['browser']) : '' ?><?= $r['os'] ? ' · ' . htmlspecialchars($r['os']) : '' ?></td>
            <td class="muted mono"><?= htmlspecialchars(date('d M Y, g:i A', strtotime($r['logged_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
