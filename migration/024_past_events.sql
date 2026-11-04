-- obitickets — Past Events page controls
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Additive and safe to run twice: it only adds
-- columns that are missing and never changes or deletes existing data.
--
-- events.highlights              what the admin wants shown as "Event highlights", one per line
-- events.past_hidden             1 = keep this finished event off the public Past Events page
-- events.past_featured           1 = show this finished event first, in the Featured row
-- events.completed_manually_at   when an admin marked the event completed by hand (it then counts as
--                                past even if its end time has not passed yet)
-- event_categories.past_group    which Past Events filter this category belongs to:
--                                concerts | business | workshops | festivals | lifestyle

SET NAMES utf8mb4;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'highlights');
SET @sql := IF(@has = 0, 'ALTER TABLE events ADD COLUMN highlights TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'past_hidden');
SET @sql := IF(@has = 0, 'ALTER TABLE events ADD COLUMN past_hidden TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'past_featured');
SET @sql := IF(@has = 0, 'ALTER TABLE events ADD COLUMN past_featured TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'completed_manually_at');
SET @sql := IF(@has = 0, 'ALTER TABLE events ADD COLUMN completed_manually_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_categories' AND COLUMN_NAME = 'past_group');
SET @sql := IF(@has = 0, 'ALTER TABLE event_categories ADD COLUMN past_group VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Starting point for the filters (only fills categories that have no group yet; the admin can change any of them).
UPDATE event_categories SET past_group = 'concerts'  WHERE past_group IS NULL AND name IN ('Music', 'Comedy');
UPDATE event_categories SET past_group = 'business'  WHERE past_group IS NULL AND name IN ('Conference');
UPDATE event_categories SET past_group = 'festivals' WHERE past_group IS NULL AND name IN ('Sports', 'Faith');
UPDATE event_categories SET past_group = 'lifestyle' WHERE past_group IS NULL AND name IN ('Fashion', 'Community');
