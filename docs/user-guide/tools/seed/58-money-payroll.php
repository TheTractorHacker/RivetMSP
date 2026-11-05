<?php
/*
 * Money out for the RivetMSP user-guide demo (group 7, part 4): payroll. Four staff members enrolled (three hourly, one
 * salaried), two deduction categories with standing deductions, three biweekly pay periods:
 *   2026-09-28 to 2026-10-11  locked, with a finalized run
 *   2026-10-12 to 2026-10-25  open, with a draft run (includes one job-specific-hours entry when a ticket exists)
 *   2026-10-26 to 2026-11-08  open, first week of hours entered, no run yet
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/58-money-payroll.php
 *
 * Gross pay only: RivetMSP calculates no tax withholding. All names and amounts are invented.
 * Depends on 01-modules-and-permissions.php (payroll switched on, staff users) and, optionally, the service-desk seeds
 * (a ticket for the job-hours example). Idempotent.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "58-payroll: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/payroll_functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "58-payroll: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function esc($v) { global $mysqli; return mysqli_real_escape_string($mysqli, $v); }
function uid($email) { return intval(one("SELECT user_id FROM users WHERE user_email = '" . esc($email) . "' AND user_type = 1 LIMIT 1")); }

q("UPDATE settings SET config_module_enable_payroll = 1, config_payroll_overtime_threshold_hours = 40.00, config_payroll_overtime_multiplier = 1.50,
     config_payroll_default_pay_frequency = 'biweekly' WHERE company_id = 1");

$morgan = uid('morgan.reyes@lakeshoremsp.example');
$devon  = uid('devon.park@lakeshoremsp.example');
$sam    = uid('sam.ortiz@lakeshoremsp.example');
$jules  = uid('jules.whitaker@lakeshoremsp.example');
if (!$morgan || !$devon || !$sam || !$jules) { fwrite(STDERR, "58-payroll: staff users missing (run 00-core.sql first).\n"); exit(1); }

// ---- Pay rates -------------------------------------------------------------------------------------------------
foreach ([[$devon, 'hourly', 32.00], [$sam, 'hourly', 30.00], [$jules, 'hourly', 28.50], [$morgan, 'salary', 4200.00]] as [$u, $type, $amt]) {
    if (!one("SELECT 1 FROM payroll_pay_rates WHERE payroll_pay_rate_user_id = $u AND payroll_pay_rate_archived_at IS NULL")) {
        q("INSERT INTO payroll_pay_rates SET payroll_pay_rate_user_id = $u, payroll_pay_rate_type = '$type', payroll_pay_rate_amount = $amt");
    }
}

// ---- Deduction categories and standing deductions --------------------------------------------------------------------
foreach ([['Health Insurance', 'Employee share of the group health plan', 1], ['401(k)', 'Retirement plan contribution', 2]] as [$n, $d, $o]) {
    if (!one("SELECT 1 FROM payroll_deduction_categories WHERE payroll_deduction_category_name = '" . esc($n) . "' AND payroll_deduction_category_archived_at IS NULL")) {
        q("INSERT INTO payroll_deduction_categories SET payroll_deduction_category_name = '" . esc($n) . "', payroll_deduction_category_description = '" . esc($d) . "', payroll_deduction_category_order = $o");
    }
}
$health = intval(one("SELECT payroll_deduction_category_id FROM payroll_deduction_categories WHERE payroll_deduction_category_name = 'Health Insurance' LIMIT 1"));
$k401   = intval(one("SELECT payroll_deduction_category_id FROM payroll_deduction_categories WHERE payroll_deduction_category_name = '401(k)' LIMIT 1"));
foreach ([[$devon, $health, 85.00], [$devon, $k401, 60.00], [$sam, $health, 85.00], [$jules, $health, 85.00], [$morgan, $health, 120.00], [$morgan, $k401, 150.00]] as [$u, $c, $amt]) {
    if (!one("SELECT 1 FROM payroll_employee_deductions WHERE payroll_employee_deduction_user_id = $u AND payroll_employee_deduction_category_id = $c AND payroll_employee_deduction_archived_at IS NULL")) {
        q("INSERT INTO payroll_employee_deductions SET payroll_employee_deduction_user_id = $u, payroll_employee_deduction_category_id = $c, payroll_employee_deduction_amount = $amt");
    }
}

// ---- Pay periods and hours ---------------------------------------------------------------------------------------------
$periods = [
    '2026-09-28' => '2026-10-11',
    '2026-10-12' => '2026-10-25',
    '2026-10-26' => '2026-11-08',
];
$period_id = [];
foreach ($periods as $s => $e) {
    $id = intval(one("SELECT payroll_period_id FROM payroll_periods WHERE payroll_period_start_date = '$s' AND payroll_period_archived_at IS NULL LIMIT 1"));
    if (!$id) {
        q("INSERT INTO payroll_periods SET payroll_period_start_date = '$s', payroll_period_end_date = '$e'");
        $id = intval(mysqli_insert_id($mysqli));
    }
    $period_id[$s] = $id;
}

// hours: week start => [user => [h, m]]  (salaried staff need none)
$hours = [
    '2026-09-28' => [$devon => [40, 0],  $sam => [38, 15], $jules => [40, 0]],
    '2026-10-05' => [$devon => [44, 30], $sam => [40, 0],  $jules => [36, 30]],
    '2026-10-12' => [$devon => [42, 0],  $sam => [40, 0],  $jules => [35, 30]],
    '2026-10-19' => [$devon => [40, 0],  $sam => [45, 15], $jules => [40, 0]],
    '2026-10-26' => [$devon => [31, 45], $sam => [32, 0],  $jules => [30, 0]],   // week in progress
];
foreach ($hours as $week => $rows) {
    foreach ($rows as $u => [$h, $m]) {
        if (!one("SELECT 1 FROM payroll_hours_entries WHERE payroll_hours_entry_user_id = $u AND payroll_hours_entry_week_start_date = '$week'")) {
            payrollUpsertHoursEntry($mysqli, $u, $week, $h, $m);
        }
    }
}

// ---- Job-specific hours for the second period (needs a ticket from the service-desk seeds) -----------------------------
$p2 = $period_id['2026-10-12'];
$ticket = intval(one("SELECT ticket_id FROM tickets ORDER BY (ticket_client_id = (SELECT client_id FROM clients WHERE client_name = 'Lakeview Family Credit Union' LIMIT 1)) DESC, ticket_id ASC LIMIT 1"));
if ($ticket && !one("SELECT 1 FROM payroll_job_hours_entries WHERE payroll_job_hours_entry_period_id = $p2 AND payroll_job_hours_entry_user_id = $devon")) {
    q("INSERT INTO payroll_job_hours_entries SET payroll_job_hours_entry_period_id = $p2, payroll_job_hours_entry_user_id = $devon,
         payroll_job_hours_entry_ticket_id = $ticket, payroll_job_hours_entry_worked_time = '02:30:00', payroll_job_hours_entry_rate = 55.00,
         payroll_job_hours_entry_note = 'After-hours work paid at a premium rate'");
}

// ---- Runs ---------------------------------------------------------------------------------------------------------------
$currency = (string) one("SELECT company_currency FROM companies WHERE company_id = 1") ?: 'USD';
$p1 = $period_id['2026-09-28'];
if (!one("SELECT 1 FROM payroll_runs WHERE payroll_run_period_id = $p1 AND payroll_run_archived_at IS NULL")) {
    $run = payrollCreateRun($mysqli, $p1, $morgan, $currency);
    payrollFinalizeRun($mysqli, $run, $morgan);
    q("UPDATE payroll_runs SET payroll_run_created_at = '2026-10-12 08:55:00', payroll_run_finalized_at = '2026-10-12 09:30:00' WHERE payroll_run_id = $run");
}
if (!one("SELECT 1 FROM payroll_runs WHERE payroll_run_period_id = $p2 AND payroll_run_archived_at IS NULL")) {
    $run = payrollCreateRun($mysqli, $p2, $morgan, $currency);
    q("UPDATE payroll_runs SET payroll_run_created_at = '2026-10-26 08:40:00' WHERE payroll_run_id = $run");
}
// The run totals live on the run row: make sure they are filled even if the engine only wrote the line items
foreach ([$p1, $p2] as $p) {
    $run = intval(one("SELECT payroll_run_id FROM payroll_runs WHERE payroll_run_period_id = $p AND payroll_run_archived_at IS NULL LIMIT 1"));
    if ($run && floatval(one("SELECT payroll_run_total_gross FROM payroll_runs WHERE payroll_run_id = $run")) == 0.0) {
        q("UPDATE payroll_runs SET
             payroll_run_total_gross = IFNULL((SELECT SUM(payroll_run_line_item_gross_pay) FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run), 0),
             payroll_run_total_deductions = IFNULL((SELECT SUM(payroll_run_line_item_total_deductions) FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run), 0),
             payroll_run_total_net = IFNULL((SELECT SUM(payroll_run_line_item_net_pay) FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run), 0)
           WHERE payroll_run_id = $run");
    }
}

echo "58-payroll: periods " . implode(',', $period_id) . "\n";
