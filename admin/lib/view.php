<?php
/**
 * Head of AI — generic list view + CSV exporter.
 *
 * Public-repo-safe: no credentials embedded; all DB access goes through
 * the lead-pipeline's PDO helper, which reads its config from
 * `helm_secret_paths()['config_dir'] . '/db-config.php'`.
 *
 * Renders for a registry entry:
 *   - filter form (date range, UTM, free-text search)
 *   - paginated list (50 rows/page)
 *   - CSV export of the current filter set
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/secrets.php';
require_once dirname(__DIR__, 2) . '/api/lib/db.php';

/**
 * Open a PDO for read-only dashboard queries. Reuses the lead-pipeline config.
 */
function helm_admin_pdo(): ?PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $paths = helm_secret_paths();
    $dbCfg = file_exists($paths['config_dir'] . '/db-config.php')
        ? require $paths['config_dir'] . '/db-config.php'
        : [];
    $pdo = helm_db_connect($dbCfg, $paths['log_dir']);
    return $pdo;
}

/**
 * Build WHERE + params from a registry entry's filters and the request.
 */
function helm_admin_build_where(array $entry, array $q): array
{
    $where = [];
    $params = [];
    foreach ($entry['filters'] ?? [] as $id => $f) {
        $val = trim((string)($q[$id] ?? ''));
        if ($val === '') continue;
        $col = $f['col'];
        switch ($f['type']) {
            case 'date_from':
                $where[] = "`$col` >= :f_$id";
                $params[":f_$id"] = $val . ' 00:00:00';
                break;
            case 'date_to':
                $where[] = "`$col` <= :f_$id";
                $params[":f_$id"] = $val . ' 23:59:59';
                break;
            case 'like':
                $where[] = "`$col` LIKE :f_$id";
                $params[":f_$id"] = '%' . $val . '%';
                break;
            case 'eq':
                $where[] = "`$col` = :f_$id";
                $params[":f_$id"] = $val;
                break;
            case 'json':
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(`$col`, '" . $f['path'] . "')) LIKE :f_$id";
                $params[":f_$id"] = '%' . $val . '%';
                break;
        }
    }
    // Free-text search across whitelisted columns.
    $qStr = trim((string)($q['q'] ?? ''));
    if ($qStr !== '' && !empty($entry['search'])) {
        $parts = [];
        foreach ($entry['search'] as $i => $col) {
            $parts[] = "`$col` LIKE :s_$i";
            $params[":s_$i"] = '%' . $qStr . '%';
        }
        if ($parts) $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

/**
 * Run the list query for an entry. Returns [rows, totalCount, page, perPage].
 */
function helm_admin_query_list(array $entry, array $q): array
{
    $pdo = helm_admin_pdo();
    if (!$pdo) return [[], 0, 1, 50];

    [$whereSql, $params] = helm_admin_build_where($entry, $q);
    $table = $entry['table'];
    $order = $entry['order'] ?? 'id DESC';

    $perPage = 50;
    $page = max(1, (int)($q['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $cols = implode(', ', array_map(fn($c) => "`$c`", $entry['columns']));
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `$table` $whereSql");
    $countStmt->execute($params);
    $total = (int)($countStmt->fetch()['c'] ?? 0);

    $sql = "SELECT $cols FROM `$table` $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return [$stmt->fetchAll(), $total, $page, $perPage];
}

/**
 * Stream a CSV export of the current filter set. Calls exit().
 */
function helm_admin_csv_export(array $entry, array $q, string $filenameStem): void
{
    $pdo = helm_admin_pdo();
    if (!$pdo) {
        http_response_code(503);
        echo "DB unavailable";
        exit;
    }
    [$whereSql, $params] = helm_admin_build_where($entry, $q);
    $cols = $entry['csv'] ?? $entry['columns'];
    $colsSql = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $sql = "SELECT $colsSql FROM `" . $entry['table'] . "` $whereSql ORDER BY " . ($entry['order'] ?? 'id DESC');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameStem . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
    fputcsv($out, $cols);
    while ($row = $stmt->fetch()) {
        fputcsv($out, array_map(fn($c) => (string)($row[$c] ?? ''), $cols));
    }
    fclose($out);
    exit;
}

/**
 * Fetch a single row by id. Returns null if not found.
 */
function helm_admin_get_one(array $entry, $id): ?array
{
    $pdo = helm_admin_pdo();
    if (!$pdo) return null;
    $stmt = $pdo->prepare("SELECT * FROM `" . $entry['table'] . "` WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
