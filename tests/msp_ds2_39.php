<?php
// MSP-DS-39: payroll. Locked periods are closed (hours edit uses the run's own period, recalc refuses), one live run per period is a DB key (double submit
// cannot duplicate), overlapping/duplicate periods are refused, a fixed company workweek makes overtime count once across a semimonthly boundary.
// Real functions and the real admin/post handlers against a scratch DB.
// Run: SCRATCH_TABLES="users user_roles user_settings logs settings payroll_periods payroll_runs payroll_run_line_items payroll_run_line_item_deductions payroll_run_line_item_jobs payroll_hours_entries payroll_job_hours_entries payroll_pay_rates payroll_employee_deductions payroll_deduction_categories tickets" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_39.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
require __DIR__ . '/lib/handler_run.php';
mysqli_report(MYSQLI_REPORT_OFF);
$mk = function () { $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''"); return $m; };
$m = $mk();
require __DIR__ . '/../includes/payroll_functions.php';
if (($argv[1] ?? '') === 'worker') {   // concurrent "Run payroll" request, bypassing the app's named lock so only the DB key can stop the duplicate
    $w = $mk(); $id = payrollCreateRun($w, intval($argv[2]), 1, 'USD'); echo $id; exit(0);
}
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;

// ---- buckets ----
$starts = fn (array $b) => array_column($b, 'start');
$legacyA = payrollGetWeekBuckets('2026-11-01', '2026-11-15', 'period');
$ok('legacy: buckets restart at the period start and the last one is 1 day (the documented under-count)', $starts($legacyA) === ['2026-11-01', '2026-11-08', '2026-11-15'] && $legacyA[2]['end'] === '2026-11-15');
$A = payrollGetWeekBuckets('2026-11-01', '2026-11-15', 'thu'); $B = payrollGetWeekBuckets('2026-11-16', '2026-11-30', 'thu');
$ok('fixed Thursday workweek: weeks start on Thursdays, always 7 days', $starts($A) === ['2026-11-05', '2026-11-12'] && $A[1]['end'] === '2026-11-18');
$ok('the week that crosses the 15th/16th belongs to ONE period (the one it starts in)', !in_array('2026-11-12', $starts($B), true) && $starts($B) === ['2026-11-19', '2026-11-26']);
$ok('consecutive periods tile the weeks: no gap, no duplicate', array_merge($starts($A), $starts($B)) === ['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26']);
$ok('global setting is read when no anchor is passed', (function () { $GLOBALS['config_payroll_workweek_start'] = 'mon'; $r = payrollGetWeekBuckets('2026-11-01', '2026-11-15'); unset($GLOBALS['config_payroll_workweek_start']); return array_column($r, 'start') === ['2026-11-02', '2026-11-09']; })());
$ok('unknown setting values fall back to the legacy behaviour', payrollWorkweekAnchor('bogus') === null && payrollWorkweekAnchor('Sun') === 0);

// ---- overtime across the boundary ----
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1)");
$hash = password_hash('x', PASSWORD_DEFAULT);
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Adm','a@x.test','$hash',1,1,1), (2,'Pat','p@x.test','$hash',1,1,1)");
$m->query("INSERT INTO settings (company_id, config_payroll_overtime_threshold_hours, config_payroll_overtime_multiplier) VALUES (1, 40.00, 1.50)");
$m->query("INSERT INTO payroll_pay_rates (payroll_pay_rate_user_id, payroll_pay_rate_type, payroll_pay_rate_amount) VALUES (2,'hourly',10.00)");
$m->query("INSERT INTO payroll_periods (payroll_period_id, payroll_period_start_date, payroll_period_end_date) VALUES (1,'2026-11-01','2026-11-15'),(2,'2026-11-16','2026-11-30')");
payrollUpsertHoursEntry($m, 2, '2026-11-12', 50, 0);   // one 50-hour workweek Thu Nov 12 - Wed Nov 18
$emp = ['user_id' => 2, 'user_name' => 'Pat', 'payroll_pay_rate_type' => 'hourly', 'payroll_pay_rate_amount' => 10];
$a = payrollComputeEmployeeLineItem($m, $emp, 1, '2026-11-01', '2026-11-15', 40, 1.5, 'thu');
$b = payrollComputeEmployeeLineItem($m, $emp, 2, '2026-11-16', '2026-11-30', 40, 1.5, 'thu');
$ok('fixed workweek: the 50-hour week is 40 regular + 10 overtime in the period it starts in', $a['regular_hours'] === 40.0 && $a['overtime_hours'] === 10.0 && $a['gross_pay'] === 400.0 + 150.0);
$ok('... and is not paid again in the next period', $b['regular_hours'] === 0.0 && $b['overtime_hours'] === 0.0);

