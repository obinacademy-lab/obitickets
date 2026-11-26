<?php
declare(strict_types=1);

/**
 * "Failed payments" on the dashboards (11/26): one list of buyers whose payment did not go through, for tickets AND for OBI MARKET orders, with a
 * WhatsApp and an Email button on each, for the organizer (their own buyers) and the admin (everyone).
 *
 * Tickets reuse includes/followup.php (order_followups, migration 025/048) unchanged: same limits, same message rules, same history as the order page.
 * OBI MARKET gets the same rules here (market_followups, migration 048):
 *  - only orders whose payment FAILED or EXPIRED, in the last FOLLOWUP_LIST_DAYS days, the newest attempt per customer and seller, and only while the
 *    customer has not since paid that seller and at least one of the items can still be bought;
 *  - once a day per channel, at most three times (FOLLOWUP_MAX_PER_CHANNEL / FOLLOWUP_COOLDOWN_HOURS);
 *  - the message is editable, never mentions why the payment failed, and may only link to this site; email goes through resend_send with the sender
 *    as Reply-To; WhatsApp opens the sender's own WhatsApp with the message ready (we record that it was opened; delivery cannot be confirmed).
 * An organizer can only ever see and contact their own buyers: every action checks the order belongs to them.
 */

const FOLLOWUP_LIST_DAYS = 30;

