<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Purchase Terms — obitickets';
$pageDescription = 'What to know before you buy a ticket on obitickets: pricing, payment, delivery, and what happens if an event changes.';
$bodyClass = 'classic-page';
include __DIR__ . '/includes/header.php';
?>

<div class="wrap">

  <section class="contact-hero">
    <div class="contact-hero-inner">
      <span class="kicker"><i></i>Legal</span>
      <h1>Purchase Terms</h1>
      <p>The specifics of buying a ticket on obitickets — pricing, payment, delivery, and what happens if plans change.</p>
    </div>
  </section>

  <section class="ah-section" style="padding-top:30px">
    <div class="legal-doc">
      <p class="updated">Last updated: <?= date('j F Y') ?></p>

      <h2>1. Pricing and fees</h2>
      <p>Every ticket tier's price is set by the event's organizer. On top of that, obitickets adds a flat service fee per ticket, shown in your order summary before you pay — the total you see at checkout is the total you're charged, with nothing added afterward.</p>

      <h2>2. Payment</h2>
      <p>Payment is collected via MTN Mobile Money or Airtel Money, processed through our payment partner. You'll get a prompt on your phone to approve the charge — your order is only confirmed once that payment succeeds. If a payment fails or is declined, your ticket selection is released so someone else can buy it.</p>

      <h2>3. Order confirmation and ticket delivery</h2>
      <p>Once your payment is confirmed, your ticket (with a unique, scannable QR code) is generated immediately and emailed to the address on your account. You can also view or re-download it anytime from your account's "My tickets" page. Check your spam folder if it doesn't arrive within a few minutes, or <a href="/contact.php">contact us</a>.</p>

      <h2>4. Accuracy of your order</h2>
      <p>You're responsible for reviewing your ticket tier, quantity, and the event's date and venue before paying — orders can't be edited once placed, and mobile money payments can't be reversed by obitickets once approved.</p>

      <h2>5. If an event is cancelled, postponed, or changed</h2>
      <p>See our <a href="/refunds.php">refund policy</a> for exactly when you're entitled to a refund. In short: a cancelled event is refunded in full; a postponed event gives you the choice of holding your ticket for the new date or requesting a refund.</p>

      <h2>6. Entry and check-in</h2>
      <p>Your QR code is your ticket — keep it private, since anyone holding a valid, unused code can be checked in with it. Each code can only be scanned in once. You're responsible for bringing a working copy (screenshot or the email itself) to the event; obitickets isn't responsible for entry issues caused by a lost or shared code.</p>

      <h2>7. Ticket transfers and resale</h2>
      <p>You may transfer your own ticket to someone else by sharing its QR code before check-in, but obitickets doesn't currently offer a formal resale marketplace, and doesn't mediate private resale disputes between buyers.</p>

      <h2>8. Organizer responsibility</h2>
      <p>The event itself — its content, safety, and whether it happens as described — is run entirely by its organizer, not obitickets. obitickets processes your payment and issues your ticket, but isn't a party to the event itself.</p>

      <h2>9. Questions about an order</h2>
      <p>Reach out via <a href="/contact.php">Contact us</a> with your order number (found on your confirmation page and in "My tickets") and we'll help.</p>
    </div>
  </section>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
