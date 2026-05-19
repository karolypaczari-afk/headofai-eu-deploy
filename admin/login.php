<?php
/**
 * Head of AI — admin login.
 *
 * GET  → render login form
 * POST → rate-limit + password_verify + session
 *
 * Generic „hibás bejelentkezés" on failure (no user enumeration).
 */

declare(strict_types=1);

$adminCfg = require __DIR__ . '/auth.php';

// If already logged in, bounce to dashboard.
if (!empty($_SESSION['admin_ok'])) {
    header('Location: /admin/', true, 302);
    exit;
}

$paths = helm_secret_paths();
$logDir = $paths['log_dir'];
if (!is_dir($logDir)) @mkdir($logDir, 0700, true);

$error = '';
$nextRaw = (string)($_GET['next'] ?? '/admin/');
$next = (str_starts_with($nextRaw, '/admin/') && !str_contains($nextRaw, "\n")) ? $nextRaw : '/admin/';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    helm_admin_csrf_check();

    // Per-IP rate limit: 5 attempts / 5 min.
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
    $rateFile = $logDir . '/admin-login-' . hash('sha1', $ip) . '.json';
    $hits = file_exists($rateFile) ? (json_decode((string)@file_get_contents($rateFile), true) ?: []) : [];
    $now = time();
    $hits = array_filter($hits, fn($t) => $t > $now - 300);
    if (count($hits) >= 5) {
        http_response_code(429);
        header('Retry-After: 300');
        $error = 'Túl sok kísérlet — próbáld pár perc múlva.';
    } else {
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');

        $expectedUser = (string)($adminCfg['admin_user'] ?? '');
        $expectedHash = (string)($adminCfg['admin_pass_hash'] ?? '');

        $userOk = ($expectedUser !== '' && hash_equals($expectedUser, $user));
        $passOk = ($expectedHash !== '' && $expectedHash !== 'REPLACE_WITH_PASSWORD_HASH'
                   && password_verify($pass, $expectedHash));

        if ($userOk && $passOk) {
            session_regenerate_id(true);
            $_SESSION['admin_ok']        = true;
            $_SESSION['admin_user']      = $expectedUser;
            $_SESSION['admin_last_seen'] = time();
            @file_put_contents(
                $logDir . '/admin-login.log',
                json_encode(['ts' => date('c'), 'ok' => true, 'ip_hash' => hash('sha256', $ip)]) . "\n",
                FILE_APPEND | LOCK_EX
            );
            header('Location: ' . $next, true, 302);
            exit;
        }

        // Failure — record the attempt and bump the rate-limit.
        $hits[] = $now;
        @file_put_contents($rateFile, json_encode(array_values($hits)), LOCK_EX);
        @file_put_contents(
            $logDir . '/admin-login.log',
            json_encode(['ts' => date('c'), 'ok' => false, 'ip_hash' => hash('sha256', $ip)]) . "\n",
            FILE_APPEND | LOCK_EX
        );
        $error = 'Hibás bejelentkezés.';
    }
}

$csrf = $_SESSION['csrf'];
?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Belépés — Head of AI admin</title>
<style>
  *,*:before,*:after{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#030014;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{width:100%;max-width:380px;background:rgba(15,23,42,.7);backdrop-filter:blur(12px);border:1px solid rgba(99,102,241,.25);border-radius:14px;padding:32px;box-shadow:0 25px 60px -20px rgba(99,102,241,.35)}
  h1{margin:0 0 4px;font-size:20px;font-weight:600;letter-spacing:-.01em}
  p.sub{margin:0 0 24px;font-size:13px;color:#94a3b8}
  label{display:block;font-size:12px;color:#cbd5e1;margin:14px 0 4px;letter-spacing:.04em;text-transform:uppercase}
  input[type=text],input[type=password]{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #1e293b;background:#0f172a;color:#f1f5f9;font-size:14px;font-family:inherit}
  input:focus{outline:none;border-color:#818cf8;box-shadow:0 0 0 3px rgba(129,140,248,.15)}
  button{margin-top:20px;width:100%;padding:11px 16px;border-radius:8px;border:0;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
  button:hover{filter:brightness(1.08)}
  .err{margin-top:14px;padding:10px 12px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);border-radius:8px;font-size:13px;color:#fca5a5}
  footer{margin-top:24px;font-size:11px;color:#64748b;text-align:center}
</style>
</head>
<body>
<form class="card" method="post" autocomplete="off">
  <h1>Head of AI — admin</h1>
  <p class="sub">Csak engedélyezett felhasználóknak.</p>

  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf, ENT_QUOTES)?>">

  <label for="user">Felhasználó</label>
  <input id="user" type="text" name="user" required autofocus value="<?=htmlspecialchars((string)($_POST['user'] ?? ''), ENT_QUOTES)?>">

  <label for="pass">Jelszó</label>
  <input id="pass" type="password" name="pass" required>

  <button type="submit">Belépés</button>

  <?php if ($error !== ''): ?>
    <div class="err"><?=htmlspecialchars($error, ENT_QUOTES)?></div>
  <?php endif; ?>

  <footer>headofai.eu · admin</footer>
</form>
</body>
</html>
