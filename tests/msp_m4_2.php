<?php
// MSP-DS-39 (re-review M4-2): the MRR7 example was still unresolvable - two same-date live periods, each holding ONE live run: the run is the only run of its period (not archivable),
// the period still has a run (not archivable), run_payroll blocks a second run, so the 2.6.139 step stayed refused (tests/msp_m3_9.php only got out with raw SQL).
// Now: (1) the REAL admin handler admin/post/payroll_period.php has "archive_duplicate_payroll_period_with_runs" (admin only, reason of 5+ chars, logged, never deletes, the period's runs get the
// archive stamp in the same transaction; a period holding a FINALIZED run needs the password and is allowed only while the KEPT period still holds a finalized run);
// (2) the Info race is closed: payrollArchiveDuplicateRun / payrollArchiveDuplicatePeriod(WithRuns) check and write in one transaction with the rows locked (FOR UPDATE), proved with
// two real PROCESSES hitting the same pair at the same instant. The stuck case is resolved THROUGH THE HANDLERS and the real 2.6.139 step then completes on tables from the OLD db.sql.
// Run: SCRATCH_TABLES="settings logs users user_roles payroll_runs payroll_periods accounting_entity_map accounting_integrations" bash tests/lib/with_scratch_db.sh php tests/msp_m4_2.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mk = function () { $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''"); return $m; };
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {   // one concurrent "request": wait for the shared start instant, then archive
    require $root . '/includes/payroll_functions.php';
    $m = $mk(); $kind = $argv[2]; $id = intval($argv[3]); $start = (float) $argv[4];
    while (microtime(true) < $start) { }
    $r = $kind === 'run' ? payrollArchiveDuplicateRun($m, $id, 1, 'race test archive')
        : ($kind === 'period_runs' ? payrollArchiveDuplicatePeriodWithRuns($m, $id, 1, 'race test archive', true) : payrollArchiveDuplicatePeriod($m, $id, 1, 'race test archive'));
    echo json_encode(['ok' => $r[0], 'msg' => $r[1]]);
    exit(0);
}

