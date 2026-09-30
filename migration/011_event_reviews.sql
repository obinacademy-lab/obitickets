-- obitickets — post-event reviews & ratings
-- Run once against the existing database: mysql -u user -p dbname < migration/011_event_reviews.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- One review per (event, user) — enforced by the unique key, so
-- submit_event_review() in includes/reviews.php can just upsert instead of
-- checking for an existing row first. Eligibility (did this user actually
-- attend?) is checked at submit time against tickets.status = 'USED', not
-- stored here — a review from someone who was checked in with a ticket that
-- later gets refunded still stands, same as the ticket itself does.
CREATE TABLE event_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event_reviews_event_user (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_event_reviews_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guards cron/send-review-prompts.php against re-prompting the same event on
-- a later run, same pattern as events.reminder_sent_at (migration 006).
ALTER TABLE events ADD COLUMN review_prompt_sent_at DATETIME NULL AFTER reminder_sent_at;

SET FOREIGN_KEY_CHECKS = 1;
