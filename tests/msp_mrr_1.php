<?php
// MSP-DS-141/DS-39 (re-review MRR1): the in-app backup dump must leave generated columns out of its INSERTs, otherwise a strict-mode restore dies
// with 1906/3105 on the first users row. Runs the REAL dump_database_streaming() (admin/post/backup.php) and the REAL restore path
// (importSqlFile from setup/setup_functions.php) under STRICT_ALL_TABLES; the generated columns are found generically from information_schema.
// Run: SCRATCH_TABLES="users payroll_runs payroll_periods" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_1.php
require __DIR__ . '/lib/ds2_migrate.php';
$m = ds2_open();
$m->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$gen = [];
$r = $m->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (EXTRA LIKE '%VIRTUAL GENERATED%' OR EXTRA LIKE '%STORED GENERATED%')");
while ($x = $r->fetch_row()) { $gen[$x[0]][] = $x[1]; }
ds2_t('scratch schema has the three generated columns', ($gen['users'] ?? []) === ['user_staff_email_key'] && ($gen['payroll_runs'] ?? []) === ['payroll_run_live_period_id'] && ($gen['payroll_periods'] ?? []) === ['payroll_period_live_key']);

$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_type,user_status) VALUES (1,'A','a@x.test','x',1,1),(2,'B','b@x.test','x',1,1)");
$m->query("INSERT INTO payroll_periods (payroll_period_id,payroll_period_start_date,payroll_period_end_date) VALUES (1,'2026-01-01','2026-01-15')");
$m->query("INSERT INTO payroll_runs (payroll_run_id,payroll_run_period_id,payroll_run_created_by) VALUES (1,1,1)");
ds2_t('seed rows have non-NULL generated values', ds2_one($m, "SELECT COUNT(*) FROM users WHERE user_staff_email_key IS NOT NULL") == 2
    && ds2_one($m, "SELECT payroll_run_live_period_id FROM payroll_runs") == 1 && ds2_one($m, "SELECT payroll_period_live_key FROM payroll_periods") !== null);
$m->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

// Real dump, run in a child process the way the handler's own include path expects (cwd = admin/).
$dump = tempnam(sys_get_temp_dir(), 'mrr1_');
$code = 'define("FROM_POST_HANDLER", 1); $_SERVER["DOCUMENT_ROOT"] = sys_get_temp_dir(); mysqli_report(MYSQLI_REPORT_OFF);'
    . '$mysqli = new mysqli("localhost", getenv("RIVETMSP_TEST_DB_USER"), getenv("RIVETMSP_TEST_DB_PASS"), getenv("RIVETMSP_TEST_DB_NAME"));'
    . 'require ' . var_export(dirname(__DIR__) . '/admin/post/backup.php', true) . '; dump_database_streaming($mysqli, ' . var_export($dump, true) . '); echo "DUMPED\n";';
$p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__) . '/admin');
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
proc_close($p);
ds2_t('real dump_database_streaming ran', str_contains($out, 'DUMPED'));
$sql = (string) file_get_contents($dump);
ds2_t('dump keeps CREATE TABLE of the generated columns', str_contains($sql, 'GENERATED ALWAYS AS') || str_contains($sql, 'AS (`'));
ds2_t('no INSERT names a generated column', !preg_match('/INSERT INTO `[a-z_]+` \([^)]*(user_staff_email_key|payroll_run_live_period_id|payroll_period_live_key)/', $sql));
ds2_t('ordinary columns are still dumped', str_contains($sql, "'a@x.test'") && str_contains($sql, '`payroll_run_period_id`'));

// Real restore path: drop everything, replay under strict mode with the real importSqlFile.
require dirname(__DIR__) . '/setup/setup_functions.php';
$m->query("SET FOREIGN_KEY_CHECKS = 0");
foreach (['users', 'payroll_runs', 'payroll_periods'] as $t) { $m->query("DROP TABLE IF EXISTS `$t`"); }
$m->query("SET FOREIGN_KEY_CHECKS = 1");
mysqli_report(MYSQLI_REPORT_OFF);
$err = '';
try { importSqlFile($m, $dump); } catch (Throwable $e) { $err = $e->getMessage(); }
ds2_t('strict-mode restore of the dump succeeds' . ($err ? " ($err)" : ''), $err === '');
ds2_t('users restored and the generated key recomputed', (int) ds2_one($m, "SELECT COUNT(*) FROM users WHERE user_staff_email_key = user_email") === 2);
ds2_t('payroll rows restored and the generated values recomputed', ds2_one($m, "SELECT payroll_run_live_period_id FROM payroll_runs") == 1 && ds2_one($m, "SELECT payroll_period_live_key FROM payroll_periods") === '2026-01-01|2026-01-15');
@unlink($dump);
ds2_done();
