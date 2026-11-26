<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * "Failed payments" for admins (11/26): everyone whose payment did not go through, for tickets (orders.view) and OBI MARKET (market.manage), with a
 * WhatsApp and an Email button on each (includes/followup-hub.php). Same limits and message rules as on the order pages; every contact is recorded.
 */
$admin = require_admin_permission('orders.view');
$canTickets = admin_can('orders.view');
$canMarket = admin_can('market.manage') && market_followup_ready();
$sender = followup_sender((int) $admin['id'], 'ADMIN');
$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'market' && $canMarket ? 'market' : 'tickets';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $res = followup_hub_post(null, $sender, $canTickets, $canMarket);
    if ($res !== null) {
        if ($res['ok'] && !empty($res['redirect'])) {
            header('Location: ' . $res['redirect']);
            exit;
        }
        $_SESSION['fu_admin_flash'] = $res['ok'] ? $res['flash'] : $res['error'];
        header('Location: /admin-followups.php?tab=' . $tab);
        exit;
    }
}
$flash = $_SESSION['fu_admin_flash'] ?? null;
unset($_SESSION['fu_admin_flash']);
$tickets = followup_ticket_list(null, 200);
$market = $canMarket ? market_followup_list(null, 200) : [];
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$pageTitle = 'Failed payments';
render_admin_head('followups');
echo followup_hub_css();
?>
<div class="admin-page-head"><div><h1>Failed payments</h1><p>Buyers whose payment did not go through, across every organizer: tickets and OBI MARKET. Contact them by WhatsApp or email to win the sale back.</p></div></div>
<?php if ($flash): ?><span data-flash="<?= $h($flash) ?>" hidden></span><?php endif; ?>
<?php if (!followup_ready()): ?>
  <div class="admin-card" style="border-left:4px solid var(--danger)"><h3 style="margin:0 0 6px">Database update needed</h3><p style="margin:0">Run <b>migration/048_followups_hub.sql</b> in phpMyAdmin first.</p></div>
<?php else: ?>
<nav class="fu-tabs" aria-label="What was being bought">
  <a class="btn <?= $tab === 'tickets' ? 'btn-purple' : 'btn-line' ?>" href="/admin-followups.php?tab=tickets">Tickets (<?= count($tickets) ?>)</a>
  <?php if ($canMarket): ?><a class="btn <?= $tab === 'market' ? 'btn-purple' : 'btn-line' ?>" href="/admin-followups.php?tab=market">OBI MARKET (<?= count($market) ?>)</a><?php endif; ?>
</nav>
<p class="fu-intro"><b>WhatsApp</b> opens your own WhatsApp with the message ready. <b>Email</b> sends it now, signed as obitickets, with replies to your email. Each buyer can be contacted once a day per channel, at most <?= (int) FOLLOWUP_MAX_PER_CHANNEL ?> times (organizers and admins share that limit). Last <?= (int) FOLLOWUP_LIST_DAYS ?> days; people who have since paid drop off.</p>
<?= $tab === 'market' ? render_followup_rows('market', $market, $sender, '/admin-followups.php?tab=market', true) : render_followup_rows('ticket', $tickets, $sender, '/admin-followups.php?tab=tickets', true) ?>
<?php endif; ?>

<?php render_admin_foot(); ?>
