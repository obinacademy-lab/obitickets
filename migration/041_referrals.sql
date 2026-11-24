-- 041: referral rewards (11/24). Organizers switch it on per event and fund it (it comes out of that event's ticket money).
-- Every buyer gets a personal invite link; when enough friends buy through it, the buyer gets a one-time promo code
-- (promo_codes, this event) worth the reward. Needs 040 (discounts) first. Before this runs nothing shows.

ALTER TABLE events
  ADD COLUMN referral_friends INT NULL,                 -- how many friends must buy; NULL = referrals off
  ADD COLUMN referral_reward DECIMAL(12,2) NULL;        -- UGX off, as a one-time code for the inviter

CREATE TABLE referral_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  code VARCHAR(16) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ref_event_user (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders ADD COLUMN referral_link_id INT NULL, ADD INDEX idx_orders_ref (referral_link_id);

-- one row per reward code given out
CREATE TABLE referral_rewards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  link_id INT NOT NULL,
  promo_code_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (link_id) REFERENCES referral_links(id) ON DELETE CASCADE,
  FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
