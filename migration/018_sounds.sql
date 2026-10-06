-- obitickets — sounds for Moments, and photo slideshows
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Additive and safe to run twice. Needs migration 017.

SET NAMES utf8mb4;

-- Library tracks (added by a platform admin) and sounds organizers upload themselves.
-- status REMOVED hides a sound everywhere without breaking the moments that used it.
CREATE TABLE IF NOT EXISTS sounds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  artist VARCHAR(120) NOT NULL DEFAULT '',
  file_path VARCHAR(255) NOT NULL,
  duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bpm DECIMAL(5,1) NOT NULL DEFAULT 100.0,
  beat_offset DECIMAL(6,3) NOT NULL DEFAULT 0,
  source ENUM('LIBRARY','USER') NOT NULL DEFAULT 'USER',
  owner_user_id INT NULL,
  license_note VARCHAR(255) NULL,
  rights_confirmed_at DATETIME NULL,
  status ENUM('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sounds_source (source, status, id),
  KEY idx_sounds_owner (owner_user_id),
  FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The photos of a slideshow moment, in order (up to 10). The moment's own media_path is the cover.
CREATE TABLE IF NOT EXISTS moment_slides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  media_path VARCHAR(500) NOT NULL,
  KEY idx_slides_post (post_id, position),
  FOREIGN KEY (post_id) REFERENCES event_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE event_moments
  MODIFY media_type ENUM('IMAGE','VIDEO','SLIDESHOW') NOT NULL;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_moments' AND COLUMN_NAME = 'sound_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE event_moments
     ADD COLUMN sound_id INT NULL,
     ADD COLUMN sound_start DECIMAL(6,2) NOT NULL DEFAULT 0,
     ADD COLUMN sound_mix TINYINT UNSIGNED NOT NULL DEFAULT 70,
     ADD COLUMN slide_beats TINYINT UNSIGNED NOT NULL DEFAULT 2,
     ADD COLUMN slide_fx ENUM(''CUT'',''FADE'',''ZOOM'',''FLASH'') NOT NULL DEFAULT ''ZOOM'',
     ADD CONSTRAINT fk_moments_sound FOREIGN KEY (sound_id) REFERENCES sounds(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
