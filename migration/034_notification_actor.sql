-- Phase 3 (11/18): who did it, so the Notifications page can show their picture / initials.
-- Optional: the site works without it (notifications just show an icon instead of the person).
ALTER TABLE notifications ADD COLUMN actor_id INT NULL AFTER user_id;
CREATE INDEX idx_notifications_actor ON notifications(actor_id);