// ---- run uniqueness (DB key) ----
$procs = [];
for ($i = 0; $i < 5; $i++) { $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', '1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i]); }
$ids = []; foreach ($procs as $i => $p) { $ids[] = intval(stream_get_contents($pp[$i][1])); proc_close($p); }
$ok('5 concurrent "Run payroll" requests create exactly one live run (' . intval($one("SELECT COUNT(*) FROM payroll_runs WHERE payroll_run_period_id = 1")) . ')', intval($one("SELECT COUNT(*) FROM payroll_runs WHERE payroll_run_period_id = 1 AND payroll_run_archived_at IS NULL")) === 1);
$ok('every request got the same run id back', count(array_unique($ids)) === 1 && $ids[0] > 0);
$run1 = $ids[0];
$ok('the DB itself refuses a second live run for the period', $m->query("INSERT INTO payroll_runs SET payroll_run_period_id = 1, payroll_run_created_by = 1") === false && $m->errno === 1062);
$m->query("UPDATE payroll_runs SET payroll_run_archived_at = NOW() WHERE payroll_run_id = $run1");
$ok('an archived run does not block a new one', $m->query("INSERT INTO payroll_runs SET payroll_run_period_id = 1, payroll_run_created_by = 1") !== false);
$ok('the DB refuses a duplicate live period', $m->query("INSERT INTO payroll_periods SET payroll_period_start_date = '2026-11-01', payroll_period_end_date = '2026-11-15'") === false && $m->errno === 1062);

// ---- handlers: lock enforcement, overlap ----
$m->query("DELETE FROM payroll_runs");
$m->query("INSERT INTO payroll_runs (payroll_run_id, payroll_run_period_id, payroll_run_status, payroll_run_created_by) VALUES (10, 1, 'draft', 1), (11, 2, 'draft', 1)");
$m->query("UPDATE payroll_periods SET payroll_period_status = 'locked' WHERE payroll_period_id = 1");
$hours = fn (int $run, array $extra = []) => runPostHandler('payroll_run.php', 1, ['edit_payroll_run_hours' => 1, 'run_id' => $run, 'user_id' => 2, 'weeks' => ['2026-11-12' => ['h' => 99, 'm' => 0]]] + $extra, [], 'admin');
$before = $one("SELECT payroll_hours_entry_worked_time FROM payroll_hours_entries WHERE payroll_hours_entry_user_id = 2 AND payroll_hours_entry_week_start_date = '2026-11-12'");
[$o, $alert] = $hours(10);
$ok("hours edit on a draft run whose period is locked is refused ($alert)", str_contains($alert, 'locked') && $one("SELECT payroll_hours_entry_worked_time FROM payroll_hours_entries WHERE payroll_hours_entry_user_id = 2 AND payroll_hours_entry_week_start_date = '2026-11-12'") === $before);
[$o, $alert] = $hours(11, ['period_id' => 1]);   // open run, naming the LOCKED period in the POST
$ok("naming a locked period in the POST does not redirect the edit ($alert)", $one("SELECT payroll_hours_entry_worked_time FROM payroll_hours_entries WHERE payroll_hours_entry_user_id = 2 AND payroll_hours_entry_week_start_date = '2026-11-12'") === $before);
[$o, $alert] = runPostHandler('payroll_run.php', 1, ['recalculate_payroll_run' => 1, 'run_id' => 10], [], 'admin');
$ok("recalculate on a locked period is refused ($alert)", str_contains($alert, 'locked'));
[$o, $alert] = runPostHandler('payroll_run.php', 1, ['finalize_payroll_run' => 1, 'run_id' => 11], [], 'admin');
$ok("finalize works once ($alert)", $one("SELECT payroll_run_status FROM payroll_runs WHERE payroll_run_id = 11") === 'finalized' && $one("SELECT payroll_period_status FROM payroll_periods WHERE payroll_period_id = 2") === 'locked');
[$o, $alert] = runPostHandler('payroll_run.php', 1, ['finalize_payroll_run' => 1, 'run_id' => 11], [], 'admin');
$ok("a second finalize is refused ($alert)", str_contains($alert, 'already finalized'));
foreach ([['2026-11-10', '2026-11-20'], ['2026-11-01', '2026-11-15'], ['2026-10-25', '2026-11-01']] as [$s, $e]) {
    [$o, $alert] = runPostHandler('payroll_periods.php', 1, ['add_payroll_period' => 1, 'start_date' => $s, 'end_date' => $e], [], 'admin');
    $ok("period $s..$e overlapping an existing one is refused ($alert)", str_contains($alert, 'overlaps') && intval($one("SELECT COUNT(*) FROM payroll_periods")) === 2);
}
[$o, $alert] = runPostHandler('payroll_periods.php', 1, ['add_payroll_period' => 1, 'start_date' => '2026-12-01', 'end_date' => '2026-12-15'], [], 'admin');
$ok("a non-overlapping period is created ($alert)", intval($one("SELECT COUNT(*) FROM payroll_periods")) === 3);
[$o, $alert] = runPostHandler('payroll_employee.php', 1, ['save_payroll_pay_rate' => 1, 'user_id' => 2, 'pay_type' => 'hourly', 'pay_amount' => '-50'], [], 'admin');
$ok("a negative pay rate is stored as 0 ($alert)", floatval($one("SELECT payroll_pay_rate_amount FROM payroll_pay_rates WHERE payroll_pay_rate_user_id = 2 AND payroll_pay_rate_archived_at IS NULL")) >= 0.0);
exit($fail ? 1 : 0);
