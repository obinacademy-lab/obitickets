-- 044: automatic organizer payouts through IOTEC disbursement (11/24).
-- external_id: our own unique reference sent to IOTEC, so one payout can never be sent twice.
-- iotec_id: IOTEC's transaction id once it accepted the request. failure_reason: why it failed or what is stuck.
-- is_auto: 1 when the organizer's own Withdraw tap sent it with no admin step.
-- payout_phone_changed_at: when the organizer last changed their payout number (a payout to a number changed
-- in the last 24 hours waits for an admin instead of going out automatically).
-- Automatic sending itself is switched on in Admin > Payouts (setting auto_payouts). Before this runs the
-- payout screens behave exactly as before.
ALTER TABLE payouts
  ADD COLUMN external_id VARCHAR(64) NULL,
  ADD COLUMN iotec_id VARCHAR(100) NULL,
  ADD COLUMN failure_reason VARCHAR(500) NULL,
  ADD COLUMN sent_at DATETIME NULL,
  ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0,
  ADD UNIQUE KEY uq_payouts_external (external_id),
  ADD INDEX idx_payouts_iotec (iotec_id);

ALTER TABLE organizer_profiles ADD COLUMN payout_phone_changed_at DATETIME NULL;
