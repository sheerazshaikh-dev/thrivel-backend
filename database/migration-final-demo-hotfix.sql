-- Thrivel IQ Final Demo hotfix (2026-08-14)
ALTER TABLE products ADD COLUMN IF NOT EXISTS billing_interval ENUM('one_time','month') NOT NULL DEFAULT 'month' AFTER standalone_price;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tags JSON NULL AFTER billing_interval;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_address_json JSON NULL AFTER items_json;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS advisor_billing_cycle ENUM('month','year') NOT NULL DEFAULT 'month' AFTER shipping_address_json;
ALTER TABLE advisor_subscriptions ADD COLUMN IF NOT EXISTS billing_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_price;
ALTER TABLE advisor_subscriptions ADD COLUMN IF NOT EXISTS billing_cycle ENUM('month','year') NOT NULL DEFAULT 'month' AFTER billing_price;

CREATE TABLE IF NOT EXISTS member_nutrition_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL,
  protein_grams DECIMAL(7,1) NOT NULL DEFAULT 0, carbs_grams DECIMAL(7,1) NOT NULL DEFAULT 0, hydration_oz DECIMAL(7,1) NOT NULL DEFAULT 0,
  logged_on DATE NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_member_nutrition_day (user_id,logged_on), KEY idx_member_nutrition_user_day (user_id,logged_on),
  CONSTRAINT fk_member_nutrition_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE products SET name='Ascend', category='Weight Loss & Metabolic', compound='Triple Agonist — GLP-1 / GIP / Glucagon', price=100, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Ascend' WHERE slug='retatrutide';
UPDATE products SET name='Momentum', category='Weight Loss & Metabolic', compound='GLP-1 Receptor Agonist', price=125, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Momentum' WHERE slug='semaglutide';
UPDATE products SET name='Catalyst', category='Weight Loss & Metabolic', compound='Dual GIP / GLP-1 Agonist', price=150, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Catalyst' WHERE slug='tirzepatide';
UPDATE products SET name='Restore', category='Recovery & Healing', compound='Body Protective Compound', price=65, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Restore' WHERE slug='bpc-157';
UPDATE products SET name='Rebound', category='Recovery & Healing', compound='Thymosin Beta-4 Fragment', price=65, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Rebound' WHERE slug='tb-500';
UPDATE products SET name='Radiance', category='Recovery & Healing', compound='Copper Peptide · Tripeptide-1', price=60, size_label='100mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Radiance' WHERE slug='ghk-cu';
UPDATE products SET name='Reforge Stack', category='Recovery & Healing', compound='Complete Recovery Stack', price=130, size_label='20mg / vial', billing_interval='month', usage_notice='', tags=JSON_ARRAY('injectable','peptide','stack'), image_alt='Reforge Stack' WHERE slug='wolverine-stack';
UPDATE products SET name='Lumina Stack', category='Recovery & Healing', compound='GHK-Cu / BPC-157 / TB-500', price=110, size_label='70mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide','stack'), image_alt='Lumina Stack' WHERE slug='glow-stack';
UPDATE products SET name='Elevate', category='Growth Hormone Support', compound='GHRH Analogue', price=60, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Elevate' WHERE slug='tesamorelin';
UPDATE products SET name='Ignite', category='Growth Hormone Support', compound='GH Secretagogue', price=60, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Ignite' WHERE slug='ipamorelin';
UPDATE products SET name='Synergy Stack', category='Growth Hormone Support', compound='GH Secretagogue Stack (No DAC)', price=115, size_label='10mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide','stack'), image_alt='Synergy Stack' WHERE slug='cjc-1295-ipamorelin';
UPDATE products SET name='Revive', category='Longevity & Wellness', compound='Mitochondrial-Derived Peptide', price=95, size_label='40mg / vial', billing_interval='month', tags=JSON_ARRAY('injectable','peptide'), image_alt='Revive' WHERE slug='ots-c-mots-c';
UPDATE products SET name='Recharge', category='Longevity & Wellness', compound='Nicotinamide Adenine Dinucleotide', price=95, size_label='1000mg / vial', billing_interval='month', tags=JSON_ARRAY('vitamin','injectable'), image_alt='Recharge' WHERE slug='nad-plus';
UPDATE products SET billing_interval='month' WHERE product_type <> 'service';
UPDATE products SET price=19.99, standalone_price=19.99, billing_interval='month', size_label='Monthly or annual subscription' WHERE slug='ai-health-advisor';

INSERT INTO products (slug,name,category,size_label,price,standalone_price,billing_interval,tags,description,compound,usage_notice,goal_tags,use_cases,product_type,active,image_url,image_alt,sort_order)
VALUES ('thymosin-alpha-1','Fortify','Recovery & Healing','10mg / vial',70.00,NULL,'month',JSON_ARRAY('injectable','peptide'),'Built for resilience.','Immune Modulator','',JSON_ARRAY('Recovery & healing','Longevity'),JSON_ARRAY('immune support','resilience','recovery'),'research',1,'/uploads/defaults/product-default.svg','Fortify',26)
ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),size_label=VALUES(size_label),price=VALUES(price),billing_interval=VALUES(billing_interval),tags=VALUES(tags),description=VALUES(description),compound=VALUES(compound),usage_notice=VALUES(usage_notice),goal_tags=VALUES(goal_tags),use_cases=VALUES(use_cases),active=1,image_alt=VALUES(image_alt);

UPDATE member_plans SET
  workout_plan=JSON_ARRAY(
    'Day 1: Bench press 3×8, seated row 3×10, lateral raise 3×12',
    'Day 3: Squat or leg press 3×8-10, Romanian deadlift 3×10, leg curl 3×12',
    'Day 5: Incline dumbbell press 3×10, lat pulldown 3×10, walking lunges 3×10/leg',
    'Two zone-2 cardio sessions and one mobility/recovery session'
  ),
  activity='Three specific strength sessions plus two zone-2 cardio sessions and one mobility/recovery session.',
  version=version+1
WHERE workout_plan IS NULL OR CAST(workout_plan AS CHAR) LIKE '%strength session%' OR CAST(workout_plan AS CHAR) LIKE '%structured training%';
