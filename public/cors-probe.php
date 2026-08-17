<?php
declare(strict_types=1);

$origin = rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
$allowed = 'https://thrivel-frontend.vercel.app';

if ($origin === $allowed || preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $origin)) {
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
    'originAllowed' => $origin === $allowed,
    'documentRoot' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'scriptName' => $_SERVER['SCRIPT_NAME'] ?? null,
], JSON_UNESCAPED_SLASHES);
