-- 049: OBI GIFTS grows to 36 gifts in 4 categories (11/26). ADR: .claude/adrs/ADR-004-obi-gifts-36.md
-- Additive and safe to run twice. Only gift tables are touched (never orders, tickets, QR codes or ticket payouts).
--  * gift_catalog gets: category, sound (a built-in sound style or an uploaded file), animation length, the signature flag, featured.
--  * gift_transactions gets a snapshot of the gift's name (prices, coins, rate and split were already stored on every gift).
--  * Crown -> Royal Crown and Luxury Car -> Luxury Sports Car (only if an admin has not renamed them already). Prices of the
--    8 existing gifts are NOT changed (they already equal the launch prices). 28 new gifts are added (exactly 36).
--  * Gifts priced under UGX 500 are "coins only": the app never offers them for a direct mobile money payment.
--  * Every gift has a price in OBI COINS. price_ugx is only the price for the old direct payment (used when OBI COINS is off);
--    the UGX value of a coin gift always comes from the accounting rate set by Admin, never from this column.

ALTER TABLE gift_catalog
  ADD COLUMN IF NOT EXISTS category ENUM('EVERYDAY','POPULAR','PREMIUM','ELITE') NOT NULL DEFAULT 'EVERYDAY' AFTER description,
  ADD COLUMN IF NOT EXISTS sound_style VARCHAR(24) NULL AFTER animation_asset,
  ADD COLUMN IF NOT EXISTS sound_asset VARCHAR(255) NULL AFTER sound_style,
  ADD COLUMN IF NOT EXISTS duration_ms INT NULL AFTER sound_asset,
  ADD COLUMN IF NOT EXISTS is_signature TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_ms,
  ADD COLUMN IF NOT EXISTS is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_signature;
ALTER TABLE gift_catalog ADD INDEX IF NOT EXISTS idx_gift_catalog_category (category, is_active, sort_order);

ALTER TABLE gift_transactions ADD COLUMN IF NOT EXISTS gift_name_snapshot VARCHAR(60) NULL AFTER gift_id;
-- older gifts: their name as it is today (the best record there is)
UPDATE gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id SET t.gift_name_snapshot = g.name WHERE t.gift_name_snapshot IS NULL;

-- the two renames from the launch list
UPDATE gift_catalog SET name = 'Royal Crown' WHERE slug = 'crown' AND name = 'Crown';
UPDATE gift_catalog SET name = 'Luxury Sports Car' WHERE slug = 'car' AND name = 'Luxury Car';

