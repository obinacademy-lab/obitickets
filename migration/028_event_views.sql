-- "Viewed but never bought": who opened an event page (event_views) and when an organizer reached out to them about it
-- (event_outreach). A logged-in viewer is stored once per event (viewer_key 'u:<user id>'); a guest is stored once per
-- browser session (viewer_key 's:<session id>') and can only ever be counted, never contacted.
-- Rows older than 90 days are removed automatically (event_view_record() purges a few now and then), so no cron job is
-- needed. Safe to run more than once.
CREATE TABLE IF NOT EXISTS event_views (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  viewer_key VARCHAR(80) NOT NULL,
  user_id INT NULL,
  view_count INT NOT NULL DEFAULT 1,
  first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_viewer (event_id, viewer_key),
  INDEX idx_views_event_user (event_id, user_id, last_viewed_at),
  INDEX idx_views_last (last_viewed_at),
  CONSTRAINT fk_views_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_outreach (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  sent_by INT NULL,
  -- ORGANIZER = the organizer owner; TEAM = a member of the organizer's team.
  sender_role ENUM('ORGANIZER','TEAM') NOT NULL,
  channel ENUM('EMAIL','WHATSAPP') NOT NULL,
  -- SENT = the email was accepted; OPENED = the WhatsApp chat was opened (delivery cannot be confirmed); FAILED = not sent.
  status ENUM('SENT','OPENED','FAILED') NOT NULL,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outreach_person (event_id, user_id, channel, created_at),
  INDEX idx_outreach_user_day (user_id, channel, created_at),
  INDEX idx_outreach_sender_day (sent_by, channel, created_at),
  CONSTRAINT fk_outreach_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_outreach_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_outreach_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
