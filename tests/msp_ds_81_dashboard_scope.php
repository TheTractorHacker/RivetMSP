<?php
// MSP-DS-81: dashboard my_open and queue honour the client scope. Run:
// SCRATCH_TABLES="tickets clients ticket_statuses user_client_permissions notifications" tests/lib/with_scratch_db.sh php tests/msp_ds_81_dashboard_scope.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
define('FROM_API', 1);
$method = 'GET';
class Done extends Exception { public $data; }
function api_error($c, $m) { throw new Exception("api_error $c $m"); }
function api_response($c, $d) { $e = new Done(); $e->data = $d; throw $e; }
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$mysqli->query("INSERT INTO clients (client_id, client_name) VALUES (1,'A'),(2,'B')");
$mysqli->query("INSERT INTO tickets (ticket_id, ticket_number, ticket_subject, ticket_client_id, ticket_assigned_to, ticket_status) VALUES (10,10,'in A',1,2,1),(20,20,'in B',2,2,1)");
$mysqli->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (2,1)");
function run($uid, $key = null) { global $api_user_id, $api_key_client_id, $mysqli, $method; $api_user_id = $uid; $api_key_client_id = $key;
    try { require __DIR__ . '/../api/v1/dashboard.php'; } catch (Done $d) { return $d->data; } }
$r = run(2);
$ok('restricted tech: my_open counts only client A', $r['my_open'] === 1);
$ok('restricted tech: queue lists only client A', count($r['queue']) === 1 && $r['queue'][0]['subject'] === 'in A');
$r = run(3);
$ok('unrestricted user unchanged: both clients', $r['my_open'] === 0 && count($r['queue']) === 0);
$mysqli->query("UPDATE tickets SET ticket_assigned_to = 3");
$r = run(3);
$ok('unrestricted user sees both', $r['my_open'] === 2 && count($r['queue']) === 2);
$r = run(3, 1);
$ok('legacy key scoped to client 1 sees only client 1', $r['my_open'] === 1 && count($r['queue']) === 1 && $r['queue'][0]['subject'] === 'in A');
exit($fail ? 1 : 0);
