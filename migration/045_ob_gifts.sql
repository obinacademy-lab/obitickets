-- 045: OB GIFTS, virtual gifts from viewers to organizers (11/24). ADR: .claude/adrs/ADR-001-ob-gifts.md
-- Only NEW tables. Nothing that exists is altered, and nothing here is read by the ticket system: gifts have their own
-- transactions, ledger, balances and withdrawals, so ticket money, orders, tickets, QR codes and `payouts` are never touched.
-- All money is whole UGX in BIGINT (no decimals, no floats). Sale-time price and split are stored on each gift and never recomputed.
-- Nothing is shown to anyone until Admin turns the setting `gifts_enabled` on (default off).

CREATE TABLE IF NOT EXISTS gift_catalog (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL,
  emoji VARCHAR(16) NULL,
  image_path VARCHAR(255) NULL,
  animation_type VARCHAR(24) NOT NULL DEFAULT 'pop',     -- float | pop | sparkle | glow | fly | confetti | drive | jet
  animation_asset VARCHAR(255) NULL,
  price_ugx BIGINT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gift_catalog_slug (slug),
  KEY idx_gift_catalog_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_commission_config (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version INT NOT NULL,
  platform_bps SMALLINT NOT NULL,                          -- basis points: 4000 = 40.00%
  organizer_bps SMALLINT NOT NULL,                         -- 6000 = 60.00%; the two always add up to 10000
  effective_from DATETIME NOT NULL,
  changed_by INT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gift_commission_version (version),
  KEY idx_gift_commission_effective (effective_from),
  CONSTRAINT chk_gift_bps CHECK (platform_bps >= 0 AND organizer_bps >= 0 AND platform_bps + organizer_bps = 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_transactions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(64) NULL,
  sender_id INT NOT NULL,
  recipient_organizer_id INT NOT NULL,
  gift_id INT NOT NULL,
  post_id BIGINT NULL,
  quantity INT NOT NULL,
  unit_price_ugx BIGINT NOT NULL,
  gross_ugx BIGINT NOT NULL,
  platform_ugx BIGINT NOT NULL,
  organizer_ugx BIGINT NOT NULL,
  gateway_fee_ugx BIGINT NULL,                             -- ioTec's collection fee, paid by obitickets out of its share
  config_version INT NOT NULL,
  payment_method ENUM('MTN_MOMO','AIRTEL_MONEY') NOT NULL,
  phone VARCHAR(32) NOT NULL,
  provider_reference VARCHAR(100) NULL,
  payment_status ENUM('PENDING','PROCESSING','SUCCESSFUL','FAILED','CANCELLED','EXPIRED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  delivery_status ENUM('NONE','DELIVERED','REVERSED') NOT NULL DEFAULT 'NONE',
  status_message VARCHAR(255) NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  delivered_at DATETIME NULL,
  available_at DATETIME NULL,                              -- when the organizer's share becomes withdrawable (paid_at + hold)
  released_at DATETIME NULL,
  refunded_at DATETIME NULL,
  UNIQUE KEY uq_gift_tx_reference (reference),
  UNIQUE KEY uq_gift_tx_provider (provider_reference),
  UNIQUE KEY uq_gift_tx_idem (sender_id, idempotency_key),
  KEY idx_gift_tx_recipient (recipient_organizer_id, payment_status, paid_at),
  KEY idx_gift_tx_sender (sender_id, created_at),
  KEY idx_gift_tx_status (payment_status, created_at),
  KEY idx_gift_tx_post (post_id),
  CONSTRAINT fk_gift_tx_sender FOREIGN KEY (sender_id) REFERENCES users (id),
  CONSTRAINT fk_gift_tx_recipient FOREIGN KEY (recipient_organizer_id) REFERENCES users (id),
  CONSTRAINT fk_gift_tx_gift FOREIGN KEY (gift_id) REFERENCES gift_catalog (id),
  CONSTRAINT chk_gift_tx_split CHECK (platform_ugx + organizer_ugx = gross_ugx AND gross_ugx = unit_price_ugx * quantity AND quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_withdrawals (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL,
  organizer_id INT NOT NULL,
  amount_ugx BIGINT NOT NULL,
  method ENUM('MTN_MOMO','AIRTEL_MONEY','BANK') NOT NULL,
  destination VARCHAR(191) NOT NULL,
  status ENUM('REQUESTED','PENDING_REVIEW','APPROVED','PROCESSING','PAID','REJECTED','FAILED','CANCELLED') NOT NULL DEFAULT 'REQUESTED',
  is_auto TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_by INT NULL,
  reason VARCHAR(255) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_gift_wd_reference (reference),
  KEY idx_gift_wd_org (organizer_id, status, requested_at),
  CONSTRAINT fk_gift_wd_org FOREIGN KEY (organizer_id) REFERENCES users (id),
  CONSTRAINT chk_gift_wd_amount CHECK (amount_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_payouts (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  withdrawal_id INT NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  provider_reference VARCHAR(100) NULL,
  amount_ugx BIGINT NOT NULL,
  status ENUM('PROCESSING','PAID','FAILED') NOT NULL DEFAULT 'PROCESSING',
  fee_ugx BIGINT NULL,
  failure_reason VARCHAR(500) NULL,
  sent_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  UNIQUE KEY uq_gift_payout_external (external_id),
  KEY idx_gift_payout_wd (withdrawal_id),
  CONSTRAINT fk_gift_payout_wd FOREIGN KEY (withdrawal_id) REFERENCES gift_withdrawals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only: corrections are new REVERSAL / ADJUSTMENT rows, never edits.
CREATE TABLE IF NOT EXISTS gift_ledger (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gift_tx_id BIGINT NULL,
  withdrawal_id INT NULL,
  account VARCHAR(40) NOT NULL,                            -- SENDER_CLEARING | PLATFORM_COMMISSION | GATEWAY_FEE | PAYOUT | ORGANIZER:<id>
  entry ENUM('DEBIT','CREDIT') NOT NULL,
  amount_ugx BIGINT NOT NULL,
  entry_type ENUM('GIFT','FEE','REVERSAL','WITHDRAWAL','ADJUSTMENT') NOT NULL,
  reverses_id BIGINT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gift_ledger_tx (gift_tx_id),
  KEY idx_gift_ledger_wd (withdrawal_id),
  KEY idx_gift_ledger_account (account, created_at),
  CONSTRAINT fk_gift_ledger_tx FOREIGN KEY (gift_tx_id) REFERENCES gift_transactions (id),
  CONSTRAINT fk_gift_ledger_wd FOREIGN KEY (withdrawal_id) REFERENCES gift_withdrawals (id),
  CONSTRAINT chk_gift_ledger_amount CHECK (amount_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A cache of what the ledger says; updated in the same DB transaction as the ledger rows, and checked by gift_reconcile().
-- available_ugx can go negative on purpose: a refunded gift that was already withdrawn is paid back from future gift earnings only.
CREATE TABLE IF NOT EXISTS gift_balances (
  organizer_id INT NOT NULL PRIMARY KEY,
  pending_ugx BIGINT NOT NULL DEFAULT 0,
  available_ugx BIGINT NOT NULL DEFAULT 0,
  reserved_ugx BIGINT NOT NULL DEFAULT 0,
  total_earned_ugx BIGINT NOT NULL DEFAULT 0,
  total_withdrawn_ugx BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gift_balance_org FOREIGN KEY (organizer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What the system itself did (settled, expired, mismatch seen, reversed ...). Admin actions also go to audit_logs.
CREATE TABLE IF NOT EXISTS gift_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gift_tx_id BIGINT NULL,
  kind VARCHAR(40) NOT NULL,
  details VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gift_events_tx (gift_tx_id),
  KEY idx_gift_events_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The official starting split (migration 045): 40% obitickets, 60% organizer.
INSERT IGNORE INTO gift_commission_config (version, platform_bps, organizer_bps, effective_from, changed_by, reason)
VALUES (1, 4000, 6000, '2000-01-01 00:00:00', NULL, 'Official starting split: 40% obitickets, 60% event organizer');

-- The starting catalogue (editable and re-priceable by Admin later; prices are always read from this table).
INSERT IGNORE INTO gift_catalog (slug, name, description, emoji, animation_type, price_ugx, sort_order) VALUES
  ('heart',   'Heart',            'A little love for the organizer.',          '❤️', 'float',    500,    10),
  ('rose',    'Rose',             'A rose to say well done.',                  '🌹', 'pop',      1000,   20),
  ('diamond', 'Diamond',          'Sparkles for a shining event.',             '💎', 'sparkle',  5000,   30),
  ('crown',   'Crown',            'Crown the organizer.',                      '👑', 'glow',     10000,  40),
  ('rocket',  'Rocket',           'Launch this event to the stars.',           '🚀', 'fly',      20000,  50),
  ('cake',    'Celebration Cake', 'Celebrate the moment.',                     '🎂', 'confetti', 30000,  60),
  ('car',     'Luxury Car',       'Arrive in style.',                          '🚘', 'drive',    50000,  70),
  ('jet',     'Private Jet',      'The biggest thank you.',                    '🛩️', 'jet',      100000, 80);
