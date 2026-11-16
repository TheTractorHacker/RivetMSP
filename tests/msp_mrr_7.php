<?php
// MSP-DS-39 (re-review MRR7): the 2.6.139 payroll step never silently archives FINALIZED duplicate runs and never silently skips the period unique key.
// Real step, tables built from the OLD db.sql (7992dd4ff). Several finalized runs in a period / duplicate periods that both carry runs -> the update REFUSES
// (DbUpdateRefusedException, version stays 2.6.138, nothing archived); once an admin resolves it the same step succeeds. Drafts archived by the step are logged.
// Run: SCRATCH_TABLES="platform_settings logs" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_7.php
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
$tableDdl = function (string $sql, string $table): string {
    $a = strpos($sql, "CREATE TABLE `$table` (");
    $b = strpos($sql, "\n) ENGINE", $a);
    return substr($sql, $a, $b - $a) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
};
$old_sql = (string) shell_exec('git -C ' . escapeshellarg($root) . ' show 7992dd4ff:db.sql');
$ok('the OLD db.sql could be read', strlen($old_sql) > 100000);
$reset = function (string $periods, string $runs) use ($mysqli, $tableDdl, $old_sql) {
    foreach (['settings', 'payroll_runs', 'payroll_periods', 'accounting_entity_map', 'accounting_integrations'] as $t) { $mysqli->query("DROP TABLE IF EXISTS `$t`"); $mysqli->query($tableDdl($old_sql, $t)); }
    foreach (['invoice_charge_attempts', 'payment_reversals'] as $t) { $mysqli->query("DROP TABLE IF EXISTS `$t`"); }
    $mysqli->query("DELETE FROM logs");
    $mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.138')");
    $mysqli->query("INSERT INTO payroll_periods (payroll_period_id, payroll_period_start_date, payroll_period_end_date) VALUES $periods");
    $mysqli->query("INSERT INTO payroll_runs (payroll_run_id, payroll_run_period_id, payroll_run_status, payroll_run_created_by) VALUES $runs");
};
define('LATEST_DATABASE_VERSION', '2.6.139'); define('CURRENT_DATABASE_VERSION', '2.6.138');
$runStep = function () use ($mysqli, $root): string {
    $src = file_get_contents($root . '/admin/database_updates.php');
    $cut = strpos($src, "if (\$rivetit_db_version() == '2.6.139')");
    $tmp = $root . '/admin/.mrr7_tmp.php'; file_put_contents($tmp, substr($src, 0, $cut));
    $res = 'OK';
    ob_start(); try { include $tmp; } catch (Throwable $e) { $res = get_class($e) . ': ' . $e->getMessage(); } finally { ob_end_clean(); @unlink($tmp); }
    return $res;
};
$ver = fn () => $one("SELECT config_current_database_version FROM settings WHERE company_id = 1");
$hasKeys = fn () => intval($one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('uniq_payroll_run_live_period','uniq_payroll_period_live_key')"));

// A: two FINALIZED runs in one period (+ a draft)
$reset("(1,'2026-11-01','2026-11-15')", "(1,1,'finalized',1),(2,1,'finalized',1),(3,1,'draft',1)");
$r = $runStep();
$ok('A: update refuses with DbUpdateRefusedException and names the runs', str_starts_with($r, 'DbUpdateRefusedException') && str_contains($r, '1,2') && str_contains($r, 'NOT applied'));
$ok('A: version stays 2.6.138, no key created', $ver() === '2.6.138' && $hasKeys() === 0);
$ok('A: NO run (finalized or draft) was archived', intval($one("SELECT COUNT(*) FROM payroll_runs WHERE payroll_run_archived_at IS NOT NULL")) === 0);
$ok('A: the refusal is logged', intval($one("SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-39:%NOT applied%'")) === 1);
// admin resolves it (archives the extra finalized run and the draft), update now succeeds
$mysqli->query("UPDATE payroll_runs SET payroll_run_archived_at = NOW() WHERE payroll_run_id IN (2,3)");
$r = $runStep();
$ok('A: after the admin resolves it the same step succeeds and creates both keys', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);

// B: two live periods with the same dates that both carry live runs
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,1,'draft',1),(2,2,'draft',1)");
$r = $runStep();
$ok('B: duplicate periods that both have runs -> refused, nothing archived, version unchanged', str_starts_with($r, 'DbUpdateRefusedException') && $ver() === '2.6.138'
    && intval($one("SELECT COUNT(*) FROM payroll_periods WHERE payroll_period_archived_at IS NOT NULL")) === 0 && $hasKeys() === 0);

// C: oldest duplicate period has no run, the later one carries the run -> the run-bearing period is kept, the empty one archived, keys created
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,2,'finalized',1)");
$r = $runStep();
$ok('C: step succeeds and creates both keys (previously the unique key failed and was skipped for good)', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);
$ok('C: the period carrying the finalized run stays live, the empty duplicate is archived', $one("SELECT payroll_period_archived_at IS NULL FROM payroll_periods WHERE payroll_period_id = 2") == 1 && $one("SELECT payroll_period_archived_at IS NOT NULL FROM payroll_periods WHERE payroll_period_id = 1") == 1);
$ok('C: the finalized run is still live', $one("SELECT payroll_run_archived_at IS NULL FROM payroll_runs WHERE payroll_run_id = 1") == 1);
$ok('C: the archived period is logged', intval($one("SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-39: duplicate payroll period 1%'")) === 1);

// D: duplicate drafts + one finalized: drafts archived (never the finalized one) and each archive is logged
$reset("(1,'2026-11-01','2026-11-15')", "(1,1,'draft',1),(2,1,'finalized',1),(3,1,'draft',1)");
$r = $runStep();
$ok('D: step succeeds', $r === 'OK' && $ver() === '2.6.139');
$ok('D: the finalized run stays live, drafts archived (not deleted)', $one("SELECT GROUP_CONCAT(payroll_run_id) FROM payroll_runs WHERE payroll_run_archived_at IS NULL") === '2' && intval($one("SELECT COUNT(*) FROM payroll_runs")) === 3);
$ok('D: every archived draft has a log row', intval($one("SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-39: duplicate draft payroll run%'")) === 2);
exit($fail ? 1 : 0);
