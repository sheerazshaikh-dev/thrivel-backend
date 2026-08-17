<?php
declare(strict_types=1);

function ensure_body_profile_schema(): void {
    static $ready = false;
    if ($ready) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS body_profiles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        assessment_token CHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        order_id BIGINT UNSIGNED NULL,
        front_path VARCHAR(500) NULL,
        side_path VARCHAR(500) NULL,
        back_path VARCHAR(500) NULL,
        visual_summary TEXT NULL,
        visible_signals JSON NULL,
        goal_tags JSON NULL,
        analysis_json JSON NULL,
        confidence ENUM('low','medium','high') NOT NULL DEFAULT 'low',
        ai_model VARCHAR(120) NULL,
        status ENUM('created','uploaded','review_pending','approved','excluded','deleted') NOT NULL DEFAULT 'created',
        consent_at DATETIME NOT NULL,
        reviewed_by BIGINT UNSIGNED NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_body_profile_token (assessment_token),
        KEY idx_body_profile_user (user_id),
        KEY idx_body_profile_order (order_id),
        KEY idx_body_profile_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function body_profile_token(): string { return bin2hex(random_bytes(32)); }

function body_profile_cleanup_orphans(): void {
    $stmt = db()->query("SELECT * FROM body_profiles WHERE user_id IS NULL AND order_id IS NULL AND status<>'deleted' AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR) LIMIT 50");
    foreach ($stmt->fetchAll() as $row) {
        body_profile_delete_files($row);
        $update = db()->prepare("UPDATE body_profiles SET front_path=NULL,side_path=NULL,back_path=NULL,status='deleted',analysis_json=NULL,goal_tags=NULL,visible_signals=NULL,visual_summary=NULL WHERE id=?");
        $update->execute([(int)$row['id']]);
    }
}


function body_profile_row(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = db()->prepare('SELECT * FROM body_profiles WHERE assessment_token=? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function body_profile_payload(array $row): array {
    return [
        'token' => (string)$row['assessment_token'],
        'status' => (string)$row['status'],
        'visualSummary' => (string)($row['visual_summary'] ?? ''),
        'visibleSignals' => decode_json_array($row['visible_signals'] ?? null),
        'goalTags' => decode_json_array($row['goal_tags'] ?? null),
        'analysis' => json_decode((string)($row['analysis_json'] ?? '{}'), true) ?: null,
        'confidence' => (string)($row['confidence'] ?? 'low'),
        'hasFront' => !empty($row['front_path']),
        'hasSide' => !empty($row['side_path']),
        'hasBack' => !empty($row['back_path']),
        'consentAt' => (string)($row['consent_at'] ?? ''),
        'reviewedAt' => (string)($row['reviewed_at'] ?? ''),
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}

function body_profile_storage_root(): string {
    $root = dirname(__DIR__) . '/storage/body-profiles';
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) throw new RuntimeException('Could not create private body-profile storage directory.');
    return $root;
}

function body_profile_delete_files(array $row): void {
    $root = realpath(body_profile_storage_root());
    foreach (['front_path','side_path','back_path'] as $column) {
        $path = trim((string)($row[$column] ?? ''));
        if ($path === '' || !is_file($path)) continue;
        $real = realpath($path);
        if ($root && $real && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) @unlink($real);
    }
    $dir = body_profile_storage_root() . '/' . (string)$row['assessment_token'];
    if (is_dir($dir)) @rmdir($dir);
}

function body_profile_uploaded_image(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['message' => 'The body photo upload failed.'], 422);
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) respond(['message' => 'Body photos must be 8 MB or smaller.'], 422);
    $tmp = (string)($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) respond(['message' => 'Upload a JPG, PNG, or WEBP body photo.'], 422);
    if (@getimagesize($tmp) === false) respond(['message' => 'The uploaded file is not a valid image.'], 422);
    return [$mime, $allowed[$mime], $size];
}

function body_profile_image_data_url(string $path): string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg';
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Could not read the private body photo.');
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

