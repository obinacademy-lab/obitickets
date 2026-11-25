<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * OBI MARKET, one order for its seller (ADR-003, phase 4): what to pack, who it is for and where it goes, the money (commission and what the seller keeps),
 * the tracking, and the next steps the seller may take: start preparing, mark as sent / ready for collection, or cancel and refund (with a reason).
 * Completing an order and paying out are NOT seller actions: the customer confirms delivery (or 7 days pass, or an admin decides). Opening another
 * seller's order number looks exactly like a number that does not exist.
 */
$ctx = require_organizer_access('market.view');
$sellerId = $ctx['organizer_id'];
$canManage = organizer_can($ctx, 'market.manage');
$number = trim((string) ($_GET['n'] ?? $_POST['n'] ?? ''));
$o = market_ready() ? market_seller_order($sellerId, $number) : null;

if ($o && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to change orders.');
    }
    $to = (string) ($_POST['to'] ?? '');
    $allowed = array_column(market_seller_next_steps($o), 0);
    if (!in_array($to, $allowed, true)) {
        $_SESSION['om_flash'] = ['bad', 'That step is not available for this order.'];
    } else {
        $r = market_order_set_status($sellerId, (int) $o['id'], $to, trim((string) ($_POST['note'] ?? '')) ?: null);
        $_SESSION['om_flash'] = $r['ok'] ? ['ok', $to === 'CANCELLED' ? 'The order was cancelled and the customer will be refunded.' : 'The order was updated and the customer was told.'] : ['bad', $r['error']];
    }
    header('Location: /org-market-order.php?n=' . urlencode($number));
    exit;
}
$flash = $_SESSION['om_flash'] ?? null;
unset($_SESSION['om_flash']);
if (!$o) {
    http_response_code(404);
}
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn ($n): string => number_format((int) $n);
$status = $o ? market_status_label((string) $o['fulfilment'], (string) $o['payment_status']) : ['', ''];
$steps = $o ? market_seller_next_steps($o) : [];
$disputed = $o && $o['dispute_at'] !== null && $o['received_by'] === null;
$words = ['NEW' => 'Order received', 'PROCESSING' => 'Being prepared', 'SHIPPED' => 'Sent to the customer', 'READY_FOR_COLLECTION' => 'Ready for collection', 'COMPLETED' => 'Delivered', 'CANCELLED' => 'Cancelled', 'REFUND_REQUESTED' => 'Refund in progress', 'REFUNDED' => 'Refunded'];
$earn = $o ? ['NONE' => 'Not earned yet', 'PENDING' => 'Waiting for the customer to confirm delivery', 'AVAILABLE' => 'Released: ready to withdraw', 'REVERSED' => 'Returned to the customer'][$o['earnings_state']] ?? '' : '';
$keep = $o ? (int) $o['seller_ugx'] + (int) $o['delivery_ugx'] : 0;
$pct = $o ? rtrim(rtrim(number_format((int) $o['commission_bps'] / 100, 2), '0'), '.') : '';

$GLOBALS['orgExtraCss'] = ['org-market.css'];
$pageTitle = $o ? 'Order ' . $o['order_number'] : 'Order';
render_organizer_head('market-orders', $ctx);
?>

<div class="admin-page-head">
  <div><h1><?= $o ? 'Order ' . $h($o['order_number']) : 'Order' ?></h1><p><?= $o ? 'Placed ' . $h(date('j M Y, g:ia', strtotime((string) $o['created_at']))) : '' ?></p></div>
  <a class="btn btn-ghost" href="/org-market-orders.php">&larr; All orders</a>
</div>

<?php if (!$o): ?>
  <div class="om-empty"><b>We could not find that order</b>It may belong to another store, or it is still waiting for payment.<p><a class="btn btn-purple" href="/org-market-orders.php">Back to orders</a></p></div>
<?php else: ?>
<?php if ($flash): ?><div class="om-note <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if ($disputed): ?><div class="om-note bad"><b>The customer reported a problem:</b> &ldquo;<?= $h($o['dispute_reason']) ?>&rdquo;. Your earnings for this order are on hold until the OBI MARKET team has looked into it. They may contact you.</div><?php endif; ?>

