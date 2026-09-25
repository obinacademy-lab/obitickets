<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('tickets.view');
$organizerId = $ctx['organizer_id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_pause') {
    verify_csrf();
    if (!organizer_can($ctx, 'tickets.manage')) {
        http_response_code(403);
        exit('You do not have permission to manage tickets.');
    }
    [$ok, $err] = set_ticket_type_paused($organizerId, $ctx['actor_id'], (int) $_POST['id'], !empty($_POST['pause']));
    if (!$ok) {
        $error = $err;
    } else {
        header('Location: /org-tickets.php?updated=1');
        exit;
    }
}

$tiers = get_ticket_types_for_organizer($organizerId);

$pageTitle = 'Tickets';
render_organizer_head('tickets', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Tickets</h1><p><?= count($tiers) ?> ticket types across all your events</p></div>
</div>

<?php if (isset($_GET['updated'])): ?><span data-flash="Saved." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$tiers): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-ticket"/></svg></div>
    <h3>No ticket types yet</h3>
    <p>Add ticket tiers when you create or edit an event.</p>
    <a class="btn btn-line" href="/event-create.php">Create an event</a>
  </div>
<?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Event</th><th>Ticket type</th><th>Price</th><th>Sold</th><th>Remaining</th><th>Revenue</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tiers as $t): $remaining = (int) $t['quantity_total'] - (int) $t['quantity_sold']; ?>
          <tr class="<?= $remaining <= 0 ? 'admin-row-attention' : '' ?>">
            <td class="muted"><?= htmlspecialchars($t['event_title']) ?></td>
            <td style="font-weight:700"><?= htmlspecialchars($t['name']) ?></td>
            <td class="mono"><?= htmlspecialchars($t['currency']) ?> <?= number_format((float) $t['price'], 0) ?></td>
            <td class="mono"><?= (int) $t['quantity_sold'] ?></td>
            <td class="mono"><?= $remaining ?><?= $remaining <= 0 ? ' <span class="admin-badge-dot"></span>' : '' ?></td>
            <td class="mono"><?= htmlspecialchars($t['currency']) ?> <?= number_format((float) $t['revenue'], 0) ?></td>
            <td>
              <?php if ($t['sales_paused']): ?><span class="admin-badge admin-badge-warn">Paused</span>
              <?php elseif ($remaining <= 0): ?><span class="admin-badge admin-badge-muted">Sold out</span>
              <?php else: ?><span class="admin-badge admin-badge-success">On sale</span><?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <a class="link" href="/event-edit.php?id=<?= (int) $t['event_id'] ?>">Edit</a>
              <?php if (organizer_can($ctx, 'tickets.manage') && $remaining > 0): ?>
                &middot;
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_pause"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="pause" value="<?= $t['sales_paused'] ? '' : '1' ?>">
                  <button type="submit" class="link" style="background:none; border:none; color:var(--purple); cursor:pointer; padding:0; font-weight:700"><?= $t['sales_paused'] ? 'Resume sales' : 'Pause sales' ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php render_organizer_foot(); ?>
