-- obitickets — public organizer profile pages (organizer.php?slug=...)
-- Run once against the existing database: mysql -u user -p dbname < migration/008_organizer_profile_slug.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- NULL for every organizer_profiles row that existed before this feature —
-- get_or_create_organizer_slug() in includes/organizer.php lazily generates
-- and saves one the first time it's needed, so no backfill script required.
ALTER TABLE organizer_profiles ADD COLUMN slug VARCHAR(191) NULL UNIQUE AFTER org_name;

SET FOREIGN_KEY_CHECKS = 1;