function body_profile_openai_model(): string {
    $configured = trim((string)(env_value('OPENAI_BODY_PROFILE_MODEL', '') ?? ''));
    return $configured !== '' ? $configured : openai_model();
}

function body_profile_analyze(array $row, array $assessment): array {
    if (trim((string)($row['front_path'] ?? '')) === '' || trim((string)($row['side_path'] ?? '')) === '') {
        respond(['message' => 'Front and side body photos are required before analysis.'], 422);
    }
    $content = [[
        'type' => 'input_text',
        'text' => "Analyze these voluntarily uploaded body-profile photos only as a secondary, non-diagnostic wellness-planning signal.\n\n"
            . "Hard rules:\n"
            . "- Do not identify the person.\n"
            . "- Do not infer race, ethnicity, age, sex/gender, disability, disease, hormone status, insulin resistance, eating disorders, or any diagnosis from appearance.\n"
            . "- Do not estimate exact body-fat percentage, BMI, weight, medication need, dosage, injection technique, or treatment.\n"
            . "- Use neutral, non-shaming language.\n"
            . "- If any image is nude, sexually explicit, or otherwise unsuitable for a wellness body-profile review, do not analyze the body. Return low confidence, no goal tags, and mark the visual evidence as insufficient.\n"
            . "- Only describe broad visible body-composition, muscle-definition, posture/mobility or recovery-planning signals when clearly visible.\n"
            . "- If the image is poor, covered, cropped, ambiguous, or unsuitable, say evidence is insufficient.\n"
            . "- goalTags may ONLY use: weight management, body composition, lean muscle, performance, recovery, mobility, posture support, general wellness.\n"
            . "- This result will be combined with questionnaire answers and reviewed by a human; it must never be the sole basis for a product or health recommendation.\n\n"
            . "Questionnaire context supplied by the member: primary goal=" . (string)answer_value($assessment, 'primary_goal', '')
            . "; activity=" . (string)answer_value($assessment, 'activity_level', '')
            . "; workouts/week=" . (string)answer_value($assessment, 'training_freq', '') . "."
    ]];
    foreach (['front_path','side_path','back_path'] as $column) {
        $path = trim((string)($row[$column] ?? ''));
        if ($path !== '' && is_file($path)) $content[] = ['type' => 'input_image', 'image_url' => body_profile_image_data_url($path), 'detail' => 'low'];
    }
    if (count($content) < 2) respond(['message' => 'Upload at least one body photo before analysis.'], 422);

    $allowedSignals = ['body composition focus','muscle definition focus','posture support','mobility support','recovery focus','general wellness','insufficient visual information'];
    $allowedTags = ['weight management','body composition','lean muscle','performance','recovery','mobility','posture support','general wellness'];
    $payload = [
        'model' => body_profile_openai_model(),
        'input' => [[
            'role' => 'user',
            'content' => $content,
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'thrivel_body_profile',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'visualSummary' => ['type' => 'string'],
                        'visibleSignals' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $allowedSignals], 'maxItems' => 4],
                        'goalTags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $allowedTags], 'maxItems' => 4],
                        'confidence' => ['type' => 'string', 'enum' => ['low','medium','high']],
                        'reviewRequired' => ['type' => 'boolean'],
                    ],
                    'required' => ['visualSummary','visibleSignals','goalTags','confidence','reviewRequired'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'max_output_tokens' => 500,
    ];
    $response = openai_response_request($payload, 75);
    $text = openai_output_text($response);
    $analysis = json_decode($text, true);
    if (!is_array($analysis)) throw new RuntimeException('The body-profile model returned an invalid structured response.');
    $analysis['reviewRequired'] = true;
    $analysis['visualSummary'] = trim((string)($analysis['visualSummary'] ?? ''));
    $analysis['visibleSignals'] = array_values(array_intersect($allowedSignals, array_map('strval', $analysis['visibleSignals'] ?? [])));
    $analysis['goalTags'] = array_values(array_intersect($allowedTags, array_map('strval', $analysis['goalTags'] ?? [])));
    $analysis['confidence'] = in_array((string)($analysis['confidence'] ?? ''), ['low','medium','high'], true) ? (string)$analysis['confidence'] : 'low';
    return ['analysis' => $analysis, 'model' => body_profile_openai_model(), 'responseId' => (string)($response['id'] ?? '')];
}

