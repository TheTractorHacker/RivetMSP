<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_payroll_period'])) {

    validateCSRFToken($_POST['csrf_token']);

    $start_date = sanitizeInput($_POST['start_date'] ?? '');
    $end_date = sanitizeInput($_POST['end_date'] ?? '');

    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);

    if ($start_ts === false || $end_ts === false || $end_ts < $start_ts) {
        flash_alert("Invalid start/end date.", 'error');
        redirect('/admin/payroll_periods.php');
    }

    $start_esc = mysqli_real_escape_string($mysqli, date('Y-m-d', $start_ts));
    $end_esc = mysqli_real_escape_string($mysqli, date('Y-m-d', $end_ts));

    // MSP-DS-39: a period may not overlap (or duplicate) another live period - they would read the same hours rows
    $overlap = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payroll_period_id FROM payroll_periods WHERE payroll_period_archived_at IS NULL AND payroll_period_start_date <= '$end_esc' AND payroll_period_end_date >= '$start_esc' LIMIT 1"));
    if ($overlap) {
        flash_alert("That pay period overlaps an existing pay period.", 'error');
        redirect('/admin/payroll_periods.php');
    }

    mysqli_query($mysqli, "INSERT INTO payroll_periods SET payroll_period_start_date = '$start_esc', payroll_period_end_date = '$end_esc'");
    $period_id = mysqli_insert_id($mysqli);

    logAction("Payroll", "Create", "$session_name created a payroll period ($start_esc - $end_esc)");

    flash_alert("Pay period created");
    redirect("/admin/payroll_period.php?id=$period_id");
}
