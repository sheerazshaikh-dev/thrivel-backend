-- Thrivel IQ current non-destructive migration note
-- The uploaded PHP backend performs the compatible runtime migration for shared-hosting MySQL/MariaDB.
-- It creates missing tables and adds missing columns without deleting products, users, orders, plans, media, or branding.
--
-- Deployment order:
-- 1. Upload the backend files.
-- 2. Keep the existing backend/.env.
-- 3. Open https://backend.thrivelid.com/health once.
-- 4. Open https://backend.thrivelid.com/settings and confirm HTTP 200 JSON.
-- 5. Import seed.sql only when the Origin Labs catalog/default branding needs to be inserted or refreshed.
--
-- For a completely empty database, import schema.sql before seed.sql.

SELECT 'Thrivel IQ runtime migration is handled by backend/src/bootstrap.php' AS migration_status;
