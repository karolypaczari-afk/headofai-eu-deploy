<?php
/**
 * Head of AI — SMTP credentials (used by contact.php notify path).
 *
 * Copy to `smtp-config.php` ON THE SERVER ONLY. Never commit the real
 * file. The current contact.php uses PHP's mail() under the hood and
 * does not consume this config directly — keep this file ready for a
 * future PHPMailer / Symfony Mailer integration if Hostinger's mail()
 * proves unreliable.
 *
 * Steps to wire it in:
 *   1. `cp smtp-config.example.php smtp-config.php && chmod 600 smtp-config.php`
 *   2. composer require phpmailer/phpmailer
 *   3. Swap mail() block in contact.php for PHPMailer using $smtpConfig.
 */

return [
    'host'      => 'smtp.hostinger.com',
    'port'      => 465,
    'encryption'=> 'ssl',              // 'ssl' (465) | 'tls' (587)
    'username'  => 'info@headofai.eu',
    'password'  => 'REPLACE_WITH_SMTP_PASSWORD',
    'from_addr' => 'info@headofai.eu',
    'from_name' => 'Head of AI',
    'reply_to'  => 'info@headofai.eu',
    'timeout'   => 8,
];
