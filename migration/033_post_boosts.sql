-- 033: Boost (our "repost"): a person pushes an organizer's video to more people. One row per person per post.
-- Boosts raise the video in For You and Trending.
CREATE TABLE IF NOT EXISTS post_boosts (
  post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_post_boosts_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
