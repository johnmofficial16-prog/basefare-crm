-- Security audit 26 Oct 2026: a password reset by someone else (a manager or an
-- admin) is a ONE-TIME password. The person must choose their own at the next
-- sign-in, so whoever reset it can't keep using the account.
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;
