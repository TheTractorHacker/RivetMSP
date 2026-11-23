<?php
declare(strict_types=1);
// Runs the REAL admin/database_updates.php (all pending version steps) in a child process against the SCRATCH database.
// Input: argv[1] = LATEST version, argv[2] = CURRENT version (what the page would have read before the run). Prints "OK" or the exception.
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
// default mysqli error mode on purpose: that is how the real Update Database request runs, so a failing ALTER would be an exception here too
$mysqli = new mysqli('localhost', (string) getenv('RIVETMSP_TEST_DB_USER'), (string) getenv('RIVETMSP_TEST_DB_PASS'), (string) getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
define('LATEST_DATABASE_VERSION', (string) $argv[1]);
define('CURRENT_DATABASE_VERSION', (string) $argv[2]);
ob_start();
try {
    require $root . '/admin/database_updates.php';
    ob_end_clean();
    echo "OK\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo 'EXC: ' . $e->getMessage() . "\n";
}
