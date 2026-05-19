<?php
/**
 * Head of AI — secret + log directory resolver.
 *
 * The Hostinger Git Deploy wipes anything not tracked in the repo. To keep
 * credentials (`*-config.php`) and persistent logs (submissions.log, db.log,
 * capi.log, mailerlite.log) safe across deploys, they live *outside* the
 * web document root, in `~/secrets/headofai/` on the server.
 *
 *   /home/u758116828/
 *   ├── domains/headofai.eu/public_html/   ← deploy target (recreated)
 *   └── secrets/headofai/                  ← persistent, chmod 700
 *       ├── db-config.php   (chmod 600)
 *       ├── smtp-config.php (chmod 600)
 *       ├── capi-config.php (chmod 600)
 *       ├── mailerlite-config.php (chmod 600)
 *       └── logs/
 *           ├── submissions.log
 *           ├── db.log
 *           ├── capi.log
 *           └── mailerlite.log
 *
 * Resolution order (first match wins):
 *   1. `$_ENV['HELM_SECRETS_DIR']` / `getenv('HELM_SECRETS_DIR')` — explicit override
 *      (set via .htaccess `SetEnv` or shell when running CLI tests)
 *   2. `<account-root>/secrets/headofai/` where account-root is computed
 *      from DOCUMENT_ROOT (`domains/<domain>/public_html` → 3 levels up)
 *   3. `__DIR__ . '/../'` legacy/in-tree fallback (dev only; the in-tree
 *      `*-config.php` would still be gitignored)
 *
 * Public-repo safety: this file contains no credentials and no
 * hard-coded production paths beyond the conventional layout.
 */

declare(strict_types=1);

if (!function_exists('helm_secret_paths')) {
    function helm_secret_paths(): array
    {
        $envOverride = getenv('HELM_SECRETS_DIR');
        if (is_string($envOverride) && $envOverride !== '' && is_dir($envOverride)) {
            return [
                'config_dir' => rtrim($envOverride, '/'),
                'log_dir'    => rtrim($envOverride, '/') . '/logs',
                'source'     => 'env',
            ];
        }

        // Web context: derive account root from DOCUMENT_ROOT.
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot !== '' && is_dir($docRoot)) {
            // /home/<acct>/domains/<domain>/public_html → 3 levels up = /home/<acct>
            $accountRoot = dirname($docRoot, 3);
            $candidate   = $accountRoot . '/secrets/headofai';
            if (is_dir($candidate)) {
                return [
                    'config_dir' => $candidate,
                    'log_dir'    => $candidate . '/logs',
                    'source'     => 'account-root',
                ];
            }
        }

        // CLI context: derive account root from this file's own path.
        // .../public_html/api/lib/secrets.php (8 segments) → 6 levels up = /home/<acct>
        $self = realpath(__FILE__);
        if ($self !== false && str_contains($self, '/public_html/api/lib/')) {
            $accountRoot = dirname($self, 6);
            $candidate   = $accountRoot . '/secrets/headofai';
            if (is_dir($candidate)) {
                return [
                    'config_dir' => $candidate,
                    'log_dir'    => $candidate . '/logs',
                    'source'     => 'self-path-cli',
                ];
            }
        }

        // Fallback: in-tree (dev / unconfigured server). Configs would still
        // be gitignored, but this lets a local `php -S` install work.
        $apiDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        return [
            'config_dir' => $apiDir,
            'log_dir'    => $apiDir . '/logs',
            'source'     => 'fallback',
        ];
    }
}
