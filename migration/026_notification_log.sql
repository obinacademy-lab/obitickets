-- Automatic messages to buyers (reminders before an event, a nudge after a failed payment).
-- One row per order, message kind and channel. The UNIQUE key is what makes "send once" true even when two cron
-- runs overlap. Run once on production; the code keeps working (with the old once-per-event reminder) until then.
CREATE TABLE IF NOT EXISTS notification_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  kind VARCHAR(32) NOT NULL,                      -- reminder_24h, reminder_2h, recovery
  channel ENUM('EMAIL','SMS','WHATSAPP') NOT NULL DEFAULT 'EMAIL',
  status ENUM('SENDING','SENT','FAILED','SKIPPED') NOT NULL DEFAULT 'SENDING',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification (order_id, kind, channel),
  KEY idx_notification_kind_created (kind, created_at)
);
