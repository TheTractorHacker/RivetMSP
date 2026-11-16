<?php
declare(strict_types=1);
// Runs the REAL client/includes/check_login.php (the portal's per-request gate) in a child process against the SCRATCH database, with a given $_SESSION.
// Input: JSON in $argv[1] {session}. Prints "PASS" when the page would render, or "BLOCKED" when the gate ended the session
// (redirect() exits before the end of the file; php-cli does not record the Location header, so that is the signal).
if (PHP_SAPI !== 'cli') { exit; }
$in = json_decode((string) ($argv[1] ?? ''), true);
$root = dirname(__DIR__, 2);
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
chdir($root . '/client');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/client/index.php'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'test';
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', (string) getenv('RIVETMSP_TEST_DB_USER'), (string) getenv('RIVETMSP_TEST_DB_PASS'), (string) getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $mysqli;
$config_app_name = 'Test'; $config_base_url = 'localhost'; $config_timezone = 'UTC'; $config_https_only = false;
require $root . '/functions.php';
$_SESSION = $in['session'] ?? [];
register_shutdown_function(static function () {
    $sv = $_SESSION['portal_sv'] ?? '-';
    echo "\n::END::", (defined('PORTAL_GATE_PASSED') ? 'PASS' : 'BLOCKED'), ":sv=", $sv, "\n";
});
ob_start();
try {
    require $root . '/client/includes/check_login.php';
    define('PORTAL_GATE_PASSED', true);
} catch (Throwable $e) {
    echo 'EXC:' . $e->getMessage();
}
