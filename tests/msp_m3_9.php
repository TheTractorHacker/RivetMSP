<?php
// MSP-DS-39 (re-review M3-9, follow-up of MRR7): the 2.6.139 payroll refusal told the admin to archive or merge duplicates "in Payroll" but no such action existed, so the
// install stayed at 2.6.138. Now (1) the REAL admin post handlers admin/post/payroll_run.php and admin/post/payroll_period.php archive a duplicate run / empty duplicate
// period (admin only, reason required and logged, finalized duplicate also needs the admin's password, rows are never deleted, a period's only run is never archivable),
// (2) the refusal names that exact path, (3) a retry after a refused run works (the clean-up pass is keyed on the unique key, not on the generated column),
// (4) scripts/update_cli.php catches DbUpdateRefusedException (clean message, exit 1).
// The real 2.6.139 step text runs on tables built from the OLD db.sql (7992dd4ff).
// Run: SCRATCH_TABLES="settings logs users user_roles payroll_runs payroll_periods accounting_entity_map accounting_integrations" bash tests/lib/with_scratch_db.sh php tests/msp_m3_9.php
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
$ok('the OLD db.sql could be read', strlen($old_sql) > 100000);
$reset = function (string $periods, string $runs) use ($mysqli, $tableDdl, $old_sql) {
    foreach (['settings', 'payroll_runs', 'payroll_periods', 'accounting_entity_map', 'accounting_integrations'] as $t) { $mysqli->query("DROP TABLE IF EXISTS `$t`"); $mysqli->query($tableDdl($old_sql, $t)); }
    foreach (['invoice_charge_attempts', 'payment_reversals'] as $t) { $mysqli->query("DROP TABLE IF EXISTS `$t`"); }
    $mysqli->query("DELETE FROM logs");
    $mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.138')");
    $mysqli->query("INSERT INTO payroll_periods (payroll_period_id, payroll_period_start_date, payroll_period_end_date) VALUES $periods");
    if ($runs !== '') { $mysqli->query("INSERT INTO payroll_runs (payroll_run_id, payroll_run_period_id, payroll_run_status, payroll_run_created_by) VALUES $runs"); }
};
define('LATEST_DATABASE_VERSION', '2.6.139'); define('CURRENT_DATABASE_VERSION', '2.6.138');
$runStep = function () use ($mysqli, $root): string {
    $src = file_get_contents($root . '/admin/database_updates.php');
    $cut = strpos($src, "if (\$rivetit_db_version() == '2.6.139')");
    $tmp = $root . '/admin/.m39_tmp.php'; file_put_contents($tmp, substr($src, 0, $cut));
    $res = 'OK';
    ob_start(); try { include $tmp; } catch (Throwable $e) { $res = get_class($e) . ': ' . $e->getMessage(); } finally { ob_end_clean(); @unlink($tmp); }
    return $res;
};
$ver = fn () => $one("SELECT config_current_database_version FROM settings WHERE company_id = 1");
$hasKeys = fn () => intval($one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('uniq_payroll_run_live_period','uniq_payroll_period_live_key')"));
$mysqli->query("DELETE FROM user_roles"); $mysqli->query("DELETE FROM users");
$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0)");
$pw = password_hash('correct-horse', PASSWORD_DEFAULT);
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id) VALUES (1,'admin','a@x.t','$pw',1,1,1),(2,'tech','t@x.t','$pw',1,1,2)");
// the REAL admin post handler, in a child process as a given user
$handler = function (string $file, int $user, array $post) use ($root): string {
    $cmd = [PHP_BINARY, $root . '/tests/lib/run_post_handler.php', json_encode(['handler' => $file, 'base' => 'admin', 'user_id' => $user, 'post' => $post, 'get' => []])];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    return (string) $out;
};
$runPost = fn (int $user, int $run, array $extra = []) => $handler('payroll_run.php', $user, ['archive_duplicate_payroll_run' => 1, 'run_id' => $run] + $extra);
$archived = fn (string $t, string $c, int $id) => intval($one("SELECT $c IS NOT NULL FROM $t WHERE " . ($t === 'payroll_runs' ? 'payroll_run_id' : 'payroll_period_id') . " = $id")) === 1;

