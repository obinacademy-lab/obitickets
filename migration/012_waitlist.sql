-- obitickets — waitlist for sold-out ticket tiers
-- Run once against the existing database: mysql -u user -p dbname < migration/012_waitlist.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- One row per (tier, user). notified_at is NULL while someone is still
-- waiting; notify_waitlist_for_tier() in includes/waitlist.php stamps it when
-- it emails them that a seat opened up, which also keeps them from being
-- emailed twice for the same seat. A person who re-joins after being notified
-- has the row reset (notified_at back to NULL, queue position refreshed)
-- rather than getting a second row — see join_waitlist().
CREATE TABLE waitlist_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_type_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified_at DATETIME NULL,
  UNIQUE KEY uniq_waitlist_tier_user (ticket_type_id, user_id),
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_waitlist_queue (ticket_type_id, notified_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
