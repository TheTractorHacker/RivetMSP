<?php
declare(strict_types=1);
// Runs the REAL guest/guest_post.php in a child process against the SCRATCH database.
// Input: JSON in $argv[1] {post, files?: [{name, content}], ip?}. Prints "::END::<alert type>:<message>". php-cli cannot move_uploaded_file(), which the handler
// ignores, so a stored file shows up as a row in `files` / `quote_files`.
if (PHP_SAPI !== 'cli') { exit; }
$in = json_decode((string) ($argv[1] ?? ''), true);
$root = dirname(__DIR__, 2);
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$stub = $root . '/config.php';
$made = !file_exists($stub);
if ($made) {
    file_put_contents($stub, "<?php\n\$config_https_only = false; \$config_base_url = 'localhost'; \$config_enable_setup = 0; \$repo_branch = 'test';\n");
    register_shutdown_function(static function () use ($stub, $made) { if ($made) { @unlink($stub); } });
}
chdir($root . '/guest');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = $in['ip'] ?? '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/guest/guest_post.php'; $_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'test';
$_POST = $in['post'] ?? [];
$files = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
foreach ($in['files'] ?? [] as $i => $f) {
    $tmp = tempnam(sys_get_temp_dir(), 'gq');
    file_put_contents($tmp, (string) ($f['content'] ?? '%PDF-1.4'));
    $files['name'][] = $f['name']; $files['type'][] = 'application/pdf'; $files['tmp_name'][] = $tmp; $files['error'][] = 0; $files['size'][] = filesize($tmp);
}
$_FILES = $files['name'] ? ['file' => $files] : [];
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', (string) getenv('RIVETMSP_TEST_DB_USER'), (string) getenv('RIVETMSP_TEST_DB_PASS'), (string) getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode='" . $mysqli->real_escape_string((string) getenv('RIVETMSP_TEST_SQL_MODE')) . "'"); // RIVETMSP_TEST_SQL_MODE=STRICT_ALL_TABLES runs the handler as production does
$GLOBALS['mysqli'] = $mysqli;
register_shutdown_function(static function () use ($files) {
    foreach ($files['tmp_name'] as $t) { @unlink($t); }
    $alert = isset($_SESSION['alert_type']) ? $_SESSION['alert_type'] . ':' . strip_tags((string) ($_SESSION['alert_message'] ?? '')) : 'none';
    echo "\n::END::", $alert, "\n";
});
ob_start();
try {
    require $root . '/guest/guest_post.php';
} catch (Throwable $e) {
    echo 'EXC:' . $e->getMessage();
}
