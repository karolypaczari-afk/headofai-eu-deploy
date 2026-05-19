<?php
/**
 * Head of AI — admin dashboard credentials.
 *
 * Copy to `admin-config.php` and place it on the SERVER ONLY, in the
 * persistent secrets directory at `/home/u758116828/secrets/headofai/`.
 * The dashboard reads it via `helm_secret_paths()` — never bundle this
 * file with the deploy.
 *
 * Generate a password hash on the server:
 *
 *   php -r 'echo password_hash("YOUR-PASSWORD-HERE", PASSWORD_DEFAULT) . PHP_EOL;'
 *
 * Then paste the resulting `$2y$…` hash into `admin_pass_hash` below.
 * Pick `session_secret` as 32+ random bytes (e.g. `openssl rand -hex 32`).
 */

return [
    // Login name (also displayed in the header after login).
    'admin_user'      => 'admin',

    // password_hash() output — NEVER commit the plaintext.
    'admin_pass_hash' => 'REPLACE_WITH_PASSWORD_HASH',

    // Random secret used to derive CSRF tokens (and to namespace cookies).
    // Rotate annually or after suspected compromise.
    'session_secret'  => 'REPLACE_WITH_32_HEX_BYTES',

    // Idle timeout (seconds) before the session expires.
    'session_ttl'     => 3600,

    // Cookie name. Distinct from anything on the marketing site.
    'cookie_name'     => 'helm_admin',
];
