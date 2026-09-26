<footer id="site-footer">
  <div class="foot-glow foot-glow-a"></div>
  <div class="foot-glow foot-glow-b"></div>

  <div class="foot-main-section">
    <div class="wrap foot-main">
      <div class="foot-brand">
        <a class="logo" href="/">
          <svg class="logo-mark" viewBox="0 0 34 34"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/></svg>
          obitickets
        </a>
        <p>Ticketing made simple &mdash; for organizers and attendees across Africa.<br>Kampala, Uganda</p>
        <div class="pay-row">
          <span class="pay-badge"><img class="pay-logo pay-logo-mtn" src="/assets/images/brands/mtn-logo.svg" alt="MTN MoMo"></span>
          <span class="pay-badge"><img class="pay-logo pay-logo-airtel" src="/assets/images/brands/airtel-logo.svg" alt="Airtel Money"></span>
        </div>
      </div>

      <div class="foot-col">
        <h4>Company</h4>
        <a href="/">Homepage</a>
        <a href="/about.php">About</a>
        <a href="/contact.php">Contact</a>
      </div>
      <div class="foot-col">
        <h4>Organizers</h4>
        <a href="/event-create.php">Create an event</a>
        <a href="/pricing.php">Pricing</a>
        <a href="/team.php">Our team</a>
      </div>
      <div class="foot-col">
        <h4>Support</h4>
        <a href="/help.php">Help centre</a>
        <a href="/refunds.php">Refunds</a>
      </div>

      <button type="button" class="foot-top-btn" id="footToTop" aria-label="Back to top">
        <svg width="18" height="18"><use href="#ic-chev"/></svg>
      </button>
    </div>
  </div>

  <div class="foot-bottom-section">
    <div class="wrap foot-bottom">
      <span>&copy; <?= date('Y') ?> obitickets. All rights reserved.</span>
    </div>
  </div>
</footer>
<script src="/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: time() ?>"></script>
</body>
</html>
