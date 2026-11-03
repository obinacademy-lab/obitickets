<footer id="site-footer">
  <div class="foot-main-section">
    <div class="wrap foot-main">
      <div class="foot-brand">
        <a class="logo" href="/">
          <svg class="logo-mark" viewBox="0 0 34 34"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/></svg>
          obitickets
        </a>
        <p>Obitickets is a modern event discovery and ticketing platform designed to transform how people discover events, purchase tickets and manage event experiences.</p>
        <p>We bring event organizers, promoters and attendees together in one convenient digital marketplace, making it easier to publish events, sell tickets online and connect people with experiences worth attending.</p>
        <div class="pay-row">
          <span class="pay-badge"><img class="pay-logo pay-logo-mtn" src="/assets/images/brands/mtn-momo-logo.png" alt="MTN MoMo"></span>
          <span class="pay-badge"><img class="pay-logo pay-logo-airtel" src="/assets/images/brands/airtel-money-logo.png" alt="Airtel Money"></span>
        </div>
      </div>

      <nav class="foot-col" aria-label="Company">
        <h4>Company</h4>
        <a href="/">Homepage</a>
        <a href="/past-events.php">Past events</a>
        <a href="/about.php">About</a>
        <a href="/contact.php">Contact</a>
        <a href="#" data-install-app hidden>Install the app</a>
      </nav>
      <nav class="foot-col" aria-label="Organizers">
        <h4>Organizers</h4>
        <a href="/event-create.php">Create an event</a>
        <a href="/pricing.php">Pricing</a>
        <a href="/team.php">Our team</a>
      </nav>
      <nav class="foot-col" aria-label="Support">
        <h4>Support</h4>
        <a href="/help.php">Help centre</a>
        <a href="/refunds.php">Refunds</a>
        <a class="foot-wa" href="<?= htmlspecialchars(support_whatsapp_url()) ?>" target="_blank" rel="noopener"><svg width="15" height="15" aria-hidden="true"><use href="#ic-whatsapp"/></svg> WhatsApp us</a>
      </nav>
      <nav class="foot-col" aria-label="Legal">
        <h4>Legal</h4>
        <a href="/terms.php">Terms of Use</a>
        <a href="/purchase-terms.php">Purchase Terms</a>
        <a href="/privacy.php">Privacy Policy</a>
        <a href="/delete-account.php">Delete account</a>
      </nav>
    </div>
    <div class="wrap">
      <div class="foot-mark" aria-hidden="true"><span><b>obitickets</b></span></div>
    </div>
  </div>

  <div class="foot-bottom-section">
    <div class="wrap foot-bottom">
      <span>&copy; <?= date('Y') ?> obitickets. All rights reserved. Kampala, Uganda</span>
      <button type="button" class="foot-top-btn" id="footToTop" aria-label="Back to top">
        <svg width="18" height="18"><use href="#ic-chev"/></svg>
      </button>
    </div>
  </div>
</footer>
<?php if (empty($hideWhatsApp)): ?>
<a class="wa-float" href="<?= htmlspecialchars(support_whatsapp_url()) ?>" target="_blank" rel="noopener" aria-label="Chat with obitickets on WhatsApp" title="Questions? Chat with us on WhatsApp">
  <svg width="28" height="28" aria-hidden="true"><use href="#ic-whatsapp"/></svg>
</a>
<?php endif; ?>
<script src="/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: time() ?>"></script>
<script src="/assets/js/motion.js?v=<?= @filemtime(__DIR__ . '/../assets/js/motion.js') ?: time() ?>"></script>
</body>
</html>
