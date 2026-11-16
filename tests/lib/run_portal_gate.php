<?php
declare(strict_types=1);
// Runs a REAL client-portal entry file in a child process against the SCRATCH database, with a given $_SESSION (and $_POST).
// Input: JSON in $argv[1] {session, post?, file?}; file defaults to includes/check_login.php (the per-request gate), or e.g. post.php (the POST handler).
// Prints "::END::PASS|BLOCKED:sv=<portal_sv>:<alert>" - PASS when the file ran to its end, BLOCKED when redirect() exit()ed first
// (php-cli does not record the Location header, so that is the signal; the flash alert, when there is one, follows).
if (PHP_SAPI !== 'cli') { exit; }
$in = json_decode((string) ($argv[1] ?? ''), true);
$root = dirname(__DIR__, 2);
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
// config.php is git-ignored and absent in a checkout/worktree: write a throwaway stub for this run only (never overwrite a real one) and remove it afterwards.
$stub = $root . '/config.php';
$made = !file_exists($stub);
if ($made) {
    file_put_contents($stub, "<?php\n\$config_https_only = false; \$config_base_url = 'localhost'; \$config_enable_setup = 0; \$repo_branch = 'test';\n");
    register_shutdown_function(static function () use ($stub, $made) { if ($made) { @unlink($stub); } });
}
chdir($root . '/client');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/client/index.php'; $_SERVER['REQUEST_METHOD'] = isset($in['post']) ? 'POST' : 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'test';
$_POST = $in['post'] ?? [];
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', (string) getenv('RIVETMSP_TEST_DB_USER'), (string) getenv('RIVETMSP_TEST_DB_PASS'), (string) getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $mysqli;
$config_app_name = 'Test'; $config_base_url = 'localhost'; $config_timezone = 'UTC'; $config_https_only = false;
require $root . '/functions.php';
$_SESSION = $in['session'] ?? [];
if (isset($in['post'])) { $_POST['csrf_token'] = $_SESSION['csrf_token'] ?? 'x'; }
register_shutdown_function(static function () {
    $alert = isset($_SESSION['alert_type']) ? $_SESSION['alert_type'] . ':' . strip_tags((string) ($_SESSION['alert_message'] ?? '')) : 'none';
    echo "\n::END::", (defined('PORTAL_GATE_PASSED') ? 'PASS' : 'BLOCKED'), ":sv=", $_SESSION['portal_sv'] ?? '-', ":", $alert, "\n";
});
ob_start();
try {
    require $root . '/client/' . ($in['file'] ?? 'includes/check_login.php');
    define('PORTAL_GATE_PASSED', true);
} catch (Throwable $e) {
    echo 'EXC:' . $e->getMessage();
}
