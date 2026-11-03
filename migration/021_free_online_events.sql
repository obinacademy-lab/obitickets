-- obitickets — free tickets and online events
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Additive and safe to run twice.
--
-- events:        how people attend (in person / online / both) plus the online meeting details
-- ticket_types:  an optional limit of tickets per person (used for free tickets, default 4 in the app)
-- online_joins:  who pressed Join on an online event (replaces door scanning for online attendance)
--
-- Free tickets need no new column: a ticket type priced at 0 is free.

SET NAMES utf8mb4;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'attend_mode');
SET @sql := IF(@has = 0,
  'ALTER TABLE events
     ADD COLUMN attend_mode ENUM(''IN_PERSON'',''ONLINE'',''HYBRID'') NOT NULL DEFAULT ''IN_PERSON'',
     ADD COLUMN online_platform VARCHAR(30) NULL,
     ADD COLUMN online_url VARCHAR(500) NULL,
     ADD COLUMN online_notes VARCHAR(500) NULL,
     ADD KEY idx_events_attend (attend_mode)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_types' AND COLUMN_NAME = 'max_per_person');
SET @sql := IF(@has = 0,
  'ALTER TABLE ticket_types ADD COLUMN max_per_person TINYINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS online_joins (
  id INT NOT NULL AUTO_INCREMENT,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  join_count INT NOT NULL DEFAULT 1,
  first_joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_online_join (event_id, user_id),
  KEY idx_online_join_event (event_id),
  CONSTRAINT fk_online_join_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_online_join_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
