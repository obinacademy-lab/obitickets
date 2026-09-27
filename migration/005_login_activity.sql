-- obitickets — login/signup activity tracking, for the admin "Login Activity" page
-- Run once against the existing database: mysql -u user -p dbname < migration/005_login_activity.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- One row per successful login or signup — never for a failed attempt.
-- `role` is snapshotted at the time of the event (not joined live from
-- `users`), so a later role change never rewrites history. No raw IP address
-- is stored — only the city/country it resolves to — since that's the only
-- thing the admin UI needs to show, and it keeps the table free of anything
-- sensitive worth retaining long-term.
CREATE TABLE login_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_type ENUM('LOGIN','SIGNUP') NOT NULL,
  role ENUM('ATTENDEE','ORGANIZER','ADMIN') NOT NULL,
  device_type ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
  browser VARCHAR(40) NULL,
  os VARCHAR(40) NULL,
  country CHAR(2) NULL,
  city VARCHAR(100) NULL,
  logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_login_log_user (user_id),
  INDEX idx_login_log_time (logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
