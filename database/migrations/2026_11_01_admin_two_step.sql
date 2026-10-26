-- Two-step sign-in for admin accounts (security, 26 Oct 2026): after the
-- password, a 6-digit code from an authenticator app (or a one-time backup code).
--   totp_secret_enc     the app's shared secret, encrypted (the key-rotation
--                       script finds it by its _enc suffix)
--   totp_enabled_at     when set up; NULL = not set up yet (asked at next sign-in)
--   totp_last_step      the last 30-second step used, so a code works only once
--   totp_backup_codes   JSON list of hashed one-time backup codes
--   totp_device_epoch   bumped on reset, which forgets every "remembered" computer
ALTER TABLE users
  ADD COLUMN totp_secret_enc   TEXT NULL,
  ADD COLUMN totp_enabled_at   DATETIME NULL,
  ADD COLUMN totp_last_step    BIGINT NULL,
  ADD COLUMN totp_backup_codes TEXT NULL,
  ADD COLUMN totp_device_epoch INT NOT NULL DEFAULT 0;
