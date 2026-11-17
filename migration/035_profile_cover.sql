-- 11/18: profile cover photos for everyone, and a short line under an organizer's name ("Event Venue · Soroti City").
-- Optional: the site works without it (profiles fall back to the newest event flyer and no line).
ALTER TABLE users ADD COLUMN cover_url VARCHAR(255) NULL AFTER avatar_url;
ALTER TABLE organizer_profiles ADD COLUMN headline VARCHAR(60) NULL AFTER bio;
ALTER TABLE organizer_profiles ADD COLUMN location VARCHAR(60) NULL AFTER headline;
