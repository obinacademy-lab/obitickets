-- 048: "Failed payments" follow-ups on the dashboards (11/26). Additive and safe to run twice.
-- 1. order_followups (tickets) is the same table as migration 025, repeated here in case 025 was never run on this server.
-- 2. market_followups: the same record for OBI MARKET orders whose payment did not go through (one row each time an admin, an organizer or a
--    team member emails the customer or opens a WhatsApp chat with them). Used for the history and the limits (once a day per channel, at most three).

CREATE TABLE IF NOT EXISTS order_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  sent_by INT NULL,
  sender_role ENUM('ADMIN','ORGANIZER','TEAM') NOT NULL,
  channel ENUM('EMAIL','WHATSAPP') NOT NULL,
  status ENUM('SENT','OPENED','FAILED') NOT NULL,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_followups_order (order_id, channel, created_at),
  CONSTRAINT fk_followups_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_followups_user FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_followups (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  market_order_id BIGINT NOT NULL,
  sent_by INT NULL,
  sender_role ENUM('ADMIN','ORGANIZER','TEAM') NOT NULL,
  channel ENUM('EMAIL','WHATSAPP') NOT NULL,
  status ENUM('SENT','OPENED','FAILED') NOT NULL,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_market_followups_order (market_order_id, channel, created_at),
  CONSTRAINT fk_market_followups_order FOREIGN KEY (market_order_id) REFERENCES market_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_market_followups_user FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
