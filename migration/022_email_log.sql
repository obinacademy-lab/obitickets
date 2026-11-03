-- Every email the site tries to send, with what happened. Lets an admin see at a glance whether ticket emails
-- are really reaching people, and why not when they are not. Safe to run more than once.
CREATE TABLE IF NOT EXISTS email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind VARCHAR(32) NOT NULL,
  to_email VARCHAR(191) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status ENUM('SENT','FAILED','SKIPPED') NOT NULL,
  provider_id VARCHAR(80) NULL,
  error VARCHAR(600) NULL,
  order_id INT NULL,
  INDEX idx_email_log_created (created_at),
  INDEX idx_email_log_status (status, created_at),
  INDEX idx_email_log_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
