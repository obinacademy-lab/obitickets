-- 050: bigger OBI COINS purchases (11/26). Owner: "let the coins go up to 1,000,000, or let people enter the number they want".
-- Safe to run twice: INSERT IGNORE everywhere, so an admin's later edits are never overwritten. Nothing about tickets is touched.
--  * Four larger packages at the same price as every live package (1 OBI COIN = UGX 1): 100,000 / 250,000 / 500,000 / 1,000,000.
--    Prices stay editable in Admin > OBI COINS > Packages (a new price applies to new purchases only).
--  * "Any amount": a hidden package row that every custom purchase is recorded under (never shown as a package), and the two settings
--    behind it: switched on, price per coin UGX 1 (stored as UGX x 10,000 = 10000). Both are changed in Admin > OBI COINS > Packages.
--  * The code limits were raised at the same time: one purchase up to UGX 1,000,000, at most UGX 2,000,000 per person per 24 hours.

INSERT IGNORE INTO coin_packages (slug, name, description, coins, bonus_coins, price_ugx, is_active, is_featured, sort_order) VALUES
  ('mega',      'Mega',      NULL,  100000, 0,  100000, 1, 0, 60),
  ('super',     'Super',     NULL,  250000, 0,  250000, 1, 0, 70),
  ('royal',     'Royal',     NULL,  500000, 0,  500000, 1, 0, 80),
  ('legend',    'Legend',    NULL, 1000000, 0, 1000000, 1, 0, 90);

-- the hidden row for "any amount" purchases: never active, no price (the price comes from the settings below)
INSERT IGNORE INTO coin_packages (slug, name, description, coins, bonus_coins, price_ugx, is_active, is_featured, sort_order) VALUES
  ('custom-amount', 'Custom amount', 'Used for purchases where the buyer typed the number of OBI COINS. Not shown as a package.', 1, 0, NULL, 0, 0, 9999);

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('coin_custom_enabled', '1'), ('coin_custom_price_e4', '10000');