function body_profile_answer_value(array $assessment): array {
    $value = answer_value($assessment, 'body_profile', []);
    return is_array($value) ? $value : [];
}

function body_profile_token_from_assessment(array $assessment): string {
    $value = body_profile_answer_value($assessment);
    $token = strtolower(trim((string)($value['token'] ?? '')));
    return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
}

function body_profile_add_match_intents(array &$intents, array $assessment): void {
    $token = body_profile_token_from_assessment($assessment);
    if ($token === '') return;
    $row = body_profile_row($token);
    if (!$row || in_array((string)($row['status'] ?? ''), ['excluded','deleted'], true)) return;
    // Never trust browser-supplied visual tags. Recommendation signals come from the server-stored analysis.
    $tags = decode_json_array($row['goal_tags'] ?? null);
    // Visual data is deliberately secondary to explicit questionnaire intent.
    foreach ($tags as $tag) add_match_intent($intents, (string)$tag, 3.5, 'Optional body-profile analysis');
}

function body_profile_bind_order(array $assessment, int $orderId): void {
    $token = body_profile_token_from_assessment($assessment);
    if ($token === '') return;
    $stmt = db()->prepare('UPDATE body_profiles SET order_id=? WHERE assessment_token=? AND order_id IS NULL');
    $stmt->execute([$orderId, $token]);
}

function body_profile_bind_user_from_order(array $order, int $userId): void {
    $assessment = json_decode((string)($order['assessment_json'] ?? '{}'), true) ?: [];
    $token = body_profile_token_from_assessment($assessment);
    if ($token === '') return;
    $stmt = db()->prepare('UPDATE body_profiles SET user_id=?,order_id=COALESCE(order_id,?) WHERE assessment_token=?');
    $stmt->execute([$userId, (int)$order['id'], $token]);
}

function body_profile_for_user(int $userId): ?array {
    $stmt = db()->prepare("SELECT * FROM body_profiles WHERE user_id=? AND status<>'deleted' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function body_profile_for_plan_assessment(array $assessment): ?array {
    $token = body_profile_token_from_assessment($assessment);
    return $token !== '' ? body_profile_row($token) : null;
}

function body_profile_for_advisor_context(int $userId): ?array {
    $row = body_profile_for_user($userId);
    if (!$row || (string)$row['status'] !== 'approved') return null;
    return [
        'status' => (string)$row['status'],
        'visualSummary' => (string)($row['visual_summary'] ?? ''),
        'goalTags' => decode_json_array($row['goal_tags'] ?? null),
        'visibleSignals' => decode_json_array($row['visible_signals'] ?? null),
        'confidence' => (string)($row['confidence'] ?? 'low'),
        'reviewed' => true,
    ];
}

function body_profile_require_staff_access(array $row, array $staff): void {
    if (($staff['role'] ?? '') === 'admin') return;
    if (($staff['role'] ?? '') !== 'reviewer') respond(['message' => 'Reviewer or administrator access is required.'], 403);
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId <= 0) respond(['message' => 'This body profile is not linked to a reviewable member yet.'], 403);
    $stmt = db()->prepare('SELECT COUNT(*) FROM member_plans WHERE user_id=? AND reviewer_user_id=?');
    $stmt->execute([$userId, (int)$staff['id']]);
    if ((int)$stmt->fetchColumn() === 0) respond(['message' => 'This body profile is assigned to another reviewer.'], 403);
}

function body_profile_stream_private_image(array $row, string $view): never {
    $column = match ($view) { 'front' => 'front_path', 'side' => 'side_path', 'back' => 'back_path', default => '' };
    if ($column === '') respond(['message' => 'Unknown body-photo view.'], 404);
    $path = trim((string)($row[$column] ?? ''));
    if ($path === '' || !is_file($path)) respond(['message' => 'Body photo not found.'], 404);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
