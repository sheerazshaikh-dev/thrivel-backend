-- Thrivel IQ final-demo UX / orders / subscriptions hotfix
-- Safe to run once before deploying the matching frontend/backend code.

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS annual_price DECIMAL(10,2) NULL AFTER standalone_price;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS product_billing_cycles_json JSON NULL AFTER advisor_billing_cycle,
  ADD COLUMN IF NOT EXISTS order_status ENUM('new','processing','completed','cancelled') NOT NULL DEFAULT 'new' AFTER product_billing_cycles_json,
  ADD COLUMN IF NOT EXISTS fulfillment_status ENUM('unfulfilled','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'unfulfilled' AFTER order_status,
  ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(190) NULL AFTER fulfillment_status,
  ADD COLUMN IF NOT EXISTS carrier VARCHAR(120) NULL AFTER tracking_number;

ALTER TABLE advisor_subscriptions
  ADD COLUMN IF NOT EXISTS pending_paid_conversion TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_cycle;

CREATE TABLE IF NOT EXISTS product_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  source_order_id BIGINT UNSIGNED NULL,
  product_slug VARCHAR(120) NOT NULL,
  product_name VARCHAR(190) NOT NULL,
  status ENUM('active','cancel_at_period_end','cancelled') NOT NULL DEFAULT 'active',
  billing_cycle ENUM('month','year') NOT NULL DEFAULT 'month',
  billing_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  current_period_start DATETIME NOT NULL,
  current_period_end DATETIME NOT NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_sub_user_status (user_id,status),
  CONSTRAINT fk_product_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sub_order FOREIGN KEY (source_order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE products
SET annual_price = COALESCE(annual_price, 99.00),
    price = 19.99,
    standalone_price = 19.99,
    billing_interval = 'month',
    size_label = 'Monthly or annual subscription'
WHERE slug = 'ai-health-advisor';
