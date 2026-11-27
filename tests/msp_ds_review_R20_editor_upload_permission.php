<?php
// Review R20 (MSP-DS-50): the shared TinyMCE image upload accepts Write on KB, support or client work; read-only and unrelated roles stay refused.
// SCRATCH_TABLES="modules user_role_permissions" tests/lib/with_scratch_db.sh php tests/msp_ds_review_R20_editor_upload_permission.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
function sanitizeInput($v) { global $mysqli; return mysqli_real_escape_string($mysqli, strip_tags(trim((string) $v))); }
function denyAccess($m = '') { throw new RuntimeException('denied'); }
define('WORDING_ROLECHECK_FAILED', 'x');
$root = dirname(__DIR__);
$fn = file_get_contents("$root/functions.php");
foreach (['function lookupUserPermission', 'function enforceUserPermission', 'function enforceEditorImageUploadPermission'] as $sig) {
    $a = strpos($fn, $sig); preg_match('/\n\}\n/', $fn, $m, PREG_OFFSET_CAPTURE, $a); eval(substr($fn, $a, $m[0][1] + 3 - $a));
}
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
foreach (['module_kb' => 1, 'module_support' => 2, 'module_client' => 3] as $name => $id) { $mysqli->query("INSERT INTO modules (module_id, module_name) VALUES ($id, '$name')"); }
$session_is_admin = false;
$can = function (int $role, array $levels) use ($mysqli, &$session_user_role) {
    $mysqli->query("DELETE FROM user_role_permissions WHERE user_role_id = $role");
    foreach ($levels as $mid => $lvl) { $mysqli->query("INSERT INTO user_role_permissions VALUES ($role, $mid, $lvl)"); }
    $GLOBALS['session_user_role'] = $role;
    try { enforceEditorImageUploadPermission(); return true; } catch (RuntimeException $e) { return false; }
};
$ok('support Write + KB Read (the R20 scenario) can upload', $can(10, [1 => 1, 2 => 2]));
$ok('KB Write only can upload', $can(11, [1 => 2]));
$ok('client Write only (bulk email) can upload', $can(12, [3 => 2]));
$ok('KB Read only refused', !$can(13, [1 => 1]));
$ok('support Read + client Read refused', !$can(14, [2 => 1, 3 => 1]));
$ok('no permissions refused', !$can(15, []));
$session_is_admin = true; $ok('admin allowed', $can(16, []));
$src = file_get_contents("$root/agent/kb_article_upload.php");
$ok('endpoint still checks same-origin, CSRF and real image bytes', str_contains($src, 'requestIsSameOrigin') && str_contains($src, 'validateCSRFToken') && str_contains($src, 'getimagesize'));
$ok('endpoint uses the shared check', str_contains($src, 'enforceEditorImageUploadPermission()') && !str_contains($src, "enforceUserPermission('module_kb', 2)"));
exit($fail ? 1 : 0);
