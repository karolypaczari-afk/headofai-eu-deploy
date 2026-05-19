<?php
/**
 * Head of AI — MailerLite relay (used by Phase 3 newsletter / lead-magnet
 * mini-forms). Browser POSTs JSON; server forwards to MailerLite Connect
 * API with the secret token kept server-side.
 *
 * Payload (POST application/json):
 *   {
 *     "email": "user@example.com",
 *     "fields": { "name": "...", "company": "...", ... },
 *     "group_id": "optional-group-override"
 *   }
 *
 * Returns 204 on success, 4xx/5xx with JSON error otherwise.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$configPath = __DIR__ . '/mailerlite-config.php';
if (!file_exists($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'mailerlite not configured']);
    exit;
}
$config = require $configPath;

$capiConfigPath = __DIR__ . '/capi-config.php';
$capiConfig = file_exists($capiConfigPath) ? require $capiConfigPath : [];
$allowedOrigins = $capiConfig['allowed_origins'] ?? ['https://headofai.eu', 'https://www.headofai.eu'];

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad json']);
    exit;
}

$email = trim((string)($body['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'invalid email']);
    exit;
}

$payload = [
    'email'  => strtolower($email),
    'fields' => is_array($body['fields'] ?? null) ? $body['fields'] : [],
    'status' => 'active',
];
$groupId = (string)($body['group_id'] ?? $config['group_id'] ?? '');
if ($groupId !== '') $payload['groups'] = [$groupId];

if (empty($config['api_token']) || $config['api_token'] === 'REPLACE_WITH_MAILERLITE_TOKEN') {
    http_response_code(202);
    echo json_encode(['status' => 'mailerlite-not-configured']);
    exit;
}

$ch = curl_init('https://connect.mailerlite.com/api/subscribers');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $config['api_token'],
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$logDir = $capiConfig['log_dir'] ?? (__DIR__ . '/logs');
if (!is_dir($logDir)) @mkdir($logDir, 0700, true);
@file_put_contents($logDir . '/mailerlite.log', json_encode([
    'ts' => date('c'),
    'email_hash' => hash('sha256', strtolower($email)),
    'http' => $code,
    'group_id' => $groupId ?: null,
]) . "\n", FILE_APPEND | LOCK_EX);

if ($code >= 200 && $code < 300) {
    http_response_code(204);
    exit;
}
http_response_code(502);
echo json_encode(['error' => 'mailerlite upstream', 'http' => $code]);
