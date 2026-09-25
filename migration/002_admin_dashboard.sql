-- obitickets — admin dashboard schema additions
-- Run once against the existing database: mysql -u user -p dbname < migration/002_admin_dashboard.sql
-- Every change here is additive (new columns/tables) — nothing existing is dropped or renamed,
-- so this is safe to run against a database that already has live data.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Sub-roles for role='ADMIN' users (NULL = full access, kept backward
-- compatible with every admin account that existed before this migration).
-- account_status lets an admin suspend a user/organizer without deleting them.
ALTER TABLE users
  ADD COLUMN admin_role ENUM('SUPER_ADMIN','FINANCE_ADMIN','EVENT_MANAGER','SUPPORT_AGENT','MARKETING_MANAGER','CHECKIN_STAFF') NULL AFTER role,
  ADD COLUMN account_status ENUM('ACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE' AFTER admin_role,
  ADD COLUMN last_login_at DATETIME NULL AFTER account_status;

-- ---------------------------------------------------------------------------
-- Event lifecycle: adds the review/suspend states the admin workflow needs,
-- plus featured-placement fields for the homepage.
ALTER TABLE events
  MODIFY COLUMN status ENUM('DRAFT','PENDING_REVIEW','PUBLISHED','SUSPENDED','REJECTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER status,
  ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER rejection_reason,
  ADD COLUMN featured_from DATE NULL AFTER featured,
  ADD COLUMN featured_until DATE NULL AFTER featured_from,
  ADD INDEX idx_events_featured (featured, featured_from, featured_until);

-- ---------------------------------------------------------------------------
-- Organizer verification workflow (replaces the old plain `verified` flag —
-- kept alongside it since existing code reads `verified`; VERIFIED keeps it
-- in sync via the admin action, not a column trigger, to stay simple).
ALTER TABLE organizer_profiles
  ADD COLUMN verification_status ENUM('UNVERIFIED','PENDING','VERIFIED','REJECTED') NOT NULL DEFAULT 'UNVERIFIED' AFTER verified,
  ADD COLUMN verification_notes VARCHAR(500) NULL AFTER verification_status,
  ADD COLUMN verified_at DATETIME NULL AFTER verification_notes;

-- ---------------------------------------------------------------------------
-- Manual refund tracking. obitickets' payment integration (iotec) only
-- exposes a collection API, not disbursements/refunds — so a refund here
-- records that an admin refunded the buyer by hand (mobile money, etc.);
-- it does not itself move money. See the admin implementation notes.
ALTER TABLE orders
  ADD COLUMN refund_amount DECIMAL(12,2) NULL AFTER status_message,
  ADD COLUMN refund_reason VARCHAR(500) NULL AFTER refund_amount,
  ADD COLUMN refunded_at DATETIME NULL AFTER refund_reason,
  ADD COLUMN refunded_by INT NULL AFTER refunded_at,
  ADD CONSTRAINT fk_orders_refunded_by FOREIGN KEY (refunded_by) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Organizer payouts. Same caveat as refunds above: there is no automated
-- disbursement API wired up, so PAID means "an admin paid the organizer
-- outside the platform and recorded it here" — this table is the ledger and
-- audit trail, not a money-mover.
CREATE TABLE payouts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organizer_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'UGX',
  method ENUM('MTN_MOMO','AIRTEL_MONEY','BANK') NOT NULL,
  destination VARCHAR(191) NOT NULL,
  status ENUM('PENDING','APPROVED','PROCESSING','PAID','FAILED','REJECTED') NOT NULL DEFAULT 'PENDING',
  notes VARCHAR(500) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  processed_by INT NULL,
  FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_payouts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
CREATE TABLE promo_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  event_id INT NULL,
  discount_type ENUM('PERCENT','FIXED') NOT NULL,
  discount_value DECIMAL(12,2) NOT NULL,
  min_order_amount DECIMAL(12,2) NULL,
  max_uses INT NULL,
  used_count INT NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Permanent record of sensitive admin actions. Never updated or deleted by
-- application code — only ever inserted into.
CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id INT NULL,
  details TEXT NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_admin (admin_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Status/triage for public/contact.php submissions, managed from the admin
-- Contact Messages inbox.
ALTER TABLE contact_messages
  ADD COLUMN status ENUM('NEW','READ','IN_PROGRESS','RESOLVED') NOT NULL DEFAULT 'NEW' AFTER message;

-- ---------------------------------------------------------------------------
-- Small key/value store for the admin Settings > General page. Kept
-- deliberately minimal — platform fee/commission rates stay as the
-- PLATFORM_COMMISSION_RATE / SERVICE_FEE_PER_TICKET constants in
-- includes/payments.php rather than moving here, since every past order
-- stores its own commission/fee amount and a live-editable rate touching
-- that code needs its own careful pass.
CREATE TABLE platform_settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
  ('platform_name', 'obitickets'),
  ('support_email', 'info@obitickets.site'),
  ('default_currency', 'UGX');

-- ---------------------------------------------------------------------------
-- Replaces the hardcoded EVENT_CATEGORIES constant (includes/events.php) as
-- the source of truth — that constant is now populated from this table at
-- bootstrap time, so every existing page that reads EVENT_CATEGORIES keeps
-- working unchanged. events.category stays a plain VARCHAR (not a foreign
-- key) so disabling or renaming a category here never orphans past events.
CREATE TABLE event_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  slug VARCHAR(64) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  icon_key VARCHAR(32) NOT NULL DEFAULT 'ic-ticket',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO event_categories (name, slug, icon_key, sort_order) VALUES
  ('Music', 'music', 'ic-music', 1),
  ('Conference', 'conference', 'ic-briefcase', 2),
  ('Comedy', 'comedy', 'ic-mic', 3),
  ('Sports', 'sports', 'ic-ball', 4),
  ('Faith', 'faith', 'ic-cross', 5),
  ('Fashion', 'fashion', 'ic-hanger', 6),
  ('Community', 'community', 'ic-people', 7);

SET FOREIGN_KEY_CHECKS = 1;
