<?php
// Group B migration DB 2.6.139 (MSP-DS-166/32/164/39/115): the upgrade step run on tables built from the OLD db.sql (7992dd4ff) with real duplicate/legacy data,
// run twice (idempotent), and the result compared column-for-column and index-for-index with a fresh install's db.sql.
// Run: SCRATCH_TABLES="platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_B_migration.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$root = dirname(__DIR__);
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$one = fn (string $s) => ($r = $mysqli->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;

$tableDdl = function (string $sql, string $table): string {
    $a = strpos($sql, "CREATE TABLE `$table` (");
    $b = strpos($sql, "\n) ENGINE", $a);
    return substr($sql, $a, $b - $a) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
};
$old_sql = (string) shell_exec('git -C ' . escapeshellarg($root) . ' show 7992dd4ff:db.sql');
$new_sql = file_get_contents($root . '/db.sql');
$ok('the OLD db.sql could be read (to build the pre-migration schema)', strlen($old_sql) > 100000);
$changed = ['settings', 'payroll_runs', 'payroll_periods', 'accounting_entity_map', 'accounting_integrations'];
foreach ($changed as $t) {
    $mysqli->query("DROP TABLE IF EXISTS `$t`");
    $mysqli->query($tableDdl($old_sql, $t));
}
$ok('old schema: no live-key columns / workweek / realm columns yet', $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('payroll_run_live_period_id','payroll_period_live_key','config_payroll_workweek_start','map_realm_id')") == 0);
$ok('old schema: the new tables do not exist', $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('invoice_charge_attempts','payment_reversals')") == 0);

// legacy data: duplicate live runs (a double submit), a duplicate period without runs, accounting rows written under the old scheme
$mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.138')");
$mysqli->query("INSERT INTO payroll_periods (payroll_period_id, payroll_period_start_date, payroll_period_end_date) VALUES (1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15'),(3,'2026-11-16','2026-11-30')");
$mysqli->query("INSERT INTO payroll_runs (payroll_run_id, payroll_run_period_id, payroll_run_status, payroll_run_created_by) VALUES (1,1,'draft',1),(2,1,'finalized',1),(3,1,'draft',1),(4,3,'draft',1),(5,3,'draft',1)");
$mysqli->query("INSERT INTO accounting_integrations (accounting_id, accounting_provider, accounting_realm_id) VALUES (1, 'quickbooks_online', 'PROD-9')");
$mysqli->query("INSERT INTO accounting_entity_map (map_accounting_id, map_local_type, map_local_id, map_remote_id) VALUES (1,'client',7,'12'),(1,'invoice',301,'145')");

define('LATEST_DATABASE_VERSION', '2.6.139'); define('CURRENT_DATABASE_VERSION', '2.6.138');
// Run the real file up to (not including) the next group's 2.6.139 -> 2.6.140 step: later steps need tables this test does not load.
$runStep = function () use ($mysqli, $root) {
    $src = file_get_contents($root . '/admin/database_updates.php');
    $cut = strpos($src, "if (\$rivetit_db_version() == '2.6.139')");
    if ($cut === false) { $cut = strpos($src, '// Core migrations catch-up'); }
    $tmp = $root . '/admin/.ds2_B_tmp.php'; file_put_contents($tmp, substr($src, 0, $cut));
    ob_start(); try { include $tmp; } finally { ob_end_clean(); @unlink($tmp); }
};
$runStep();
$ok('the step advances the version to 2.6.139', $one("SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.139');
$ok('duplicate live runs: the finalized one is kept, the extra drafts archived', $one("SELECT GROUP_CONCAT(payroll_run_id) FROM payroll_runs WHERE payroll_run_period_id = 1 AND payroll_run_archived_at IS NULL") === '2');
$ok('duplicate live runs without a finalized one: the oldest is kept', $one("SELECT GROUP_CONCAT(payroll_run_id) FROM payroll_runs WHERE payroll_run_period_id = 3 AND payroll_run_archived_at IS NULL") === '4');
$ok('no run row was deleted (the duplicates are archived)', intval($one("SELECT COUNT(*) FROM payroll_runs")) === 5);
$ok('duplicate period: the run-less duplicate is archived, the one with runs kept', $one("SELECT payroll_period_archived_at IS NOT NULL FROM payroll_periods WHERE payroll_period_id = 2") == 1 && $one("SELECT payroll_period_archived_at IS NULL FROM payroll_periods WHERE payroll_period_id = 1") == 1);
$ok('the DB now refuses a second live run for a period', $mysqli->query("INSERT INTO payroll_runs SET payroll_run_period_id = 1, payroll_run_created_by = 1") === false && $mysqli->errno === 1062);
$ok('existing accounting rows are tagged with the connection they belong to (the integration\'s current one)', intval($one("SELECT COUNT(*) FROM accounting_entity_map WHERE map_provider = 'quickbooks_online' AND map_realm_id = 'PROD-9'")) === 2);
$ok('workweek setting defaults to the legacy behaviour', $one("SELECT config_payroll_workweek_start FROM settings WHERE company_id = 1") === 'period');

// idempotent: run the same step again from 2.6.138
$mysqli->query("UPDATE settings SET config_current_database_version = '2.6.138'");
$before = [intval($one("SELECT COUNT(*) FROM payroll_runs")), intval($one("SELECT COUNT(*) FROM accounting_entity_map WHERE map_realm_id = 'PROD-9'"))];
$runStep();
$ok('a second run of the step changes nothing and does not fail', $one("SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.139' && $before === [intval($one("SELECT COUNT(*) FROM payroll_runs")), intval($one("SELECT COUNT(*) FROM accounting_entity_map WHERE map_realm_id = 'PROD-9'"))]);

// marker wins over the integration row when a company was remembered by a disconnect
$mysqli->query("INSERT INTO accounting_entity_map (map_accounting_id, map_local_type, map_local_id, map_remote_id) VALUES (1,'client',8,'77')");
$mysqli->query("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('accounting_map_connection', 'quickbooks_online|OLD-CO')");
$mysqli->query("UPDATE settings SET config_current_database_version = '2.6.138'");
$runStep();
$ok('an untagged row is attributed to the remembered company when there is one', $one("SELECT map_realm_id FROM accounting_entity_map WHERE map_local_id = 8") === 'OLD-CO');

// the migrated schema equals a fresh install's schema for every table this group touches
$all = array_merge($changed, ['invoice_charge_attempts', 'payment_reversals']);
foreach ($all as $t) {
    $mysqli->query("DROP TABLE IF EXISTS `{$t}__fresh`");
    $ddl = str_replace("CREATE TABLE `$t` (", "CREATE TABLE `{$t}__fresh` (", $tableDdl($new_sql, $t));
    $mysqli->query($ddl);
    $cols = function (string $tbl) use ($mysqli) {
        $r = $mysqli->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT,'~'), EXTRA, COALESCE(GENERATION_EXPRESSION,'') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tbl' ORDER BY COLUMN_NAME");
        $o = []; while ($x = $r->fetch_row()) { $o[] = implode('|', $x); } return $o;
    };
    $idx = function (string $tbl) use ($mysqli) {
        $r = $mysqli->query("SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tbl' GROUP BY INDEX_NAME ORDER BY INDEX_NAME");
        $o = []; while ($x = $r->fetch_row()) { $o[] = implode('|', $x); } return $o;
    };
    $ok("$t: migrated columns equal a fresh install's", $cols($t) === $cols("{$t}__fresh"));
    $ok("$t: migrated indexes equal a fresh install's", $idx($t) === $idx("{$t}__fresh"));
}
$ok('the version constant is at least 2.6.139', preg_match('/"(\d+\.\d+\.\d+)"/', file_get_contents($root . '/includes/database_version.php'), $vm) && version_compare($vm[1], '2.6.139', '>='));
exit($fail ? 1 : 0);
