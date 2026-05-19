<?php
/**
 * Head of AI — Meta CAPI relay for non-Lead browser events.
 *
 * Used by `js/analytics.js` to mirror PageView / ViewContent /
 * InitiateCheckout to server-side CAPI for dedup and iOS / ad-block
 * resiliency. Re-uses the same machinery as `meta-capi.php`.
 *
 * This file is intentionally a thin wrapper — if you want server-side
 * relay for *every* event the browser sends, point analytics.js at it.
 */

declare(strict_types=1);

// Reuse meta-capi.php's processing by simulating its expected payload.
// The browser must POST: { event_name, event_id, event_source_url, user_data, custom_data }

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/meta-capi.php';
require __DIR__ . '/meta-capi.php';
