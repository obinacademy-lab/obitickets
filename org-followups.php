<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * "Failed payments" for an organizer (11/26): the people whose payment did not go through, for their tickets and their OBI MARKET products, with a
 * WhatsApp and an Email button on each (includes/followup-hub.php). Only the organizer's own buyers; limits and message rules as on the order page.
 * Tickets need tickets.view (owner, event manager); OBI MARKET needs market.manage (owner, event manager).
 */
$ctx = require_organizer_access('dashboard.view');
$organizerId = $ctx['organizer_id'];
$canTickets = organizer_can($ctx, 'tickets.view');
$canMarket = organizer_can($ctx, 'market.manage') && market_followup_ready();
if (!$canTickets && !$canMarket) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}
$sender = followup_sender((int) $ctx['actor_id'], $ctx['is_owner'] ? 'ORGANIZER' : 'TEAM');
$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'market' && $canMarket ? 'market' : ($canTickets ? 'tickets' : 'market');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $res = followup_hub_post($organizerId, $sender, $canTickets, $canMarket);
    if ($res !== null) {
        if ($res['ok'] && !empty($res['redirect'])) {
            header('Location: ' . $res['redirect']);   // opens the sender's WhatsApp with the message ready
            exit;
        }
        $_SESSION['fu_flash'] = [$res['ok'] ? 'ok' : 'bad', $res['ok'] ? $res['flash'] : $res['error']];
        header('Location: /org-followups.php?tab=' . $tab);
        exit;
    }
}
$flash = $_SESSION['fu_flash'] ?? null;
unset($_SESSION['fu_flash']);
$tickets = $canTickets ? followup_ticket_list($organizerId) : [];
$market = $canMarket ? market_followup_list($organizerId) : [];
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = 'Failed payments';
render_organizer_head('followups', $ctx);
echo followup_hub_css();
?>
<div class="admin-page-head"><div><h1>Failed payments</h1><p>People who tried to buy from you but whose payment did not go through. A quick, friendly message often wins the sale back.</p></div></div>
<?php if ($flash): ?><div class="om-note <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if (!followup_ready()): ?><div class="om-note warn">Follow-ups are being set up. Please check back soon.</div><?php else: ?>

<nav class="fu-tabs" aria-label="What was being bought">
  <?php if ($canTickets): ?><a class="btn <?= $tab === 'tickets' ? 'btn-purple' : 'btn-line' ?>" href="/org-followups.php?tab=tickets">Tickets (<?= count($tickets) ?>)</a><?php endif; ?>
  <?php if ($canMarket): ?><a class="btn <?= $tab === 'market' ? 'btn-purple' : 'btn-line' ?>" href="/org-followups.php?tab=market">OBI MARKET (<?= count($market) ?>)</a><?php endif; ?>
</nav>
<p class="fu-intro"><b>WhatsApp</b> opens your own WhatsApp (phone or WhatsApp Web) with the message ready to send. <b>Email</b> sends it straight away from obitickets, and replies come to your email. Each person can be contacted once a day per channel, at most <?= (int) FOLLOWUP_MAX_PER_CHANNEL ?> times. Only the last <?= (int) FOLLOWUP_LIST_DAYS ?> days are shown, and people who have since paid drop off the list.</p>
<?= $tab === 'market' ? render_followup_rows('market', $market, $sender, '/org-followups.php?tab=market') : render_followup_rows('ticket', $tickets, $sender, '/org-followups.php?tab=tickets') ?>
<?php endif; ?>

<?php render_organizer_foot(); ?>
