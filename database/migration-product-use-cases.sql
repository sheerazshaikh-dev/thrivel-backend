-- Add structured use cases used by the assessment catalogue matcher.
-- Existing non-empty use cases are preserved.
SET NAMES utf8mb4;
ALTER TABLE products ADD COLUMN IF NOT EXISTS use_cases JSON NULL AFTER goal_tags;

UPDATE products SET use_cases=JSON_ARRAY('weight management','appetite support','metabolic support') WHERE slug='weightloss-stack' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('recovery','healing','muscle recovery','tissue repair') WHERE slug='recovery-stack' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('wellness guidance','workout planning','meal planning','reviewer check-ins') WHERE slug='ai-health-advisor' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('body weight reduction','insulin sensitivity','metabolic support') WHERE slug='retatrutide' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('appetite suppression','sustained body weight reduction','cardiovascular metabolic support') WHERE slug='semaglutide' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('body weight reduction','glycemic control','metabolic support') WHERE slug='tirzepatide' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('tendon repair','ligament repair','gut tissue repair','inflammation response','angiogenesis') WHERE slug='bpc-157' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('muscle repair','flexibility','wound healing','inflammation response','cardiac muscle repair') WHERE slug='tb-500' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('collagen synthesis','elastin production','wound healing','hair follicle stimulation','cellular regeneration','anti aging') WHERE slug='ghk-cu' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('reconstitution solution') WHERE slug='bacteriostatic-water' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('connective tissue repair','muscle repair','gut repair','recovery') WHERE slug='wolverine-stack' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('tissue repair','collagen synthesis','gut lining repair','inflammation response') WHERE slug='klow-stack' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('skin regeneration','collagen synthesis','tissue repair','cellular renewal','anti aging') WHERE slug='glow-stack' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('visceral fat reduction','lean body composition','cognitive function','igf 1 support') WHERE slug='tesamorelin' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('lean mass','sleep quality','bone density maintenance') WHERE slug='ipamorelin' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('lean mass','recovery','sleep architecture') WHERE slug='cjc-1295-ipamorelin' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('telomere maintenance','circadian rhythm','antioxidant activity','immune modulation','longevity') WHERE slug='epithalon' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('energy balance','insulin sensitivity','exercise metabolism','mitochondrial biogenesis') WHERE slug='ots-c-mots-c' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('cellular energy','dna repair','mitochondrial function','cognitive clarity','longevity') WHERE slug='nad-plus' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
UPDATE products SET use_cases=JSON_ARRAY('focus','memory','mood','neuroprotection','cognitive function') WHERE slug='semax' AND COALESCE(JSON_LENGTH(use_cases),0)=0;
