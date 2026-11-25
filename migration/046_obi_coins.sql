-- 046: OBI COINS, the virtual currency used to send OBI GIFTS (11/24). ADR: .claude/adrs/ADR-002-obi-coins.md
-- Additive and safe to run twice. New tables, plus a few NULLABLE or defaulted columns on the gift tables, so every existing gift
-- (paid directly in UGX before coins existed) stays valid and unchanged. Nothing here touches orders, tickets, QR codes or ticket payouts.
-- Money is whole numbers only: coins and UGX are BIGINT, and the accounting rate is an integer "UGX per coin x 10,000" (no floats).
-- Nothing can be bought or sent until an admin has set an accounting rate AND priced a package AND switched `coins_enabled` on (default off).

-- One wallet per user. The balance only ever changes together with a coin_ledger row (see includes/coins.php).
CREATE TABLE IF NOT EXISTS coin_wallets (
  user_id INT NOT NULL PRIMARY KEY,
  balance BIGINT NOT NULL DEFAULT 0,
  total_purchased BIGINT NOT NULL DEFAULT 0,
  total_bonus BIGINT NOT NULL DEFAULT 0,
  total_spent BIGINT NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','FROZEN') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_coin_wallet_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_coin_wallet_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only. `coins` is signed (credit +, debit -). A correction is a new row, never an edit.
-- The unique key makes every operation happen once (a purchase credits once, a gift is spent once, a refund returns once).
CREATE TABLE IF NOT EXISTS coin_ledger (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  entry_type ENUM('PURCHASE','BONUS','GIFT_SPEND','GIFT_REFUND','PURCHASE_REVERSAL','BONUS_REVERSAL','ADJUSTMENT') NOT NULL,
  coins BIGINT NOT NULL,
  balance_after BIGINT NOT NULL,
  reference VARCHAR(40) NOT NULL,
  related_type VARCHAR(20) NULL,                           -- purchase | gift
  related_id BIGINT NULL,
  note VARCHAR(255) NULL,
  admin_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coin_ledger_reference (reference),
  UNIQUE KEY uq_coin_ledger_once (entry_type, related_type, related_id),
  KEY idx_coin_ledger_user (user_id, id),
  CONSTRAINT fk_coin_ledger_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_coin_ledger_coins CHECK (coins <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recharge packages. price_ugx stays NULL (and the package inactive) until an admin sets it: no price is invented here.
CREATE TABLE IF NOT EXISTS coin_packages (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL,
  coins BIGINT NOT NULL,
  bonus_coins BIGINT NOT NULL DEFAULT 0,
  price_ugx BIGINT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coin_package_slug (slug),
  KEY idx_coin_package_active (is_active, sort_order),
  CONSTRAINT chk_coin_package CHECK (coins > 0 AND bonus_coins >= 0 AND (price_ugx IS NULL OR price_ugx > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per attempt to buy coins. The package's name, coins, bonus and price are copied onto the row, so a later price change never alters it.
CREATE TABLE IF NOT EXISTS coin_purchases (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(64) NULL,
  user_id INT NOT NULL,
  package_id INT NOT NULL,
  package_name VARCHAR(60) NOT NULL,
  coins BIGINT NOT NULL,
  bonus_coins BIGINT NOT NULL DEFAULT 0,
  price_ugx BIGINT NOT NULL,
  payment_method ENUM('MTN_MOMO','AIRTEL_MONEY') NOT NULL,
  phone VARCHAR(32) NOT NULL,
  provider_reference VARCHAR(100) NULL,
  payment_status ENUM('PENDING','PROCESSING','SUCCESSFUL','FAILED','CANCELLED','EXPIRED','REVERSED') NOT NULL DEFAULT 'PENDING',
  status_message VARCHAR(255) NULL,
  gateway_fee_ugx BIGINT NULL,                             -- the ioTec collection fee: tracked separately, an obitickets cost
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  reversed_at DATETIME NULL,
  UNIQUE KEY uq_coin_purchase_reference (reference),
  UNIQUE KEY uq_coin_purchase_provider (provider_reference),
  UNIQUE KEY uq_coin_purchase_idem (user_id, idempotency_key),
  KEY idx_coin_purchase_user (user_id, created_at),
  KEY idx_coin_purchase_status (payment_status, created_at),
  CONSTRAINT fk_coin_purchase_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_coin_purchase_package FOREIGN KEY (package_id) REFERENCES coin_packages (id),
  CONSTRAINT chk_coin_purchase CHECK (coins > 0 AND bonus_coins >= 0 AND price_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The accounting rate: how many UGX one coin is worth when a gift is valued (x 10,000: 10000 = UGX 1.0000 per coin).
-- Versioned like the 40/60 split. A new version applies from its start time; old gifts keep the rate they were sent with.
CREATE TABLE IF NOT EXISTS coin_accounting_rates (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version INT NOT NULL,
  rate_e4 BIGINT NOT NULL,
  effective_from DATETIME NOT NULL,
  changed_by INT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coin_rate_version (version),
  KEY idx_coin_rate_effective (effective_from),
  CONSTRAINT chk_coin_rate CHECK (rate_e4 > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who may receive gifts and earn from them. No row = the default rule (an organizer with a live video).
-- SUSPENDED blocks new gifts AND new withdrawals (history and balance stay); APPROVED lets another kind of account receive gifts.
CREATE TABLE IF NOT EXISTS gift_recipient_status (
  user_id INT NOT NULL PRIMARY KEY,
  status ENUM('APPROVED','SUSPENDED') NOT NULL,
  reason VARCHAR(255) NULL,
  changed_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gift_recipient_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- the gift tables: columns only, all NULLABLE or defaulted, so every gift sent before coins stays valid
ALTER TABLE gift_catalog ADD COLUMN IF NOT EXISTS price_coins BIGINT NULL AFTER price_ugx;
ALTER TABLE gift_transactions
  ADD COLUMN IF NOT EXISTS payment_source ENUM('DIRECT','COINS') NOT NULL DEFAULT 'DIRECT',
  ADD COLUMN IF NOT EXISTS coins_spent BIGINT NULL,
  ADD COLUMN IF NOT EXISTS unit_coins BIGINT NULL,
  ADD COLUMN IF NOT EXISTS accounting_rate_e4 BIGINT NULL;
ALTER TABLE gift_transactions MODIFY COLUMN payment_method ENUM('MTN_MOMO','AIRTEL_MONEY','COINS') NOT NULL;

-- The starting coin prices of the gifts are the owner's suggested catalogue (editable by Admin any time).
UPDATE gift_catalog SET price_coins = price_ugx WHERE price_coins IS NULL;

-- The suggested recharge packages. They start INACTIVE with NO UGX price: an admin must set the price before anyone can buy them.
INSERT IGNORE INTO coin_packages (slug, name, description, coins, bonus_coins, price_ugx, is_active, is_featured, sort_order) VALUES
  ('basic',    'Basic',    'A little to get started.',          500,   0, NULL, 0, 0, 10),
  ('starter',  'Starter',  'A few gifts for your favourite events.', 1000,  0, NULL, 0, 0, 20),
  ('popular',  'Popular',  'Most people start here.',           5000,  0, NULL, 0, 1, 30),
  ('premium',  'Premium',  'For generous supporters.',          10000, 0, NULL, 0, 0, 40),
  ('ultimate', 'Ultimate', 'The big one.',                      50000, 0, NULL, 0, 0, 50);
