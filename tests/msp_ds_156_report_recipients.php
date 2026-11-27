<?php
// MSP-DS-156: scheduled-report recipients must hold the report's module and be unrestricted.
// Run: SCRATCH_TABLES="users user_roles user_role_permissions modules user_client_permissions" tests/lib/with_scratch_db.sh php tests/msp_ds_156_report_recipients.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/report_schedule_guards.php';
foreach (['users', 'user_roles', 'user_role_permissions', 'modules', 'user_client_permissions'] as $t) { $m->query("DELETE FROM $t"); }
$m->query("INSERT INTO modules (module_id, module_name) VALUES (1,'module_support'),(2,'module_financial')");
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0),(3,'Accountant',0)");
$m->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2,1,2),(3,2,1)");
$m->query("INSERT INTO users (user_id, user_name, user_email, user_type, user_status, user_role_id) VALUES (1,'a','admin@x.t',1,1,1),(2,'t','tech@x.t',1,1,2),(3,'c','acct@x.t',1,1,3),(4,'r','restricted@x.t',1,1,3)");
$m->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (4,10)");
$fin = reportScheduleEligibleEmails($m, 'mrr'); sort($fin);
$ok('financial report: admin + accountant only (' . implode(',', $fin) . ')', $fin === ['acct@x.t', 'admin@x.t']);
$sup = reportScheduleEligibleEmails($m, 'ticket_summary'); sort($sup);
$ok('support report: admin + tech only (' . implode(',', $sup) . ')', $sup === ['admin@x.t', 'tech@x.t']);
$ok('client-restricted user never gets a report', !in_array('restricted@x.t', array_merge($fin, $sup), true));
$ok('scheduler applies the filter', str_contains(file_get_contents(__DIR__ . '/../cron/report_scheduler.php'), 'reportScheduleEligibleEmails($mysqli, $report_key)'));
exit($fail ? 1 : 0);