$mysqli = $mk();
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
    $mysqli->query("UPDATE users SET user_failed_login_count = 0, user_failed_login_at = NULL");
    $mysqli->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.138')");
    $mysqli->query("INSERT INTO payroll_periods (payroll_period_id, payroll_period_start_date, payroll_period_end_date) VALUES $periods");
    if ($runs !== '') { $mysqli->query("INSERT INTO payroll_runs (payroll_run_id, payroll_run_period_id, payroll_run_status, payroll_run_created_by) VALUES $runs"); }
};
define('LATEST_DATABASE_VERSION', '2.6.139'); define('CURRENT_DATABASE_VERSION', '2.6.138');
$runStep = function () use ($root): string {
    $src = file_get_contents($root . '/admin/database_updates.php');
    $cut = strpos($src, "if (\$rivetit_db_version() == '2.6.139')");
    $tmp = $root . '/admin/.m42_tmp.php'; file_put_contents($tmp, substr($src, 0, $cut));
    $mysqli = $GLOBALS['mysqli'];
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
$handler = function (string $file, int $user, array $post) use ($root): string {
    $cmd = [PHP_BINARY, $root . '/tests/lib/run_post_handler.php', json_encode(['handler' => $file, 'base' => 'admin', 'user_id' => $user, 'post' => $post, 'get' => []])];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    return (string) $out;
};
$perRuns = fn (int $user, int $period, array $extra = []) => $handler('payroll_period.php', $user, ['archive_duplicate_payroll_period_with_runs' => 1, 'period_id' => $period] + $extra);
$runPost = fn (int $user, int $run, array $extra = []) => $handler('payroll_run.php', $user, ['archive_duplicate_payroll_run' => 1, 'run_id' => $run] + $extra);
$perEmpty = fn (int $user, int $period, array $extra = []) => $handler('payroll_period.php', $user, ['archive_duplicate_payroll_period' => 1, 'period_id' => $period] + $extra);
$pArch = fn (int $id) => intval($one("SELECT payroll_period_archived_at IS NOT NULL FROM payroll_periods WHERE payroll_period_id = $id")) === 1;
$rArch = fn (int $id) => intval($one("SELECT payroll_run_archived_at IS NOT NULL FROM payroll_runs WHERE payroll_run_id = $id")) === 1;
$refused = fn (string $r) => str_starts_with($r, 'DbUpdateRefusedException');

// A: the MRR7 example (two same-date periods, one live DRAFT run each): every old path refuses, the new handler resolves it, the 2.6.139 step then completes
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15'),(3,'2026-11-16','2026-11-30')", "(1,1,'draft',1),(2,2,'draft',1),(3,3,'draft',1)");
$ok('A: the 2.6.139 step is refused', $refused($runStep()) && $ver() === '2.6.138');
$runPost(1, 2, ['archive_reason' => 'only run of period 2']);
$perEmpty(1, 2, ['archive_reason' => 'period still has a run']);
$ok('A: the old paths still refuse (the run is the period\'s only run; the period still has a run)', !$rArch(2) && !$pArch(2));
$o = $perRuns(2, 2, ['archive_reason' => 'duplicate period copy']);
$ok('A: a technician cannot use it', str_contains($o, 'Only an administrator') && !$pArch(2) && !$rArch(2));
$o = $perRuns(1, 2, ['archive_reason' => 'x']);
$ok('A: a reason under 5 characters is refused', str_contains($o, 'reason') && !$pArch(2) && !$rArch(2));
$o = $perRuns(1, 3, ['archive_reason' => 'not a duplicate at all']);
$ok('A: a period with unique dates is never archivable here', !$pArch(3) && !$rArch(3) && str_contains($o, 'not a duplicate'));
$o = $perRuns(1, 2, ['archive_reason' => 'duplicate period copy']);
$ok('A: admin archives period 2 together with its run (both stamped, rows kept)', $pArch(2) && $rArch(2) && !$pArch(1) && !$rArch(1) && intval($one("SELECT COUNT(*) FROM payroll_periods")) === 3 && intval($one("SELECT COUNT(*) FROM payroll_runs")) === 3);
$ok('A: logged with actor, period, run and the reason', intval($one("SELECT COUNT(*) FROM logs WHERE log_type = 'Payroll' AND log_action = 'Archive' AND log_user_id = 1 AND log_entity_id = 2 AND log_description LIKE '%pay period #2%run(s) #2%Reason: duplicate period copy%'")) === 1);
$o = $perRuns(1, 1, ['archive_reason' => 'now the only one left']);
$ok('A: the kept period can no longer be archived (nothing else holds a run)', !$pArch(1) && !$rArch(1));
$r = $runStep();
$ok('A: the SAME step now runs to completion on the old tables and creates both keys', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);

// B: finalized run in BOTH periods: needs the password; the kept one still holds finalized pay
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,1,'finalized',1),(2,2,'finalized',1)");
$ok('B: refused', $refused($runStep()));
$o = $perRuns(1, 2, ['archive_reason' => 'duplicate finalized copy']);
$ok('B: no password -> refused', str_contains($o, 'password') && !$pArch(2) && !$rArch(2));
$o = $perRuns(1, 2, ['archive_reason' => 'duplicate finalized copy', 'admin_current_password' => 'wrong']);
$ok('B: wrong password -> refused', !$pArch(2) && !$rArch(2));
$o = $perRuns(1, 2, ['archive_reason' => 'duplicate finalized copy', 'admin_current_password' => 'correct-horse']);
$ok('B: right password + reason -> period 2 and its finalized run archived, period 1 and run 1 stay live and finalized', $pArch(2) && $rArch(2) && !$pArch(1) && !$rArch(1) && $one("SELECT payroll_run_status FROM payroll_runs WHERE payroll_run_id = 1") === 'finalized');
$o = $perRuns(1, 1, ['archive_reason' => 'second try on the other', 'admin_current_password' => 'correct-horse']);
$ok('B: the other copy is now protected (the last finalized pay record is never hidden)', !$pArch(1) && !$rArch(1));
$r = $runStep();
$ok('B: the step runs to completion', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);

