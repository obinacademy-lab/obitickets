-- 029: obitickets Socials (the event video community).
-- Posting is a paid plan for organizers: one free month, then UGX 25,000 per 30 days paid by mobile money.
-- Watching is open to everyone. Safe to run twice; it only creates two tables and touches nothing else.
-- The videos, comments, reactions and sounds themselves already live in the Moments tables (017 to 020).

CREATE TABLE IF NOT EXISTS social_subscriptions (
  user_id INT NOT NULL PRIMARY KEY,                 -- the organizer's own account (the plan covers their team)
  trial_started_at DATETIME NULL,
  trial_ends_at DATETIME NULL,
  paid_until DATETIME NULL,
  -- the plan end each reminder email was sent for, so each is sent once per period (cron/send-event-reminders.php)
  remind_3d_for DATETIME NULL,
  remind_1d_for DATETIME NULL,
  ended_notice_for DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_socsub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS social_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organizer_id INT NOT NULL,                        -- whose plan this pays for
  paid_by INT NULL,                                 -- who started the payment (the owner or a team member)
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'UGX',
  days SMALLINT NOT NULL DEFAULT 30,                -- how many days this payment adds
  phone VARCHAR(32) NULL,
  status ENUM('PENDING','PAID','FAILED','GRANTED') NOT NULL DEFAULT 'PENDING',  -- GRANTED = days added by an admin, no money
  payment_reference VARCHAR(100) NULL,
  status_message VARCHAR(255) NULL,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_socpay_org (organizer_id, status, created_at),
  INDEX idx_socpay_status (status, created_at),
  CONSTRAINT fk_socpay_org FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_socpay_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
