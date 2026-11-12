<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Behavioural proof on a SCRATCH database loaded from db.sql (RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_x ...): MSP-INJ-11 recipient scoping
// (the exact SQL fragment from appNotify) and MSP-INJ-16 (secMfaEnforcementState, which the API sign-in uses). Deletes users/roles/permissions in that database.
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: needs RIVETMSP_TEST_DB=1 and a DB name containing 'scratch'\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('RIVETMSP_TEST_DB_HOST') ?: 'localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $mysqli=$m;
function nullable_htmlentities($s){return $s;}
require ''.dirname(__DIR__).'/includes/security_policy.php';
$q=fn($s)=>$m->query($s) or die($m->error."\n".$s);
$q("SET SESSION sql_mode=''"); $q("DELETE FROM users"); $q("DELETE FROM modules"); $q("DELETE FROM user_role_permissions"); $q("DELETE FROM user_roles"); $q("DELETE FROM user_client_permissions");
$q("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Adm',1),(92,'Tech',0)");
// users: 1 admin, 2 unrestricted tech, 3 tech restricted to client 5, 4 tech restricted to client 6
foreach ([[1,91],[2,92],[3,92],[4,92]] as [$id,$r]) { $q("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status,user_token,user_auth_method,user_created_at) VALUES ($id,'u$id','u$id@x.test','x',$r,1,1,'','local','2020-01-01')"); }
$q("INSERT INTO user_client_permissions (user_id,client_id) VALUES (3,5),(4,6)");
$fail=0; $ok=function($n,$c) use(&$fail){echo ($c?'ok   ':'FAIL ').$n."\n"; $fail+=$c?0:1;};
// ---- INJ-11: the exact fragment from functions.php appNotify
$src=file_get_contents(''.dirname(__DIR__).'/functions.php'); preg_match('/\$client_scope_sql = \'\';\n    if \(\$client_id > 0\) \{\n        \$client_scope_sql = (.*?);\n    \}/s',$src,$mm);
$run=function(int $client_id) use($m,$mm){ eval('$client_scope_sql = '.$mm[1].';'); if ($client_id === 0) { $client_scope_sql = ''; } $r=$m->query("SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL$client_scope_sql ORDER BY user_id"); $o=[]; while($x=$r->fetch_row()) $o[]=(int)$x[0]; return $o; };
$ok('client 5 -> admin, unrestricted, and the user restricted to 5 (not the one restricted to 6)', $run(5)===[1,2,3]);
$ok('client 6 -> admin, unrestricted, user restricted to 6', $run(6)===[1,2,4]);
$ok('no client (0) -> everyone', $run(0)===[1,2,3,4]);
// ---- INJ-16: must-have-MFA, unenrolled, out of grace
$q("REPLACE INTO security_settings (setting_key,setting_value) VALUES ('mfa_policy','all'),('mfa_policy_since','2020-01-01 00:00:00'),('mfa_grace_days','7')");
secSettingsAll($m,true);
$s=secMfaEnforcementState($m,2); $ok('policy all: unenrolled user is blocked (out of grace) -> API must refuse', $s['applies'] && !$s['enrolled'] && !$s['in_grace'] && $s['blocked']);
$q("UPDATE users SET user_token='wrapped' WHERE user_id=2"); $s=secMfaEnforcementState($m,2); $ok('enrolled user is not blocked', $s['enrolled'] && !$s['blocked']);
$q("UPDATE users SET user_created_at=NOW() WHERE user_id=3"); $q("REPLACE INTO security_settings (setting_key,setting_value) VALUES ('mfa_policy_since','".date('Y-m-d H:i:s')."')");
secSettingsAll($m,true); $s=secMfaEnforcementState($m,3); $ok('inside the grace period the account is not blocked', $s['in_grace'] && !$s['blocked']);
// ---- RMM remote launch / device access (CORE-14: Mesh does not separate clients, so this edition scope is the barrier)
require dirname(__DIR__) . '/vendor/autoload.php';
$q("INSERT INTO modules (module_id, module_name) VALUES (901,'module_rmm'),(902,'module_rmm_remote_connect'),(903,'module_rmm_scripts')");
$q("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92,901,1),(92,902,1),(92,903,1)");
$db = new \RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter($m);
$auth = new \RivetCore\Rmm\Authz\RmmAuthorizer(new \RivetMSP\Core\Adapter\Endpoint\EndpointAccessPolicy($db), new \RivetMSP\Core\Adapter\Endpoint\EndpointTenancy($db), static fn (): bool => true, 'client', [], false);
$L = \RivetCore\Rmm\Authz\RmmAbility::REMOTE_LAUNCH;
$ok('remote.launch: tech restricted to client 5 may open a session on a client-5 device', $auth->allowed(3, $L, 5));
$ok('remote.launch: the same tech is refused on a client-6 device', !$auth->allowed(3, $L, 6));
$ok('remote.launch: unrestricted tech may open on client 6', $auth->allowed(2, $L, 6));
$ok('remote.launch: needs module_rmm_remote_connect (level 0 -> refused)', (function () use ($q, $auth, $L) { $q("UPDATE user_role_permissions SET user_role_permission_level = 0 WHERE module_id = 902"); $r = !$auth->allowed(2, $L, 6); $q("UPDATE user_role_permissions SET user_role_permission_level = 1 WHERE module_id = 902"); return $r; })());
$ok('run_script needs scripts>=3: a level-1 tech is refused, reboot (>=2) refused too', !$auth->allowed(2, \RivetCore\Rmm\Authz\RmmAbility::JOB_RUN_SCRIPT, 5) && !$auth->allowed(2, \RivetCore\Rmm\Authz\RmmAbility::JOB_REBOOT, 5));
$ok('admin is allowed everywhere', $auth->allowed(1, $L, 6) && $auth->allowed(1, \RivetCore\Rmm\Authz\RmmAbility::JOB_RUN_SCRIPT, 6));
// ---- MSP-WEB-1: the non-reveal disclosure paths honour the reveal limit and the bulk step-up
require dirname(__DIR__) . '/includes/vault_reveal.php';
$GLOBALS['session_user_id'] = 2; $GLOBALS['session_name'] = 'u2'; $GLOBALS['session_is_admin'] = 0;
$_SESSION = [];
$q("DELETE FROM logs");
$ok('under the limit: a one-off disclosure is allowed', vaultDisclosureCheck($m, 'asset page', 1) === null);
$ok('bulk (export/PDF) without a recent password entry is refused', vaultDisclosureCheck($m, 'credential export', 5, true) !== null);
$_SESSION['vault_stepup_at'] = time();
$ok('bulk after the step-up is allowed', vaultDisclosureCheck($m, 'client pdf', 5, true) === null);
for ($i = 0; $i < 30; $i++) { $q("INSERT INTO logs (log_type, log_action, log_description, log_user_id, log_created_at) VALUES ('Credential','Reveal','x',2,NOW())"); }
$ok('at the limit (30 reveals in 10 minutes): every other path is refused too', vaultDisclosureCheck($m, 'global search', 1) !== null && vaultDisclosureCheck($m, 'share link', 1) !== null);
echo $fail?"FAILED\n":"all ok\n";
