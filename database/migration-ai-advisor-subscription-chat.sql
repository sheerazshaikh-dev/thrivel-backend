-- Thrivel IQ: AI Health Coach monthly subscription + per-member chat
-- Runtime migration normally applies this automatically.

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS billing_interval ENUM('one_time','month') NOT NULL DEFAULT 'one_time' AFTER standalone_price;

CREATE TABLE IF NOT EXISTS advisor_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  source_order_id BIGINT UNSIGNED NULL,
  product_slug VARCHAR(120) NOT NULL DEFAULT 'ai-health-advisor',
  status ENUM('active','past_due','cancel_at_period_end','cancelled') NOT NULL DEFAULT 'active',
  monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  current_period_start DATETIME NOT NULL,
  current_period_end DATETIME NOT NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  payment_provider VARCHAR(50) NOT NULL DEFAULT 'prototype',
  provider_subscription_id VARCHAR(190) NULL,
  cancelled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_advisor_subscription_user (user_id),
  KEY idx_advisor_subscription_status (status),
  CONSTRAINT fk_advisor_subscription_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_advisor_subscription_order FOREIGN KEY (source_order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advisor_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  model VARCHAR(120) NULL,
  response_id VARCHAR(190) NULL,
  safety_class VARCHAR(60) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_advisor_messages_user_created (user_id, created_at, id),
  CONSTRAINT fk_advisor_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE products SET
  billing_interval='month',
  size_label='Monthly subscription',
  description='Private AI wellness advisor chat personalized to the member assessment, purchased products and reviewer-published plan. Restricted to health and Thrivel IQ topics.',
  usage_notice='AI wellness guidance. Medication and dosage remain reviewer-controlled. Research-use products are not recommended for human or animal use.'
WHERE slug='ai-health-advisor';
