-- Thrivel IQ checkout cart migration
-- Non-destructive. Existing orders remain valid through legacy stack fields.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS product_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER stack_price,
  ADD COLUMN IF NOT EXISTS items_json JSON NULL AFTER assessment_json;

UPDATE orders
SET product_subtotal = stack_price
WHERE product_subtotal = 0 AND stack_price > 0;
