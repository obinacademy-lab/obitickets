<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('dashboard.view');
$organizerId = $ctx['organizer_id'];
$actor = current_user();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        $error = 'Enter a message describing your issue.';
    } else {
        create_organizer_support_ticket($organizerId, $actor['name'], $actor['email'], $subject, $message, (string) ($_POST['priority'] ?? 'MEDIUM'));
        header('Location: /org-support.php?created=1');
        exit;
    }
}

$tickets = get_support_tickets_for_organizer($organizerId);
$statusMeta = ['NEW' => 'admin-badge-warn', 'READ' => 'admin-badge-muted', 'IN_PROGRESS' => 'admin-badge-purple', 'RESOLVED' => 'admin-badge-success'];

$pageTitle = 'Support Tickets';
render_organizer_head('support', $ctx);
?>

<div class="admin-page-head">
  <div><h1>Support</h1><p>Get help from the obitickets team</p></div>
</div>

<?php if (isset($_GET['created'])): ?><span data-flash="Your support ticket was submitted." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<div class="admin-grid-2">
  <div>
    <?php if (!$tickets): ?>
      <div class="admin-empty">
        <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-mail"/></svg></div>
        <h3>No support tickets yet</h3>
        <p>Submit one using the form and we'll get back to you.</p>
      </div>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:14px;">
        <?php foreach ($tickets as $t): ?>
          <div class="admin-card<?= $t['status'] === 'NEW' ? ' admin-row-attention' : '' ?>">
            <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:10px;">
              <div>
                <strong><?= htmlspecialchars($t['topic']) ?></strong>
                <div class="muted" style="font-size:0.8rem; margin-top:2px;"><?= htmlspecialchars(date('d M Y, H:i', strtotime($t['created_at']))) ?> &middot; <?= htmlspecialchars(ucfirst(strtolower($t['priority']))) ?> priority</div>
              </div>
              <span class="admin-badge <?= $statusMeta[$t['status']] ?? 'admin-badge-muted' ?>"><?= $t['status'] === 'NEW' ? '<span class="admin-badge-dot"></span>' : '' ?><?= htmlspecialchars(str_replace('_', ' ', $t['status'])) ?></span>
            </div>
            <p style="font-size:0.9rem; color:var(--ink); margin:0"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h3 style="margin-bottom:14px">New support ticket</h3>
    <form method="post">
      <?= csrf_field() ?>
      <div class="admin-form-row"><label>Subject</label><input type="text" name="subject" placeholder="What's this about?"></div>
      <div class="admin-form-row"><label>Priority</label>
        <select name="priority"><option value="LOW">Low</option><option value="MEDIUM" selected>Medium</option><option value="HIGH">High</option></select>
      </div>
      <div class="admin-form-row"><label>Message</label><textarea name="message" rows="6" required placeholder="Describe your issue…" style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:10px; font-family:inherit; font-size:0.9rem; resize:vertical;"></textarea></div>
      <button class="btn btn-purple btn-block" type="submit">Submit ticket</button>
    </form>
  </div>
</div>

<?php render_organizer_foot(); ?>
