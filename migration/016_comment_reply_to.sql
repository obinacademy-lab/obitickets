-- obitickets — "replying to" on comments
-- Run once against the existing database (select the database in phpMyAdmin first,
-- then paste this into the SQL tab). Purely additive and safe to run twice.
--
-- parent_id keeps pointing at the top-level comment (the thread). The new column
-- remembers the exact comment being answered, so a reply can show "Replying to <name>"
-- even when it answers another reply.

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_comments' AND COLUMN_NAME = 'reply_to_comment_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE post_comments ADD COLUMN reply_to_comment_id BIGINT NULL AFTER parent_id, ADD CONSTRAINT fk_comments_reply_to FOREIGN KEY (reply_to_comment_id) REFERENCES post_comments(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
