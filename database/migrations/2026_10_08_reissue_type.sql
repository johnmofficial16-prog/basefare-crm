-- =============================================================================
-- Reissuance — its own booking type (8 Oct 2026)
-- =============================================================================
-- A Reissuance is filled in like a New Booking (one itinerary, fare lines,
-- payment) but the acceptance, transaction and e-ticket say "Reissuance", and
-- the amount charged under Base Fare (fare line 1) comes back to the customer
-- as a Future Travel Voucher (App\Services\ReissueVoucherService).
--
-- The voucher columns on travel_vouchers already exist
-- (2026_10_04_exchange_vouchers.sql); this adds 'reissue' to the type ENUMs.
--
-- It also adds 'award_booking', which the forms have offered for months but
-- production never had (database/update_enum.sql was never run there). Award /
-- Miles acceptances were saved with type '' (8 rows on 8 Oct 2026, all with
-- is_miles_booking = 1). They are parked as 'other' so the ALTER cannot trip
-- over the invalid '' value, then restored as 'award_booking'.
--
-- Production lists before this change (checked 8 Oct 2026):
--   acceptance_requests: new_booking, exchange, cancel_refund, cancel_credit,
--                        seat_purchase, cabin_upgrade, name_correction, other
--   transactions:        new_booking, exchange, seat_purchase, cabin_upgrade,
--                        cancel_refund, cancel_credit, name_correction, other
-- Nothing is removed; only 'reissue' and 'award_booking' are added.
-- =============================================================================

UPDATE `acceptance_requests` SET `type` = 'other' WHERE `type` = '' AND `is_miles_booking` = 1;

ALTER TABLE `acceptance_requests`
  MODIFY COLUMN `type` ENUM('new_booking','exchange','reissue','cancel_refund','cancel_credit','seat_purchase','cabin_upgrade','name_correction','award_booking','other') NOT NULL;

UPDATE `acceptance_requests` SET `type` = 'award_booking' WHERE `type` = 'other' AND `is_miles_booking` = 1;

ALTER TABLE `transactions`
  MODIFY COLUMN `type` ENUM('new_booking','exchange','reissue','seat_purchase','cabin_upgrade','cancel_refund','cancel_credit','name_correction','award_booking','other') NOT NULL;
