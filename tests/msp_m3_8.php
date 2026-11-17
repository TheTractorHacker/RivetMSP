<?php
// MSP-DS-16/33 + MSP-DS-156 (re-review M3-8): the DB 2.6.140 admin notifications were inserted with notification_user_id = 0, which no web page shows and any technician can
// dismiss for everyone in the mobile API. They are now one row per ACTIVE ADMIN (like appNotify): the technician and archived admins get none, and dismissing one
// admin's row leaves the others. The real step text of admin/database_updates.php runs on tables built from the OLD db.sql (7992dd4ff).
// Run: SCRATCH_TABLES="domains report_schedules settings logs notifications users user_roles" bash tests/lib/with_scratch_db.sh php tests/msp_m3_8.php
require __DIR__ . '/lib/ds_src.php';
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
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
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id, user_archived_at) VALUES
  (1,'admin1','a1@x.t','x',1,1,1,NULL),(2,'admin2','a2@x.t','x',1,1,1,NULL),(3,'tech','t@x.t','x',1,1,2,NULL),(4,'oldadmin','o@x.t','x',1,1,1,NOW()),(5,'portal','p@x.t','x',2,1,1,NULL)");
$mysqli->query("INSERT INTO domains (domain_id, domain_name, domain_client_id) VALUES (1,'planted.example', 7)");
$mysqli->query("INSERT INTO report_schedules (schedule_id, schedule_report, schedule_frequency, schedule_recipients) VALUES (1,'mrr','daily','a@x.t')");
$run();
ds_t('step ran', $rivetit_db_version() === '2.6.140');
ds_t('no notification row has user id 0', (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id = 0") === 0);
foreach (['Domain', 'Report Schedule'] as $type) {
    ds_t("$type: one row for each active admin (1 and 2)", (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = '$type' AND notification_user_id IN (1,2)") === 2);
    ds_t("$type: none for the technician, the archived admin or the portal login", (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = '$type' AND notification_user_id IN (3,4,5)") === 0);
}
$mysqli->query("DELETE FROM notifications WHERE notification_type = 'Domain' AND notification_user_id = 1");   // admin 1 dismisses theirs
ds_t('one admin dismissing the notice leaves it for the other admin', (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Domain' AND notification_user_id = 2") === 1);
ds_done();