// A: two finalized runs (+ a draft) in one period -> refused; the message names the exact supported path; the admin resolves it with the real handler; retry succeeds
$reset("(1,'2026-11-01','2026-11-15')", "(1,1,'finalized',1),(2,1,'finalized',1),(3,1,'draft',1)");
$r = $runStep();
$ok('A: refused, version stays 2.6.138', str_starts_with($r, 'DbUpdateRefusedException') && $ver() === '2.6.138');
$ok('A: the refusal names the real Payroll pages and the "Archive this duplicate" action', str_contains($r, 'payroll_runs.php') && str_contains($r, 'Archive this duplicate') && str_contains($r, 'payroll_periods.php'));
$ok('A: the page the refusal names carries that action (admin/payroll_run.php)', str_contains((string) file_get_contents($root . '/admin/payroll_run.php'), 'archive_duplicate_payroll_run') && str_contains((string) file_get_contents($root . '/admin/payroll_run.php'), 'Archive this duplicate'));
$o = $runPost(2, 3, ['archive_reason' => 'extra draft copy']);
$ok('A: a non-admin cannot archive (handler refuses)', str_contains($o, 'Only an administrator') && !$archived('payroll_runs', 'payroll_run_archived_at', 3));
$o = $runPost(1, 3, ['archive_reason' => 'x']);
$ok('A: a reason of under 5 characters is refused', str_contains($o, 'reason') && !$archived('payroll_runs', 'payroll_run_archived_at', 3));
$o = $runPost(1, 3, ['archive_reason' => 'extra draft copy']);
$ok('A: admin archives the duplicate DRAFT with a reason (no password needed)', $archived('payroll_runs', 'payroll_run_archived_at', 3) && str_contains($o, 'archived'));
$o = $runPost(1, 2, ['archive_reason' => 'second finalized copy of the same run']);
$ok('A: archiving a FINALIZED duplicate without the password is refused', str_contains($o, 'password') && !$archived('payroll_runs', 'payroll_run_archived_at', 2));
$o = $runPost(1, 2, ['archive_reason' => 'second finalized copy of the same run', 'admin_current_password' => 'wrong']);
$ok('A: ... and with a WRONG password', !$archived('payroll_runs', 'payroll_run_archived_at', 2));
$o = $runPost(1, 2, ['archive_reason' => 'second finalized copy of the same run', 'admin_current_password' => 'correct-horse']);
$ok('A: ... with the right password and a reason it is archived (kept, not deleted)', $archived('payroll_runs', 'payroll_run_archived_at', 2) && intval($one("SELECT COUNT(*) FROM payroll_runs")) === 3);
$ok('A: every archive is logged with the actor and the reason', intval($one("SELECT COUNT(*) FROM logs WHERE log_type = 'Payroll' AND log_action = 'Archive' AND log_user_id = 1 AND log_description LIKE '%run #2%Reason: second finalized copy%'")) === 1
    && intval($one("SELECT COUNT(*) FROM logs WHERE log_type = 'Payroll' AND log_action = 'Archive' AND log_description LIKE '%run #3%Reason: extra draft copy%'")) === 1);
$o = $runPost(1, 1, ['archive_reason' => 'trying the only run', 'admin_current_password' => 'correct-horse']);
$ok('A: the period\'s only (finalized) run can never be archived this way', !$archived('payroll_runs', 'payroll_run_archived_at', 1) && str_contains($o, 'not a duplicate'));
$r = $runStep();
$ok('A: after the admin resolved it the same step succeeds and creates both keys', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);

