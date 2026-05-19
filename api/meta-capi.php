<?php
/**
 * Head of AI — Meta Conversions API relay
 *
 * Server-side companion for the browser Meta Pixel. Receives a JSON
 * payload from form.js (after a successful Formspree submit) and
 * forwards a deduplicated event to the Meta CAPI Graph endpoint.
 *
 * Dedup key: `event_id` — same value used by `fbq('track', …, {eventID})`.
 *
 * Expected payload (POST application/json):
 *   {
 *     "event_name": "Lead",
 *     "event_id":   "<uuid>",
 *     "event_source_url": "https://headofai.eu/...",
 *     "value": 10, "currency": "HUF",
 *     "user_data": { "email_hash": "...", "phone_hash": "..." }
 *   }
 *
 * Returns 204 on success, 4xx/5xx with JSON error otherwise.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------- Config ----------
$configPath = __DIR__ . '/capi-config.php';
if (!file_exists($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'CAPI not configured on server']);
    exit;
}
/** @var array $config */
$config = require $configPath;

// ---------- CORS ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $config['allowed_origins'] ?? [], true)) {
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

// ---------- Payload ----------
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad json']);
    exit;
}

$eventName = (string)($body['event_name'] ?? 'Lead');
$eventId = (string)($body['event_id'] ?? '');
$sourceUrl = (string)($body['event_source_url'] ?? '');
$value = $body['value'] ?? null;
$currency = (string)($body['currency'] ?? 'HUF');
$userData = is_array($body['user_data'] ?? null) ? $body['user_data'] : [];

if ($eventId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing event_id']);
    exit;
}

// ---------- Build Meta event ----------
$pixelId = (string)($config['meta_pixel_id'] ?? '');
$accessToken = (string)($config['meta_access_token'] ?? '');

if ($pixelId === '' || $pixelId === 'XXXXXXXXXXXXXXXXX' ||
    $accessToken === '' || $accessToken === 'REPLACE_WITH_CAPI_ACCESS_TOKEN') {
    // Not yet configured — log and return success so the browser is happy
    error_log('[helm-capi] not configured; dropping event ' . $eventId);
    http_response_code(202);
    echo json_encode(['status' => 'capi-not-configured']);
    exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (str_contains($ip, ',')) {
    $ip = trim(explode(',', $ip)[0]);
}

// Collect _fbp / _fbc browser cookies (Meta deduplication aids)
$fbp = $_COOKIE['_fbp'] ?? '';
$fbc = $_COOKIE['_fbc'] ?? '';

$user = array_filter([
    'em' => $userData['email_hash'] ?? null,
    'ph' => $userData['phone_hash'] ?? null,
    'client_ip_address' => $ip,
    'client_user_agent' => $ua,
    'fbp' => $fbp ?: null,
    'fbc' => $fbc ?: null,
]);
// Meta expects array values for hashed fields
foreach (['em', 'ph'] as $k) {
    if (isset($user[$k])) $user[$k] = [$user[$k]];
}

$customData = array_filter([
    'currency' => $currency,
    'value'    => $value,
]);

$payload = [
    'data' => [[
        'event_name'       => $eventName,
        'event_time'       => time(),
        'event_id'         => $eventId,
        'event_source_url' => $sourceUrl,
        'action_source'    => 'website',
        'user_data'        => $user,
        'custom_data'      => $customData,
    ]],
];
if (!empty($config['meta_test_event_code'])) {
    $payload['test_event_code'] = $config['meta_test_event_code'];
}

$url = 'https://graph.facebook.com/v18.0/' . rawurlencode($pixelId) . '/events?access_token=' . rawurlencode($accessToken);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 6,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

// ---------- Log ----------
$logDir = $config['log_dir'] ?? (__DIR__ . '/logs');
if (!is_dir($logDir)) @mkdir($logDir, 0700, true);
$logLine = json_encode([
    'ts' => date('c'),
    'event' => $eventName,
    'event_id' => $eventId,
    'pixel_id' => $pixelId,
    'http' => $httpCode,
    'err' => $err ?: null,
    'resp' => $resp ? json_decode($resp, true) : null,
]) . "\n";
@file_put_contents($logDir . '/capi.log', $logLine, FILE_APPEND | LOCK_EX);

if ($httpCode >= 200 && $httpCode < 300) {
    http_response_code(204);
    exit;
}
http_response_code(502);
echo json_encode(['error' => 'capi upstream', 'http' => $httpCode]);
