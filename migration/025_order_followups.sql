-- Follow-ups to buyers whose payment failed: one row each time an admin or an organizer emails them, or opens a
-- WhatsApp chat with them from the order page. Used to show the history on the order and to limit how often a buyer
-- can be contacted (once a day per channel, at most three). Safe to run more than once.
CREATE TABLE IF NOT EXISTS order_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  sent_by INT NULL,
  -- ADMIN = platform admin; ORGANIZER = the organizer owner; TEAM = a member of the organizer's team.
  sender_role ENUM('ADMIN','ORGANIZER','TEAM') NOT NULL,
  channel ENUM('EMAIL','WHATSAPP') NOT NULL,
  -- SENT = the email was accepted; OPENED = the WhatsApp chat was opened (delivery cannot be confirmed); FAILED = not sent.
  status ENUM('SENT','OPENED','FAILED') NOT NULL,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_followups_order (order_id, channel, created_at),
  CONSTRAINT fk_followups_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_followups_user FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
