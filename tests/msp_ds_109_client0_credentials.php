<?php
// MSP-DS-109: client-0 (MSP-internal) credentials are not open to client-restricted users on the vault reveal and the API credential detail. Run:
// SCRATCH_TABLES="user_client_permissions" tests/lib/with_scratch_db.sh php tests/msp_ds_109_client0_credentials.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
define('FROM_API', 1);
function api_error($c, $m) { throw new Exception("api_error $c"); }
require dirname(__DIR__) . '/includes/vault_reveal.php';
require dirname(__DIR__) . '/api/v1/includes/api_permissions.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$mysqli->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (2, 1)");   // user 2: restricted to client 1; user 3: unrestricted
$ok('vault: admin reaches client 0', vaultUserCanAccessClient($mysqli, 1, true, 0));
$ok('vault: unrestricted tech reaches client 0 (unchanged)', vaultUserCanAccessClient($mysqli, 3, false, 0));
$ok('vault: unrestricted tech reaches client 5 (unchanged)', vaultUserCanAccessClient($mysqli, 3, false, 5));
$ok('vault: restricted tech is REFUSED client 0', !vaultUserCanAccessClient($mysqli, 2, false, 0));
$ok('vault: restricted tech reaches its own client 1', vaultUserCanAccessClient($mysqli, 2, false, 1));
$ok('vault: restricted tech refused client 2', !vaultUserCanAccessClient($mysqli, 2, false, 2));
$api_user_id = 2; $api_key_client_id = null;
$ok('api: restricted user is REFUSED client 0', !api_client_scope_ok(0));
$ok('api: restricted user reaches client 1', api_client_scope_ok(1));
$api_user_id = 3;
$ok('api: unrestricted user reaches client 0 (unchanged)', api_client_scope_ok(0));
$c = file_get_contents(dirname(__DIR__) . '/api/v1/credentials.php');
$ok('api credentials.php no longer skips the scope check for client 0', str_contains($c, 'if (!api_client_scope_ok($cred_client_id)) {') && !str_contains($c, '$cred_client_id > 0 && !api_client_scope_ok'));
exit($fail ? 1 : 0);
