-- obitickets — drafts for Moments (easy upload)
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Additive and safe to run twice. Needs migration 017.
--
-- A moment can now be uploaded in the background while the organizer writes the caption.
-- Until they press Post it is a draft: kept, but not shown to anyone and not announced.

SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_moments' AND COLUMN_NAME = 'is_draft');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE event_moments ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_moments_draft (is_draft, created_at)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
