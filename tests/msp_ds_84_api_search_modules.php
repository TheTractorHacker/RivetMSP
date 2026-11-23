<?php
// MSP-DS-84: api_module_permission_ok() (per-section module gate for /search, /products). Run:
// SCRATCH_TABLES="users user_roles modules user_role_permissions" tests/lib/with_scratch_db.sh php tests/msp_ds_84_api_search_modules.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
define('FROM_API', 1);
function api_error($c, $m) { throw new Exception("api_error $c"); }
require __DIR__ . '/../api/v1/includes/api_permissions.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Acct',0),(3,'Tech',0)");
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_role_id) VALUES (1,'a','a@x',1),(2,'b','b@x',2),(3,'c','c@x',3)");
$mysqli->query("INSERT INTO modules (module_id, module_name) VALUES (1,'module_support'),(2,'module_client'),(3,'module_sales')");
$mysqli->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2,3,1),(2,2,1),(3,1,2),(3,2,1)");
$ok('admin passes any module', api_module_permission_ok($mysqli, 1, 'module_support'));
$ok('accountant (no module_support) is refused support', !api_module_permission_ok($mysqli, 2, 'module_support'));
$ok('accountant has module_client', api_module_permission_ok($mysqli, 2, 'module_client'));
$ok('tech has module_support at level 2', api_module_permission_ok($mysqli, 3, 'module_support', 2));
$ok('tech lacks module_sales', !api_module_permission_ok($mysqli, 3, 'module_sales'));
$ok('unknown user fails closed', !api_module_permission_ok($mysqli, 99, 'module_support'));
$refused = false; try { api_require_module_permission($mysqli, 2, 'module_support'); } catch (Exception $e) { $refused = $e->getMessage() === 'api_error 403'; }
$ok('api_require_module_permission still ends with 403', $refused);
$s = file_get_contents(__DIR__ . '/../api/v1/search.php');
$ok('search.php gates tickets, assets and clients', substr_count($s, '!$can_support ? [] : api_q(') === 2 && substr_count($s, '!$can_client ? [] : api_q(') === 1);
$p = file_get_contents(__DIR__ . '/../api/v1/products.php');
$ok('products.php has a module check', str_contains($p, "api_module_permission_ok(\$mysqli, intval(\$api_user_id), 'module_sales')"));
exit($fail ? 1 : 0);
