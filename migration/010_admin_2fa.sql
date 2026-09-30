-- obitickets — two-factor authentication (TOTP) for admin accounts
-- Run once against the existing database: mysql -u user -p dbname < migration/010_admin_2fa.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- totp_secret is set the moment an admin starts setup, before totp_enabled
-- flips to 1 — see includes/totp.php and public/admin-security.php. Login
-- only ever checks the secret when totp_enabled = 1, so an abandoned setup
-- (secret saved, never confirmed) has no effect on login.
ALTER TABLE users ADD COLUMN totp_secret VARCHAR(32) NULL AFTER admin_role;
ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
-- JSON array of password_hash()'d one-time recovery codes — consumed (removed)
-- one at a time by verify_and_consume_recovery_code() in includes/totp.php.
ALTER TABLE users ADD COLUMN totp_recovery_codes TEXT NULL AFTER totp_enabled;

SET FOREIGN_KEY_CHECKS = 1;
