-- 030: organizers choose whether their event page shows how many tickets are left.
-- Off by default (most organizers do not want buyers to see sales numbers).
-- Before this runs the site behaves as if every event has it switched off.
ALTER TABLE events ADD COLUMN show_tickets_left TINYINT(1) NOT NULL DEFAULT 0;
