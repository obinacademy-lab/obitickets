-- obitickets — reactions on the event itself (any logged-in account, no ticket needed)
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Purely additive and safe to run twice.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- One reaction per person per event (changing it replaces the row). Cascades away
-- with the event or the account, like every other community table.
CREATE TABLE IF NOT EXISTS event_reactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  reaction ENUM('LOVE','FIRE','FUNNY','EXCITED','APPLAUSE','PARTY','WOW') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event_reaction (event_id, user_id),
  KEY idx_event_reactions_event (event_id, reaction),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
