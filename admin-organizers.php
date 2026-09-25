<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_admin_permission('organizers.view');

$q = trim((string) ($_GET['q'] ?? ''));
$organizers = get_organizers_admin();
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
  <div><h1>Organizers</h1><p><?= count($organizers) ?> total</p></div>
</div>

<form class="admin-filter-bar" method="get">
  <input type="text" name="q" placeholder="Search name, email or business…" value="<?= htmlspecialchars($q) ?>">
  <button class="btn btn-line" type="submit" style="padding:9px 18px">Search</button>
</form>

<?php if (!$organizers): ?>
  <div class="admin-empty"><svg width="40" height="40"><use href="#ic-briefcase"/></svg><h3>No organizers found</h3><p>Try a different search.</p></div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Organizer</th><th>Events</th><th>Tickets sold</th><th>Revenue</th><th>Verification</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($organizers as $o): $vm = $verificationMeta[$o['verification_status']] ?? $verificationMeta['UNVERIFIED']; ?>
          <tr>
            <td>
              <div class="row-user"><span class="avatar"><?= htmlspecialchars(initials_from_name($o['name'])) ?></span>
                <div><?= htmlspecialchars($o['org_name'] ?: $o['name']) ?><br><span class="muted" style="font-weight:400; font-size:0.8rem"><?= htmlspecialchars($o['email']) ?></span></div>
              </div>
            </td>
            <td class="mono"><?= (int) $o['event_count'] ?></td>
            <td class="mono"><?= (int) $o['tickets_sold'] ?></td>
            <td class="mono">UGX <?= number_format((float) $o['revenue'], 0) ?></td>
            <td><span class="admin-badge <?= $vm[1] ?>"><?= htmlspecialchars($vm[0]) ?></span></td>
            <td><span class="admin-badge <?= $o['account_status'] === 'ACTIVE' ? 'admin-badge-success' : 'admin-badge-danger' ?>"><?= htmlspecialchars($o['account_status']) ?></span></td>
            <td><a class="link" href="/admin-organizer-detail.php?id=<?= (int) $o['id'] ?>">Manage &rarr;</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_admin_foot(); ?>
