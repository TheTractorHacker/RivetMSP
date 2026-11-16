<?php
// DB 2.6.140 step (domains.domain_route_mail, report_schedules.schedule_created_by): upgrade path from the OLD db.sql (7992dd4ff), idempotency, fresh db.sql. Run:
//   SCRATCH_TABLES="domains report_schedules settings" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_16_33_migration.php
require __DIR__ . '/lib/ds_src.php';
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$col = function ($t, $c) use ($mysqli) { $r = $mysqli->query("SELECT COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'"); return $r ? $r->fetch_row() : null; };
// the step's own code, taken from the real file
$f = ds_src('admin/database_updates.php');
$a = strpos($f, "    if (\$rivetit_db_version() == '2.6.139') {");
$b = strpos($f, '// Core migrations catch-up');
$step = substr($f, $a, $b - $a);
ds_t('step found and bumps to 2.6.140', $a !== false && strpos($step, "'2.6.140'") !== false);
ds_t('LATEST_DATABASE_VERSION is 2.6.140', strpos(ds_src('includes/database_version.php'), '"2.6.140"') !== false);
$rivetit_db_version = static function () use ($mysqli): string { return (string) $mysqli->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0]; };
$run = function () use ($step, $mysqli, $rivetit_db_version) { eval($step); };   // runs the real migration step text against the scratch DB

// fresh install (new db.sql): both columns exist with the intended definitions
ds_t('fresh db.sql: domains.domain_route_mail default 0 not null', $col('domains', 'domain_route_mail') === ['0', 'NO']);
ds_t('fresh db.sql: report_schedules.schedule_created_by nullable', $col('report_schedules', 'schedule_created_by')[1] === 'YES');

// upgrade path: the tables exactly as the OLD db.sql (7992dd4ff) defines them
$old = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && git show 7992dd4ff:db.sql');
ds_t('old db.sql readable', is_string($old) && strlen($old) > 1000);
foreach (['domains', 'report_schedules'] as $t) {
    preg_match('/CREATE TABLE `' . $t . '` \(.*?\n\) ENGINE[^\n]*;/s', $old, $mm);
    $mysqli->query("DROP TABLE `$t`");
    $mysqli->multi_query($mm[0]); while ($mysqli->more_results() && $mysqli->next_result()) {}
}
ds_t('old schema has neither column', $col('domains', 'domain_route_mail') === null && $col('report_schedules', 'schedule_created_by') === null);
$mysqli->query("DELETE FROM settings"); $mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.139')");
$mysqli->query("INSERT INTO domains (domain_name, domain_client_id) VALUES
  ('solo.example', 1), ('Solo-Upper.example', 2), ('dup.example', 3), ('dup.example', 4), ('gmail.com', 5), ('noclient.example', 0)");
$mysqli->query("INSERT INTO domains (domain_name, domain_client_id, domain_archived_at) VALUES ('archived.example', 6, NOW())");
$mysqli->query("INSERT INTO report_schedules (schedule_report, schedule_frequency, schedule_recipients) VALUES ('mrr', 'daily', 'a@x.t')");
$run();
$flag = function ($name, $client = null) use ($mysqli) { $r = $mysqli->query("SELECT domain_route_mail FROM domains WHERE domain_name = '$name'" . ($client ? " AND domain_client_id = $client" : '') . " LIMIT 1"); return (int) $r->fetch_row()[0]; };
ds_t('upgrade: columns added', $col('domains', 'domain_route_mail') !== null && $col('report_schedules', 'schedule_created_by') !== null);
ds_t('upgrade: version bumped to 2.6.140', $rivetit_db_version() === '2.6.140');
ds_t('upgrade: existing unambiguous domain keeps routing (grandfathered)', $flag('solo.example') === 1 && $flag('Solo-Upper.example') === 1);
ds_t('upgrade: ambiguous domain (two clients) not routable', $flag('dup.example', 3) === 0 && $flag('dup.example', 4) === 0);
ds_t('upgrade: public mailbox provider not routable', $flag('gmail.com') === 0);
ds_t('upgrade: no-client and archived rows not routable', $flag('noclient.example') === 0 && $flag('archived.example') === 0);
ds_t('upgrade: existing schedule has NULL creator', $mysqli->query("SELECT schedule_created_by FROM report_schedules")->fetch_row()[0] === null);
// idempotent re-run: an admin's later choice is not overwritten
$mysqli->query("UPDATE domains SET domain_route_mail = 0 WHERE domain_name = 'solo.example'");
$mysqli->query("UPDATE settings SET config_current_database_version = '2.6.139'");
$run();
ds_t('re-run is safe: no error, version 2.6.140 again', $rivetit_db_version() === '2.6.140');
ds_t('re-run does not re-enable a domain an admin switched off', $flag('solo.example') === 0);
ds_done();
