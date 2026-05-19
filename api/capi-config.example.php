<?php
/**
 * Head of AI — Meta CAPI + server-side tracking config
 *
 * Copy this file to `capi-config.php` ON THE SERVER ONLY.
 * Never commit `capi-config.php` to git.
 *
 * To go live:
 *   1. cp capi-config.example.php capi-config.php
 *   2. Fill in the real tokens / IDs below
 *   3. Lock down `capi-config.php` with chmod 600
 */

return [
    // Meta Pixel ID (same as the browser-side window.HELM_TRACKING_CONFIG.metaPixelId)
    'meta_pixel_id'    => 'XXXXXXXXXXXXXXXXX',    // REPLACE

    // CAPI Access Token — Business Manager → Events Manager → Pixel → Settings → Generate Access Token
    'meta_access_token' => 'REPLACE_WITH_CAPI_ACCESS_TOKEN',

    // Test event code (optional). Use during validation in Events Manager → Test Events.
    // Set to '' or remove key when going production.
    'meta_test_event_code' => '',

    // Allowed origins for the CAPI relay (CORS). Add staging + prod hosts.
    'allowed_origins' => [
        'https://headofai.eu',
        'https://www.headofai.eu',
    ],

    // Primary notification inbox (contact.php uses this as To: ; karolypaczari@gmail.com
    // is hardcoded as Bcc inside contact.php for guaranteed founder-side delivery).
    'notify_email'   => 'info@headofai.eu',
    'notify_from'    => 'info@headofai.eu',
    'notify_subject' => 'Új Head of AI érdeklődő',

    // Log directory (relative to api/). Must be writable, deny-listed in .htaccess.
    'log_dir' => __DIR__ . '/logs',

    // Toggle: write submissions.log in addition to CAPI relay
    'write_submission_log' => true,

    // Default monetary value attached to Lead events (for ROAS attribution)
    'lead_value'    => 10,
    'lead_currency' => 'HUF',
];
