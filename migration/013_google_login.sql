-- obitickets — "Continue with Google": remember which Google account belongs to which user
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab).

SET NAMES utf8mb4;

-- Google's stable account id (the "sub" claim). NULL for everyone who signs in
-- with email + password only; unique so one Google account can never be tied
-- to two obitickets users.
ALTER TABLE users
  ADD COLUMN google_id VARCHAR(64) NULL AFTER email,
  ADD UNIQUE KEY uniq_users_google_id (google_id);