<div class="om-detail">
  <div style="display:grid; gap:18px">
    <section class="om-card om-items">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:8px"><h3 style="margin:0">Items to pack</h3><span class="om-badge <?= $h($status[1]) ?>"><?= $h($status[0]) ?></span></div>
      <?php foreach ($o['items'] as $i): $img = market_img($i['image_path']); ?>
        <div class="om-item"><span class="om-thumb"><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt=""><?php endif; ?></span>
          <div><b><?= $h($i['product_name']) ?></b><small><?= $i['variant_label'] !== '' ? $h($i['variant_label']) . ' &middot; ' : '' ?><?= (int) $i['quantity'] ?> &times; UGX <?= $num($i['unit_price_ugx']) ?><?= $i['sku'] ? ' &middot; SKU ' . $h($i['sku']) : '' ?></small></div>
          <b class="mono">UGX <?= $num($i['line_ugx']) ?></b></div>
      <?php endforeach; ?>
    </section>

    <?php if ($steps && $canManage): ?>
    <section class="om-card">
      <h3>Next step</h3><p>The customer is told each time you change the status.</p>
      <div class="om-step">
        <?php foreach ($steps as [$to, $label, $needsNote, $tone]): ?> <?php if ($tone === 'danger'): ?><details class="om-cancel"><summary><?= $h($label) ?>...</summary><?php endif; ?>
          <form method="post" action="/org-market-order.php?n=<?= urlencode((string) $o['order_number']) ?>" class="om-step"<?= $tone === 'danger' ? ' onsubmit="return confirm(\'Cancel this order? The customer is refunded in full and you lose this sale.\')"' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="n" value="<?= $h($o['order_number']) ?>"><input type="hidden" name="to" value="<?= $h($to) ?>">
            <?php if ($needsNote): ?><label class="om-field"><span>Reason (the customer will read this)</span><textarea name="note" rows="2" maxlength="240" required placeholder="e.g. Sorry, this colour just ran out"></textarea></label>
            <?php elseif (in_array($to, ['SHIPPED', 'READY_FOR_COLLECTION'], true)): ?><label class="om-field"><span>Note for the customer (optional)</span><input name="note" maxlength="240" placeholder="<?= $to === 'SHIPPED' ? 'Courier name, parcel number, expected day' : 'Where and when to collect' ?>"></label><?php endif; ?>
            <button class="om-btn <?= $tone === 'danger' ? 'danger' : 'primary' ?>" type="submit"><?= $h($label) ?></button>
          </form><?php if ($tone === 'danger'): ?></details><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="om-card">
      <h3>Tracking</h3>
      <ol class="om-track"><?php foreach ($o['history'] as $e): ?>
        <li><b><?= $h($words[$e['to_status']] ?? ucfirst(strtolower(str_replace('_', ' ', (string) $e['to_status'])))) ?></b><small><?= $h(date('j M, g:ia', strtotime((string) $e['created_at']))) ?> &middot; <?= $e['actor_role'] === 'SELLER' ? 'by you' : ($e['actor_role'] === 'CUSTOMER' ? 'by the customer' : ($e['actor_role'] === 'ADMIN' ? 'by obitickets' : 'automatic')) ?></small><?php if (!empty($e['note'])): ?><span><?= $h($e['note']) ?></span><?php endif; ?></li>
      <?php endforeach; ?></ol>
    </section>
  </div>

  <div style="display:grid; gap:18px">
    <section class="om-card om-who">
      <h3><?= $o['delivery_method'] === 'COLLECTION' ? 'Collection' : 'Deliver to' ?></h3>
      <?php if ($o['delivery_method'] === 'COLLECTION'): ?>
        <p><b><?= $h($o['customer_name']) ?></b> will collect this order.</p>
      <?php else: ?>
        <p><b><?= $h($o['delivery_name']) ?></b></p>
        <p><a href="tel:<?= $h(preg_replace('/[^\d+]/', '', (string) $o['delivery_phone'])) ?>"><?= $h($o['delivery_phone']) ?></a></p>
        <p><?= $h($o['delivery_address']) ?>, <?= $h($o['delivery_district']) ?></p>
        <?php if (!empty($o['customer_note'])): ?><p class="om-fine">Customer note: <?= $h($o['customer_note']) ?></p><?php endif; ?>
      <?php endif; ?>
      <h3 style="margin-top:16px">Customer</h3>
      <p><b><?= $h($o['customer_name']) ?></b></p>
      <p><a href="tel:<?= $h(preg_replace('/[^\d+]/', '', (string) $o['customer_phone'])) ?>"><?= $h($o['customer_phone']) ?></a> &middot; <a href="mailto:<?= $h($o['customer_email']) ?>"><?= $h($o['customer_email']) ?></a></p>
    </section>

    <section class="om-card om-money">
      <h3>Money</h3>
      <div><span>Merchandise</span><span>UGX <?= $num($o['merchandise_ugx']) ?></span></div>
      <div><span>Delivery fee (all yours)</span><span>UGX <?= $num($o['delivery_ugx']) ?></span></div>
      <div><span>Customer paid</span><span>UGX <?= $num($o['total_ugx']) ?></span></div>
      <div><span>obitickets commission (<?= $h($pct) ?>% of merchandise)</span><span>&minus; UGX <?= $num($o['commission_ugx']) ?></span></div>
      <?php if ((int) $o['refunded_ugx'] > 0): ?><div><span>Refunded to the customer</span><span>UGX <?= $num($o['refunded_ugx']) ?></span></div><?php endif; ?>
      <div class="tot keep"><span>You keep</span><span>UGX <?= $num($keep) ?></span></div>
      <p class="om-fine"><b><?= $h($earn) ?>.</b> <?php if ($o['earnings_state'] === 'PENDING'): ?>Your money is released when the customer confirms they received the order<?= $o['release_after'] ? ', or automatically on ' . $h(date('j M Y', strtotime((string) $o['release_after']))) . ' if they do not answer' : '' ?>.<?php endif; ?></p>
    </section>
  </div>
</div>
<?php endif; ?>

<?php render_organizer_foot(); ?>
