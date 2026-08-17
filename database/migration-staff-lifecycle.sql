-- Thrivel IQ staff lifecycle management
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER verified,
  ADD COLUMN IF NOT EXISTS deactivated_at DATETIME NULL AFTER is_active,
  ADD COLUMN IF NOT EXISTS deactivated_by BIGINT UNSIGNED NULL AFTER deactivated_at;

CREATE TABLE IF NOT EXISTS staff_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_name VARCHAR(190) NOT NULL DEFAULT '',
  target_user_id BIGINT UNSIGNED NULL,
  target_name VARCHAR(190) NOT NULL DEFAULT '',
  target_email VARCHAR(190) NOT NULL DEFAULT '',
  action VARCHAR(80) NOT NULL,
  from_role VARCHAR(40) NULL,
  to_role VARCHAR(40) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_staff_audit_target (target_user_id, created_at),
  KEY idx_staff_audit_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE users SET is_active=1 WHERE is_active IS NULL;
