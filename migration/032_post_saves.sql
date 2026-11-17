-- 032: saved videos (the Save / bookmark button in the feed). One row per person per post.
CREATE TABLE IF NOT EXISTS post_saves (
  post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_post_saves_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
