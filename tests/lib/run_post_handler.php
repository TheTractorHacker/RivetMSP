<?php
declare(strict_types=1);
// Runs ONE agent/post/*.php handler in a child process as a given user, against the SCRATCH database named in the environment (see tests/security_behaviour_scratch.php).
// Input: JSON in $argv[1] {handler, post, get, user_id}. Prints the captured output (CSV, JSON, HTML) and a final line "::END::<exit state>".
if (PHP_SAPI !== 'cli') { exit; }
$in = json_decode((string) ($argv[1] ?? ''), true);
$root = dirname(__DIR__, 2);
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
chdir($root . '/agent');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/agent/post.php'; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $in['post'] ?? []; $_GET = $in['get'] ?? [];
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', (string) getenv('RIVETMSP_TEST_DB_USER'), (string) getenv('RIVETMSP_TEST_DB_PASS'), (string) getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $mysqli;
$config_app_name = 'Test'; $config_base_url = 'localhost'; $config_timezone = 'UTC';
require $root . '/functions.php';
$uid = (int) $in['user_id'];
$u = $mysqli->query("SELECT u.user_name, u.user_role_id, r.role_is_admin FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = $uid")->fetch_assoc();
$session_user_id = $uid; $session_name = $u['user_name']; $session_user_role = (int) $u['user_role_id']; $session_is_admin = (int) $u['role_is_admin'] === 1;
$session_user_agent = 'test'; $session_ip = '127.0.0.1'; $session_company_name = 'T'; $session_user_type = 1; $session_email = 'x@x.test';
$_SESSION = ['csrf_token' => 'tok', 'logged' => true, 'user_id' => $uid];
$_POST['csrf_token'] = 'tok'; $_GET['csrf_token'] = 'tok';
$client_access_array = [];
$r = $mysqli->query("SELECT client_id FROM user_client_permissions WHERE user_id = $uid"); while ($r && ($x = $r->fetch_row())) { $client_access_array[] = $x[0]; }
$client_access_string = implode(',', $client_access_array);
$access_permission_query = ($client_access_string && !$session_is_admin) ? "AND clients.client_id IN ($client_access_string)" : '';
define('FROM_POST_HANDLER', true);
ob_start();
register_shutdown_function(static function () { $o = ob_get_clean(); echo $o, "\n::END::", (isset($_SESSION['alert_type']) ? $_SESSION['alert_type'] . ':' . strip_tags((string) ($_SESSION['alert_message'] ?? '')) : 'none'), "\n"; });
try { require $root . '/agent/post/' . $in['handler']; } catch (Throwable $e) { echo 'EXC:' . $e->getMessage(); }
