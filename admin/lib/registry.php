<?php
/**
 * Head of AI — forms registry.
 *
 * Each entry maps a form-id to:
 *   - label:   display name in the nav + page title
 *   - table:   MySQL table name (must exist; see site/api/sql/*.sql)
 *   - columns: which columns to show in the list view, in display order
 *   - search:  optional list of columns the free-text search hits
 *   - filters: ID → { type, col, [path] }   type ∈ {date_from, date_to, like, eq, json}
 *   - order:   default ORDER BY (default 'id DESC')
 *
 * To add a new form-type:
 *   1. write site/api/sql/00N_create_<formname>.sql + run it on the server,
 *   2. write a contact-style endpoint that INSERTs into the new table,
 *   3. add an entry below — the dashboard list view, filters, and CSV
 *      export pick it up automatically.
 */

declare(strict_types=1);

return [
    'leads' => [
        'label'   => 'Kapcsolatfelvétel',
        'icon'    => '✉️',
        'table'   => 'leads',
        'columns' => ['id', 'created_at', 'name', 'company', 'email', 'phone', 'challenge'],
        'search'  => ['name', 'company', 'email'],
        'filters' => [
            'date_from' => ['type' => 'date_from', 'col' => 'created_at', 'label' => 'Dátumtól'],
            'date_to'   => ['type' => 'date_to',   'col' => 'created_at', 'label' => 'Dátumig'],
            'utm'       => ['type' => 'json',      'col' => 'attribution', 'path' => '$.utm_source', 'label' => 'UTM forrás'],
        ],
        'order'   => 'created_at DESC',
        'csv'     => ['id', 'created_at', 'name', 'company', 'email', 'phone', 'challenge', 'attribution'],
    ],
];
