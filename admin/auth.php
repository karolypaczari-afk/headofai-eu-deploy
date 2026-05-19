<?php
/**
 * Head of AI — admin auth gate.
 *
 * Every PHP file in /admin/ requires this. It:
 *   - loads admin-config.php from the persistent secrets dir,
 *   - starts/refreshes the session,
 *   - on /login.php: allows access (the login form lives there),
 *   - everywhere else: redirects to /admin/login.php if not authenticated.
 *
 * No credentials are embedded here. The repo is public ([[feedback-repo-is-public-no-secrets-in-git]]).
 */

declare(strict_types=1);

if (!defined('HELM_ADMIN')) { define('HELM_ADMIN', true); }

require_once dirname(__DIR__) . '/api/lib/secrets.php';

$paths = helm_secret_paths();
$adminConfigPath = $paths['config_dir'] . '/admin-config.php';

if (!file_exists($adminConfigPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Admin dashboard not configured yet. Place admin-config.php at:\n";
    echo "  " . $adminConfigPath . "\n";
    exit;
}

$adminCfg = require $adminConfigPath;
$cookieName = $adminCfg['cookie_name'] ?? 'helm_admin';
$sessionTtl = (int)($adminCfg['session_ttl'] ?? 3600);

session_name($cookieName);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/admin/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Idle expiry.
if (!empty($_SESSION['admin_ok']) && !empty($_SESSION['admin_last_seen'])) {
    if (time() - (int)$_SESSION['admin_last_seen'] > $sessionTtl) {
        $_SESSION = [];
        session_destroy();
        session_start();   // fresh empty session for the redirect
    }
}
if (!empty($_SESSION['admin_ok'])) {
    $_SESSION['admin_last_seen'] = time();
}

// CSRF token: derive from session_secret so it survives session regen.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/**
 * Verify a CSRF token from the form. Use on every state-changing POST.
 */
function helm_admin_csrf_check(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "CSRF token mismatch.\n";
        exit;
    }
}

/**
 * Redirect to login unless authenticated. The login page itself opts out.
 */
function helm_admin_require_login(): void
{
    if (empty($_SESSION['admin_ok'])) {
        $self = $_SERVER['REQUEST_URI'] ?? '/admin/';
        header('Location: /admin/login.php?next=' . urlencode($self), true, 302);
        exit;
    }
}

// Make the loaded config visible to includers.
return $adminCfg;
