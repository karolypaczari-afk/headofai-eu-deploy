<?php
/**
 * Head of AI — MySQL DB config (Hostinger shared hosting)
 *
 * Copy this file to `db-config.php` ON THE SERVER ONLY.
 * Never commit `db-config.php` to git — it carries DB credentials.
 *
 * To go live:
 *   1. Hostinger hPanel → Databases → MySQL Databases → Create New
 *        - Database name:   headofai      (Hostinger prefixes → u758116828_headofai)
 *        - Username:        headofai      (Hostinger prefixes → u758116828_headofai;
 *                                          user and DB name can be identical)
 *        - Password:        <generate strong, copy here>
 *        - Privileges:      ALL
 *   2. phpMyAdmin → select the new DB → SQL → paste site/api/sql/001_create_leads.sql → Go
 *   3. SSH/SFTP:
 *        cp db-config.example.php db-config.php
 *        chmod 600 db-config.php
 *        # fill in the real values below
 */

return [
    // Hostinger MySQL host — almost always 'localhost' on shared hosting
    'host'    => 'localhost',
    'port'    => 3306,

    // The full DB name including the u<accountId>_ prefix Hostinger forces
    'dbname'  => 'u758116828_headofai',

    // DB user (also prefixed by Hostinger) + password.
    // Hostinger allows the user to share the DB name — in this account both
    // are `u758116828_headofai`.
    'user'    => 'u758116828_headofai',
    'pass'    => 'REPLACE_WITH_DB_PASSWORD',

    // Charset
    'charset' => 'utf8mb4',

    // Soft kill-switch — set to false to temporarily disable DB writes
    // without removing this file or the code path. Failures never block
    // the user-facing success either way.
    'enabled' => true,
];
