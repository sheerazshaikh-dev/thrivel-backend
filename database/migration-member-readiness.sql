ALTER TABLE member_plans ADD COLUMN IF NOT EXISTS member_response TEXT NULL AFTER requested_information;
ALTER TABLE member_plans ADD COLUMN IF NOT EXISTS member_response_at DATETIME NULL AFTER member_response;

CREATE TABLE IF NOT EXISTS member_plan_progress (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  item_type ENUM('weekly_target','workout') NOT NULL,
  item_key CHAR(64) NOT NULL,
  item_text VARCHAR(500) NOT NULL,
  period_start DATE NOT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member_progress_period_item (user_id,plan_id,item_type,item_key,period_start),
  KEY idx_member_progress_plan_period (plan_id,period_start),
  CONSTRAINT fk_member_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_member_progress_plan FOREIGN KEY (plan_id) REFERENCES member_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_weight_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  weight_lbs DECIMAL(7,2) NOT NULL,
  logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_weight_user_logged (user_id,logged_at),
  CONSTRAINT fk_weight_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_checkins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NULL,
  energy_score TINYINT UNSIGNED NULL,
  adherence_score TINYINT UNSIGNED NULL,
  sleep_hours DECIMAL(4,1) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checkin_user_created (user_id,created_at),
  KEY idx_checkin_plan_created (plan_id,created_at),
  CONSTRAINT fk_checkin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_checkin_plan FOREIGN KEY (plan_id) REFERENCES member_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
