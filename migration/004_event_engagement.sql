-- Event engagement (likes) — additive schema only (no drops, no destructive changes).
-- Run this against production via phpMyAdmin the same way migrations 002/003 were run.

-- Cached count so event.php/search.php/shelf cards can show it without a COUNT(*)
-- join every time; kept in sync with event_likes by toggle_event_like().
ALTER TABLE events ADD COLUMN likes_count INT NOT NULL DEFAULT 0 AFTER status;

-- One row per like. A logged-in like is keyed by user_id; a guest like is keyed
-- by their PHP session_token instead (no account required to like an event).
-- Exactly one of the two is set per row — the unique keys stop either identity
-- from liking the same event twice, which is what makes the heart a toggle.
CREATE TABLE event_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NULL,
    session_token VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_el_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_el_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_event_like_user (event_id, user_id),
    UNIQUE KEY uniq_event_like_session (event_id, session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
