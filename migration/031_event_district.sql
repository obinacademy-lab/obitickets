-- 031: the district an event is in (organizer picks it; Browse uses it for "Near me" and the district/region filter).
-- Events without it are recognised from their address text, so this is safe to run any time.
ALTER TABLE events ADD COLUMN district VARCHAR(48) NULL, ADD INDEX idx_events_district (district);
