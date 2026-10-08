-- =============================================================================
-- Reissuance — its own booking type (8 Oct 2026)
-- =============================================================================
-- A Reissuance is filled in like a New Booking (one itinerary, fare lines,
-- payment) but the acceptance, transaction and e-ticket say "Reissuance", and
-- the amount charged under Base Fare (fare line 1) comes back to the customer
-- as a Future Travel Voucher (App\Services\ReissueVoucherService).
--
-- The voucher columns on travel_vouchers already exist
-- (2026_10_04_exchange_vouchers.sql); this only adds 'reissue' to the type ENUMs.
--
-- The value lists are the ones live in production before this change
-- (database/update_enum.sql) plus 'reissue'. If a row ever used a value missing
-- here, MariaDB (strict mode) refuses the ALTER instead of corrupting data.
-- =============================================================================

ALTER TABLE `acceptance_requests`
  MODIFY COLUMN `type` ENUM('new_booking','exchange','reissue','cancel_refund','cancel_credit','seat_purchase','cabin_upgrade','name_correction','award_booking','other') NOT NULL;

ALTER TABLE `transactions`
  MODIFY COLUMN `type` ENUM('new_booking','exchange','reissue','seat_purchase','cabin_upgrade','cancel_refund','cancel_credit','name_correction','award_booking','other') NOT NULL;