// C: finalized ONLY in the period being archived: refused even with the right password
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,1,'draft',1),(2,2,'finalized',1)");
$o = $perRuns(1, 2, ['archive_reason' => 'duplicate finalized copy', 'admin_current_password' => 'correct-horse']);
$ok('C: archiving the period that holds the ONLY finalized run is refused (even with the password)', !$pArch(2) && !$rArch(2) && str_contains($o, 'only finalized'));
$o = $perRuns(1, 1, ['archive_reason' => 'the draft copy is the extra one']);
$ok('C: the draft-only period can be archived (no password needed): the finalized period is kept', $pArch(1) && $rArch(1) && !$pArch(2) && !$rArch(2));
$r = $runStep();
$ok('C: the step runs to completion', $r === 'OK' && $ver() === '2.6.139' && $hasKeys() === 2);

// D: two RUNS in the archived period (draft + finalized) with a finalized run in the kept period: all runs stamped together
$reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,1,'finalized',1),(2,2,'finalized',1),(3,2,'draft',1)");
$o = $perRuns(1, 2, ['archive_reason' => 'duplicate copy of the period', 'admin_current_password' => 'correct-horse']);
$ok('D: every live run of the archived period gets the stamp', $pArch(2) && $rArch(2) && $rArch(3) && !$rArch(1));

// E: the Info race, two REAL processes at the same instant, many rounds
$race = function (array $jobs) use ($root): array {
    $start = microtime(true) + 0.35; $procs = []; $pipes = [];
    foreach ($jobs as $i => [$kind, $id]) { $procs[$i] = proc_open([PHP_BINARY, __FILE__, 'worker', $kind, (string) $id, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]); }
    $res = [];
    foreach ($procs as $i => $p) { $res[] = json_decode(stream_get_contents($pipes[$i][1]), true); stream_get_contents($pipes[$i][2]); proc_close($p); }
    return $res;
};
$rounds = 30; $badPeriods = 0; $badRuns = 0; $badEmpty = 0; $raceErr = 0;
for ($i = 0; $i < $rounds; $i++) {
    // two same-date periods, each with a finalized run: both archived at once must not hide both
    $reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", "(1,1,'finalized',1),(2,2,'finalized',1)");
    $res = $race([['period_runs', 1], ['period_runs', 2]]);
    $wins = count(array_filter($res, fn ($r) => $r && $r['ok']));
    $liveFin = intval($one("SELECT COUNT(*) FROM payroll_runs WHERE payroll_run_archived_at IS NULL AND payroll_run_status = 'finalized'"));
    $raceErr += in_array(null, $res, true) ? 1 : 0;
    if ($wins !== 1 || $liveFin !== 1) { $badPeriods++; }
    // one period with two finalized runs: both runs archived at once must not hide both
    $reset("(1,'2026-11-01','2026-11-15')", "(1,1,'finalized',1),(2,1,'finalized',1)");
    $res = $race([['run', 1], ['run', 2]]);
    $wins = count(array_filter($res, fn ($r) => $r && $r['ok']));
    $liveFin = intval($one("SELECT COUNT(*) FROM payroll_runs WHERE payroll_run_archived_at IS NULL AND payroll_run_status = 'finalized'"));
    $raceErr += in_array(null, $res, true) ? 1 : 0;
    if ($wins !== 1 || $liveFin !== 1) { $badRuns++; }
    // two empty same-date periods, one finalized-free: archiving both at once must leave one live
    $reset("(1,'2026-11-01','2026-11-15'),(2,'2026-11-01','2026-11-15')", '');
    $res = $race([['period', 1], ['period', 2]]);
    $wins = count(array_filter($res, fn ($r) => $r && $r['ok']));
    $livePer = intval($one("SELECT COUNT(*) FROM payroll_periods WHERE payroll_period_archived_at IS NULL"));
    if ($wins !== 1 || $livePer !== 1) { $badEmpty++; }
}
$ok("E: $rounds rounds of two concurrent period+run archives: exactly one wins and one finalized run stays live every time (bad rounds: $badPeriods)", $badPeriods === 0 && $raceErr === 0);
$ok("E: $rounds rounds of two concurrent archives of the two finalized runs of one period: exactly one wins (bad rounds: $badRuns)", $badRuns === 0);
$ok("E: $rounds rounds of two concurrent empty-duplicate-period archives: one stays live (bad rounds: $badEmpty)", $badEmpty === 0);
exit($fail ? 1 : 0);