// B: two live periods with the same dates that both carry a draft run: the admin handler archives the extra period with its run (M4-2), an empty duplicate period is archived separately, retry succeeds
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15'),(3,'2026-11-16','2026-11-30'),(4,'2026-12-01','2026-12-15'),(5,'2026-12-01','2026-12-15')", "(1,1,'draft',1),(2,2,'draft',1),(3,3,'draft',1)");
$r = $runStep();
$ok('B: refused, nothing archived, version unchanged', str_starts_with($r, 'DbUpdateRefusedException') && $ver() === '2.6.138' && intval($one("SELECT COUNT(*) FROM payroll_periods WHERE payroll_period_archived_at IS NOT NULL")) === 0);
$perPost = fn (int $user, int $period, array $extra = []) => $handler('payroll_period.php', $user, ['archive_duplicate_payroll_period' => 1, 'period_id' => $period] + $extra);
$o = $perPost(1, 2, ['archive_reason' => 'duplicate period']);
$ok('B: a period that still has a live run cannot be archived', !$archived('payroll_periods', 'payroll_period_archived_at', 2));
$o = $runPost(1, 1, ['archive_reason' => 'only run of period 1']);
$ok('B: run 1 is the only run of period 1 -> not archivable (it is not a duplicate)', !$archived('payroll_runs', 'payroll_run_archived_at', 1));
// M4-2: the stuck pair (two same-date periods, one live run each) is resolved THROUGH THE HANDLER, no raw SQL (full coverage: tests/msp_m4_2.php)
$o = $handler('payroll_period.php', 1, ['archive_duplicate_payroll_period_with_runs' => 1, 'period_id' => 2, 'archive_reason' => 'duplicate period with its run']);
$ok('B: the extra period is archived together with its run by the admin handler (kept, not deleted)', $archived('payroll_periods', 'payroll_period_archived_at', 2) && $archived('payroll_runs', 'payroll_run_archived_at', 2) && !$archived('payroll_runs', 'payroll_run_archived_at', 1));
$o = $perPost(2, 5, ['archive_reason' => 'duplicate period']);
$ok('B: a technician cannot archive a period', !$archived('payroll_periods', 'payroll_period_archived_at', 5) && str_contains($o, 'Only an administrator'));
$o = $perPost(1, 5, ['archive_reason' => 'x']);
$ok('B: the period archive also needs a reason', !$archived('payroll_periods', 'payroll_period_archived_at', 5));
$o = $perPost(1, 3, ['archive_reason' => 'not a duplicate at all']);
$ok('B: a period with unique dates is never archivable here', !$archived('payroll_periods', 'payroll_period_archived_at', 3));
$o = $perPost(1, 5, ['archive_reason' => 'empty duplicate period']);
$ok('B: admin archives the EMPTY duplicate period (kept, logged with reason)', $archived('payroll_periods', 'payroll_period_archived_at', 5) && intval($one("SELECT COUNT(*) FROM payroll_periods")) === 5
    && intval($one("SELECT COUNT(*) FROM logs WHERE log_type = 'Payroll' AND log_action = 'Archive' AND log_description LIKE '%pay period #5%Reason: empty duplicate period%'")) === 1);
$r = $runStep();
$ok('B: the same step now succeeds and creates both keys', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);

// C: the retry path (re-review ~8685): the generated columns already exist (an earlier run died before creating the keys) and duplicates are still live.
// Before the fix the clean-up pass was skipped because the column existed, so the unique key could never be created.
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,1,'draft',1),(2,1,'draft',1)");
$mysqli->query("ALTER TABLE `payroll_periods` ADD COLUMN `payroll_period_live_key` varchar(21) GENERATED ALWAYS AS (if(`payroll_period_archived_at` is null,concat(`payroll_period_start_date`,'|',`payroll_period_end_date`),NULL)) STORED");
$mysqli->query("ALTER TABLE `payroll_runs` ADD COLUMN `payroll_run_live_period_id` int(11) GENERATED ALWAYS AS (if(`payroll_run_archived_at` is null,`payroll_run_period_id`,NULL)) STORED");
$r = $runStep();
$ok('C: retry with the columns present but duplicates live: the clean-up still runs and both keys are created', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);
$ok('C: the duplicate draft run and the empty duplicate period were archived (kept) and logged', $archived('payroll_runs', 'payroll_run_archived_at', 2) && $archived('payroll_periods', 'payroll_period_archived_at', 2)
    && intval($one("SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-39: duplicate%'")) === 2);
$r = $runStep();   // already applied: a further run changes nothing
$ok('C: a second run is a no-op', $r === 'OK' && $hasKeys() === 2);

// D: the CLI entry point
$cli = (string) file_get_contents($root . '/scripts/update_cli.php');
$ok('D: scripts/update_cli.php catches DbUpdateRefusedException with a clean message and a non-zero exit', preg_match('/catch \(\\\\DbUpdateRefusedException \$e\) \{[^}]*fwrite\(STDERR[^}]*exit\(1\);/s', $cli) === 1);
$ok('D: admin/post/update.php still catches it too (web path)', str_contains((string) file_get_contents($root . '/admin/post/update.php'), 'DbUpdateRefusedException'));
exit($fail ? 1 : 0);
