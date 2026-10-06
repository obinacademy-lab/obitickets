-- obitickets — Moments: 9:16 photos and videos with comments, reactions and sharing
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Additive and safe to run twice.
--
-- A moment is a normal community post (post_type MOMENT) plus one event_moments row that
-- holds the media. That gives every moment comments, reactions, reports and moderation
-- through the same tables the feed already uses.

SET NAMES utf8mb4;

ALTER TABLE event_posts
  MODIFY post_type ENUM('TEXT','IMAGE','ORGANIZER_UPDATE','MOMENT') NOT NULL DEFAULT 'TEXT';

CREATE TABLE IF NOT EXISTS event_moments (
  post_id BIGINT NOT NULL PRIMARY KEY,
  event_id INT NOT NULL,
  media_type ENUM('IMAGE','VIDEO') NOT NULL,
  media_path VARCHAR(500) NOT NULL,
  poster_path VARCHAR(500) NULL,
  focus_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
  fit_mode ENUM('FILL','FIT') NOT NULL DEFAULT 'FILL',
  duration_seconds SMALLINT UNSIGNED NULL,
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  share_count INT UNSIGNED NOT NULL DEFAULT 0,
  legacy_media_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_moment_legacy (legacy_media_id),
  KEY idx_moments_event (event_id, post_id),
  FOREIGN KEY (post_id) REFERENCES event_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bring the existing event gallery (event_media) across so those photos and videos
-- can be commented on and reacted to too. Skips anything already converted.
INSERT INTO event_posts (event_id, author_id, post_type, body, status, created_at)
SELECT m.event_id, e.organizer_id, 'MOMENT', CONCAT('__legacy_media_', m.id), 'PUBLISHED', m.created_at
FROM event_media m
JOIN events e ON e.id = m.event_id
WHERE NOT EXISTS (SELECT 1 FROM event_moments em WHERE em.legacy_media_id = m.id);

INSERT INTO event_moments (post_id, event_id, media_type, media_path, legacy_media_id, created_at)
SELECT p.id, m.event_id, m.media_type, m.file_path, m.id, m.created_at
FROM event_media m
JOIN event_posts p ON p.body = CONCAT('__legacy_media_', m.id) AND p.post_type = 'MOMENT'
WHERE NOT EXISTS (SELECT 1 FROM event_moments em WHERE em.legacy_media_id = m.id);

UPDATE event_posts SET body = NULL WHERE post_type = 'MOMENT' AND body LIKE '\_\_legacy\_media\_%';
