<?php
/**
 * Applies database/schema.sql to the database configured for this server
 * (config/config.local.php or config/config.php). schema.sql is idempotent
 * (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, ON DUPLICATE KEY),
 * so re-running it only adds what is missing — it never drops data or
 * resets passwords. Called by deploy/pull.ps1 when schema.sql changes.
 *
 * CLI only: php deploy/apply_schema.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/config.php';

$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read database/schema.sql\n");
    exit(1);
}
// The file targets `usaasa_mel`; this server may use another database name.
$sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
if ($db->connect_errno) {
    fwrite(STDERR, "Database connection failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');
$name = $db->real_escape_string(DB_NAME);
$db->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db(DB_NAME);

$n = 0;
if ($db->multi_query($sql)) {
    do {
        $n++;
        if ($r = $db->store_result()) {
            $r->free();
        }
    } while ($db->more_results() && $db->next_result());
}
if ($db->errno) {
    fwrite(STDERR, "Schema failed after statement $n: {$db->error}\n");
    exit(1);
}
echo "Schema applied to " . DB_NAME . " ($n statements).\n";
