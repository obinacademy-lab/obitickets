-- IP blocker (includes/ipguard.php). ip_events remembers risky actions per IP address (failed logins, refused
-- payments, probing for files...); ip_blocks holds the addresses currently blocked and for how long.
-- Run once on production. Until then the site works exactly as before, with no blocking.
CREATE TABLE IF NOT EXISTS ip_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  kind VARCHAR(24) NOT NULL,                      -- login_fail, signup_fail, reset, contact, pay_refused, human_fail, csrf_fail, probe
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_events_ip_time (ip, created_at),
  KEY idx_ip_events_time (created_at)
);

CREATE TABLE IF NOT EXISTS ip_blocks (
  ip VARCHAR(45) NOT NULL PRIMARY KEY,
  blocked_until DATETIME NOT NULL,
  level TINYINT UNSIGNED NOT NULL DEFAULT 1,      -- 1 = first offence (1 hour), 2 = repeat within a day (24 hours)
  reason VARCHAR(120) NOT NULL DEFAULT '',
  hits INT UNSIGNED NOT NULL DEFAULT 1,           -- how many times this address has been blocked
  first_blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_blocks_until (blocked_until)
);
