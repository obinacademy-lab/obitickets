-- 039: buyers can send a ticket to a friend (11/24).
-- The friend gets a claim link; when they accept (logged in), the ticket gets a NEW code (the old QR stops working)
-- and they become its holder. Before this runs the "Send to a friend" button does not show.

-- Who holds the ticket now. NULL = the person who bought it (orders.user_id).
ALTER TABLE tickets ADD COLUMN holder_user_id INT NULL, ADD INDEX idx_tickets_holder (holder_user_id);

-- Organizers can switch sending off for an event (on by default).
ALTER TABLE events ADD COLUMN allow_transfers TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE ticket_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  from_user_id INT NOT NULL,
  to_contact VARCHAR(191) NOT NULL,          -- the email or phone the sender typed
  to_user_id INT NULL,                       -- who accepted it
  token CHAR(64) NOT NULL UNIQUE,            -- the secret in the claim link
  status ENUM('PENDING','CLAIMED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  INDEX idx_tt_ticket (ticket_id, status),
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
