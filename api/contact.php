<?php
/**
 * Head of AI — Server-side form submission endpoint.
 *
 * Replaces Formspree. Browser posts:
 *
 *   <form action="/api/contact.php" method="POST" data-helm-form>...</form>
 *
 * Responsibilities (in order):
 *   1. Origin + method + honeypot + rate-limit checks
 *   2. Server-side validation of all visible form fields
 *   3. Append SHA-256 hashed PII + attribution to submissions.log
 *   4. SMTP notify info@headofai.eu (PHP mail() fallback)
 *   5. Relay Lead event to Meta CAPI (deduplicated by event_id)
 *   6. Push subscriber to MailerLite (optional, if configured)
 *   7. Return 200 JSON  { ok: true, redirect: "/koszonjuk.html?eid=…" }
 *
 * Never trusts client-side validation. Never echoes raw POST data.
 * All third-party calls are best-effort; failure does not block the
 * user-facing success path.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// ---------- Config (resolved via lib/secrets.php — see that file for layout) ----------
require_once __DIR__ . '/lib/secrets.php';
$paths     = helm_secret_paths();
$configDir = $paths['config_dir'];

$configPath = $configDir . '/capi-config.php';
$config = file_exists($configPath) ? require $configPath : [];

$smtpConfigPath = $configDir . '/smtp-config.php';
$smtpConfig = file_exists($smtpConfigPath) ? require $smtpConfigPath : [];

$mailerliteConfigPath = $configDir . '/mailerlite-config.php';
$mailerliteConfig = file_exists($mailerliteConfigPath) ? require $mailerliteConfigPath : [];

$dbConfigPath = $configDir . '/db-config.php';
$dbConfig = file_exists($dbConfigPath) ? require $dbConfigPath : [];

// ---------- Method gate ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// ---------- Same-origin gate (loose; Origin is missing on some browsers) ----------
$allowedOrigins = $config['allowed_origins'] ?? ['https://headofai.eu', 'https://www.headofai.eu'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$valid = false;
if ($origin && in_array($origin, $allowedOrigins, true)) $valid = true;
foreach ($allowedOrigins as $allowed) {
    if (!$valid && $referer && stripos($referer, $allowed) === 0) { $valid = true; break; }
}
if (!$valid && $origin) {
    http_response_code(403);
    echo json_encode(['error' => 'origin not allowed']);
    exit;
}

// ---------- Rate limit (per-IP, file-based, 10/min) ----------
$logDir = $config['log_dir'] ?? $paths['log_dir'];
if (!is_dir($logDir)) @mkdir($logDir, 0700, true);

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);

$rateFile = $logDir . '/rate-' . hash('sha1', $ip) . '.json';
$now = time();
$window = 60;
$max = 10;
$hits = [];
if (file_exists($rateFile)) {
    $raw = @file_get_contents($rateFile);
    $hits = $raw ? (json_decode($raw, true) ?: []) : [];
}
$hits = array_filter($hits, fn($t) => $t > $now - $window);
if (count($hits) >= $max) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'rate limited']);
    exit;
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode(array_values($hits)), LOCK_EX);

// ---------- Honeypot ----------
if (!empty($_POST['_gotcha'])) {
    // silently 200 so bots see a success — they stop retrying
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Validate ----------
$name      = trim((string)($_POST['name'] ?? ''));
$company   = trim((string)($_POST['company'] ?? ''));
$email     = trim((string)($_POST['email'] ?? ''));
$phone     = trim((string)($_POST['phone'] ?? ''));
$challenge = trim((string)($_POST['challenge'] ?? ''));
$gdpr      = !empty($_POST['gdpr']);
$eventId   = trim((string)($_POST['helm_event_id'] ?? ''));

// Generate a UUID-ish event_id if the browser didn't ship one — server
// still needs a deterministic key for CAPI dedup.
if ($eventId === '') {
    $eventId = bin2hex(random_bytes(12));
}

$errors = [];
if (mb_strlen($name) < 2 || mb_strlen($name) > 120)         $errors[] = 'name';
if (mb_strlen($company) < 2 || mb_strlen($company) > 200)    $errors[] = 'company';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))              $errors[] = 'email';
if ($phone !== '' && !preg_match('/^[\d\s+()\-\.]{5,30}$/', $phone)) $errors[] = 'phone';
if (mb_strlen($challenge) < 10 || mb_strlen($challenge) > 5000) $errors[] = 'challenge';
if (!$gdpr)                                                  $errors[] = 'gdpr';

if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => 'validation', 'fields' => $errors]);
    exit;
}

// ---------- Attribution payload (helm_attr_*) ----------
$attribution = [];
foreach ($_POST as $k => $v) {
    if (strpos($k, 'helm_attr_') === 0) {
        $key = substr($k, strlen('helm_attr_'));
        $attribution[$key] = is_array($v) ? implode(',', $v) : substr((string)$v, 0, 500);
    }
}

// ---------- Hashes for CAPI / MailerLite ----------
$emailLower = strtolower($email);
$emailHash  = hash('sha256', $emailLower);
$phoneDigits = $phone !== '' ? preg_replace('/\D/', '', $phone) : '';
$phoneHash  = $phoneDigits !== '' ? hash('sha256', $phoneDigits) : '';

// ---------- Canonical row (shared by file log + DB insert) ----------
$row = [
    'ts'         => date('c'),
    'event_id'   => $eventId,
    'name'       => $name,
    'company'    => $company,
    'email'      => $emailLower,
    'email_hash' => $emailHash,
    'phone'      => $phone,
    'phone_hash' => $phoneHash,
    'challenge'  => mb_substr($challenge, 0, 2000),
    'attribution'=> $attribution,
    'ip_hash'    => hash('sha256', $ip),
    'ua'         => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
];

// ---------- Log submission to file ----------
if (!isset($config['write_submission_log']) || !empty($config['write_submission_log'])) {
    @file_put_contents($logDir . '/submissions.log', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// ---------- Notify by e-mail ----------
// Primary inbox = info@headofai.eu (domain mailbox, default if config is
// missing). Bcc = karolypaczari@gmail.com so the founder always gets a
// hidden copy of every lead, even if the domain mailbox/forward breaks.
$notify = trim((string)($config['notify_email'] ?? ''));
if ($notify === '') {
    $notify = 'info@headofai.eu';
}
$ownerBcc = 'karolypaczari@gmail.com';

$subject = $config['notify_subject'] ?? 'Új Head of AI érdeklődő';
$from = $config['notify_from'] ?? 'info@headofai.eu';
$bodyLines = [
    'Új érdeklődő jelentkezett a Head of AI form-on.',
    '',
    'Név:      ' . $name,
    'Cég:      ' . $company,
    'E-mail:   ' . $email,
    'Telefon:  ' . ($phone !== '' ? $phone : '—'),
    '',
    'Kihívás:',
    $challenge,
    '',
    'Attribúció: ' . json_encode($attribution, JSON_UNESCAPED_UNICODE),
    'Event ID:   ' . $eventId,
    'Időbélyeg:  ' . date('c'),
];
$headers = [
    'From: ' . $from,
    'Reply-To: ' . $email,
    'Bcc: ' . $ownerBcc,
    'Content-Type: text/plain; charset=utf-8',
    'X-Mailer: helm-contact',
];
$mailOk = @mail(
    $notify,
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    implode("\r\n", $bodyLines),
    implode("\r\n", $headers)
);

@file_put_contents(
    $logDir . '/mail.log',
    json_encode([
        'ts'       => date('c'),
        'event_id' => $eventId,
        'to'       => $notify,
        'bcc'      => $ownerBcc,
        'mail_ok'  => (bool)$mailOk,
    ], JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

// ---------- Relay to Meta CAPI ----------
$capiOk = null;
if (!empty($config['meta_access_token']) && $config['meta_access_token'] !== 'REPLACE_WITH_CAPI_ACCESS_TOKEN' && function_exists('curl_init')) {
    $relayPayload = json_encode([
        'event_name'        => 'Lead',
        'event_id'          => $eventId,
        'event_source_url'  => $_SERVER['HTTP_REFERER'] ?? '',
        'value'             => $config['lead_value'] ?? 10,
        'currency'          => $config['lead_currency'] ?? 'HUF',
        'user_data'         => [
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash !== '' ? $phoneHash : null,
        ],
    ]);
    $relayUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'headofai.eu') . '/api/meta-capi.php';
    $ch = curl_init($relayUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Origin: https://' . ($_SERVER['HTTP_HOST'] ?? 'headofai.eu')],
        CURLOPT_POSTFIELDS     => $relayPayload,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $capiOk = $code >= 200 && $code < 300;
}

// ---------- Push to MailerLite (optional) ----------
$mlOk = null;
if (!empty($mailerliteConfig['api_token']) && $mailerliteConfig['api_token'] !== 'REPLACE_WITH_MAILERLITE_TOKEN' && function_exists('curl_init')) {
    $groupId = $mailerliteConfig['group_id'] ?? null;
    $endpoint = 'https://connect.mailerlite.com/api/subscribers';
    $payload = [
        'email'  => $emailLower,
        'fields' => [
            'name'         => $name,
            'company'      => $company,
            'phone'        => $phone,
            'challenge'    => mb_substr($challenge, 0, 1000),
            'utm_source'   => $attribution['utm_source']   ?? null,
            'utm_medium'   => $attribution['utm_medium']   ?? null,
            'utm_campaign' => $attribution['utm_campaign'] ?? null,
            'first_touch'  => $attribution['first_touch']  ?? null,
            'last_touch'   => $attribution['last_touch']   ?? null,
        ],
        'status' => 'active',
    ];
    if ($groupId) $payload['groups'] = [(string)$groupId];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $mailerliteConfig['api_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $mlOk = $code >= 200 && $code < 300;

    @file_put_contents($logDir . '/mailerlite.log', json_encode([
        'ts' => date('c'), 'email_hash' => $emailHash, 'http' => $code, 'ok' => $mlOk,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

// ---------- Persist to MySQL (best-effort) ----------
// Failures here never block the user-facing success — they are logged
// to logs/db.log and the file-based submissions.log is the redundancy.
if (!empty($dbConfig['enabled'])) {
    require_once __DIR__ . '/lib/db.php';
    $pdo = helm_db_connect($dbConfig, $logDir);
    if ($pdo) {
        $row['meta_capi_ok']  = $capiOk;
        $row['mailerlite_ok'] = $mlOk;
        helm_db_insert_lead($pdo, $row, $logDir);
    }
}

// ---------- Done ----------
http_response_code(200);
echo json_encode([
    'ok'       => true,
    'redirect' => '/koszonjuk.html?eid=' . urlencode($eventId),
    'meta'     => ['capi' => $capiOk, 'mailerlite' => $mlOk, 'mail' => $mailOk],
]);
