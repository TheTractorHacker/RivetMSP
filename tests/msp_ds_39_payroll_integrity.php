<?php
// MSP-DS-39: payroll hours edit uses the run's own period and refuses locked; one run per period under a lock;
// no overlapping periods; no negative pay rate/deduction.
require __DIR__ . '/lib/ds_src.php';
$run = ds_src('admin/post/payroll_run.php');
$edit = substr($run, strpos($run, "isset(\$_POST['edit_payroll_run_hours'])"));
ds_t('period_id is not read from POST in hours edit', strpos($edit, "\$_POST['period_id']") === false);
ds_t('period taken from the run row', strpos($edit, "\$run['payroll_run_period_id']") !== false);
ds_t('locked period refused', preg_match("/payroll_period_status'\] \?\? ''\) === 'locked'/", $edit) === 1);
$per = ds_src('admin/post/payroll_period.php');
ds_t('run creation under GET_LOCK', strpos($per, "GET_LOCK('\$lock_name'") !== false && substr_count($per, "RELEASE_LOCK") >= 2);
ds_t('lock taken before the existing-run check', strpos($per, 'GET_LOCK') < strpos($per, '$existing_run = '));
$pp = ds_src('admin/post/payroll_periods.php');
ds_t('overlap check before insert', strpos($pp, 'payroll_period_start_date <=') !== false && strpos($pp, 'payroll_period_start_date <=') < strpos($pp, 'INSERT INTO payroll_periods'));
$emp = ds_src('admin/post/payroll_employee.php');
ds_t('pay rate clamped >= 0', strpos($emp, "max(0, round(floatval(\$_POST['pay_amount']") !== false);
ds_t('deduction clamped >= 0', strpos($emp, "max(0, round(floatval(\$_POST['amount']") !== false);
foreach (['admin/post/payroll_run.php','admin/post/payroll_period.php','admin/post/payroll_periods.php','admin/post/payroll_employee.php'] as $f) {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../' . $f) . ' 2>&1', $o, $rc); ds_t("lint $f", $rc === 0);
}
ds_done();
