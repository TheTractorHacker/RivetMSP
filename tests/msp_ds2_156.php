<?php
// MSP-DS-156 (rest): the creator is re-checked at send time; creation also filters recipients by what they may read.
// Run: SCRATCH_TABLES="users user_roles user_role_permissions modules user_client_permissions report_schedules" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_156.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/report_schedule_guards.php';
foreach (['users', 'user_roles', 'user_role_permissions', 'modules', 'user_client_permissions'] as $t) { $m->query("DELETE FROM $t"); }
$m->query("INSERT INTO modules (module_id, module_name) VALUES (1,'module_support'),(2,'module_financial'),(3,'module_reporting')");
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Fin+Rep',0),(3,'Fin only',0),(4,'Rep write only',0),(5,'Rep read + Fin',0)");
$m->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2,2,1),(2,3,2),(3,2,3),(4,3,2),(5,2,1),(5,3,1)");
$m->query("INSERT INTO users (user_id, user_name, user_email, user_type, user_status, user_role_id, user_archived_at) VALUES
  (1,'admin','admin@x.t',1,1,1,NULL),(2,'finrep','finrep@x.t',1,1,2,NULL),(3,'fin','fin@x.t',1,1,3,NULL),(4,'rep','rep@x.t',1,1,4,NULL),
  (5,'ro','ro@x.t',1,1,5,NULL),(6,'gone','gone@x.t',1,1,2,NOW()),(7,'off','off@x.t',1,0,2,NULL)");
$may = function ($uid, $report) use ($m) { return reportScheduleCreatorMaySend($m, $uid, $report); };
$ok('legacy schedule (no creator recorded) keeps sending', $may(null, 'mrr') === true);
$ok('admin creator may send', $may(1, 'mrr'));
$ok('creator with reporting write + financial may send a financial report', $may(2, 'mrr'));
$ok('creator who lost the financial module may NOT send a financial report', !$may(4, 'mrr'));
$ok('creator without reporting write may NOT send', !$may(3, 'mrr') && !$may(5, 'mrr'));
$ok('creator with reporting write but no support module may NOT send a support report', !$may(2, 'ticket_summary'));
$ok('archived creator may NOT send', !$may(6, 'mrr'));
$ok('disabled creator may NOT send', !$may(7, 'mrr'));
$ok('deleted creator may NOT send', !$may(999, 'mrr'));
// wiring: scheduler calls it before rendering/queueing; creation records the creator and filters by eligibility
$sch = file_get_contents(__DIR__ . '/../cron/report_scheduler.php');
$ok('scheduler checks the creator before rendering', ($a = strpos($sch, 'reportScheduleCreatorMaySend(')) !== false && $a < strpos($sch, 'report_render_email_html($mysqli, $report_key)'));
$ui = file_get_contents(__DIR__ . '/../agent/reports/schedules.php');
$ok('schedule creation records the creator', strpos($ui, 'schedule_created_by') !== false && strpos($ui, 'intval($session_user_id)') !== false);
$ok('schedule creation filters recipients by eligibility', strpos($ui, 'reportScheduleEligibleEmails($mysqli, $report)') !== false);
exit($fail ? 1 : 0);