-- the 28 new gifts (INSERT IGNORE: running this twice, or an admin's later edits, are never overwritten)
INSERT IGNORE INTO gift_catalog (slug, name, description, category, emoji, animation_type, price_ugx, price_coins, sort_order) VALUES
  ('thumbs-up',           'Thumbs Up',           'Quick support for a great post.',        'EVERYDAY', '👍', 'pop',       50,     50,     10),
  ('applause',            'Applause',            'A round of applause.',                   'EVERYDAY', '👏', 'clap',      100,    100,    20),
  ('golden-star',         'Golden Star',         'You are a star.',                        'EVERYDAY', '⭐', 'sparkle',   250,    250,    30),
  ('fire',                'Fire',                'This event is on fire.',                 'EVERYDAY', '🔥', 'flame',     750,    750,    50),
  ('balloons',            'Balloons',            'Lift the mood.',                         'EVERYDAY', '🎈', 'float',     1500,   1500,   70),
  ('cupcake',             'Cupcake',             'A sweet treat for the organizer.',       'EVERYDAY', '🧁', 'pop',       2000,   2000,   80),
  ('rose-bouquet',        'Rose Bouquet',        'A bouquet for a beautiful show.',        'POPULAR',  '💐', 'glow',      2500,   2500,   90),
  ('luxury-gift-box',     'Luxury Gift Box',     'A surprise inside.',                     'POPULAR',  '🎁', 'unbox',     3000,   3000,   100),
  ('music-notes',         'Music Notes',         'For the music lovers.',                  'POPULAR',  '🎶', 'float',     4000,   4000,   110),
  ('golden-microphone',   'Golden Microphone',   'Give the stage a voice.',                'POPULAR',  '🎤', 'stage',     6000,   6000,   130),
  ('party-popper',        'Party Popper',        'Let the party start.',                   'POPULAR',  '🎉', 'confetti',  7000,   7000,   140),
  ('gold-medal',          'Gold Medal',          'First place.',                           'POPULAR',  '🏅', 'spin',      8000,   8000,   150),
  ('golden-trophy',       'Golden Trophy',       'Champion of the night.',                 'POPULAR',  '🏆', 'spin',      12000,  12000,  170),
  ('fireworks',           'Fireworks',           'Light up the sky.',                      'POPULAR',  '🎆', 'fireworks', 15000,  15000,  180),
  ('grand-stage',         'Grand Stage',         'Lights, stage, action.',                 'PREMIUM',  '🎭', 'stage',     25000,  25000,  200),
  ('golden-drum',         'Golden Drum',         'Feel the beat.',                         'PREMIUM',  '🥁', 'beat',      35000,  35000,  220),
  ('dj-turntable',        'DJ Turntable',        'Drop the beat.',                         'PREMIUM',  '🎧', 'beat',      40000,  40000,  230),
  ('golden-football',     'Golden Football',     'A golden goal.',                         'PREMIUM',  '⚽', 'bounce',    45000,  45000,  240),
  ('professional-camera', 'Professional Camera', 'Picture perfect.',                       'PREMIUM',  '📸', 'flash',     60000,  60000,  260),
  ('luxury-gold-watch',   'Luxury Gold Watch',   'Your time to shine.',                    'PREMIUM',  '⌚', 'glow',      75000,  75000,  270),
  ('luxury-yacht',        'Luxury Yacht',        'Sail in luxury.',                        'ELITE',    '🛥️', 'sail',      125000, 125000, 290),
  ('royal-castle',        'Royal Castle',        'A kingdom for the organizer.',           'ELITE',    '🏰', 'castle',    150000, 150000, 300),
  ('golden-dragon',       'Golden Dragon',       'Legendary power.',                       'ELITE',    '🐉', 'dragon',    200000, 200000, 310),
  ('world-tour',          'World Tour',          'Take this show around the world.',       'ELITE',    '🌍', 'globe',     250000, 250000, 320),
  ('golden-throne',       'Golden Throne',       'Rule the night.',                        'ELITE',    '👑', 'throne',    300000, 300000, 330),
  ('supernova',           'Supernova',           'An explosion of support.',               'ELITE',    '💥', 'nova',      400000, 400000, 340),
  ('grand-finale',        'Grand Finale',        'The biggest ending.',                    'ELITE',    '🎇', 'finale',    450000, 450000, 350),
  ('obi-universe',        'OBI Universe',        'The signature gift of obitickets.',      'ELITE',    '🌌', 'universe',  500000, 500000, 360);

-- category, sound, length and order for all 36 (by slug, so the 8 existing gifts get theirs too). Names, prices and pictures are not touched.
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'pop'),      duration_ms = COALESCE(duration_ms, 1600),  sort_order = 10  WHERE slug = 'thumbs-up';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'clap'),     duration_ms = COALESCE(duration_ms, 1800),  sort_order = 20  WHERE slug = 'applause';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'chime'),    duration_ms = COALESCE(duration_ms, 1800),  sort_order = 30  WHERE slug = 'golden-star';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'heart'),    duration_ms = COALESCE(duration_ms, 2000),  sort_order = 40  WHERE slug = 'heart';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'whoosh'),   duration_ms = COALESCE(duration_ms, 2000),  sort_order = 50  WHERE slug = 'fire';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'sparkle'),  duration_ms = COALESCE(duration_ms, 2000),  sort_order = 60  WHERE slug = 'rose';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'balloon'),  duration_ms = COALESCE(duration_ms, 2000),  sort_order = 70  WHERE slug = 'balloons';
UPDATE gift_catalog SET category = 'EVERYDAY', sound_style = COALESCE(sound_style, 'sweet'),    duration_ms = COALESCE(duration_ms, 2000),  sort_order = 80  WHERE slug = 'cupcake';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'sparkle'),  duration_ms = COALESCE(duration_ms, 3000),  sort_order = 90  WHERE slug = 'rose-bouquet';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'unwrap'),   duration_ms = COALESCE(duration_ms, 3000),  sort_order = 100 WHERE slug = 'luxury-gift-box';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'melody'),   duration_ms = COALESCE(duration_ms, 3200),  sort_order = 110 WHERE slug = 'music-notes';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'crystal'),  duration_ms = COALESCE(duration_ms, 3200),  sort_order = 120 WHERE slug = 'diamond';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'mic'),      duration_ms = COALESCE(duration_ms, 3400),  sort_order = 130 WHERE slug = 'golden-microphone';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'popper'),   duration_ms = COALESCE(duration_ms, 3200),  sort_order = 140 WHERE slug = 'party-popper';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'medal'),    duration_ms = COALESCE(duration_ms, 3400),  sort_order = 150 WHERE slug = 'gold-medal';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'royal'),    duration_ms = COALESCE(duration_ms, 3600),  sort_order = 160 WHERE slug = 'crown';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'fanfare'),  duration_ms = COALESCE(duration_ms, 3800),  sort_order = 170 WHERE slug = 'golden-trophy';
UPDATE gift_catalog SET category = 'POPULAR',  sound_style = COALESCE(sound_style, 'boom'),     duration_ms = COALESCE(duration_ms, 4000),  sort_order = 180 WHERE slug = 'fireworks';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'launch'),   duration_ms = COALESCE(duration_ms, 4500),  sort_order = 190 WHERE slug = 'rocket';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'crowd'),    duration_ms = COALESCE(duration_ms, 5000),  sort_order = 200 WHERE slug = 'grand-stage';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'party'),    duration_ms = COALESCE(duration_ms, 5000),  sort_order = 210 WHERE slug = 'cake';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'drum'),     duration_ms = COALESCE(duration_ms, 5000),  sort_order = 220 WHERE slug = 'golden-drum';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'dj'),       duration_ms = COALESCE(duration_ms, 5500),  sort_order = 230 WHERE slug = 'dj-turntable';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'kick'),     duration_ms = COALESCE(duration_ms, 5000),  sort_order = 240 WHERE slug = 'golden-football';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'engine'),   duration_ms = COALESCE(duration_ms, 5500),  sort_order = 250 WHERE slug = 'car';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'shutter'),  duration_ms = COALESCE(duration_ms, 5500),  sort_order = 260 WHERE slug = 'professional-camera';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'tick'),     duration_ms = COALESCE(duration_ms, 6000),  sort_order = 270 WHERE slug = 'luxury-gold-watch';
UPDATE gift_catalog SET category = 'PREMIUM',  sound_style = COALESCE(sound_style, 'jet'),      duration_ms = COALESCE(duration_ms, 6500),  sort_order = 280 WHERE slug = 'jet';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'horn'),     duration_ms = COALESCE(duration_ms, 7500),  sort_order = 290 WHERE slug = 'luxury-yacht';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'royal'),    duration_ms = COALESCE(duration_ms, 8000),  sort_order = 300 WHERE slug = 'royal-castle';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'roar'),     duration_ms = COALESCE(duration_ms, 8000),  sort_order = 310 WHERE slug = 'golden-dragon';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'tour'),     duration_ms = COALESCE(duration_ms, 8000),  sort_order = 320 WHERE slug = 'world-tour';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'royal'),    duration_ms = COALESCE(duration_ms, 8500),  sort_order = 330 WHERE slug = 'golden-throne';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'nova'),     duration_ms = COALESCE(duration_ms, 9000),  sort_order = 340 WHERE slug = 'supernova';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'finale'),   duration_ms = COALESCE(duration_ms, 9500),  sort_order = 350 WHERE slug = 'grand-finale';
UPDATE gift_catalog SET category = 'ELITE',    sound_style = COALESCE(sound_style, 'cosmic'),   duration_ms = COALESCE(duration_ms, 10000), sort_order = 360, is_signature = 1, is_featured = 1 WHERE slug = 'obi-universe';
-- only one signature gift
UPDATE gift_catalog SET is_signature = 0 WHERE is_signature = 1 AND slug <> 'obi-universe';
