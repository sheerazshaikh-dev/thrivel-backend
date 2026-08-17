-- Manual fallback only.
-- The PHP runtime migration normally adds this column automatically.
-- Import this file in phpMyAdmin only if the backend database user cannot ALTER tables.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS api_token_expires_at DATETIME NULL AFTER api_token_hash;
