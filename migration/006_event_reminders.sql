-- obitickets — "event tomorrow" reminder emails
-- Run once against the existing database: mysql -u user -p dbname < migration/006_event_reminders.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Set once cron/send-event-reminders.php has emailed every paid attendee for
-- this event, so a later cron run never sends the same reminder twice. NULL
-- means "not sent yet" (or the event has already passed and never qualified).
ALTER TABLE events ADD COLUMN reminder_sent_at DATETIME NULL AFTER status;

SET FOREIGN_KEY_CHECKS = 1;
