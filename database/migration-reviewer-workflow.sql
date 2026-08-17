-- Thrivel IQ reviewer workflow hotfix
-- Safe to import once on an existing database. Runtime schema repair performs the same changes automatically.

ALTER TABLE users
  MODIFY COLUMN role ENUM('customer','reviewer','admin') NOT NULL DEFAULT 'customer';

ALTER TABLE member_plans
  ADD COLUMN IF NOT EXISTS reviewer_user_id BIGINT UNSIGNED NULL AFTER reviewer,
  ADD COLUMN IF NOT EXISTS reviewer_assigned_at DATETIME NULL AFTER reviewer_user_id,
  ADD COLUMN IF NOT EXISTS internal_reviewer_note TEXT NULL AFTER reviewer_assigned_at,
  ADD COLUMN IF NOT EXISTS requested_information TEXT NULL AFTER internal_reviewer_note,
  ADD COLUMN IF NOT EXISTS released_at DATETIME NULL AFTER reviewer_approved_at;

CREATE TABLE IF NOT EXISTS plan_review_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_name VARCHAR(190) NOT NULL DEFAULT '',
  actor_role VARCHAR(40) NOT NULL DEFAULT '',
  action VARCHAR(80) NOT NULL,
  from_status VARCHAR(50) NULL,
  to_status VARCHAR(50) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_review_events_plan_created (plan_id, created_at),
  CONSTRAINT fk_review_events_plan FOREIGN KEY (plan_id) REFERENCES member_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
