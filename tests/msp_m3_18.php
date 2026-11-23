<?php
// MSP-DS-156 (re-review M3-18): the unowned-schedules log row of DB 2.6.140 listed EVERY id into logs.log_description varchar(1000); about 146+ unowned schedules overflowed it and,
// in strict sql_mode, aborted the update. The list is now cut (first 40 ids + "and N more") like the other rows. 200 ownerless schedules, STRICT_ALL_TABLES session.
// The real step text of admin/database_updates.php runs on tables built from the OLD db.sql (7992dd4ff).
// Run: SCRATCH_TABLES="domains report_schedules settings logs notifications users user_roles" bash tests/lib/with_scratch_db.sh php tests/msp_m3_18.php
require __DIR__ . '/lib/ds_src.php';
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // like the real Update Database request: a failing statement throws and aborts the update
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$one = fn (string $s) => ($r = $mysqli->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$f = ds_src('admin/database_updates.php');
$a = strpos($f, "    if (\$rivetit_db_version() == '2.6.139') {");
$b = strpos($f, "    if (\$rivetit_db_version() == '2.6.140') {");
$step = substr($f, $a, $b - $a);
$rivetit_db_version = static function () use ($mysqli): string { return (string) $mysqli->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0]; };
$run = function () use ($step, $mysqli, $rivetit_db_version) { eval($step); };
$old = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' show 7992dd4ff:db.sql');
foreach (['domains', 'report_schedules'] as $t) {
    preg_match('/CREATE TABLE `' . $t . '` \(.*?\n\) ENGINE[^\n]*;/s', $old, $mm);
    $mysqli->query("DROP TABLE `$t`");
    $mysqli->multi_query($mm[0]); while ($mysqli->more_results() && $mysqli->next_result()) {}
}
$mysqli->query("DELETE FROM settings"); $mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.139')");
$mysqli->query("DELETE FROM user_roles"); $mysqli->query("DELETE FROM users"); $mysqli->query("DELETE FROM notifications"); $mysqli->query("DELETE FROM logs");
$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0)");
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id) VALUES (1,'admin1','a1@x.t','x',1,1,1)");
$vals = []; for ($i = 1; $i <= 200; $i++) { $vals[] = "($i,'mrr','daily','r$i@x.t')"; }
$mysqli->query("INSERT INTO report_schedules (schedule_id, schedule_report, schedule_frequency, schedule_recipients) VALUES " . implode(',', $vals));
$abort = ''; try { $run(); } catch (Throwable $e) { $abort = $e->getMessage(); }
ds_t('strict mode: 200 unowned schedules do not abort the update (reaches 2.6.140)', $rivetit_db_version() === '2.6.140' && $abort === '');
$d = (string) $one("SELECT log_description FROM logs WHERE log_action = 'Review' AND log_description LIKE 'MSP-DS-156: report schedule(s)%'");
ds_t('the Review log row exists, fits varchar(1000) and says how many more', $d !== '' && strlen($d) <= 1000 && str_contains($d, 'and 160 more') && str_contains($d, '#1, #2') && str_contains($d, 'have no known creator'));
ds_t('the admin notification still states the full count of 200', (int) $one("SELECT COUNT(*) FROM notifications WHERE notification LIKE '200 scheduled report(s)%' AND notification_user_id = 1") === 1);
ds_done();
