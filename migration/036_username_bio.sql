-- Phase 4 (11/18): a @username and a short bio for everyone (the "Me" page). Optional: without it the Me page
-- shows no @username or bio and the Edit profile page hides those fields.
ALTER TABLE users ADD COLUMN username VARCHAR(24) NULL AFTER name;
ALTER TABLE users ADD COLUMN bio VARCHAR(160) NULL AFTER username;
CREATE UNIQUE INDEX uq_users_username ON users(username);