function market_followup_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = market_ready() && (bool) db()->query("SHOW TABLES LIKE 'market_followups'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

// ---------------------------------------------------------------------------------------------------- tickets

/** Does this ticket order belong to this organizer's events? */
function followup_ticket_owned_by(int $orderId, int $organizerId): bool
{
    $st = db()->prepare('SELECT 1 FROM orders o JOIN events e ON e.id = o.event_id WHERE o.id = ? AND e.organizer_id = ?');
    $st->execute([$orderId, $organizerId]);
    return (bool) $st->fetchColumn();
}

/**
 * Ticket buyers to follow up: the newest failed order per buyer and event, the event still ahead, the buyer has not paid since.
 * @return list<array<string,mixed>>
 */
function followup_ticket_list(?int $organizerId, int $limit = 100): array
{
    if (!followup_ready()) {
        return [];
    }
    $args = [FOLLOWUP_LIST_DAYS];
    $own = '';
    if ($organizerId !== null) {
        $own = ' AND e.organizer_id = ?';
        $args[] = $organizerId;
    }
    $st = db()->prepare("SELECT o.id, o.total_amount, o.currency, o.created_at, o.phone AS pay_phone, u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone,
                                e.title AS event_title, e.slug AS event_slug, e.starts_at, op.org_name,
                                (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', tt.name) SEPARATOR ', ') FROM order_items oi JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE oi.order_id = o.id) AS items,
                                (SELECT COUNT(*) FROM order_followups f WHERE f.order_id = o.id AND f.channel = 'EMAIL' AND f.status = 'SENT') AS emails,
                                (SELECT COUNT(*) FROM order_followups f WHERE f.order_id = o.id AND f.channel = 'WHATSAPP' AND f.status = 'OPENED') AS whatsapps,
                                (SELECT MAX(f.created_at) FROM order_followups f WHERE f.order_id = o.id AND f.status IN ('SENT','OPENED')) AS last_contact
                         FROM orders o JOIN users u ON u.id = o.user_id JOIN events e ON e.id = o.event_id LEFT JOIN organizer_profiles op ON op.user_id = e.organizer_id
                         WHERE o.status = 'FAILED' AND o.created_at >= NOW() - INTERVAL ? DAY AND COALESCE(e.ends_at, e.starts_at) > NOW() $own
                           AND o.id = (SELECT MAX(o3.id) FROM orders o3 WHERE o3.user_id = o.user_id AND o3.event_id = o.event_id AND o3.status = 'FAILED')
                           AND NOT EXISTS (SELECT 1 FROM orders o2 WHERE o2.user_id = o.user_id AND o2.event_id = o.event_id AND o2.status = 'PAID' AND o2.created_at >= o.created_at)
                         ORDER BY o.id DESC LIMIT " . max(1, min(300, $limit)));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['wa'] = followup_phone($r);
    }
    unset($r);
    return $rows;
}

// ---------------------------------------------------------------------------------------------------- OBI MARKET

/** One market order with what a follow-up needs. Null if it does not exist. */
function market_followup_load(int $orderId): ?array
{
    if (!market_ready()) {
        return null;
    }
    $st = db()->prepare('SELECT mo.id, mo.order_number, mo.seller_id, mo.customer_id, mo.total_ugx, mo.payment_status, mo.created_at, c.customer_name AS buyer_name, c.customer_email AS buyer_email,
                                c.customer_phone AS buyer_phone, c.phone AS pay_phone, s.name AS store_name, s.slug AS store_slug, s.status AS store_status, s.is_visible
                         FROM market_orders mo JOIN market_checkouts c ON c.id = mo.checkout_id JOIN market_stores s ON s.seller_id = mo.seller_id WHERE mo.id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    $i = db()->prepare("SELECT i.product_id, i.product_name, i.variant_label, i.quantity, p.status, (SELECT COALESCE(SUM(v.stock - v.reserved), 0) FROM market_variants v WHERE v.product_id = i.product_id AND v.is_active = 1) AS available
                        FROM market_order_items i JOIN market_products p ON p.id = i.product_id WHERE i.order_id = ? ORDER BY i.id");
    $i->execute([$orderId]);
    $o['items'] = $i->fetchAll();
    return $o;
}

/** Where the customer can buy it again: the product page when it was one product, the store otherwise. */
function market_followup_url(array $o): string
{
    $base = rtrim(APP_URL, '/');
    $products = array_values(array_unique(array_map(static fn ($i) => (int) $i['product_id'], $o['items'])));
    return count($products) === 1 ? $base . '/market-product.php?id=' . $products[0] : $base . '/market.php?store=' . rawurlencode((string) $o['store_slug']);
}

/** @return array{0: bool, 1: string} [may follow up, why not] */
function market_followup_eligibility(array $o): array
{
    if (!in_array($o['payment_status'], ['FAILED', 'EXPIRED'], true)) {
        return [false, 'Follow-ups are only for orders whose payment did not go through.'];
    }
    if (strtotime((string) $o['created_at']) < time() - FOLLOWUP_LIST_DAYS * 86400) {
        return [false, 'This was more than ' . FOLLOWUP_LIST_DAYS . ' days ago.'];
    }
    $paid = db()->prepare("SELECT COUNT(*) FROM market_orders WHERE customer_id = ? AND seller_id = ? AND payment_status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED') AND created_at >= ?");
    $paid->execute([(int) $o['customer_id'], (int) $o['seller_id'], $o['created_at']]);
    if ((int) $paid->fetchColumn() > 0) {
        return [false, 'Good news: this customer has since bought from this store, so no follow-up is needed.'];
    }
    if ($o['store_status'] !== 'ACTIVE' || !(int) $o['is_visible']) {
        return [false, 'The store is not open to customers right now.'];
    }
    $buyable = array_filter($o['items'], static fn ($i) => $i['status'] === 'ACTIVE' && (int) $i['available'] > 0);
    if (!$buyable) {
        return [false, 'None of these items can be bought any more (sold out or hidden).'];
    }
    return [true, ''];
}

/** @return array{0: bool, 1: string} */
function market_followup_channel_check(int $orderId, string $channel): array
{
    $st = db()->prepare("SELECT COUNT(*) AS n, MAX(created_at) AS last FROM market_followups WHERE market_order_id = ? AND channel = ? AND status IN ('SENT','OPENED')");
    $st->execute([$orderId, $channel]);
    $row = $st->fetch();
    $label = $channel === 'EMAIL' ? 'emailed' : 'messaged on WhatsApp';
    if ((int) $row['n'] >= FOLLOWUP_MAX_PER_CHANNEL) {
        return [false, 'This customer has already been ' . $label . ' ' . FOLLOWUP_MAX_PER_CHANNEL . ' times about this order. That is the limit.'];
    }
    if ($row['last'] && strtotime((string) $row['last']) > time() - FOLLOWUP_COOLDOWN_HOURS * 3600) {
        return [false, 'This customer was ' . $label . ' in the last day. You can do it again after ' . date('d M, H:i', strtotime((string) $row['last']) + FOLLOWUP_COOLDOWN_HOURS * 3600) . '.'];
    }
    return [true, ''];
}

function market_followup_items_text(array $o): string
{
    $parts = array_map(static fn ($i) => (int) $i['quantity'] . ' x ' . $i['product_name'] . ($i['variant_label'] !== '' ? ' (' . $i['variant_label'] . ')' : ''), $o['items']);
    return count($parts) > 3 ? implode(', ', array_slice($parts, 0, 3)) . ' and more' : implode(', ', $parts);
}

/** The message the sender starts from: friendly, plain, never says why the payment failed. */
function market_followup_default_message(array $o, array $sender): string
{
    $first = trim(explode(' ', trim((string) $o['buyer_name']))[0]) ?: 'there';
    $from = $sender['role'] === 'ADMIN' ? 'OBI MARKET on obitickets' : (string) $o['store_name'];
    $name = trim($sender['name']);
    $who = rtrim(($name !== '' && strcasecmp($name, $from) !== 0) ? $name . ' from ' . $from : $from, '. ');
    return "Hi {$first}, this is {$who}. We noticed your OBI MARKET order for " . market_followup_items_text($o) . " didn't go through, so nothing was sent to you.\n\n"
        . 'If you would still like it, you can order here: ' . market_followup_url($o) . "\n\n"
        . 'If any money left your account, just reply and we will sort it out.';
}

function market_followup_record(int $orderId, array $sender, string $channel, string $status, ?string $note = null): void
{
    db()->prepare('INSERT INTO market_followups (market_order_id, sent_by, sender_role, channel, status, note) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $sender['id'] ?: null, $sender['role'], $channel, $status, $note !== null ? mb_substr($note, 0, 300) : null]);
}

function render_market_followup_email_html(array $o, array $sender, string $message): string
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    $from = $sender['role'] === 'ADMIN' ? 'OBI MARKET' : (string) $o['store_name'];
    return '<div style="font-family:sans-serif; max-width:560px; margin:0 auto; color:#1C1526;">'
        . '<p style="font-size:15px; line-height:1.65;">' . nl2br($h(trim($message)), false) . '</p>'
        . '<p style="margin:24px 0;"><a href="' . $h(market_followup_url($o)) . '" style="display:inline-block; background:#E5383B; color:#fff; padding:12px 24px; border-radius:999px; text-decoration:none; font-weight:600;">Order again</a></p>'
        . '<p style="color:#726C7E; font-size:12px; line-height:1.6;">You are receiving this because you tried to buy from ' . $h($o['store_name']) . ' on OBI MARKET (obitickets) and the payment did not go through. Sent by ' . $h($from) . '. You can reply to this email.</p></div>';
}

/**
 * One follow-up action on a market order. $sellerScope = the organizer's own seller id (null for an admin): an order of another seller is refused.
 * Returns ['ok' => true, 'flash' => text] | ['ok' => true, 'redirect' => wa.me link] | ['ok' => false, 'error' => text].
 */
function market_followup_handle(int $orderId, ?int $sellerScope, array $sender, string $action, string $message, ?callable $emailSender = null): array
{
    if (!market_followup_ready()) {
        return ['ok' => false, 'error' => 'Follow-ups are not set up on this server yet.'];
    }
    $o = market_followup_load($orderId);
    if (!$o || ($sellerScope !== null && (int) $o['seller_id'] !== $sellerScope)) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    [$may, $why] = market_followup_eligibility($o);
    if (!$may) {
        return ['ok' => false, 'error' => $why];
    }
    if ($err = followup_validate_message($message)) {
        return ['ok' => false, 'error' => $err];
    }
    $message = trim($message);
    if ($action === 'followup_email') {
        [$can, $whyNot] = market_followup_channel_check($orderId, 'EMAIL');
        if (!$can) {
            return ['ok' => false, 'error' => $whyNot];
        }
        $replyTo = filter_var($sender['email'], FILTER_VALIDATE_EMAIL) ? $sender['email'] : null;
        $send = $emailSender ?? 'resend_send';
        $sent = (bool) $send((string) $o['buyer_email'], 'About your OBI MARKET order from ' . $o['store_name'], render_market_followup_email_html($o, $sender, $message), 'market_followup', null, $replyTo);
        market_followup_record($orderId, $sender, 'EMAIL', $sent ? 'SENT' : 'FAILED', $sent ? null : 'Not accepted by the email service (see Email log).');
        return $sent ? ['ok' => true, 'flash' => 'Email sent to ' . $o['buyer_email'] . '.'] : ['ok' => false, 'error' => 'The email was not accepted. An admin can see exactly why in the Email log.'];
    }
    if ($action === 'followup_whatsapp') {
        $phone = followup_phone($o);
        if ($phone === null) {
            return ['ok' => false, 'error' => 'This customer has no mobile number we can use for WhatsApp.'];
        }
        [$can, $whyNot] = market_followup_channel_check($orderId, 'WHATSAPP');
        if (!$can) {
            return ['ok' => false, 'error' => $whyNot];
        }
        market_followup_record($orderId, $sender, 'WHATSAPP', 'OPENED', 'Chat opened from the sender\'s WhatsApp.');
        return ['ok' => true, 'redirect' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($message)];
    }
    return ['ok' => false, 'error' => 'Unknown action.'];
}

/**
 * Market customers to follow up: the newest failed order per customer and seller in the last FOLLOWUP_LIST_DAYS days, who have not paid that seller since.
 * @return list<array<string,mixed>>
 */
function market_followup_list(?int $sellerId, int $limit = 100): array
{
    if (!market_followup_ready()) {
        return [];
    }
    $args = [FOLLOWUP_LIST_DAYS];
    $own = '';
    if ($sellerId !== null) {
        $own = ' AND mo.seller_id = ?';
        $args[] = $sellerId;
    }
    $st = db()->prepare("SELECT mo.id FROM market_orders mo
                         WHERE mo.payment_status IN ('FAILED','EXPIRED') AND mo.created_at >= NOW() - INTERVAL ? DAY $own
                           AND mo.id = (SELECT MAX(m3.id) FROM market_orders m3 WHERE m3.customer_id = mo.customer_id AND m3.seller_id = mo.seller_id AND m3.payment_status IN ('FAILED','EXPIRED'))
                           AND NOT EXISTS (SELECT 1 FROM market_orders m2 WHERE m2.customer_id = mo.customer_id AND m2.seller_id = mo.seller_id AND m2.payment_status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED') AND m2.created_at >= mo.created_at)
                         ORDER BY mo.id DESC LIMIT " . max(1, min(300, $limit)));
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $o = market_followup_load((int) $id);
        if (!$o) {
            continue;
        }
        [$o['eligible'], $o['why_not']] = market_followup_eligibility($o);
        $c = db()->prepare("SELECT SUM(channel = 'EMAIL' AND status = 'SENT') AS emails, SUM(channel = 'WHATSAPP' AND status = 'OPENED') AS whatsapps, MAX(IF(status IN ('SENT','OPENED'), created_at, NULL)) AS last_contact FROM market_followups WHERE market_order_id = ?");
        $c->execute([(int) $id]);
        $o += array_map(static fn ($v) => $v ?? 0, (array) $c->fetch());
        $o['wa'] = followup_phone($o);
        $out[] = $o;
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------- the list, as the dashboards show it

/**
 * Handles a POST from a "Failed payments" page. $organizerId = the organizer's account (null for an admin: everyone). Returns null when it is not a
 * follow-up post; otherwise ['ok'=>..., 'error'|'flash'|'redirect'=>...].
 */
function followup_hub_post(?int $organizerId, array $sender, bool $canTickets, bool $canMarket): ?array
{
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['followup_email', 'followup_whatsapp'], true)) {
        return null;
    }
    $kind = (string) ($_POST['kind'] ?? '');
    $id = max(0, (int) ($_POST['id'] ?? 0));
    $message = (string) ($_POST['message'] ?? '');
    if ($kind === 'ticket') {
        if (!$canTickets || ($organizerId !== null && !followup_ticket_owned_by($id, $organizerId))) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        return followup_handle($id, $sender, $action, $message);
    }
    if ($kind === 'market') {
        if (!$canMarket) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        return market_followup_handle($id, $organizerId, $sender, $action, $message);
    }
    return ['ok' => false, 'error' => 'Unknown order.'];
}

/** The rows: who, what, how much, when, their contacts, how often they were contacted, and the two buttons with an editable message. */
function render_followup_rows(string $kind, array $rows, array $sender, string $postUrl, bool $showSeller = false): string
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    if (!$rows) {
        return '<div class="fu-empty">' . ($kind === 'ticket' ? 'No ticket buyers to follow up right now. When a payment for an upcoming event fails, the buyer shows up here.' : 'No OBI MARKET customers to follow up right now. When a payment for products fails, the customer shows up here.') . '</div>';
    }
    $out = '<div class="fu-list">';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        if ($kind === 'ticket') {
            $what = $r['event_title'] . ($r['items'] ? ' · ' . $r['items'] : '');
            $amount = ($r['currency'] ?: 'UGX') . ' ' . number_format((float) $r['total_amount']);
            [$canE, $whyE] = followup_channel_check($id, 'EMAIL');
            [$canW, $whyW] = followup_channel_check($id, 'WHATSAPP');
            $eligible = true;
            $whyNot = '';
            $message = followup_default_message(['buyer_name' => $r['buyer_name'], 'org_name' => $r['org_name'], 'event_title' => $r['event_title'], 'event_slug' => $r['event_slug']], $sender);
            $open = '/' . ($sender['role'] === 'ADMIN' ? 'admin-order-detail.php' : 'org-order-detail.php') . '?id=' . $id;
            $seller = (string) ($r['org_name'] ?? '');
        } else {
            $what = market_followup_items_text($r);
            $amount = 'UGX ' . number_format((int) $r['total_ugx']);
            [$canE, $whyE] = market_followup_channel_check($id, 'EMAIL');
            [$canW, $whyW] = market_followup_channel_check($id, 'WHATSAPP');
            $eligible = (bool) $r['eligible'];
            $whyNot = (string) $r['why_not'];
            $message = market_followup_default_message($r, $sender);
            $open = $sender['role'] === 'ADMIN' ? '/admin-market-order.php?n=' . rawurlencode((string) $r['order_number']) : '';
            $seller = (string) $r['store_name'];
        }
        $wa = $r['wa'] ?? null;
        $contacted = [];
        if ((int) $r['emails'] > 0) { $contacted[] = 'emailed ' . (int) $r['emails'] . 'x'; }
        if ((int) $r['whatsapps'] > 0) { $contacted[] = 'WhatsApp ' . (int) $r['whatsapps'] . 'x'; }
        $out .= '<article class="fu-row" id="fu-' . $h($kind) . '-' . $id . '">'
            . '<div class="fu-who"><b>' . $h($r['buyer_name']) . '</b><span>' . $h($r['buyer_email']) . ($wa ? ' · ' . $h(followup_phone_display($wa)) : '') . '</span></div>'
            . '<div class="fu-what"><span>' . $h($what) . '</span><small>' . $h($amount) . ' · ' . $h(date('d M, H:i', strtotime((string) $r['created_at']))) . ($showSeller && $seller !== '' ? ' · ' . $h($seller) : '') . ($open !== '' ? ' · <a href="' . $h($open) . '">Open order</a>' : '') . '</small></div>'
            . '<div class="fu-status">' . ($contacted ? '<span class="admin-badge admin-badge-purple">Contacted: ' . $h(implode(', ', $contacted)) . '</span>' : '<span class="admin-badge admin-badge-warn">Not contacted yet</span>') . '</div>';
        if (!$eligible) {
            $out .= '<p class="fu-why">' . $h($whyNot) . '</p></article>';
            continue;
        }
        $out .= '<form method="post" action="' . $h($postUrl) . '#fu-' . $h($kind) . '-' . $id . '" class="fu-form">' . csrf_field()
            . '<input type="hidden" name="kind" value="' . $h($kind) . '"><input type="hidden" name="id" value="' . $id . '">'
            . '<details class="fu-msg"><summary>See or edit the message</summary><textarea name="message" rows="6" maxlength="' . FOLLOWUP_MAX_MESSAGE . '" required>' . $h($message) . '</textarea></details>'
            . '<div class="fu-btns">'
            . ($wa ? '<button class="btn fu-wa" type="submit" name="action" value="followup_whatsapp" formtarget="_blank"' . ($canW ? '' : ' disabled title="' . $h($whyW) . '"') . '>WhatsApp</button>' : '<span class="muted fu-nowa">No WhatsApp number</span>')
            . '<button class="btn btn-line" type="submit" name="action" value="followup_email"' . ($canE ? '' : ' disabled title="' . $h($whyE) . '"') . '>Email</button>'
            . '</div>';
        foreach ([[$canE, $whyE], [$canW || !$wa, $whyW]] as [$can, $why]) {
            if (!$can && $why !== '') {
                $out .= '<p class="fu-why">' . $h($why) . '</p>';
            }
        }
        $out .= '</form></article>';
    }
    return $out . '</div>';
}

/** The small style block both pages use (kept here so organizer and admin look the same). */
function followup_hub_css(): string
{
    return '<style>
.fu-intro{color:var(--muted);font-size:.88rem;line-height:1.5;margin:0 0 16px;max-width:760px}
.fu-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
.fu-list{display:grid;gap:12px}
.fu-row{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1.4fr) auto;gap:8px 18px;align-items:start;padding:16px 18px;border:1px solid var(--line);border-radius:16px;background:var(--surface)}
.fu-who b{display:block;font-size:.95rem}.fu-who span,.fu-what small{display:block;color:var(--muted);font-size:.8rem;margin-top:3px;overflow-wrap:anywhere}
.fu-what span{font-size:.88rem;line-height:1.4}
.fu-form{grid-column:1/-1;display:grid;gap:10px}
.fu-msg summary{cursor:pointer;font-size:.82rem;font-weight:700;color:var(--muted)}
.fu-msg textarea{width:100%;box-sizing:border-box;margin-top:8px;padding:10px 12px;border:1px solid var(--line-strong,var(--line));border-radius:10px;font:inherit;font-size:.88rem;background:var(--paper,#fff);color:var(--ink)}
.fu-btns{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.fu-wa{background:#25D366;border:1px solid #25D366;color:#fff;font-weight:700}.fu-wa:hover{filter:brightness(.95);color:#fff}
.fu-btns .btn[disabled]{opacity:.45;cursor:not-allowed}
.fu-why{grid-column:1/-1;margin:0;color:var(--muted);font-size:.8rem}
.fu-nowa{font-size:.8rem}
.fu-empty{padding:28px 18px;text-align:center;color:var(--muted);border:1px dashed var(--line-strong,var(--line));border-radius:16px;font-size:.9rem}
@media (max-width:760px){.fu-row{grid-template-columns:1fr}}
</style>';
}
