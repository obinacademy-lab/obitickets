-- 051: who viewed a video (11/27). Owner: "organizers and users can see which users viewed this video and inbox them, like TikTok".
-- Owner's decisions: only the video's OWNER sees the list; every signed-in viewer is listed unless they switch it off in Settings
-- ("Show me in video viewer lists", on by default). Someone who switched it off is not recorded at all, and cannot see who viewed
-- their own videos either (fair, like TikTok). Guests are never listed (they only count in the view number).
-- Additive and safe to run twice. Nothing about tickets, payments or the view counter changes.

CREATE TABLE IF NOT EXISTS moment_viewers (
  post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  view_count INT NOT NULL DEFAULT 1,
  first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_mv_post_recent (post_id, last_viewed_at),
  KEY idx_mv_user (user_id),
  CONSTRAINT fk_mv_post FOREIGN KEY (post_id) REFERENCES event_posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_mv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN IF NOT EXISTS show_in_viewers TINYINT(1) NOT NULL DEFAULT 1;
