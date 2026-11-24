-- 042: group tickets and quantity deals (11/24).
-- admits: how many people one ticket of this type lets in ("Table for 4" = 4). Buying one gives that many QR codes,
--         and the buyer fee is charged per person (owner's choice). Stock and per-person limits count tickets bought.
-- deal_buy / deal_pay: "Buy 5, pay for 4" (both NULL = no deal). The free ones come off the event's ticket money.
-- order_items.admits keeps the number at the moment of purchase, so a later edit never changes how many QR codes
-- an existing order gets. Before this runs every ticket admits 1 and has no deal.
ALTER TABLE ticket_types
  ADD COLUMN admits INT NOT NULL DEFAULT 1,
  ADD COLUMN deal_buy INT NULL,
  ADD COLUMN deal_pay INT NULL;
ALTER TABLE order_items ADD COLUMN admits INT NOT NULL DEFAULT 1;
