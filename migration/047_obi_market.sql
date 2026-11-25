-- 047: OBI MARKET, the merchandise marketplace inside obitickets (11/25). ADR: .claude/adrs/ADR-003-obi-market.md
-- Additive and safe to run twice. Brand new tables only: nothing here touches orders, tickets, QR codes, gifts or coins.
-- Money is whole shillings (BIGINT/INT), never floats. Nothing is visible to the public until an admin switches OBI MARKET on (setting market_enabled, default off).

-- Categories (the tiles in the mockup). Admin can add more.
CREATE TABLE IF NOT EXISTS market_categories (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(60) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_market_category_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One store per seller (an organizer account). Created the first time they open OBI MARKET.
CREATE TABLE IF NOT EXISTS market_stores (
  seller_id INT NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(90) NOT NULL,
  description VARCHAR(600) NULL,
  logo_path VARCHAR(255) NULL,
  banner_path VARCHAR(255) NULL,
  contact_email VARCHAR(191) NULL,
  contact_phone VARCHAR(32) NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  delivery_mode ENUM('DELIVERY','COLLECTION','BOTH') NOT NULL DEFAULT 'BOTH',
  delivery_fee_ugx INT NOT NULL DEFAULT 0,
  delivery_note VARCHAR(255) NULL,
  policies TEXT NULL,
  status ENUM('ACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_store_slug (slug),
  CONSTRAINT fk_market_store_seller FOREIGN KEY (seller_id) REFERENCES users (id),
  CONSTRAINT chk_market_store_fee CHECK (delivery_fee_ugx >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_products (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seller_id INT NOT NULL,
  category_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  base_price_ugx INT NOT NULL,
  sku VARCHAR(60) NULL,
  brand VARCHAR(80) NULL,
  tags VARCHAR(255) NULL,
  status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','REMOVED') NOT NULL DEFAULT 'DRAFT',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  sold_count INT NOT NULL DEFAULT 0,
  primary_image VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_market_product_seller (seller_id, status),
  KEY idx_market_product_category (category_id, status),
  KEY idx_market_product_list (status, is_featured, id),
  CONSTRAINT fk_market_product_seller FOREIGN KEY (seller_id) REFERENCES users (id),
  CONSTRAINT fk_market_product_category FOREIGN KEY (category_id) REFERENCES market_categories (id),
  CONSTRAINT chk_market_product_price CHECK (base_price_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_product_images (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  path VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_market_image_product (product_id, sort_order),
  CONSTRAINT fk_market_image_product FOREIGN KEY (product_id) REFERENCES market_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every product has at least one variant (a product with no sizes or colours has one plain variant). Stock lives here.
-- available = stock - reserved. reserved = being paid for right now. Neither can go below zero; reserved can never exceed stock.
CREATE TABLE IF NOT EXISTS market_variants (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  size VARCHAR(20) NOT NULL DEFAULT '',
  colour VARCHAR(30) NOT NULL DEFAULT '',
  sku VARCHAR(60) NULL,
  price_ugx INT NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  reserved INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_market_variant (product_id, size, colour),
  CONSTRAINT fk_market_variant_product FOREIGN KEY (product_id) REFERENCES market_products (id),
  CONSTRAINT chk_market_variant_price CHECK (price_ugx > 0),
  CONSTRAINT chk_market_variant_stock CHECK (stock >= 0 AND reserved >= 0 AND reserved <= stock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_product_events (
  product_id INT NOT NULL,
  event_id INT NOT NULL,
  PRIMARY KEY (product_id, event_id),
  KEY idx_market_pe_event (event_id),
  CONSTRAINT fk_market_pe_product FOREIGN KEY (product_id) REFERENCES market_products (id) ON DELETE CASCADE,
  CONSTRAINT fk_market_pe_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- History of every stock change (who, why, which order). Append-only.
CREATE TABLE IF NOT EXISTS market_stock_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  variant_id INT NOT NULL,
  delta_stock INT NOT NULL DEFAULT 0,
  delta_reserved INT NOT NULL DEFAULT 0,
  reason VARCHAR(30) NOT NULL,
  checkout_id BIGINT NULL,
  order_id BIGINT NULL,
  actor_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_market_stocklog_variant (variant_id, id),
  KEY idx_market_stocklog_checkout (checkout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The shopping cart (signed-in customers). Prices are NOT stored: they are read from the product at checkout.
CREATE TABLE IF NOT EXISTS market_cart_items (
  user_id INT NOT NULL,
  variant_id INT NOT NULL,
  quantity INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, variant_id),
  CONSTRAINT fk_market_cart_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_market_cart_variant FOREIGN KEY (variant_id) REFERENCES market_variants (id) ON DELETE CASCADE,
  CONSTRAINT chk_market_cart_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Versioned commission (like the gift split). A new version applies to NEW orders from its start time; every order stores the rate it was bought at.
CREATE TABLE IF NOT EXISTS market_commission_config (
  version INT NOT NULL PRIMARY KEY,
  commission_bps INT NOT NULL,
  effective_from DATETIME NOT NULL,
  changed_by INT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_market_commission_effective (effective_from),
  CONSTRAINT chk_market_commission CHECK (commission_bps >= 0 AND commission_bps <= 5000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The customer's ONE payment (it can hold several sellers' items; each seller gets their own order below).
CREATE TABLE IF NOT EXISTS market_checkouts (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(64) NULL,
  user_id INT NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_email VARCHAR(191) NOT NULL,
  customer_phone VARCHAR(32) NOT NULL,
  delivery_method ENUM('DELIVERY','COLLECTION') NOT NULL DEFAULT 'DELIVERY',
  delivery_name VARCHAR(120) NULL,
  delivery_phone VARCHAR(32) NULL,
  delivery_address VARCHAR(255) NULL,
  delivery_district VARCHAR(80) NULL,
  delivery_note VARCHAR(255) NULL,
  payment_method ENUM('MTN_MOMO','AIRTEL_MONEY') NOT NULL,
  phone VARCHAR(32) NOT NULL,
  total_ugx BIGINT NOT NULL,
  payment_status ENUM('PENDING','PROCESSING','SUCCESSFUL','FAILED','CANCELLED','EXPIRED','REVERSED') NOT NULL DEFAULT 'PENDING',
  provider_reference VARCHAR(100) NULL,
  status_message VARCHAR(255) NULL,
  gateway_fee_ugx BIGINT NULL,
  paid_late TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  UNIQUE KEY uq_market_checkout_reference (reference),
  UNIQUE KEY uq_market_checkout_provider (provider_reference),
  UNIQUE KEY uq_market_checkout_idem (user_id, idempotency_key),
  KEY idx_market_checkout_user (user_id, created_at),
  KEY idx_market_checkout_status (payment_status, created_at),
  CONSTRAINT fk_market_checkout_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_market_checkout_total CHECK (total_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One order per seller. merchandise_ugx is what commission is taken on; delivery_ugx goes 100% to the seller.
CREATE TABLE IF NOT EXISTS market_orders (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) NOT NULL,
  checkout_id BIGINT NOT NULL,
  seller_id INT NOT NULL,
  customer_id INT NOT NULL,
  merchandise_ugx BIGINT NOT NULL,
  delivery_ugx BIGINT NOT NULL DEFAULT 0,
  discount_ugx BIGINT NOT NULL DEFAULT 0,
  total_ugx BIGINT NOT NULL,
  config_version INT NOT NULL,
  commission_bps INT NOT NULL,
  commission_ugx BIGINT NOT NULL,
  seller_ugx BIGINT NOT NULL,
  delivery_method ENUM('DELIVERY','COLLECTION') NOT NULL DEFAULT 'DELIVERY',
  payment_status ENUM('PENDING','PAID','FAILED','EXPIRED','PARTIALLY_REFUNDED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  fulfilment ENUM('PENDING_PAYMENT','NEW','PROCESSING','SHIPPED','READY_FOR_COLLECTION','COMPLETED','CANCELLED','REFUND_REQUESTED','REFUNDED') NOT NULL DEFAULT 'PENDING_PAYMENT',
  earnings_state ENUM('NONE','PENDING','AVAILABLE','REVERSED') NOT NULL DEFAULT 'NONE',
  shipped_at DATETIME NULL,
  received_at DATETIME NULL,
  received_by ENUM('CUSTOMER','AUTO','ADMIN') NULL,
  release_after DATETIME NULL,
  dispute_at DATETIME NULL,
  dispute_reason VARCHAR(255) NULL,
  refunded_ugx BIGINT NOT NULL DEFAULT 0,
  refunded_merch_ugx BIGINT NOT NULL DEFAULT 0,
  refund_paid_ugx BIGINT NOT NULL DEFAULT 0,
  seller_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_order_number (order_number),
  KEY idx_market_order_seller (seller_id, fulfilment, id),
  KEY idx_market_order_customer (customer_id, id),
  KEY idx_market_order_checkout (checkout_id),
  KEY idx_market_order_release (earnings_state, release_after),
  CONSTRAINT fk_market_order_checkout FOREIGN KEY (checkout_id) REFERENCES market_checkouts (id),
  CONSTRAINT fk_market_order_seller FOREIGN KEY (seller_id) REFERENCES users (id),
  CONSTRAINT fk_market_order_customer FOREIGN KEY (customer_id) REFERENCES users (id),
  CONSTRAINT chk_market_order_split CHECK (commission_ugx >= 0 AND seller_ugx >= 0 AND commission_ugx + seller_ugx = merchandise_ugx),
  CONSTRAINT chk_market_order_total CHECK (total_ugx = merchandise_ugx + delivery_ugx - discount_ugx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What was bought, copied at the moment of purchase (a later price or name change never alters an order).
CREATE TABLE IF NOT EXISTS market_order_items (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  variant_id INT NOT NULL,
  product_name VARCHAR(120) NOT NULL,
  variant_label VARCHAR(60) NOT NULL DEFAULT '',
  sku VARCHAR(60) NULL,
  image_path VARCHAR(255) NULL,
  unit_price_ugx INT NOT NULL,
  quantity INT NOT NULL,
  line_ugx BIGINT NOT NULL,
  KEY idx_market_item_order (order_id),
  KEY idx_market_item_variant (variant_id),
  CONSTRAINT fk_market_item_order FOREIGN KEY (order_id) REFERENCES market_orders (id),
  CONSTRAINT chk_market_item CHECK (quantity > 0 AND unit_price_ugx > 0 AND line_ugx = unit_price_ugx * quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every change of an order's status (who and why). Append-only.
CREATE TABLE IF NOT EXISTS market_order_history (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  actor_id INT NULL,
  actor_role VARCHAR(12) NOT NULL DEFAULT 'SYSTEM',
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_market_history_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The seller's money. Append-only: each row moves money between buckets (pending / available / reserved for a withdrawal / withdrawn) and records
-- obitickets' own share (platform_delta). A correction is a new row. The balances table below must always equal the sum of these rows.
CREATE TABLE IF NOT EXISTS market_ledger (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seller_id INT NOT NULL,
  entry_type ENUM('SALE','RELEASE','REFUND','WITHDRAW_RESERVE','WITHDRAW_PAID','WITHDRAW_RETURN','ADJUSTMENT') NOT NULL,
  order_id BIGINT NULL,
  withdrawal_id BIGINT NULL,
  pending_delta BIGINT NOT NULL DEFAULT 0,
  available_delta BIGINT NOT NULL DEFAULT 0,
  reserved_delta BIGINT NOT NULL DEFAULT 0,
  withdrawn_delta BIGINT NOT NULL DEFAULT 0,
  platform_delta BIGINT NOT NULL DEFAULT 0,
  once_key VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  actor_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_ledger_once (once_key),
  KEY idx_market_ledger_seller (seller_id, id),
  KEY idx_market_ledger_order (order_id),
  CONSTRAINT fk_market_ledger_seller FOREIGN KEY (seller_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_balances (
  seller_id INT NOT NULL PRIMARY KEY,
  pending_ugx BIGINT NOT NULL DEFAULT 0,
  available_ugx BIGINT NOT NULL DEFAULT 0,
  reserved_ugx BIGINT NOT NULL DEFAULT 0,
  withdrawn_ugx BIGINT NOT NULL DEFAULT 0,
  total_earned_ugx BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_market_balance_seller FOREIGN KEY (seller_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seller withdrawals (the engine for these is built with the organizer dashboard phase; the table is created now so there is one migration).
CREATE TABLE IF NOT EXISTS market_withdrawals (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL,
  seller_id INT NOT NULL,
  amount_ugx BIGINT NOT NULL,
  method ENUM('MTN_MOMO','AIRTEL_MONEY') NOT NULL,
  destination VARCHAR(191) NOT NULL,
  status ENUM('REQUESTED','PENDING_REVIEW','APPROVED','PROCESSING','PAID','REJECTED','FAILED','CANCELLED') NOT NULL DEFAULT 'REQUESTED',
  provider_reference VARCHAR(100) NULL,
  reviewed_by INT NULL,
  reason VARCHAR(255) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_market_wd_reference (reference),
  KEY idx_market_wd_seller (seller_id, status, requested_at),
  CONSTRAINT fk_market_wd_seller FOREIGN KEY (seller_id) REFERENCES users (id),
  CONSTRAINT chk_market_wd_amount CHECK (amount_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order emails (the organizer's "new order" email and the customer's confirmation). Queue + retry + log; the engine is built in the email phase.
CREATE TABLE IF NOT EXISTS market_emails (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT NOT NULL,
  kind VARCHAR(30) NOT NULL,
  to_email VARCHAR(191) NOT NULL,
  status ENUM('QUEUED','SENDING','SENT','FAILED','SKIPPED') NOT NULL DEFAULT 'QUEUED',
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  claimed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_market_email_once (order_id, kind),
  KEY idx_market_email_status (status, id),
  CONSTRAINT fk_market_email_order FOREIGN KEY (order_id) REFERENCES market_orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starting data: the categories from the design, and the commission (10%).
INSERT IGNORE INTO market_categories (slug, name, sort_order) VALUES
  ('t-shirts', 'T-Shirts', 10), ('hoodies', 'Hoodies', 20), ('caps', 'Caps', 30), ('accessories', 'Accessories', 40),
  ('artist-merch', 'Artist Merchandise', 50), ('event-merch', 'Event Merchandise', 60), ('other', 'Other', 70);
INSERT IGNORE INTO market_commission_config (version, commission_bps, effective_from, reason) VALUES (1, 1000, '2000-01-01 00:00:00', 'Starting commission: 10% of the merchandise value');
