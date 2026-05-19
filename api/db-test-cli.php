<?php
/**
 * Head of AI — MySQL smoke-test (CLI only).
 *
 * Run on the Hostinger server (hPanel → Advanced → Browser Terminal, or SSH):
 *
 *   php /home/u758116828/domains/headofai.eu/public_html/api/db-test-cli.php
 *
 * Does a 4-step round-trip:
 *   1. PDO connect via site/api/db-config.php
 *   2. SELECT VERSION()
 *   3. SHOW TABLES LIKE 'leads'   (verifies the migration ran)
 *   4. INSERT a synthetic lead → SELECT it back → DELETE it
 *
 * If anything fails, the error is printed and exit code is 1.
 * No credentials are embedded in this file — it reads db-config.php.
 *
 * Web access is blocked via php_sapi_name() guard AND the api/.htaccess
 * already denies GET to *.php across the folder. Safe to commit.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "db-test-cli.php is a CLI-only smoke test. Run it from the Hostinger Browser Terminal:\n";
    echo "  php " . __FILE__ . "\n";
    exit(1);
}

require_once __DIR__ . '/lib/secrets.php';
$paths = helm_secret_paths();

$dbConfigPath = $paths['config_dir'] . '/db-config.php';
$libPath      = __DIR__ . '/lib/db.php';
$logDir       = $paths['log_dir'];

echo "Secrets resolved via: " . $paths['source'] . " (" . $paths['config_dir'] . ")\n";

if (!file_exists($dbConfigPath)) {
    fwrite(STDERR, "❌ db-config.php missing at $dbConfigPath\n");
    fwrite(STDERR, "   Copy db-config.example.php → db-config.php (in {$paths['config_dir']}) and fill in the real password.\n");
    exit(1);
}
if (!file_exists($libPath)) {
    fwrite(STDERR, "❌ lib/db.php missing at $libPath\n");
    exit(1);
}

$cfg = require $dbConfigPath;
require_once $libPath;

echo "──────────────────────────────────────────────────\n";
echo " Head of AI — MySQL smoke test\n";
echo "──────────────────────────────────────────────────\n";
echo "Host:    " . ($cfg['host'] ?? '?') . "\n";
echo "DB:      " . ($cfg['dbname'] ?? '?') . "\n";
echo "User:    " . ($cfg['user'] ?? '?') . "\n";
echo "Enabled: " . (!empty($cfg['enabled']) ? 'yes' : 'NO — set enabled=true in db-config.php') . "\n";
echo "\n";

$step = 1;
$fail = function (string $why) use (&$step): void {
    fwrite(STDERR, "❌ Step $step: $why\n");
    exit(1);
};

// ---------- 1. Connect ----------
echo "[$step] Connecting…\n";
$pdo = helm_db_connect($cfg, $logDir);
if (!$pdo) {
    $fail("connect failed — check db.log and the credentials in db-config.php.");
}
echo "    ok\n";
$step++;

// ---------- 2. SELECT VERSION() ----------
echo "[$step] SELECT VERSION()…\n";
try {
    $version = $pdo->query('SELECT VERSION() AS v')->fetch()['v'] ?? '?';
    echo "    MySQL version: $version\n";
} catch (Throwable $e) {
    $fail($e->getMessage());
}
$step++;

// ---------- 3. Schema check ----------
echo "[$step] Checking `leads` table…\n";
try {
    $row = $pdo->query("SHOW TABLES LIKE 'leads'")->fetch();
    if (!$row) {
        $fail("`leads` table does not exist. Run sql/001_create_leads.sql in phpMyAdmin.");
    }
    $cnt = (int)$pdo->query('SELECT COUNT(*) AS c FROM `leads`')->fetch()['c'];
    echo "    table exists, current row count: $cnt\n";
} catch (Throwable $e) {
    $fail($e->getMessage());
}
$step++;

// ---------- 4. Insert → select → delete round-trip ----------
echo "[$step] Round-trip insert / select / delete…\n";
$testEventId = 'smoketest-' . bin2hex(random_bytes(6));
$testRow = [
    'event_id'   => $testEventId,
    'name'       => 'Smoke Test',
    'company'    => 'Head of AI',
    'email'      => 'smoketest@example.invalid',
    'email_hash' => hash('sha256', 'smoketest@example.invalid'),
    'phone'      => null,
    'phone_hash' => null,
    'challenge'  => 'CLI smoke test — automated insert from db-test-cli.php',
    'attribution'=> ['source' => 'db-test-cli'],
    'ip_hash'    => str_repeat('0', 64),
    'ua'         => 'helm-smoke-test',
    'meta_capi_ok'  => null,
    'mailerlite_ok' => null,
];

if (!helm_db_insert_lead($pdo, $testRow, $logDir)) {
    $fail("INSERT failed — see logs/db.log");
}
$got = $pdo->prepare('SELECT id, event_id, email FROM `leads` WHERE event_id = ?');
$got->execute([$testEventId]);
$found = $got->fetch();
if (!$found) {
    $fail("INSERT succeeded but row not found by event_id — strange. Check phpMyAdmin manually.");
}
echo "    inserted id={$found['id']} event_id={$found['event_id']}\n";

$del = $pdo->prepare('DELETE FROM `leads` WHERE event_id = ?');
$del->execute([$testEventId]);
echo "    deleted (rows affected: " . $del->rowCount() . ")\n";
$step++;

echo "\n";
echo "──────────────────────────────────────────────────\n";
echo " ✅ All checks passed. The lead pipeline is live.\n";
echo "──────────────────────────────────────────────────\n";
exit(0);
