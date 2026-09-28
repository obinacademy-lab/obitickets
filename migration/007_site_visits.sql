-- obitickets — daily site visitor tracking, for the admin dashboard
-- Run once against the existing database: mysql -u user -p dbname < migration/007_site_visits.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- One row per (session, day) — the unique key means a visitor hitting many
-- pages in the same browser session only ever counts once per day, so
-- COUNT(*) grouped by visit_date is a real "unique visitors that day"
-- figure, not a raw pageview count. No IP address or user-agent is stored.
CREATE TABLE site_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(64) NOT NULL,
  visit_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site_visits_session_day (session_id, visit_date),
  INDEX idx_site_visits_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
