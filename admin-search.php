<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('dashboard.view');
$q = trim((string) ($_GET['q'] ?? ''));
$results = $q !== '' ? admin_global_search($q) : [];

$typeIcons = ['Event' => 'ic-cal', 'Organizer' => 'ic-briefcase', 'Customer' => 'ic-user', 'Order' => 'ic-bolt', 'Ticket' => 'ic-ticket'];

$pageTitle = 'Search';
render_admin_head('dashboard');
?>

<div class="admin-page-head">
  <div><h1>Search results for &ldquo;<?= htmlspecialchars($q) ?>&rdquo;</h1><p><?= count($results) ?> match<?= count($results) === 1 ? '' : 'es' ?></p></div>
</div>

<?php if ($q === ''): ?>
  <div class="admin-empty"><svg width="40" height="40"><use href="#ic-search"/></svg><h3>Type something to search</h3><p>Events, organizers, customers, orders and tickets.</p></div>
<?php elseif (!$results): ?>
  <div class="admin-empty"><svg width="40" height="40"><use href="#ic-search"/></svg><h3>No matches</h3><p>Try a different name, email, order number or ticket code.</p></div>
<?php else: ?>
  <div class="admin-search-results">
    <?php foreach ($results as $r): ?>
      <a class="admin-search-result" href="<?= htmlspecialchars($r['href']) ?>">
        <svg width="18" height="18" style="color:var(--purple); flex:none"><use href="#<?= $typeIcons[$r['type']] ?? 'ic-search' ?>"/></svg>
        <span class="admin-badge admin-badge-purple" style="flex:none"><?= htmlspecialchars($r['type']) ?></span>
        <span style="font-weight:600"><?= htmlspecialchars($r['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
