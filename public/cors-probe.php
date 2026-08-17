<?php
declare(strict_types=1);

$origin = rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
$allowed = [
    'https://staging.thrivelid.com',
    'https://thrivel-frontend.vercel.app',
];

if (in_array($origin, $allowed, true) || preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $origin)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Key, Accept, Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Content-Length: 0');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'requestOrigin' => $origin,
    'originAllowed' => in_array($origin, $allowed, true),
    'documentRoot' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'scriptName' => $_SERVER['SCRIPT_NAME'] ?? null,
], JSON_UNESCAPED_SLASHES);
