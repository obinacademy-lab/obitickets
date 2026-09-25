<footer id="site-footer">
  <div class="foot-glow foot-glow-a"></div>
  <div class="foot-glow foot-glow-b"></div>

  <div class="foot-community">
    <div class="wrap">
      <h2><i></i>Join Our Community</h2>
      <div class="foot-social-row">
        <a class="foot-social" href="#" aria-label="Instagram">
          <span class="foot-social-ring"><svg width="22" height="22"><use href="#ic-instagram"/></svg></span>
          <span class="foot-social-label">Instagram</span>
        </a>
        <a class="foot-social" href="#" aria-label="X">
          <span class="foot-social-ring"><svg width="20" height="20"><use href="#ic-x"/></svg></span>
          <span class="foot-social-label">X</span>
        </a>
        <a class="foot-social" href="#" aria-label="TikTok">
          <span class="foot-social-ring"><svg width="20" height="20"><use href="#ic-tiktok"/></svg></span>
          <span class="foot-social-label">TikTok</span>
        </a>
      </div>
    </div>
  </div>

  <div class="wrap"><div class="foot-divider"></div></div>

  <div class="foot-main-section">
    <div class="wrap foot-main">
      <div class="foot-brand">
        <a class="logo" href="/">
          <svg class="logo-mark" viewBox="0 0 34 34"><rect x="1" y="1" width="32" height="32" rx="10" fill="var(--purple)"/><circle cx="17" cy="17" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="17" cy="9.6" r="2" fill="var(--purple)" stroke="#fff" stroke-width="1.6"/></svg>
          obitickets
        </a>
        <p>Ticketing made simple &mdash; for organizers and attendees across Africa.<br>Kampala, Uganda</p>
        <div class="pay-row"><span class="pay-badge">MTN MoMo</span><span class="pay-badge">Airtel Money</span></div>
      </div>

      <div class="foot-links">
        <div class="foot-col">
          <a href="/">Homepage</a>
          <a href="/about.php">About</a>
          <a href="/contact.php">Contact</a>
          <a href="/help.php">Help centre</a>
        </div>
        <div class="foot-col">
          <a href="/event-create.php">Create an event</a>
          <a href="/pricing.php">Pricing</a>
          <a href="/team.php">Our team</a>
          <a href="/refunds.php">Refunds</a>
        </div>
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
