-- obitickets — promo videos and sales tracking for Moments
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Additive and safe to run twice. Needs migration 017.
--
-- event_moments: a promo flag, a switch for the "Get tickets" bar, and counters for taps on it
-- and checkouts started from it. orders: which moment (if any) a purchase came from, so
-- Event Social can show what each video sold.

SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_moments' AND COLUMN_NAME = 'buy_bar');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE event_moments
     ADD COLUMN is_promo TINYINT(1) NOT NULL DEFAULT 0,
     ADD COLUMN buy_bar TINYINT(1) NOT NULL DEFAULT 0,
     ADD COLUMN cta_taps INT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN checkouts INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'source_moment_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE orders ADD COLUMN source_moment_id BIGINT NULL, ADD KEY idx_orders_source_moment (source_moment_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
