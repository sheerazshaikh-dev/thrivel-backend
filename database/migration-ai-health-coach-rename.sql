-- Thrivel IQ: rename the monthly AI service for existing databases.
UPDATE products
SET name = 'AI Health Coach',
    image_alt = 'AI Health Coach',
    description = REPLACE(description, 'AI wellness advisor', 'AI wellness coach')
WHERE slug = 'ai-health-advisor';
