<?php
require_once __DIR__ . '/includes/bootstrap.php';

$admin = require_admin_permission('audit.view');
$ready = ipguard_ready();
$installError = null;

// "Switch on": runs migration/027_ip_guard.sql (two CREATE TABLE IF NOT EXISTS statements) so nobody has to open phpMyAdmin.
if (!$ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install' && admin_can('admins.manage')) {
    verify_csrf();
    $sqlFile = rtrim((string) (env_status()['root'] ?? dirname(__DIR__)), '/\\') . '/migration/027_ip_guard.sql';
    $sql = is_file($sqlFile) ? (string) file_get_contents($sqlFile) : '';
    $sql = preg_replace('/--[^\n]*/', '', $sql);                       // drop comments
    $statements = array_filter(array_map('trim', explode(';', (string) $sql)));
    if (!$statements || count($statements) > 4 || array_filter($statements, static fn ($s) => stripos($s, 'CREATE TABLE IF NOT EXISTS ip_') !== 0)) {
        $installError = 'The migration file is missing or not what was expected, so nothing was run.';
    } else {
        try {
            foreach ($statements as $s) {
                db()->exec($s);
            }
            log_admin_action((int) $admin['id'], 'ipguard.install', 'settings', null, null);
            header('Location: /admin-ip-blocks.php?installed=1');
            exit;
        } catch (Throwable $e) {
            error_log('[ipguard] install failed: ' . $e->getMessage());
            $installError = 'The database refused to create the tables. Run migration/027_ip_guard.sql in phpMyAdmin instead.';
        }
    }
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ip = trim((string) ($_POST['ip'] ?? ''));
    if (($_POST['action'] ?? '') === 'unblock' && filter_var($ip, FILTER_VALIDATE_IP)) {
        ipguard_unblock($ip);
        log_admin_action((int) $admin['id'], 'ip.unblock', 'ip', null, ['ip' => $ip]);
        header('Location: /admin-ip-blocks.php?done=1');
        exit;
    }
}

$blocked = $recent = $busy = [];
if ($ready) {
    $blocked = db()->query('SELECT ip, level, reason, hits, last_blocked_at, GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), blocked_until) / 60)) AS minutes
                            FROM ip_blocks WHERE blocked_until > NOW() ORDER BY last_blocked_at DESC')->fetchAll();
    $recent = db()->query('SELECT ip, level, reason, hits, last_blocked_at FROM ip_blocks WHERE blocked_until <= NOW() AND last_blocked_at >= (NOW() - INTERVAL 7 DAY) ORDER BY last_blocked_at DESC LIMIT 25')->fetchAll();
    $busy = db()->query('SELECT ip, COUNT(*) AS n, GROUP_CONCAT(DISTINCT kind ORDER BY kind SEPARATOR ", ") AS kinds, MAX(created_at) AS last_at
                         FROM ip_events WHERE created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY ip ORDER BY n DESC, last_at DESC LIMIT 20')->fetchAll();
}
$minLabel = static fn (int $m): string => $m >= 90 ? 'about ' . (int) round($m / 60) . ' h' : $m . ' min';

$pageTitle = 'Blocked IPs';
render_admin_head('ipblocks');
?>

<div class="admin-page-head">
  <div><h1>Blocked IP addresses</h1><p>An address that does more than <?= IPGUARD_LIMIT ?> failed or suspicious things in an hour is blocked for <?= $minLabel(IPGUARD_FIRST_BLOCK_MINUTES) ?>, or 24 hours if it was blocked in the last day.</p></div>
</div>

<?php if (!$ready): ?>
  <?php if ($installError): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($installError) ?></div><?php endif; ?>
  <div class="admin-card"><h3>Not switched on yet</h3>
    <p class="muted" style="margin:8px 0 14px">The blocker needs two small database tables first. Until then nothing is recorded or blocked, and the site works as normal. The button creates them (it only adds tables, it never changes or removes existing data).</p>
    <?php if (admin_can('admins.manage')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="install"><button class="btn btn-purple" type="submit">Switch on the IP blocker</button></form>
    <?php else: ?><p class="muted">Ask a full admin to switch it on.</p><?php endif; ?>
  </div>
<?php else: ?>
  <?php if (isset($_GET['done'])): ?><div class="alert alert-success" style="margin-bottom:16px">Block lifted.</div><?php endif; ?>
  <?php if (isset($_GET['installed'])): ?><div class="alert alert-success" style="margin-bottom:16px">The IP blocker is switched on.</div><?php endif; ?>

  <h3 style="margin:6px 0 12px">Blocked now <span class="muted" style="font-weight:400;font-size:.85rem">&middot; <?= count($blocked) ?></span></h3>
  <?php if (!$blocked): ?>
    <div class="admin-empty"><div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-shield"/></svg></div><h3>No address is blocked</h3><p>Addresses appear here the moment they cross the limit.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Address</th><th>Why</th><th>Length</th><th>Times blocked</th><th>Frees up in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($blocked as $b): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($b['ip']) ?></td>
          <td class="muted"><?= htmlspecialchars($b['reason']) ?></td>
          <td><span class="admin-badge <?= (int) $b['level'] === 2 ? 'admin-badge-danger' : 'admin-badge-muted' ?>"><?= (int) $b['level'] === 2 ? '24 hours' : '1 hour' ?></span></td>
          <td class="mono"><?= (int) $b['hits'] ?></td>
          <td class="muted"><?= $minLabel((int) $b['minutes']) ?></td>
          <td><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?= htmlspecialchars($b['ip']) ?>"><button class="btn btn-line" type="submit" style="padding:7px 14px">Unblock</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <h3 style="margin:28px 0 12px">Busiest addresses in the last 24 hours</h3>
  <?php if (!$busy): ?>
    <p class="muted">No failed or suspicious actions recorded in the last 24 hours.</p>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Address</th><th>Actions</th><th>Kinds</th><th>Last seen</th></tr></thead>
      <tbody>
      <?php foreach ($busy as $b): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($b['ip']) ?></td>
          <td class="mono"><?= (int) $b['n'] ?></td>
          <td class="muted"><?= htmlspecialchars((string) $b['kinds']) ?></td>
          <td class="muted mono"><?= htmlspecialchars(date('d M, g:i A', strtotime($b['last_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($recent): ?>
    <h3 style="margin:28px 0 12px">Blocks that have ended <span class="muted" style="font-weight:400;font-size:.85rem">&middot; last 7 days</span></h3>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Address</th><th>Why</th><th>Times blocked</th><th>Last blocked</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($b['ip']) ?></td>
          <td class="muted"><?= htmlspecialchars($b['reason']) ?></td>
          <td class="mono"><?= (int) $b['hits'] ?></td>
          <td class="muted mono"><?= htmlspecialchars(date('d M, g:i A', strtotime($b['last_blocked_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
