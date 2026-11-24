-- 040: discounts at checkout (11/24): promo codes finally apply, and later referral rewards and bundle deals use the same step.
-- orders.subtotal_amount stays "the ticket money the organizer gets before commission", now AFTER the discount,
-- so every existing sales / payout report keeps adding up. The list-price lines stay in order_items, and the
-- discount itself is kept here for receipts and reports. Before this runs, checkout shows no promo box.
ALTER TABLE orders
  ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN promo_code_id INT NULL,
  ADD COLUMN discount_note VARCHAR(160) NULL,
  ADD INDEX idx_orders_promo (promo_code_id);
