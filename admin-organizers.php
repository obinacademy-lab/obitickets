<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('organizers.view');

$q = trim((string) ($_GET['q'] ?? ''));
$verificationFilter = $_GET['verification'] ?? '';
$organizers = get_organizers_admin();

$verificationCounts = array_fill_keys(['UNVERIFIED', 'PENDING', 'VERIFIED', 'REJECTED'], 0);
foreach ($organizers as $o) {
    $vs = $o['verification_status'] ?: 'UNVERIFIED';
    if (isset($verificationCounts[$vs])) {
        $verificationCounts[$vs]++;
    }
}

if ($verificationFilter !== '') {
    $organizers = array_values(array_filter($organizers, static fn ($o) => ($o['verification_status'] ?: 'UNVERIFIED') === $verificationFilter));
}
if ($q !== '') {
    $organizers = array_values(array_filter($organizers, static fn ($o) => stripos($o['name'], $q) !== false || stripos($o['email'], $q) !== false || stripos((string) $o['org_name'], $q) !== false));
}

$verificationMeta = [
    'UNVERIFIED' => ['Unverified', 'admin-badge-muted'], 'PENDING' => ['Pending', 'admin-badge-warn'],
    'VERIFIED' => ['Verified', 'admin-badge-success'], 'REJECTED' => ['Rejected', 'admin-badge-danger'],
];

$pageTitle = 'Organizers';
render_admin_head('organizers');
?>

<div class="admin-page-head">
  <div><h1>Organizers</h1><p><?= count($organizers) ?> shown</p></div>
</div>

<div class="admin-mini-stat-row">
  <a class="admin-mini-stat<?= $verificationFilter === '' ? ' active' : '' ?>" href="/admin-organizers.php">
    <span class="n"><?= array_sum($verificationCounts) ?></span><span class="l">All</span>
  </a>
  <?php foreach ($verificationMeta as $val => $meta): ?>
    <a class="admin-mini-stat<?= $verificationFilter === $val ? ' active' : '' ?>" href="/admin-organizers.php?verification=<?= $val ?>">
      <span class="n"><?= $verificationCounts[$val] ?></span><span class="l"><?= htmlspecialchars($meta[0]) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form class="admin-filter-bar" method="get">
  <?php if ($verificationFilter !== ''): ?><input type="hidden" name="verification" value="<?= htmlspecialchars($verificationFilter) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Search name, email or business…" value="<?= htmlspecialchars($q) ?>">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$organizers): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-briefcase"/></svg></div>
    <h3>No organizers found</h3>
    <p>Try a different search, or clear your filters to see everyone.</p>
    <a class="btn btn-line" href="/admin-organizers.php">Clear filters</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Organizer</th><th>Events</th><th>Tickets sold</th><th>Revenue</th><th>Verification</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($organizers as $o): $vm = $verificationMeta[$o['verification_status']] ?? $verificationMeta['UNVERIFIED']; $needsReview = $o['verification_status'] === 'PENDING'; ?>
          <tr class="<?= $needsReview ? 'admin-row-attention' : '' ?>">
            <td>
              <div class="row-user"><span class="avatar"><?= htmlspecialchars(initials_from_name($o['name'])) ?></span>
                <div><?= htmlspecialchars($o['org_name'] ?: $o['name']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($o['email']) ?></span></div>
              </div>
            </td>
            <td class="mono"><?= (int) $o['event_count'] ?></td>
            <td class="mono"><?= (int) $o['tickets_sold'] ?></td>
            <td class="mono">UGX <?= number_format((float) $o['revenue'], 0) ?></td>
            <td><span class="admin-badge <?= $vm[1] ?>"><?= $needsReview ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars($vm[0]) ?></span></td>
            <td><span class="admin-badge <?= $o['account_status'] === 'ACTIVE' ? 'admin-badge-success' : 'admin-badge-danger' ?>"><?= htmlspecialchars($o['account_status']) ?></span></td>
            <td><a class="link" href="/admin-organizer-detail.php?id=<?= (int) $o['id'] ?>">Manage &rarr;</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
