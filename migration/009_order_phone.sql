-- obitickets — persist the checkout phone number for SMS ticket delivery
-- Run once against the existing database: mysql -u user -p dbname < migration/009_order_phone.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- The mobile money phone number entered at checkout — previously only ever
-- used transiently for the iotec collection request, never saved. Needed
-- now so finalize_order_success() has a number to SMS the ticket codes to;
-- NULL on any order placed before this migration.
ALTER TABLE orders ADD COLUMN phone VARCHAR(32) NULL AFTER payment_method;

SET FOREIGN_KEY_CHECKS = 1;
