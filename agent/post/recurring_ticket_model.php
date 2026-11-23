<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$subject = sanitizeInput($_POST['subject']);
$priority = sanitizeInput($_POST['priority']);
$details = mysqli_real_escape_string($mysqli, $_POST['details']);
$frequency = strtolower(sanitizeInput($_POST['frequency']));
// MSP-DS-18: cron.php schedules the next run from this value, so only the known frequencies are accepted.
if (!in_array($frequency, ['three days', 'weekly', 'biweekly', 'monthly', 'quarterly', 'biannually', 'annually'], true)) {
    flash_alert("Pick a valid frequency for the recurring ticket.", 'error');
    redirect();
}
$billable = intval($_POST['billable'] ?? 0);
$asset_id = intval($_POST['asset_id'] ?? 0);
$contact_id = intval($_POST['contact_id'] ?? 0);
$assigned_to = intval($_POST['assigned_to'] ?? 0);
$category_id = intval($_POST['category_id'] ?? 0);
