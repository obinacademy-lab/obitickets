-- obitickets — MySQL schema
-- Import once in phpMyAdmin (or `mysql -u user -p dbname < schema.sql`) after creating an
-- empty database. utf8mb4 throughout for full emoji/unicode support (event titles, etc).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE,
  phone VARCHAR(32) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('ATTENDEE','ORGANIZER','ADMIN') NOT NULL DEFAULT 'ATTENDEE',
  avatar_url VARCHAR(500) NULL,
  email_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- One-to-one profile for users with role=ORGANIZER. Kept separate from `users`
-- so the attendee-facing columns on `users` stay small — most users never
-- organize an event and would never populate these fields.
CREATE TABLE organizer_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  org_name VARCHAR(191) NOT NULL,
  bio TEXT NULL,
  payout_phone VARCHAR(32) NULL,
  payout_provider ENUM('MTN_MOMO','AIRTEL_MONEY','BANK') NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organizer_id INT NOT NULL,
  title VARCHAR(191) NOT NULL,
  slug VARCHAR(191) NOT NULL UNIQUE,
  category VARCHAR(64) NOT NULL,
  description TEXT NULL,
  venue_name VARCHAR(191) NOT NULL,
  venue_address VARCHAR(255) NULL,
  -- Emoji + tint pair used as the card/poster art whenever banner_image is
  -- NULL (no upload) — see shelf-card.php / poster-art in style.css. Kept
  -- even for events with a real banner_image, as the fallback if it's ever
  -- removed.
  banner_emoji VARCHAR(8) NOT NULL DEFAULT '🎟️',
  -- Path under public/ (e.g. "/uploads/events/<hash>.jpg"), or NULL to fall
  -- back to banner_emoji. Never trust the original filename/extension —
  -- see handle_event_banner_upload() in includes/uploads.php.
  banner_image VARCHAR(500) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('DRAFT','PUBLISHED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_events_status_starts (status, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Past-event photos/videos an organizer adds to help sell tickets (separate
-- from the one banner_image on `events`, which is the card/hero art). Never
-- trust the original filename/extension or claimed MIME type — see
-- handle_event_media_uploads() in includes/uploads.php.
CREATE TABLE event_media (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  media_type ENUM('IMAGE','VIDEO') NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_event_media_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
CREATE TABLE ticket_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  price DECIMAL(12,2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'UGX',
  quantity_total INT NOT NULL,
  quantity_sold INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- One row per checkout. `total_amount` is stored (not recomputed from items)
-- so a later price change to a ticket_type never rewrites a past order.
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NOT NULL,
  -- PENDING = a mobile money collection is in flight with iotec; FAILED = the
  -- payer declined/the request timed out (see status_message below) — the
  -- ticket reservation behind a FAILED order has already been released.
  status ENUM('PENDING','PAID','FAILED','CANCELLED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  subtotal_amount DECIMAL(12,2) NOT NULL,
  -- Charged to the BUYER at checkout (currently a flat UGX 700 per ticket,
  -- see SERVICE_FEE_PER_TICKET in includes/payments.php) — a platform
  -- convenience/processing fee, added on top of subtotal_amount.
  service_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- Owed BY THE ORGANIZER out of subtotal_amount (currently 10%, see
  -- PLATFORM_COMMISSION_RATE in includes/payments.php) — obitickets' cut of
  -- the ticket price itself, separate from and in addition to the buyer's
  -- service fee above. The organizer's payout for this order is
  -- subtotal_amount - commission_amount. Stored (not recomputed from a
  -- "current rate") so a later rate change never rewrites a past order.
  commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'UGX',
  -- iotec routes MTN vs Airtel itself from the phone number — obitickets
  -- never needs to know or store which network a MOBILE_MONEY order used.
  payment_method ENUM('MOBILE_MONEY','CARD') NULL,
  -- iotec's collection transaction id once a payment has been initiated.
  payment_reference VARCHAR(191) NULL,
  -- iotec's human-readable reason on a FAILED order (e.g. "Insufficient
  -- funds", "Request timed out") — shown to the buyer so "try again" isn't
  -- the only information they get.
  status_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
CREATE TABLE order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  ticket_type_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- One row per physical/QR ticket — an order_item with quantity 3 produces 3
-- rows here, each independently scannable and check-in-able at the gate.
CREATE TABLE tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_item_id INT NOT NULL,
  ticket_code VARCHAR(64) NOT NULL UNIQUE,
  status ENUM('VALID','USED','CANCELLED') NOT NULL DEFAULT 'VALID',
  checked_in_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Submissions from public/contact.php. No FK to users — the form works for
-- anonymous visitors too, not just logged-in accounts.
CREATE TABLE contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL,
  topic VARCHAR(64) NOT NULL DEFAULT 'General',
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
