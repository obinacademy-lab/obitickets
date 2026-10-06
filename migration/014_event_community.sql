-- obitickets — Event Community (social layer), slice 1
-- Organizer social links, event/organizer follows, event posts, reactions,
-- comments (one reply level), reports, blocks and a moderation log.
--
-- Run once against the existing database (select the database in phpMyAdmin
-- first, then paste this into the SQL tab). Purely additive: no existing
-- table or row is changed. Every table cascades away with its event/user, so
-- deleting an event or account leaves no orphaned social rows.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- An organizer's external profiles. One row per platform; only enabled ones
-- are shown publicly. Only https:// URLs are ever stored (enforced in PHP).
CREATE TABLE IF NOT EXISTS organizer_social_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organizer_user_id INT NOT NULL,
  platform ENUM('INSTAGRAM','TIKTOK','FACEBOOK','X','YOUTUBE','WHATSAPP','WEBSITE') NOT NULL,
  url VARCHAR(500) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_org_platform (organizer_user_id, platform),
  FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_follows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event_follow (event_id, user_id),
  KEY idx_event_follows_user (user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organizer_follows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organizer_user_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_organizer_follow (organizer_user_id, user_id),
  KEY idx_organizer_follows_user (user_id),
  FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Posts on an event's community feed. One table for every post type.
-- status: HIDDEN = removed from public view but kept (can be restored);
-- DELETED = removed by the author or a moderator (kept only for the audit trail).
CREATE TABLE IF NOT EXISTS event_posts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  author_id INT NOT NULL,
  post_type ENUM('TEXT','IMAGE','ORGANIZER_UPDATE') NOT NULL DEFAULT 'TEXT',
  body TEXT NULL,
  image_path VARCHAR(255) NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('PUBLISHED','HIDDEN','DELETED') NOT NULL DEFAULT 'PUBLISHED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_posts_feed (event_id, status, is_pinned, id),
  KEY idx_posts_author (author_id, created_at),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One reaction per person per post (changing it replaces the row).
CREATE TABLE IF NOT EXISTS post_reactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  reaction ENUM('LOVE','FIRE','FUNNY','EXCITED','APPLAUSE','PARTY','WOW') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_post_reaction (post_id, user_id),
  KEY idx_post_reactions_post (post_id, reaction),
  FOREIGN KEY (post_id) REFERENCES event_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Comments; parent_id is set for a reply. Replies are one level deep only
-- (enforced in PHP: a reply's parent must itself be a top-level comment).
CREATE TABLE IF NOT EXISTS post_comments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  parent_id BIGINT NULL,
  body VARCHAR(1000) NOT NULL,
  status ENUM('PUBLISHED','HIDDEN','DELETED') NOT NULL DEFAULT 'PUBLISHED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comments_post (post_id, status, id),
  KEY idx_comments_parent (parent_id),
  FOREIGN KEY (post_id) REFERENCES event_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES post_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comment_reactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  comment_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  reaction ENUM('LOVE','FIRE','FUNNY','EXCITED','APPLAUSE','PARTY','WOW') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_comment_reaction (comment_id, user_id),
  FOREIGN KEY (comment_id) REFERENCES post_comments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reports on a post or a comment. A person can report the same item once.
-- content_id points at event_posts.id or post_comments.id depending on content_type;
-- event_id is stored so an organizer's queue is one indexed lookup.
CREATE TABLE IF NOT EXISTS content_reports (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  content_type ENUM('POST','COMMENT') NOT NULL,
  content_id BIGINT NOT NULL,
  reporter_id INT NOT NULL,
  reason ENUM('SPAM','HARASSMENT','NUDITY','VIOLENCE','HATE','SCAM','COPYRIGHT','OTHER') NOT NULL,
  details VARCHAR(500) NULL,
  status ENUM('OPEN','ACTIONED','DISMISSED') NOT NULL DEFAULT 'OPEN',
  handled_by INT NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_report (content_type, content_id, reporter_id),
  KEY idx_reports_event (event_id, status, created_at),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A person blocking another: neither sees the other's posts/comments.
CREATE TABLE IF NOT EXISTS user_blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  blocker_id INT NOT NULL,
  blocked_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_block (blocker_id, blocked_id),
  KEY idx_blocks_blocked (blocked_id),
  FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every hide / restore / delete by an organizer or admin, for accountability.
-- actor_id is kept (SET NULL) if that account is later removed.
CREATE TABLE IF NOT EXISTS moderation_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  actor_id INT NULL,
  actor_role ENUM('ORGANIZER','ADMIN','AUTHOR') NOT NULL,
  content_type ENUM('POST','COMMENT') NOT NULL,
  content_id BIGINT NOT NULL,
  action ENUM('HIDE','RESTORE','DELETE') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_modlog_event (event_id, created_at),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
