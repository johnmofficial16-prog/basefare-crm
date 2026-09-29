-- =============================================================================
-- Fare type / refundability on acceptances and e-tickets
-- =============================================================================
-- Until now every authorisation and e-ticket said NON-REFUNDABLE regardless of
-- the fare. The agent now picks a fare type (pre-selected from the cabin) and
-- fills the template's blanks; App\Services\FareTermsService renders the Fare
-- Rules, Endorsements, policy refund clause and the customer's checkbox from it.
--
--   fare_type          non_refundable | refundable_penalty | fully_refundable | custom
--   fare_terms_values  the blanks as filled, e.g. {"cancel_fee":"150.00"}
--   refund_ack_text    the exact refund sentence the customer ticked — acceptance
--                      checkbox / e-ticket acknowledgement. Stored per record so
--                      chargeback evidence shows the wording as agreed, not
--                      whatever the template says later.
--
-- NULL on all three = record created before this shipped; views fall back to the
-- old non-refundable wording, which is what those customers actually saw.
--
-- Template wording itself lives in system_config (fare_terms.template.*,
-- fare_terms.cabin_map) — no table needed; code defaults apply until an admin
-- edits them at /admin/fare-terms.
--
-- Safe to re-run: ADD COLUMN IF NOT EXISTS is a no-op once applied.
-- =============================================================================

ALTER TABLE `acceptance_requests`
  ADD COLUMN IF NOT EXISTS `fare_type` VARCHAR(30) NULL
    COMMENT 'FareTermsService type; NULL = legacy non-refundable' AFTER `policy_text`,
  ADD COLUMN IF NOT EXISTS `fare_terms_values` JSON NULL
    COMMENT 'Fare-type template blanks as filled' AFTER `fare_type`,
  ADD COLUMN IF NOT EXISTS `refund_ack_text` TEXT NULL
    COMMENT 'Refund checkbox wording shown to and ticked by the customer' AFTER `fare_terms_values`;

ALTER TABLE `etickets`
  ADD COLUMN IF NOT EXISTS `fare_type` VARCHAR(30) NULL
    COMMENT 'FareTermsService type; NULL = legacy non-refundable' AFTER `policy_text`,
  ADD COLUMN IF NOT EXISTS `fare_terms_values` JSON NULL
    COMMENT 'Fare-type template blanks as filled' AFTER `fare_type`,
  ADD COLUMN IF NOT EXISTS `refund_ack_text` TEXT NULL
    COMMENT 'Refundability phrase in the customer acknowledgement' AFTER `fare_terms_values`;
