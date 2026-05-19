<?php
/**
 * Head of AI — MySQL helper for the lead capture pipeline.
 *
 * Best-effort by design: every failure path is swallowed and logged to
 * api/logs/db.log so the user-facing 200 success path is never blocked
 * by a DB outage. Schema lives in api/sql/001_create_leads.sql.
 */

declare(strict_types=1);

/**
 * Open a PDO connection from a db-config array. Returns null if the
 * config is missing, disabled, or the connection fails.
 */
function helm_db_connect(array $cfg, string $logDir): ?PDO
{
    if (empty($cfg) || empty($cfg['enabled'])) return null;
    if (empty($cfg['dbname']) || empty($cfg['user'])) return null;
    if (($cfg['pass'] ?? '') === 'REPLACE_WITH_DB_PASSWORD') return null;

    $host    = $cfg['host']    ?? 'localhost';
    $port    = (int)($cfg['port'] ?? 3306);
    $charset = $cfg['charset'] ?? 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $cfg['dbname'], $charset);

    try {
        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    } catch (Throwable $e) {
        helm_db_log($logDir, 'connect_failed', $e->getMessage());
        return null;
    }
}

/**
 * Insert one lead row. Returns true on success.
 *
 * `$row` is the same associative array contact.php already builds for
 * the JSONL submissions.log — we just project the relevant fields into
 * the `leads` table. ON DUPLICATE KEY (event_id) is treated as success
 * so retries / double-submits don't blow up.
 */
function helm_db_insert_lead(PDO $pdo, array $row, string $logDir): bool
{
    $sql = 'INSERT INTO `leads`
        (event_id, name, company, email, email_hash, phone, phone_hash,
         challenge, attribution, ip_hash, user_agent, meta_capi_ok, mailerlite_ok)
        VALUES
        (:event_id, :name, :company, :email, :email_hash, :phone, :phone_hash,
         :challenge, :attribution, :ip_hash, :user_agent, :meta_capi_ok, :mailerlite_ok)
        ON DUPLICATE KEY UPDATE
         meta_capi_ok = COALESCE(VALUES(meta_capi_ok), meta_capi_ok),
         mailerlite_ok = COALESCE(VALUES(mailerlite_ok), mailerlite_ok)';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':event_id'      => (string)($row['event_id'] ?? ''),
            ':name'          => (string)($row['name'] ?? ''),
            ':company'       => (string)($row['company'] ?? ''),
            ':email'         => (string)($row['email'] ?? ''),
            ':email_hash'    => (string)($row['email_hash'] ?? ''),
            ':phone'         => isset($row['phone']) && $row['phone'] !== '' ? (string)$row['phone'] : null,
            ':phone_hash'    => isset($row['phone_hash']) && $row['phone_hash'] !== '' ? (string)$row['phone_hash'] : null,
            ':challenge'     => (string)($row['challenge'] ?? ''),
            ':attribution'   => !empty($row['attribution']) ? json_encode($row['attribution'], JSON_UNESCAPED_UNICODE) : null,
            ':ip_hash'       => (string)($row['ip_hash'] ?? '') ?: null,
            ':user_agent'    => (string)($row['ua'] ?? '') ?: null,
            ':meta_capi_ok'  => isset($row['meta_capi_ok']) ? (int)!!$row['meta_capi_ok'] : null,
            ':mailerlite_ok' => isset($row['mailerlite_ok']) ? (int)!!$row['mailerlite_ok'] : null,
        ]);
        return true;
    } catch (Throwable $e) {
        helm_db_log($logDir, 'insert_failed', $e->getMessage(), [
            'event_id' => $row['event_id'] ?? null,
        ]);
        return false;
    }
}

function helm_db_log(string $logDir, string $kind, string $msg, array $extra = []): void
{
    if (!is_dir($logDir)) @mkdir($logDir, 0700, true);
    $line = json_encode([
        'ts'    => date('c'),
        'kind'  => $kind,
        'msg'   => mb_substr($msg, 0, 500),
        'extra' => $extra,
    ], JSON_UNESCAPED_UNICODE);
    @file_put_contents($logDir . '/db.log', $line . "\n", FILE_APPEND | LOCK_EX);
}
