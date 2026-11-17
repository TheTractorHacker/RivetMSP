<?php
// MSP-DS-16/33 (re-review MRR17): domain claims that the 2.6.140 migration grandfathers for inbound mail routing are logged one by one (type Database, action Review)
// and the admins get ONE notification pointing at Domains, so a claim planted before the upgrade can be audited. Domains that are not grandfathered are not listed.
// The real step text of admin/database_updates.php runs against tables built from the OLD db.sql (7992dd4ff).
// Run: SCRATCH_TABLES="domains report_schedules settings logs notifications users user_roles" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_17.php
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
$mysqli->query("DELETE FROM user_roles"); $mysqli->query("DELETE FROM users"); $mysqli->query("DELETE FROM notifications");
$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0)");
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id) VALUES (1,'admin','a@x.t','x',1,1,1),(2,'tech','t@x.t','x',1,1,2)");
$mysqli->query("INSERT INTO domains (domain_id, domain_name, domain_client_id) VALUES
  (1,'planted.example', 7), (2,'dup.example', 3), (3,'dup.example', 4), (4,'gmail.com', 5), (5,'legit.example', 8)");
$run();
ds_t('step ran and reached 2.6.140', $rivetit_db_version() === '2.6.140');
ds_t('grandfathered domains keep routing (compatibility)', (int) $one("SELECT COUNT(*) FROM domains WHERE domain_route_mail = 1") === 2);
ds_t('one review log row per grandfathered domain', (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Database' AND log_action = 'Review' AND log_description LIKE 'MSP-DS-16/33:%'") === 2);
ds_t('the log row names the domain and client and carries ids for the Domains view', (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%planted.example (id 1)%' AND log_client_id = 7 AND log_entity_id = 1") === 1);
ds_t('ambiguous / freemail domains (not grandfathered) are not listed', (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%dup.example%' OR log_description LIKE '%gmail.com%'") === 0);
ds_t('admins get exactly one notification pointing at Domains', (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Domain' AND notification_action = 'domains.php' AND notification LIKE '2 domain(s) were grandfathered%' AND notification_user_id = 1") === 1);
ds_t('M3-8: no notification rows for user 0 or the technician', (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id <> 1") === 0);
ds_done();
