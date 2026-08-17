<?php
declare(strict_types=1);

function load_env(string $file): void {
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $key = ltrim($key, "\xEF\xBB\xBF");
        if ($key === '') continue;
        $value = trim($value, "\"'");
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

load_env(dirname(__DIR__) . '/.env');

function env_value(string $key, ?string $default = null): ?string {
    if (array_key_exists($key, $_ENV)) return (string)$_ENV[$key];
    $value = getenv($key);
    return $value === false ? $default : (string)$value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env_value('DB_HOST', 'localhost'),
        env_value('DB_PORT', '3306'),
        env_value('DB_NAME', '')
    );
    $pdo = new PDO($dsn, env_value('DB_USER', ''), env_value('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function database_table_exists(string $table): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function database_column_exists(string $table, string $column): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function database_column_type(string $table, string $column): string {
    $stmt = db()->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return (string)($stmt->fetchColumn() ?: '');
}

function database_add_column_if_missing(string $table, string $column, string $definition): void {
    if (!database_table_exists($table) || database_column_exists($table, $column)) return;
    db()->exec(sprintf(
        'ALTER TABLE `%s` ADD COLUMN `%s` %s',
        str_replace('`', '', $table),
        str_replace('`', '', $column),
        $definition
    ));
}

function ensure_runtime_schema(): void {
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(120) NOT NULL,
        name VARCHAR(190) NOT NULL,
        category VARCHAR(120) NOT NULL,
        size_label VARCHAR(120) NOT NULL DEFAULT '',
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        standalone_price DECIMAL(10,2) NULL,
        description TEXT NULL,
        compound VARCHAR(255) NULL,
        usage_notice VARCHAR(255) NULL,
        goal_tags JSON NULL,
        use_cases JSON NULL,
        product_type ENUM('checkout','research','stack','service','solution','medication','vitamin') NOT NULL DEFAULT 'research',
        active TINYINT(1) NOT NULL DEFAULT 1,
        medication VARCHAR(190) NULL,
        dosage VARCHAR(190) NULL,
        image_url VARCHAR(500) NULL,
        image_alt VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 100,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_products_slug (slug),
        KEY idx_products_active_sort (active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
        id TINYINT UNSIGNED NOT NULL DEFAULT 1,
        brand_name VARCHAR(190) NOT NULL DEFAULT 'Thrivel IQ',
        tagline VARCHAR(255) NULL,
        logo_dark_url VARCHAR(500) NULL,
        logo_light_url VARCHAR(500) NULL,
        favicon_url VARCHAR(500) NULL,
        hero_image_url VARCHAR(500) NULL,
        auth_image_url VARCHAR(500) NULL,
        checkout_image_url VARCHAR(500) NULL,
        dashboard_image_url VARCHAR(500) NULL,
        assessment_image_url VARCHAR(500) NULL,
        default_product_image_url VARCHAR(500) NULL,
        primary_color VARCHAR(20) NOT NULL DEFAULT '#7AC7C8',
        gradient_mid_color VARCHAR(20) NOT NULL DEFAULT '#9971B1',
        secondary_color VARCHAR(20) NOT NULL DEFAULT '#EC437D',
        accent_color VARCHAR(20) NOT NULL DEFAULT '#F4946E',
        background_color VARCHAR(20) NOT NULL DEFAULT '#0A1133',
        panel_color VARCHAR(20) NOT NULL DEFAULT '#101943',
        support_email VARCHAR(190) NULL,
        footer_text VARCHAR(255) NULL,
        login_headline VARCHAR(255) NULL,
        login_subheadline TEXT NULL,
        login_title VARCHAR(190) NULL,
        login_description VARCHAR(255) NULL,
        signup_headline VARCHAR(255) NULL,
        signup_subheadline TEXT NULL,
        signup_title VARCHAR(190) NULL,
        signup_description VARCHAR(255) NULL,
        account_title VARCHAR(190) NULL,
        account_description VARCHAR(255) NULL,
        checkout_title VARCHAR(255) NULL,
        checkout_description TEXT NULL,
        dashboard_title VARCHAR(255) NULL,
        dashboard_description TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS media (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        url VARCHAR(500) NOT NULL,
        alt_text VARCHAR(255) NULL,
        size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_media_file_name (file_name),
        KEY idx_media_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        country VARCHAR(100) NULL,
        state VARCHAR(100) NULL,
        phone VARCHAR(40) NULL,
        role ENUM('customer','reviewer','admin') NOT NULL DEFAULT 'customer',
        verified TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        deactivated_at DATETIME NULL,
        deactivated_by BIGINT UNSIGNED NULL,
        plan_updates TINYINT(1) NOT NULL DEFAULT 1,
        reviewer_messages TINYINT(1) NOT NULL DEFAULT 1,
        marketing_emails TINYINT(1) NOT NULL DEFAULT 0,
        api_token_hash CHAR(64) NULL,
        api_token_expires_at DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_email (email),
        UNIQUE KEY uq_users_api_token_hash (api_token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_audit_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        actor_user_id BIGINT UNSIGNED NULL,
        actor_name VARCHAR(190) NOT NULL DEFAULT '',
        target_user_id BIGINT UNSIGNED NULL,
        target_name VARCHAR(190) NOT NULL DEFAULT '',
        target_email VARCHAR(190) NOT NULL DEFAULT '',
        action VARCHAR(80) NOT NULL,
        from_role VARCHAR(40) NULL,
        to_role VARCHAR(40) NULL,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_staff_audit_target (target_user_id, created_at),
        KEY idx_staff_audit_actor (actor_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_token VARCHAR(100) NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        email VARCHAR(190) NULL,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        stack_product_id BIGINT UNSIGNED NULL,
        stack_name VARCHAR(190) NULL,
        stack_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        product_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        advisor_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
        payment_reference VARCHAR(190) NULL,
        assessment_json JSON NULL,
        items_json JSON NULL,
        account_created_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_orders_token (order_token),
        KEY idx_orders_email (email),
        KEY idx_orders_payment_status (payment_status),
        CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_orders_stack_product FOREIGN KEY (stack_product_id) REFERENCES products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS member_plans (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        goal VARCHAR(190) NOT NULL,
        medication VARCHAR(190) NULL,
        dosage VARCHAR(190) NULL,
        package_name VARCHAR(190) NULL,
        workout_plan JSON NULL,
        meal_plan JSON NULL,
        vitamins JSON NULL,
        weekly_targets JSON NULL,
        reviewer_note TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'needs_review',
        focus TEXT NULL,
        nutrition TEXT NULL,
        activity TEXT NULL,
        sleep TEXT NULL,
        recovery TEXT NULL,
        milestones JSON NULL,
        categories JSON NULL,
        product_ids JSON NULL,
        flags JSON NULL,
        reviewer VARCHAR(190) NULL,
        reviewer_user_id BIGINT UNSIGNED NULL,
        reviewer_assigned_at DATETIME NULL,
        internal_reviewer_note TEXT NULL,
        requested_information TEXT NULL,
        next_check_in DATETIME NULL,
        version INT NOT NULL DEFAULT 1,
        reviewer_approved_at DATETIME NULL,
        released_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_member_plans_user (user_id),
        KEY idx_member_plans_status (status),
        CONSTRAINT fk_member_plans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_review_events (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        role ENUM('user','assistant','system') NOT NULL,
        message MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_chat_user_created (user_id, created_at),
        CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS member_plan_progress (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS member_weight_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        weight_lbs DECIMAL(7,2) NOT NULL,
        logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_weight_user_logged (user_id,logged_at),
        CONSTRAINT fk_weight_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS member_checkins (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS member_nutrition_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        protein_grams DECIMAL(7,1) NOT NULL DEFAULT 0,
        carbs_grams DECIMAL(7,1) NOT NULL DEFAULT 0,
        hydration_oz DECIMAL(7,1) NOT NULL DEFAULT 0,
        logged_on DATE NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_member_nutrition_day (user_id,logged_on),
        KEY idx_member_nutrition_user_day (user_id,logged_on),
        CONSTRAINT fk_member_nutrition_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $productColumns = [
        'standalone_price' => 'DECIMAL(10,2) NULL AFTER price',
        'annual_price' => 'DECIMAL(10,2) NULL AFTER standalone_price',
        'billing_interval' => "ENUM('one_time','month') NOT NULL DEFAULT 'month' AFTER standalone_price",
        'compound' => 'VARCHAR(255) NULL AFTER description',
        'usage_notice' => 'VARCHAR(255) NULL AFTER compound',
        'use_cases' => 'JSON NULL AFTER goal_tags',
        'tags' => 'JSON NULL AFTER billing_interval',
        'image_url' => 'VARCHAR(500) NULL AFTER dosage',
        'image_alt' => 'VARCHAR(255) NULL AFTER image_url',
        'sort_order' => 'INT NOT NULL DEFAULT 100',
    ];
    foreach ($productColumns as $column => $definition) database_add_column_if_missing('products', $column, $definition);

    $orderColumns = [
        'product_subtotal' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER stack_price',
        'items_json' => 'JSON NULL AFTER assessment_json',
        'shipping_address_json' => 'JSON NULL AFTER items_json',
        'advisor_billing_cycle' => "ENUM('month','year') NOT NULL DEFAULT 'month' AFTER shipping_address_json",
        'product_billing_cycles_json' => 'JSON NULL AFTER advisor_billing_cycle',
        'order_status' => "ENUM('new','processing','completed','cancelled') NOT NULL DEFAULT 'new' AFTER advisor_billing_cycle",
        'fulfillment_status' => "ENUM('unfulfilled','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'unfulfilled' AFTER order_status",
        'tracking_number' => 'VARCHAR(190) NULL AFTER fulfillment_status',
        'carrier' => 'VARCHAR(120) NULL AFTER tracking_number',
    ];
    foreach ($orderColumns as $column => $definition) database_add_column_if_missing('orders', $column, $definition);

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_subscriptions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaultProductUseCases = [
        'retatrutide' => ['weight loss','appetite reduction','hunger control','stored fat use','blood sugar balance'],
        'tirzepatide' => ['weight loss','appetite reduction','fat metabolism'],
        'semaglutide' => ['satiety','reduced food intake','steady weight loss','blood sugar support'],
        'bpc-157' => ['injury recovery','tendon healing','muscle healing','gut support','inflammation support'],
        'tb-500' => ['muscle tear recovery','soft tissue repair','stiffness reduction','mobility support','recovery'],
        'bacteriostatic-water' => ['peptide reconstitution','preparation'],
        'wolverine-stack' => ['joint recovery','tendon recovery','muscle recovery','gut support','recovery'],
        'klow-stack' => ['tendon repair','ligament repair','gut lining support','muscle recovery','flexibility','collagen support','skin quality','gut inflammation','systemic inflammation'],
        'glow-stack' => ['skin renewal','deep tissue renewal','collagen support','inflammation support','full-body recovery','skin quality'],
        'tesamorelin' => ['growth hormone support','belly fat reduction','lean muscle','mental focus','body composition'],
        'ipamorelin' => ['growth hormone support','better sleep','sleep quality','lean muscle','bone strength','body composition'],
        'cjc-1295-ipamorelin' => ['growth hormone support','body composition','recovery'],
        'epithalon' => ['cellular aging','dna protection','better sleep','sleep support','melatonin regulation','longevity'],
        'ghk-cu' => ['collagen support','skin tightening','wound healing','hair growth','anti-aging','skin quality'],
        'ots-c-mots-c' => ['cellular energy','energy production','insulin response','physical stress response','mitochondrial energy'],
        'nad-plus' => ['cellular energy','mental clarity','dna repair','cellular health','longevity'],
        'semax' => ['focus','memory','brain cell protection','cognitive enhancement','stroke recovery research'],
    ];
    $useCaseStmt = $pdo->prepare('UPDATE products SET use_cases = ? WHERE slug = ? AND COALESCE(JSON_LENGTH(use_cases), 0) = 0');
    foreach ($defaultProductUseCases as $slug => $useCases) {
        $useCaseStmt->execute([json_encode($useCases, JSON_UNESCAPED_SLASHES), $slug]);
    }

    $settingsColumns = [
        'brand_name' => "VARCHAR(190) NOT NULL DEFAULT 'Thrivel IQ'",
        'tagline' => 'VARCHAR(255) NULL',
        'logo_dark_url' => 'VARCHAR(500) NULL',
        'logo_light_url' => 'VARCHAR(500) NULL',
        'favicon_url' => 'VARCHAR(500) NULL',
        'hero_image_url' => 'VARCHAR(500) NULL',
        'auth_image_url' => 'VARCHAR(500) NULL',
        'checkout_image_url' => 'VARCHAR(500) NULL',
        'dashboard_image_url' => 'VARCHAR(500) NULL',
        'assessment_image_url' => 'VARCHAR(500) NULL',
        'default_product_image_url' => 'VARCHAR(500) NULL',
        'primary_color' => "VARCHAR(20) NOT NULL DEFAULT '#7AC7C8'",
        'gradient_mid_color' => "VARCHAR(20) NOT NULL DEFAULT '#9971B1'",
        'secondary_color' => "VARCHAR(20) NOT NULL DEFAULT '#EC437D'",
        'accent_color' => "VARCHAR(20) NOT NULL DEFAULT '#F4946E'",
        'background_color' => "VARCHAR(20) NOT NULL DEFAULT '#0A1133'",
        'panel_color' => "VARCHAR(20) NOT NULL DEFAULT '#101943'",
        'support_email' => 'VARCHAR(190) NULL',
        'footer_text' => 'VARCHAR(255) NULL',
        'login_headline' => 'VARCHAR(255) NULL',
        'login_subheadline' => 'TEXT NULL',
        'login_title' => 'VARCHAR(190) NULL',
        'login_description' => 'VARCHAR(255) NULL',
        'signup_headline' => 'VARCHAR(255) NULL',
        'signup_subheadline' => 'TEXT NULL',
        'signup_title' => 'VARCHAR(190) NULL',
        'signup_description' => 'VARCHAR(255) NULL',
        'account_title' => 'VARCHAR(190) NULL',
        'account_description' => 'VARCHAR(255) NULL',
        'checkout_title' => 'VARCHAR(255) NULL',
        'checkout_description' => 'TEXT NULL',
        'dashboard_title' => 'VARCHAR(255) NULL',
        'dashboard_description' => 'TEXT NULL',
    ];
    foreach ($settingsColumns as $column => $definition) database_add_column_if_missing('site_settings', $column, $definition);

    $userColumns = [
        'country' => 'VARCHAR(100) NULL',
        'state' => 'VARCHAR(100) NULL',
        'phone' => 'VARCHAR(40) NULL',
        'role' => "ENUM('customer','reviewer','admin') NOT NULL DEFAULT 'customer'",
        'verified' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'deactivated_at' => 'DATETIME NULL',
        'deactivated_by' => 'BIGINT UNSIGNED NULL',
        'plan_updates' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'reviewer_messages' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'marketing_emails' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'api_token_expires_at' => 'DATETIME NULL',
        'last_login_at' => 'DATETIME NULL',
    ];
    foreach ($userColumns as $column => $definition) database_add_column_if_missing('users', $column, $definition);
    // Expand staff roles once; avoid an ALTER TABLE on every request.
    if (!str_contains(database_column_type('users', 'role'), "'reviewer'")) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('customer','reviewer','admin') NOT NULL DEFAULT 'customer'");
    }

    database_add_column_if_missing('orders', 'first_name', 'VARCHAR(100) NULL AFTER email');
    database_add_column_if_missing('orders', 'last_name', 'VARCHAR(100) NULL AFTER first_name');

    $planColumns = [
        'status' => "VARCHAR(50) NOT NULL DEFAULT 'needs_review'",
        'focus' => 'TEXT NULL',
        'nutrition' => 'TEXT NULL',
        'activity' => 'TEXT NULL',
        'sleep' => 'TEXT NULL',
        'recovery' => 'TEXT NULL',
        'milestones' => 'JSON NULL',
        'categories' => 'JSON NULL',
        'product_ids' => 'JSON NULL',
        'flags' => 'JSON NULL',
        'reviewer' => 'VARCHAR(190) NULL',
        'reviewer_user_id' => 'BIGINT UNSIGNED NULL',
        'reviewer_assigned_at' => 'DATETIME NULL',
        'internal_reviewer_note' => 'TEXT NULL',
        'requested_information' => 'TEXT NULL',
        'member_response' => 'TEXT NULL',
        'member_response_at' => 'DATETIME NULL',
        'next_check_in' => 'DATETIME NULL',
        'version' => 'INT NOT NULL DEFAULT 1',
        'reviewer_approved_at' => 'DATETIME NULL',
        'released_at' => 'DATETIME NULL',
    ];
    foreach ($planColumns as $column => $definition) database_add_column_if_missing('member_plans', $column, $definition);

    database_add_column_if_missing('media', 'alt_text', 'VARCHAR(255) NULL');
    database_add_column_if_missing('media', 'size_bytes', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');

    $stmt = $pdo->prepare("INSERT IGNORE INTO site_settings (
        id,brand_name,tagline,logo_dark_url,logo_light_url,favicon_url,hero_image_url,auth_image_url,
        checkout_image_url,dashboard_image_url,assessment_image_url,default_product_image_url,
        primary_color,gradient_mid_color,secondary_color,accent_color,background_color,panel_color,
        support_email,footer_text,login_headline,login_subheadline,login_title,login_description,
        signup_headline,signup_subheadline,signup_title,signup_description,account_title,account_description,
        checkout_title,checkout_description,dashboard_title,dashboard_description
    ) VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        'Thrivel IQ',
        'Private, personalized, expert-reviewed wellness guidance.',
        '/uploads/defaults/logo-dark.svg',
        '/uploads/defaults/logo-light.svg',
        '/uploads/defaults/favicon.svg',
        '/uploads/defaults/hero-wellness.svg',
        '/uploads/defaults/auth-wellness.svg',
        '/uploads/defaults/checkout-stack.svg',
        '/uploads/defaults/dashboard-progress.svg',
        '/uploads/defaults/assessment-wellness.svg',
        '/uploads/defaults/product-default.svg',
        '#7AC7C8','#9971B1','#EC437D','#F4946E','#0A1133','#101943',
        'support@thriveliq.com',
        'Private, secure, and expert-reviewed.',
        'Welcome back to your personalized health dashboard',
        'Continue your plan, review your recommendations and track your progress.',
        'Log in',
        'Use the email and password created after checkout.',
        'Start your personalized wellness plan',
        'Complete the assessment and checkout before creating your account.',
        'Create your account after payment',
        'Your assessment, purchase and plan are linked during account creation.',
        'Create your member account',
        'This account will own your assessment, order and wellness plan.',
        'Choose your stack and advisor access',
        'Select a package or continue with advisor-only access. Account creation follows payment.',
        'Your wellness dashboard',
        'Your goal, plan, purchased package and reviewer-controlled details are shown here.',
    ]);

    // One-time catalogue refresh from the user-supplied Origin Labs styled pricing sheet.
    // It updates matching metadata and source descriptions once, then remains fully editable in Admin Products.
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (
        migration_key VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $catalogMigrationKey = '2026-08-10-origin-styled-catalog-v1';
    $migrationCheck = $pdo->prepare('SELECT COUNT(*) FROM app_migrations WHERE migration_key = ?');
    $migrationCheck->execute([$catalogMigrationKey]);
    if ((int)$migrationCheck->fetchColumn() === 0) {
        $originCatalog = [
        'retatrutide' => ['name' => 'Retatrutide', 'category' => 'Weight Loss & Metabolic', 'size' => '10mg / vial', 'price' => 100.00, 'description' => 'The strongest fat-loss peptide available. Curbs hunger, burns stored fat, and balances blood sugar — all at the same time. Think of it as a triple-action version of Ozempic.', 'goalTags' => ['Weight management'], 'useCases' => ['weight loss','appetite reduction','hunger control','stored fat use','blood sugar balance'], 'productType' => 'research', 'sortOrder' => 10],
        'tirzepatide' => ['name' => 'Tirzepatide', 'category' => 'Weight Loss & Metabolic', 'size' => '10mg / vial', 'price' => 150.00, 'description' => 'Reduces appetite and helps the body burn fat more efficiently. Works like the active ingredient in Mounjaro. One of the most effective weight-loss compounds studied.', 'goalTags' => ['Weight management'], 'useCases' => ['weight loss','appetite reduction','fat metabolism'], 'productType' => 'research', 'sortOrder' => 11],
        'semaglutide' => ['name' => 'Semaglutide', 'category' => 'Weight Loss & Metabolic', 'size' => '10mg / vial', 'price' => 200.00, 'description' => 'Tells your brain you\'re full so you eat less. Supports steady weight loss and healthier blood sugar. The same mechanism as Ozempic and Wegovy.', 'goalTags' => ['Weight management'], 'useCases' => ['satiety','reduced food intake','steady weight loss','blood sugar support'], 'productType' => 'research', 'sortOrder' => 12],
        'bpc-157' => ['name' => 'BPC-157', 'category' => 'Recovery & Healing', 'size' => '10mg / vial', 'price' => 65.00, 'description' => 'Speeds up healing from injuries — tendons, muscles, gut issues, and inflammation. One of the most studied healing compounds available.', 'goalTags' => ['Recovery & healing'], 'useCases' => ['injury recovery','tendon healing','muscle healing','gut support','inflammation support'], 'productType' => 'research', 'sortOrder' => 20],
        'tb-500' => ['name' => 'TB-500', 'category' => 'Recovery & Healing', 'size' => '10mg / vial', 'price' => 65.00, 'description' => 'Helps repair muscle tears and soft tissue injuries faster. Reduces stiffness and gets you back to full movement sooner. Works great alongside BPC-157.', 'goalTags' => ['Recovery & healing'], 'useCases' => ['muscle tear recovery','soft tissue repair','stiffness reduction','mobility support','recovery'], 'productType' => 'research', 'sortOrder' => 21],
        'bacteriostatic-water' => ['name' => 'Bacteriostatic Water', 'category' => 'Recovery & Healing', 'size' => '10ml / vial', 'price' => 15.00, 'description' => 'The sterile water used to mix peptide powders before use. Required for proper preparation.', 'goalTags' => [], 'useCases' => ['peptide reconstitution','preparation'], 'productType' => 'solution', 'sortOrder' => 22],
        'wolverine-stack' => ['name' => 'Wolverine Stack', 'category' => 'Recovery Stacks — Pre-Mixed Combinations', 'size' => '20mg / vial', 'price' => 130.00, 'description' => 'The ultimate healing combo. Covers every major recovery pathway — joints, tendons, muscles, and gut.', 'goalTags' => ['Recovery & healing'], 'useCases' => ['joint recovery','tendon recovery','muscle recovery','gut support','recovery'], 'productType' => 'stack', 'sortOrder' => 23],
        'klow-stack' => ['name' => 'KLOW Stack', 'category' => 'Recovery Stacks — Pre-Mixed Combinations', 'size' => '80mg / vial', 'price' => 125.00, 'description' => 'Four healing compounds targeting four repair pathways at once: BPC-157 heals tendons, ligaments, and gut lining; TB-500 repairs muscle tears and improves flexibility; GHK-Cu rebuilds collagen, tightens skin, and activates over 1,000 repair genes; KPV directly calms gut and systemic inflammation at the cellular level. The most comprehensive healing stack in the lineup.', 'goalTags' => ['Recovery & healing'], 'useCases' => ['tendon repair','ligament repair','gut lining support','muscle recovery','flexibility','collagen support','skin quality','gut inflammation','systemic inflammation'], 'productType' => 'stack', 'sortOrder' => 24],
        'glow-stack' => ['name' => 'GLOW Stack', 'category' => 'Recovery Stacks — Pre-Mixed Combinations', 'size' => '70mg / vial', 'price' => 110.00, 'description' => 'A three-compound stack focused on skin and deep tissue renewal. Boosts collagen, reduces inflammation, and supports full-body repair. A great starting point before stepping up to KLOW.', 'goalTags' => ['Recovery & healing'], 'useCases' => ['skin renewal','deep tissue renewal','collagen support','inflammation support','full-body recovery','skin quality'], 'productType' => 'stack', 'sortOrder' => 25],
        'tesamorelin' => ['name' => 'Tesamorelin', 'category' => 'Growth Hormone & Body Composition', 'size' => '10mg / vial', 'price' => 60.00, 'description' => 'Tells your body to produce more of its own growth hormone naturally. Helps reduce belly fat, build lean muscle, and sharpen mental focus.', 'goalTags' => ['Weight management','Performance'], 'useCases' => ['growth hormone support','belly fat reduction','lean muscle','mental focus','body composition'], 'productType' => 'research', 'sortOrder' => 30],
        'ipamorelin' => ['name' => 'Ipamorelin', 'category' => 'Growth Hormone & Body Composition', 'size' => '10mg / vial', 'price' => 60.00, 'description' => 'Triggers a clean burst of growth hormone with no unwanted side effects. Supports better sleep, lean muscle, and stronger bones.', 'goalTags' => ['Performance'], 'useCases' => ['growth hormone support','better sleep','sleep quality','lean muscle','bone strength','body composition'], 'productType' => 'research', 'sortOrder' => 31],
        'cjc-1295-ipamorelin' => ['name' => 'CJC-1295 + Ipamorelin', 'category' => 'Growth Hormone & Body Composition', 'size' => '10mg / vial', 'price' => 115.00, 'description' => 'Two growth hormone boosters that work together for a stronger, longer-lasting effect than either alone. A popular combination for body composition and recovery.', 'goalTags' => ['Performance'], 'useCases' => ['growth hormone support','body composition','recovery'], 'productType' => 'stack', 'sortOrder' => 32],
        'epithalon' => ['name' => 'Epithalon', 'category' => 'Anti-Aging & Longevity', 'size' => '50mg / vial', 'price' => 65.00, 'description' => 'Studied for slowing cellular aging. Works by protecting the ends of your DNA — the same structures that shorten as we get older. Also supports better sleep through melatonin regulation.', 'goalTags' => ['Longevity'], 'useCases' => ['cellular aging','dna protection','better sleep','sleep support','melatonin regulation','longevity'], 'productType' => 'research', 'sortOrder' => 40],
        'ghk-cu' => ['name' => 'GHK-Cu', 'category' => 'Anti-Aging & Longevity', 'size' => '100mg / vial', 'price' => 60.00, 'description' => 'A copper peptide that rebuilds collagen, tightens skin, speeds wound healing, and stimulates hair growth. Naturally declines with age — one of the most researched anti-aging compounds.', 'goalTags' => ['Longevity'], 'useCases' => ['collagen support','skin tightening','wound healing','hair growth','anti-aging','skin quality'], 'productType' => 'research', 'sortOrder' => 41],
        'ots-c-mots-c' => ['name' => 'MOTS-c', 'category' => 'Anti-Aging & Longevity', 'size' => '40mg / vial', 'price' => 95.00, 'description' => 'Supports energy production at the cellular level. Helps the body respond better to insulin and handle physical stress. Produced naturally inside your mitochondria.', 'goalTags' => ['Energy','Performance'], 'useCases' => ['cellular energy','energy production','insulin response','physical stress response','mitochondrial energy'], 'productType' => 'research', 'sortOrder' => 42],
        'nad-plus' => ['name' => 'NAD+', 'category' => 'Anti-Aging & Longevity', 'size' => '1000mg / vial', 'price' => 95.00, 'description' => 'The energy molecule your cells run on — and it drops by half by middle age. Restoring it supports mental clarity, DNA repair, and overall cellular health.', 'goalTags' => ['Energy','Longevity'], 'useCases' => ['cellular energy','mental clarity','dna repair','cellular health','longevity'], 'productType' => 'research', 'sortOrder' => 43],
        'semax' => ['name' => 'Semax', 'category' => 'Brain & Focus', 'size' => '10mg / vial', 'price' => 55.00, 'description' => 'Sharpens focus, improves memory, and protects brain cells. Works by boosting the brain\'s own growth factor (BDNF). Originally developed for cognitive enhancement and stroke recovery.', 'goalTags' => ['Performance'], 'useCases' => ['focus','memory','brain cell protection','cognitive enhancement','stroke recovery research'], 'productType' => 'research', 'sortOrder' => 50],
        ];
        $catalogUpsert = $pdo->prepare("INSERT INTO products (
            slug,name,category,size_label,price,description,usage_notice,goal_tags,use_cases,product_type,active,image_url,image_alt,sort_order
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            name=VALUES(name), category=VALUES(category), size_label=VALUES(size_label), price=VALUES(price),
            description=VALUES(description), usage_notice=VALUES(usage_notice), goal_tags=VALUES(goal_tags),
            use_cases=VALUES(use_cases), product_type=VALUES(product_type), active=1, sort_order=VALUES(sort_order)");
        foreach ($originCatalog as $slug => $product) {
            $catalogUpsert->execute([
                $slug, $product['name'], $product['category'], $product['size'], $product['price'], $product['description'],
                'FOR RESEARCH USE ONLY - NOT FOR HUMAN OR ANIMAL CONSUMPTION',
                json_encode($product['goalTags'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($product['useCases'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $product['productType'], 1, '/uploads/defaults/product-default.svg', $product['name'], $product['sortOrder']
            ]);
        }
        $migrationDone = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)');
        $migrationDone->execute([$catalogMigrationKey]);
    }

    database_add_column_if_missing('advisor_subscriptions', 'billing_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_price');
    database_add_column_if_missing('advisor_subscriptions', 'billing_cycle', "ENUM('month','year') NOT NULL DEFAULT 'month' AFTER billing_price");

    $finalDemoMigration = '2026-08-14-final-demo-hotfix-v1';
    $migrationCheck->execute([$finalDemoMigration]);
    if ((int)$migrationCheck->fetchColumn() === 0) {
        $updates = [
            ['retatrutide','Ascend','Weight Loss & Metabolic','Triple Agonist — GLP-1 / GIP / Glucagon',100.00,'10mg / vial',['injectable','peptide']],
            ['semaglutide','Momentum','Weight Loss & Metabolic','GLP-1 Receptor Agonist',125.00,'10mg / vial',['injectable','peptide']],
            ['tirzepatide','Catalyst','Weight Loss & Metabolic','Dual GIP / GLP-1 Agonist',150.00,'10mg / vial',['injectable','peptide']],
            ['bpc-157','Restore','Recovery & Healing','Body Protective Compound',65.00,'10mg / vial',['injectable','peptide']],
            ['tb-500','Rebound','Recovery & Healing','Thymosin Beta-4 Fragment',65.00,'10mg / vial',['injectable','peptide']],
            ['ghk-cu','Radiance','Recovery & Healing','Copper Peptide · Tripeptide-1',60.00,'100mg / vial',['injectable','peptide']],
            ['wolverine-stack','Reforge Stack','Recovery & Healing','Complete Recovery Stack',130.00,'20mg / vial',['injectable','peptide','stack']],
            ['glow-stack','Lumina Stack','Recovery & Healing','GHK-Cu / BPC-157 / TB-500',110.00,'70mg / vial',['injectable','peptide','stack']],
            ['tesamorelin','Elevate','Growth Hormone Support','GHRH Analogue',60.00,'10mg / vial',['injectable','peptide']],
            ['ipamorelin','Ignite','Growth Hormone Support','GH Secretagogue',60.00,'10mg / vial',['injectable','peptide']],
            ['cjc-1295-ipamorelin','Synergy Stack','Growth Hormone Support','GH Secretagogue Stack (No DAC)',115.00,'10mg / vial',['injectable','peptide','stack']],
            ['ots-c-mots-c','Revive','Longevity & Wellness','Mitochondrial-Derived Peptide',95.00,'40mg / vial',['injectable','peptide']],
            ['nad-plus','Recharge','Longevity & Wellness','Nicotinamide Adenine Dinucleotide',95.00,'1000mg / vial',['vitamin','injectable']],
        ];
        $brandUpdate = $pdo->prepare("UPDATE products SET name=?,category=?,compound=?,price=?,size_label=?,billing_interval='month',tags=?,image_alt=? WHERE slug=?");
        foreach ($updates as $item) $brandUpdate->execute([$item[1],$item[2],$item[3],$item[4],$item[5],json_encode($item[6]),$item[1],$item[0]]);
        $fortify = $pdo->prepare("INSERT INTO products (slug,name,category,size_label,price,standalone_price,billing_interval,tags,description,compound,usage_notice,goal_tags,use_cases,product_type,active,image_url,image_alt,sort_order) VALUES ('thymosin-alpha-1','Fortify','Recovery & Healing','10mg / vial',70.00,NULL,'month',?,'Built for resilience.','Immune Modulator','',?,?, 'research',1,'/uploads/defaults/product-default.svg','Fortify',26) ON DUPLICATE KEY UPDATE name='Fortify',category='Recovery & Healing',size_label='10mg / vial',price=70.00,billing_interval='month',tags=VALUES(tags),description='Built for resilience.',compound='Immune Modulator',usage_notice='',goal_tags=VALUES(goal_tags),use_cases=VALUES(use_cases),active=1,image_alt='Fortify'");
        $fortify->execute([json_encode(['injectable','peptide']), json_encode(['Recovery & healing','Longevity']), json_encode(['immune support','resilience','recovery'])]);
        $pdo->exec("UPDATE products SET billing_interval='month' WHERE product_type <> 'service'");
        $pdo->exec("UPDATE products SET usage_notice='' WHERE slug='wolverine-stack'");
        $pdo->exec("UPDATE products SET price=19.99,standalone_price=19.99,annual_price=COALESCE(annual_price,99.00),billing_interval='month',size_label='Monthly or annual subscription' WHERE slug='ai-health-advisor'");
        $pdo->exec("UPDATE member_plans SET workout_plan=JSON_ARRAY('Day 1: Bench press 3×8, seated row 3×10, lateral raise 3×12','Day 3: Squat or leg press 3×8-10, Romanian deadlift 3×10, leg curl 3×12','Day 5: Incline dumbbell press 3×10, lat pulldown 3×10, walking lunges 3×10/leg','Two zone-2 cardio sessions and one mobility/recovery session'), activity='Three specific strength sessions plus two zone-2 cardio sessions and one mobility/recovery session.', version=version+1 WHERE workout_plan IS NULL OR CAST(workout_plan AS CHAR) LIKE '%strength session%' OR CAST(workout_plan AS CHAR) LIKE '%structured training%'");
        $migrationDone = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)');
        $migrationDone->execute([$finalDemoMigration]);
    }

    $subscriptionBackfillMigration = '2026-08-14-product-subscription-backfill-v1';
    $migrationCheck->execute([$subscriptionBackfillMigration]);
    if ((int)$migrationCheck->fetchColumn() === 0) {
        $orderRows = $pdo->query("SELECT * FROM orders WHERE user_id IS NOT NULL AND payment_status='paid' AND items_json IS NOT NULL ORDER BY id ASC")->fetchAll();
        foreach ($orderRows as $orderRow) ensure_product_subscriptions_for_order((int)$orderRow['user_id'], $orderRow);
        // Bundled AI Health Coach access is free while any product subscription is active.
        $pdo->exec("UPDATE advisor_subscriptions a SET a.monthly_price=0,a.billing_price=0,a.pending_paid_conversion=0 WHERE EXISTS (SELECT 1 FROM product_subscriptions ps WHERE ps.user_id=a.user_id AND ps.status IN ('active','cancel_at_period_end') AND ps.current_period_end > NOW())");
        $migrationDone = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)');
        $migrationDone->execute([$subscriptionBackfillMigration]);
    }

    $coachRenameMigration = '2026-08-13-ai-health-coach-rename-v1';
    $migrationCheck->execute([$coachRenameMigration]);
    if ((int)$migrationCheck->fetchColumn() === 0) {
        $renameCoach = $pdo->prepare("UPDATE products SET name='AI Health Coach', image_alt='AI Health Coach', description=REPLACE(description, 'AI wellness advisor', 'AI wellness coach') WHERE slug='ai-health-advisor'");
        $renameCoach->execute();
        $migrationDone = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)');
        $migrationDone->execute([$coachRenameMigration]);
    }

    $ready = true;
}

function json_input(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(['message' => 'Invalid JSON body.'], 400);
    return $data;
}

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_origin(string $origin): string { return rtrim(trim($origin), '/'); }

function cors_allowed_origins(): array {
    $configured = explode(',', (string)(env_value('FRONTEND_ORIGIN', '') ?? ''));
    $configured[] = 'https://staging.thrivelid.com';
    $configured[] = 'https://thrivel-frontend.vercel.app';
    $allowed = [];
    foreach ($configured as $origin) {
        $origin = normalize_origin((string)$origin);
        if ($origin !== '') $allowed[$origin] = true;
    }
    return array_keys($allowed);
}

function cors_origin_is_allowed(string $origin): bool {
    $origin = normalize_origin($origin);
    if ($origin === '') return false;
    if (in_array($origin, cors_allowed_origins(), true)) return true;
    if (preg_match('#^https?://(?:localhost|127\\.0\\.0\\.1)(?::\\d+)?$#i', $origin)) return true;
    return (bool)preg_match('#^https://thrivel-frontend(?:-[a-z0-9-]+)*\\.vercel\\.app$#i', $origin);
}

function cors(): void {
    $origin = normalize_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && cors_origin_is_allowed($origin)) {
        header("Access-Control-Allow-Origin: {$origin}", true);
        header('Vary: Origin', false);
    }
    $requestedHeaders = trim((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? ''));
    $allowedHeaders = 'Content-Type, Authorization, X-Admin-Key, Accept, Origin';
    if ($requestedHeaders !== '') $allowedHeaders .= ', ' . $requestedHeaders;
    header("Access-Control-Allow-Headers: {$allowedHeaders}", true);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS', true);
    header('Access-Control-Max-Age: 86400', true);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        header('Content-Length: 0');
        header('Cache-Control: no-store');
        exit;
    }
}

function request_header(string $name): string {
    $normalized = strtoupper(str_replace('-', '_', $name));
    foreach (["HTTP_{$normalized}", "REDIRECT_HTTP_{$normalized}", $normalized] as $serverKey) {
        if (isset($_SERVER[$serverKey]) && trim((string)$_SERVER[$serverKey]) !== '') return trim((string)$_SERVER[$serverKey]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $headerName => $value) {
            if (strcasecmp((string)$headerName, $name) === 0) return trim((string)$value);
        }
    }
    return '';
}

function admin_key_from_request(): string {
    $provided = request_header('X-Admin-Key');
    if ($provided !== '') return $provided;
    $authorization = request_header('Authorization');
    if (preg_match('/^Admin\s+(.+)$/i', $authorization, $matches)) return trim((string)$matches[1]);
    return '';
}

function bearer_token(): string {
    $header = request_header('Authorization');
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return '';
    return trim((string)$m[1]);
}

function find_user_by_bearer_token(): ?array {
    $token = bearer_token();
    if ($token === '') return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE api_token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
    if (!$user) return null;
    if (array_key_exists('is_active', $user) && !(bool)$user['is_active']) {
        $clear = db()->prepare('UPDATE users SET api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $clear->execute([(int)$user['id']]);
        return null;
    }
    $expiresAt = trim((string)($user['api_token_expires_at'] ?? ''));
    if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= time()) {
        $clear = db()->prepare('UPDATE users SET api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $clear->execute([(int)$user['id']]);
        return null;
    }
    return $user;
}

function require_user(): array {
    if (bearer_token() === '') respond(['message' => 'Authentication required.'], 401);
    $user = find_user_by_bearer_token();
    if (!$user) respond(['message' => 'Your session has expired. Log in again.'], 401);
    return $user;
}

function require_admin(): array {
    $user = find_user_by_bearer_token();
    if ($user) {
        if (($user['role'] ?? 'customer') !== 'admin') respond(['message' => 'Administrator access is required.'], 403);
        return $user;
    }

    // Emergency server-to-server access only. The browser UI never asks for this key.
    $provided = trim(admin_key_from_request());
    $expected = trim((string)(env_value('ADMIN_API_KEY', '') ?? ''));
    if ($provided !== '' && $expected !== '' && $expected !== 'REPLACE_WITH_A_LONG_RANDOM_KEY' && hash_equals($expected, $provided)) {
        return ['id' => 0, 'email' => 'emergency-key', 'first_name' => 'Emergency', 'last_name' => 'Access', 'role' => 'admin'];
    }

    if (bearer_token() !== '') respond(['message' => 'Your admin session has expired. Log in again.'], 401);
    respond(['message' => 'Administrator login required.'], 401);
}

function require_staff(): array {
    $user = require_user();
    if (!in_array((string)($user['role'] ?? 'customer'), ['admin', 'reviewer'], true)) {
        respond(['message' => 'Reviewer or administrator access is required.'], 403);
    }
    return $user;
}

function staff_display_name(array $user): string {
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return $name !== '' ? $name : (string)($user['email'] ?? 'Reviewer');
}

function add_plan_review_event(int $planId, array $actor, string $action, ?string $fromStatus = null, ?string $toStatus = null, string $note = ''): void {
    if (!database_table_exists('plan_review_events')) return;
    $stmt = db()->prepare('INSERT INTO plan_review_events (plan_id,actor_user_id,actor_name,actor_role,action,from_status,to_status,note) VALUES (?,?,?,?,?,?,?,?)');
    $actorId = (int)($actor['id'] ?? 0);
    $stmt->execute([
        $planId,
        $actorId > 0 ? $actorId : null,
        staff_display_name($actor),
        (string)($actor['role'] ?? 'admin'),
        $action,
        $fromStatus,
        $toStatus,
        trim($note),
    ]);
}

function issue_user_token(int $userId, bool $remember = true): array {
    $token = random_token();
    $hours = $remember ? 24 * 30 : 12;
    $expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));
    $stmt = db()->prepare('UPDATE users SET api_token_hash=?,api_token_expires_at=?,last_login_at=NOW() WHERE id=?');
    $stmt->execute([hash('sha256', $token), $expiresAt, $userId]);
    return ['token' => $token, 'expiresAt' => $expiresAt];
}

function admin_setup_required(): bool {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() === 0;
}

function admin_setup_token_configured(): bool {
    $token = trim((string)(env_value('ADMIN_SETUP_TOKEN', '') ?? ''));
    return $token !== '' && $token !== 'REPLACE_WITH_A_ONE_TIME_SETUP_TOKEN';
}

function absolute_url(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('#^https?://#i', $value)) return $value;
    return rtrim(env_value('APP_URL', '') ?? '', '/') . '/' . ltrim($value, '/');
}

function decode_listish(mixed $value): array {
    if (is_array($value)) $items = $value;
    else {
        $raw = trim((string)$value);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : preg_split('/\\n|\r?\n|,/', $raw);
    }
    $out = [];
    foreach ($items as $item) {
        if (!is_scalar($item)) continue;
        foreach (preg_split('/\\n|\r?\n/', (string)$item) ?: [] as $piece) {
            $piece = trim($piece);
            if ($piece !== '') $out[] = $piece;
        }
    }
    return array_values(array_unique($out));
}

function annual_billing_price(float $monthlyPrice): float {
    return round($monthlyPrice * 12 * 0.83, 2);
}

function product_payload(array $row): array {
    return [
        'id' => (string)$row['slug'],
        'name' => (string)$row['name'],
        'category' => (string)$row['category'],
        'size' => (string)$row['size_label'],
        'price' => (float)$row['price'],
        'standalonePrice' => $row['standalone_price'] !== null ? (float)$row['standalone_price'] : null,
        'annualPrice' => annual_billing_price((float)$row['price']),
        'billingInterval' => 'month',
        'tags' => decode_listish($row['tags'] ?? null),
        'description' => (string)($row['description'] ?? ''),
        'compound' => (string)($row['compound'] ?? ''),
        'usageNotice' => (string)($row['usage_notice'] ?? ''),
        'goalTags' => decode_listish($row['goal_tags'] ?? null),
        'useCases' => decode_listish($row['use_cases'] ?? null),
        'productType' => (string)$row['product_type'],
        'active' => (bool)$row['active'],
        'medication' => (string)($row['medication'] ?? ''),
        'dosage' => (string)($row['dosage'] ?? ''),
        'sortOrder' => (int)($row['sort_order'] ?? 100),
        'imageUrl' => absolute_url($row['image_url'] ?? ''),
        'imageAlt' => (string)($row['image_alt'] ?? ''),
    ];
}

function settings_payload(array $row): array {
    return [
        'brandName' => $row['brand_name'] ?? 'Thrivel IQ',
        'tagline' => $row['tagline'] ?? '',
        'logoDarkUrl' => absolute_url($row['logo_dark_url'] ?? ''),
        'logoLightUrl' => absolute_url($row['logo_light_url'] ?? ''),
        'faviconUrl' => absolute_url($row['favicon_url'] ?? ''),
        'heroImageUrl' => absolute_url($row['hero_image_url'] ?? ''),
        'authImageUrl' => absolute_url($row['auth_image_url'] ?? ''),
        'checkoutImageUrl' => absolute_url($row['checkout_image_url'] ?? ''),
        'dashboardImageUrl' => absolute_url($row['dashboard_image_url'] ?? ''),
        'assessmentImageUrl' => absolute_url($row['assessment_image_url'] ?? ''),
        'defaultProductImageUrl' => absolute_url($row['default_product_image_url'] ?? ''),
        'primaryColor' => $row['primary_color'] ?? '#7AC7C8',
        'gradientMidColor' => $row['gradient_mid_color'] ?? '#9971B1',
        'secondaryColor' => $row['secondary_color'] ?? '#EC437D',
        'accentColor' => $row['accent_color'] ?? '#F4946E',
        'backgroundColor' => $row['background_color'] ?? '#0A1133',
        'panelColor' => $row['panel_color'] ?? '#101943',
        'supportEmail' => $row['support_email'] ?? '',
        'footerText' => $row['footer_text'] ?? '',
        'loginHeadline' => $row['login_headline'] ?? 'Welcome back to your personalized health dashboard',
        'loginSubheadline' => $row['login_subheadline'] ?? '',
        'loginTitle' => $row['login_title'] ?? 'Log in',
        'loginDescription' => $row['login_description'] ?? '',
        'signupHeadline' => $row['signup_headline'] ?? 'Start your personalized wellness plan',
        'signupSubheadline' => $row['signup_subheadline'] ?? '',
        'signupTitle' => $row['signup_title'] ?? 'Create your account after payment',
        'signupDescription' => $row['signup_description'] ?? '',
        'accountTitle' => $row['account_title'] ?? 'Create your member account',
        'accountDescription' => $row['account_description'] ?? '',
        'checkoutTitle' => $row['checkout_title'] ?? 'Choose your stack and advisor access',
        'checkoutDescription' => $row['checkout_description'] ?? '',
        'dashboardTitle' => $row['dashboard_title'] ?? 'Your wellness dashboard',
        'dashboardDescription' => $row['dashboard_description'] ?? '',
    ];
}

function media_payload(array $row): array {
    return [
        'id' => (string)$row['id'],
        'fileName' => (string)$row['file_name'],
        'originalName' => (string)$row['original_name'],
        'mimeType' => (string)$row['mime_type'],
        'url' => absolute_url($row['url'] ?? ''),
        'altText' => (string)($row['alt_text'] ?? ''),
        'sizeBytes' => (int)($row['size_bytes'] ?? 0),
        'createdAt' => (string)$row['created_at'],
    ];
}

function user_payload(array $row): array {
    return [
        'id' => (string)$row['id'],
        'email' => (string)$row['email'],
        'firstName' => (string)$row['first_name'],
        'lastName' => (string)$row['last_name'],
        'country' => (string)($row['country'] ?? ''),
        'state' => (string)($row['state'] ?? ''),
        'phone' => (string)($row['phone'] ?? ''),
        'role' => (string)($row['role'] ?? 'customer'),
        'verified' => (bool)($row['verified'] ?? true),
        'active' => !array_key_exists('is_active', $row) || (bool)$row['is_active'],
        'deactivatedAt' => !empty($row['deactivated_at']) ? (string)$row['deactivated_at'] : null,
        'lastLoginAt' => !empty($row['last_login_at']) ? (string)$row['last_login_at'] : null,
        'notifications' => [
            'planUpdates' => (bool)($row['plan_updates'] ?? true),
            'reviewerMessages' => (bool)($row['reviewer_messages'] ?? true),
            'marketingEmails' => (bool)($row['marketing_emails'] ?? false),
        ],
        'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)($row['updated_at'] ?? $row['created_at']),
    ];
}

function order_payload(array $row): array {
    $items = decode_json_array($row['items_json'] ?? null);
    if (!$items && !empty($row['stack_slug'])) {
        $items = [[
            'id' => (string)$row['stack_slug'],
            'name' => (string)($row['stack_name'] ?? ''),
            'category' => 'Checkout package',
            'size' => '',
            'price' => (float)($row['stack_price'] ?? 0),
            'imageUrl' => '',
        ]];
    }
    $items = array_values(array_map(static function (array $item): array {
        return [
            'id' => (string)($item['id'] ?? ''),
            'name' => (string)($item['name'] ?? ''),
            'category' => (string)($item['category'] ?? ''),
            'size' => (string)($item['size'] ?? ''),
            'price' => (float)($item['price'] ?? 0),
            'billingCycle' => (($item['billingCycle'] ?? 'month') === 'year') ? 'year' : 'month',
            'imageUrl' => absolute_url((string)($item['imageUrl'] ?? '')),
        ];
    }, array_filter($items, 'is_array')));
    $productSubtotal = isset($row['product_subtotal']) ? (float)$row['product_subtotal'] : (float)($row['stack_price'] ?? 0);
    return [
        'id' => 'order_' . (string)$row['id'],
        'token' => (string)$row['order_token'],
        'email' => (string)($row['email'] ?? ''),
        'firstName' => (string)($row['first_name'] ?? ''),
        'lastName' => (string)($row['last_name'] ?? ''),
        'items' => $items,
        'selectedProductIds' => array_values(array_filter(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $items))),
        'productSubtotal' => $productSubtotal,
        'stackProductId' => $row['stack_slug'] ?? null,
        'stackName' => $row['stack_name'] ?? null,
        'stackPrice' => (float)($row['stack_price'] ?? $productSubtotal),
        'advisorPrice' => (float)$row['advisor_price'],
        'total' => (float)$row['total'],
        'paymentStatus' => (string)$row['payment_status'],
        'accountCreated' => !empty($row['account_created_at']) || !empty($row['user_id']),
        'shippingAddress' => (is_array(json_decode((string)($row['shipping_address_json'] ?? ''), true)) ? json_decode((string)$row['shipping_address_json'], true) : null),
        'advisorBillingCycle' => (string)($row['advisor_billing_cycle'] ?? 'month'),
        'orderStatus' => (string)($row['order_status'] ?? 'new'),
        'fulfillmentStatus' => (string)($row['fulfillment_status'] ?? 'unfulfilled'),
        'trackingNumber' => (string)($row['tracking_number'] ?? ''),
        'carrier' => (string)($row['carrier'] ?? ''),
        'createdAt' => (string)$row['created_at'],
    ];
}

function product_subscription_payload(array $row): array {
    return [
        'id' => (string)$row['id'],
        'productId' => (string)$row['product_slug'],
        'productName' => (string)$row['product_name'],
        'status' => (string)$row['status'],
        'billingCycle' => (string)$row['billing_cycle'],
        'billingPrice' => (float)$row['billing_price'],
        'currentPeriodStart' => (string)$row['current_period_start'],
        'currentPeriodEnd' => (string)$row['current_period_end'],
        'cancelAtPeriodEnd' => (bool)$row['cancel_at_period_end'],
    ];
}

function active_product_subscriptions_for_user(int $userId): array {
    if (!database_table_exists('product_subscriptions')) return [];
    $stmt = db()->prepare("SELECT * FROM product_subscriptions WHERE user_id=? AND status IN ('active','cancel_at_period_end') AND current_period_end > NOW() ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function member_has_active_product_subscription(int $userId): bool {
    return count(active_product_subscriptions_for_user($userId)) > 0;
}

function ensure_product_subscriptions_for_order(int $userId, array $order): array {
    if ((string)($order['payment_status'] ?? '') !== 'paid') return [];
    $items = decode_json_array($order['items_json'] ?? null);
    if (!$items) return [];
    $billingCycles = [];
    $rawCycles = json_decode((string)($order['product_billing_cycles_json'] ?? '{}'), true);
    if (is_array($rawCycles)) $billingCycles = $rawCycles;
    $created = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $slug = trim((string)($item['id'] ?? ''));
        if ($slug === '') continue;
        $check = db()->prepare("SELECT id FROM product_subscriptions WHERE user_id=? AND source_order_id=? AND product_slug=? LIMIT 1");
        $check->execute([$userId, (int)$order['id'], $slug]);
        if ($check->fetch()) continue;
        $cycle = (($billingCycles[$slug] ?? $item['billingCycle'] ?? 'month') === 'year') ? 'year' : 'month';
        $price = (float)($item['price'] ?? 0);
        $start = new DateTimeImmutable('now');
        $end = $start->modify($cycle === 'year' ? '+1 year' : '+1 month');
        $stmt = db()->prepare("INSERT INTO product_subscriptions (user_id,source_order_id,product_slug,product_name,status,billing_cycle,billing_price,current_period_start,current_period_end) VALUES (?,?,?,?, 'active', ?,?,?,?)");
        $stmt->execute([$userId,(int)$order['id'],$slug,(string)($item['name'] ?? $slug),$cycle,$price,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
        $created[] = (int)db()->lastInsertId();
    }
    return $created;
}

function decode_json_array(mixed $value): array {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function plan_payload(array $row): array {
    return [
        'id' => (string)$row['id'],
        'userId' => (string)$row['user_id'],
        'goal' => (string)$row['goal'],
        'medication' => (string)($row['medication'] ?? 'No medication selected'),
        'dosage' => (string)($row['dosage'] ?? 'Not applicable'),
        'packageName' => (string)($row['package_name'] ?? 'AI Health Coach only'),
        'workoutPlan' => decode_json_array($row['workout_plan'] ?? null),
        'mealPlan' => decode_json_array($row['meal_plan'] ?? null),
        'vitamins' => decode_json_array($row['vitamins'] ?? null),
        'weeklyTargets' => decode_json_array($row['weekly_targets'] ?? null),
        'reviewerNote' => (string)($row['reviewer_note'] ?? ''),
        'status' => (string)($row['status'] ?? 'needs_review'),
        'focus' => (string)($row['focus'] ?? ''),
        'nutrition' => (string)($row['nutrition'] ?? ''),
        'activity' => (string)($row['activity'] ?? ''),
        'sleep' => (string)($row['sleep'] ?? ''),
        'recovery' => (string)($row['recovery'] ?? ''),
        'milestones' => decode_json_array($row['milestones'] ?? null),
        'categories' => decode_json_array($row['categories'] ?? null),
        'productIds' => decode_json_array($row['product_ids'] ?? null),
        'flags' => decode_json_array($row['flags'] ?? null),
        'reviewer' => (string)($row['reviewer'] ?? ''),
        'reviewerUserId' => !empty($row['reviewer_user_id']) ? (string)$row['reviewer_user_id'] : null,
        'reviewerAssignedAt' => $row['reviewer_assigned_at'] ?? null,
        'internalReviewerNote' => (string)($row['internal_reviewer_note'] ?? ''),
        'requestedInformation' => (string)($row['requested_information'] ?? ''),
        'memberResponse' => (string)($row['member_response'] ?? ''),
        'memberResponseAt' => $row['member_response_at'] ?? null,
        'nextCheckIn' => $row['next_check_in'] ?? null,
        'version' => (int)($row['version'] ?? 1),
        'reviewerApprovedAt' => $row['reviewer_approved_at'] ?? null,
        'releasedAt' => $row['released_at'] ?? null,
        'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)$row['updated_at'],
    ];
}

function member_plan_payload(array $row): array {
    $plan = plan_payload($row);
    if (($plan['status'] ?? 'needs_review') !== 'released') {
        $plan['medication'] = 'Reviewer pending';
        $plan['dosage'] = 'Reviewer pending';
        $plan['workoutPlan'] = [];
        $plan['mealPlan'] = [];
        $plan['vitamins'] = [];
        $plan['weeklyTargets'] = [];
        $plan['focus'] = '';
        $plan['nutrition'] = '';
        $plan['activity'] = '';
        $plan['sleep'] = '';
        $plan['recovery'] = '';
        $plan['milestones'] = [];
        $plan['reviewerNote'] = ($plan['status'] ?? '') === 'needs_information'
            ? 'The reviewer needs more information before publishing your plan.'
            : 'Your plan is waiting for reviewer publication.';
        if (($plan['status'] ?? '') !== 'needs_information') $plan['requestedInformation'] = '';
        $plan['internalReviewerNote'] = '';
    }
    return $plan;
}

function random_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }

function safe_uploaded_image(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['message' => 'Image upload failed.'], 422);
    $size = (int)($file['size'] ?? 0);
    $max = (int)(env_value('MAX_UPLOAD_BYTES', '8388608') ?? 8388608);
    if ($size < 1 || $size > $max) respond(['message' => 'Image must be smaller than 8MB.'], 422);
    $tmp = (string)($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) respond(['message' => 'Upload a JPG, PNG, WEBP, GIF, SVG, or ICO image.'], 422);
    return [$mime, $allowed[$mime], $size];
}
