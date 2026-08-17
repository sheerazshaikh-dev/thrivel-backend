-- Thrivel IQ admin subscription controls
-- Required once before using Admin > Members > Manage subscriptions.

ALTER TABLE product_subscriptions
  MODIFY COLUMN status ENUM('active','paused','cancel_at_period_end','cancelled') NOT NULL DEFAULT 'active';

ALTER TABLE advisor_subscriptions
  MODIFY COLUMN status ENUM('active','paused','past_due','cancel_at_period_end','cancelled') NOT NULL DEFAULT 'active';
