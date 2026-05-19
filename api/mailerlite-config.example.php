<?php
/**
 * Head of AI — MailerLite Classic + new (connect.mailerlite.com) credentials.
 *
 * Copy to `mailerlite-config.php` ON THE SERVER ONLY. Never commit the
 * real file. Used by contact.php (server-side) and mailerlite-relay.php
 * (browser-side opt-in if/when we wire one).
 *
 * Steps:
 *   1. `cp mailerlite-config.example.php mailerlite-config.php && chmod 600 mailerlite-config.php`
 *   2. MailerLite → Integrations → API → "Generate new token"
 *   3. Find your audience group ID under Subscribers → Groups (URL has the ID).
 */

return [
    // New MailerLite API (api.mailerlite.com / connect.mailerlite.com)
    'api_token' => 'REPLACE_WITH_MAILERLITE_TOKEN',

    // Default group all form leads land in (e.g., "Audit-érdeklődők")
    'group_id'  => '',                // string or null

    // Custom fields expected to exist in MailerLite (matched by name):
    //   name, company, phone, challenge,
    //   utm_source, utm_medium, utm_campaign,
    //   first_touch, last_touch
    // Create them in MailerLite → Subscribers → Fields before going live.
];
