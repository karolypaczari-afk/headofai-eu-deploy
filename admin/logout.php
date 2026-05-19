<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    helm_admin_csrf_check();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /admin/login.php', true, 302);
    exit;
}

// GET → minimal "are you sure?" form (so CSRF stays POST-only).
$csrf = $_SESSION['csrf'] ?? '';
?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Kijelentkezés</title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#030014;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}form{background:rgba(15,23,42,.7);padding:32px;border-radius:14px;border:1px solid rgba(99,102,241,.25)}button{padding:10px 18px;border:0;border-radius:8px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:600;cursor:pointer;font-family:inherit}a{color:#a5b4fc;margin-left:14px}</style>
</head><body>
<form method="post">
  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf, ENT_QUOTES)?>">
  <button type="submit">Kijelentkezés</button>
  <a href="/admin/">Mégsem</a>
</form>
</body></html>
