<?php
// MSP-DS-156 (re-review MRR16): report schedules created before schedule_created_by existed (NULL creator) used to keep sending forever.
// Now the 2.6.140 step attributes them from the "Report Schedule / Create" audit row when that is unambiguous, the rest have no owner and do NOT send
// (reportScheduleCreatorMaySend fails closed) until someone allowed to send the report takes ownership (Reports > Schedules).
// The real step text of admin/database_updates.php runs on tables built from the OLD db.sql (7992dd4ff); the real guard and the real scheduler/handler sources are used.
// Run: SCRATCH_TABLES="domains report_schedules settings logs notifications" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_16.php
require __DIR__ . '/lib/ds_src.php';
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $mysqli->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
require dirname(__DIR__) . '/includes/report_schedule_guards.php';
$f = ds_src('admin/database_updates.php');
$a = strpos($f, "    if (\$rivetit_db_version() == '2.6.139') {");
$b = strpos($f, "    if (\$rivetit_db_version() == '2.6.140') {");
$step = substr($f, $a, $b - $a);
$rivetit_db_version = static function () use ($mysqli): string { return (string) $mysqli->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0]; };
$run = function () use ($step, $mysqli, $rivetit_db_version) { eval($step); };   // the real migration step text

$old = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' show 7992dd4ff:db.sql');
foreach (['domains', 'report_schedules'] as $t) {
    preg_match('/CREATE TABLE `' . $t . '` \(.*?\n\) ENGINE[^\n]*;/s', $old, $mm);
    $mysqli->query("DROP TABLE `$t`");
    $mysqli->multi_query($mm[0]); while ($mysqli->more_results() && $mysqli->next_result()) {}
}
$mysqli->query("DELETE FROM settings"); $mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.139')");
$mysqli->query("INSERT INTO report_schedules (schedule_id, schedule_report, schedule_frequency, schedule_recipients) VALUES
  (1,'mrr','daily','a@x.t, b@x.t'),      -- one Create log by user 5 -> attributable
  (2,'mrr','weekly','a@x.t'),             -- two different users logged an identical Create -> ambiguous
  (3,'income_summary','monthly','c@x.t'), -- no log row at all
  (4,'mrr','daily','50%_odd@x.t')");      // LIKE wildcards in the recipient list must not over-match
$mysqli->query("INSERT INTO logs (log_type, log_action, log_description, log_user_id) VALUES
  ('Report Schedule','Create',\"Scheduled 'MRR' (daily) to a@x.t, b@x.t\",5),
  ('Report Schedule','Create',\"Scheduled 'MRR' (weekly) to a@x.t\",5),
  ('Report Schedule','Create',\"Scheduled 'MRR' (weekly) to a@x.t\",6),
  ('Report Schedule','Create',\"Scheduled 'MRR' (daily) to xx50%_odd@x.t-other\",5)");
$run();
$creator = fn (int $id) => $one("SELECT schedule_created_by FROM report_schedules WHERE schedule_id = $id");
ds_t('step ran', $rivetit_db_version() === '2.6.140');
ds_t('schedule with exactly one matching Create log is attributed to that user', (int) $creator(1) === 5);
ds_t('ambiguous (two users) stays without an owner', $creator(2) === null);
ds_t('schedule with no log stays without an owner', $creator(3) === null);
ds_t('LIKE wildcards in recipients do not attribute by accident', $creator(4) === null);
ds_t('attributions and the unowned set are logged', (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-156: report schedule #1 attributed%'") === 1
    && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'Review' AND log_description LIKE 'MSP-DS-156: report schedule(s) #2, #3, #4 %'") === 1);
ds_t('one admin notification for the unowned schedules', (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Report Schedule' AND notification LIKE '3 scheduled report(s)%'") === 1);

// default-safe at send time: ownerless schedules never send, whatever the report
ds_t('guard: NULL / empty / 0 creator does NOT send', reportScheduleCreatorMaySend($mysqli, null, 'mrr') === false && reportScheduleCreatorMaySend($mysqli, '', 'mrr') === false && reportScheduleCreatorMaySend($mysqli, 0, 'mrr') === false);
// the real scheduler asks the guard with the row's own creator before rendering anything
$cron = ds_src('cron/report_scheduler.php');
ds_t('scheduler passes the stored creator to the guard before rendering', preg_match('/reportScheduleCreatorMaySend\(\$mysqli, \$sched\[.schedule_created_by.\]/', $cron) === 1
    && strpos($cron, 'reportScheduleCreatorMaySend(') < strpos($cron, 'report_render_email_html($mysqli, $report_key)'));
// re-running the step does not change owners or duplicate attributions
$mysqli->query("UPDATE settings SET config_current_database_version = '2.6.139'");
$run();
ds_t('re-run keeps the same owners', (int) $creator(1) === 5 && $creator(2) === null);
// takeover: only for ownerless rows, only after the write/module check, and it is logged
$ui = ds_src('agent/reports/schedules.php');
$pos = strpos($ui, "isset(\$_GET['claim'])");
$blk = substr($ui, $pos, 1500);
ds_t('take-ownership handler is CSRF-checked, permission-checked before the UPDATE, only touches ownerless rows and is logged',
    $pos !== false && strpos($blk, 'validateCSRFToken') !== false && strpos($blk, '$requireScheduleWrite(') < strpos($blk, 'UPDATE report_schedules SET schedule_created_by')
    && substr_count($blk, 'schedule_created_by IS NULL OR schedule_created_by = 0') >= 2 && strpos($blk, 'logAction(') !== false);
ds_done();
