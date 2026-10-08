<?php

/*
 * Scheduled / emailed reports admin (agent/reports/schedules.php)
 *
 * Add / list / delete / toggle rows in report_schedules. The cron/report_scheduler.php worker
 * picks up due, active schedules and emails a headline summary of the chosen report to the
 * recipient list. Gated by module_reporting; all mutations are CSRF-protected.
 *
 * Mutations are handled up-front (before any HTML output) so the post/redirect/get pattern
 * can issue a clean redirect; the interactive list then renders via inc_all_reports.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/report_schedule_guards.php';

enforceUserPermission('module_reporting');

// Creating, pausing and deleting schedules is a write action, and a schedule may only cover reports the user could
// open on screen: the emailed summaries are company-wide figures (nightly IT-8).
$can_schedule = lookupUserPermission('module_reporting') >= 2;
$can_schedule_report = function ($report_key) {
    return lookupUserPermission(reportScheduleRequiredModule($report_key)) >= 1;
};
$requireScheduleWrite = function ($report_key = null) use ($can_schedule, $can_schedule_report) {
    if (!$can_schedule || ($report_key !== null && !$can_schedule_report($report_key))) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'You do not have permission to manage this report schedule.';
        header('Location: schedules.php');
        exit;
    }
};

$schedulable_all = report_schedulable_reports();
$schedulable = array_filter($schedulable_all, function ($key) use ($can_schedule_report) {
    return $can_schedule_report($key);
}, ARRAY_FILTER_USE_KEY);
$valid_frequencies = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

// ---- Add a schedule -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');

    $report     = $_POST['schedule_report'] ?? '';
    $frequency  = $_POST['schedule_frequency'] ?? '';
    $recipients = trim($_POST['schedule_recipients'] ?? '');

    $requireScheduleWrite(isset($schedulable[$report]) ? $report : null);

    // Validate against the whitelists so only known reports/frequencies land in the table.
    // Recipients must be addresses of active staff accounts: company financials are never emailed to outside addresses.
    $filtered = reportScheduleFilterRecipients($recipients, reportScheduleStaffEmails($mysqli));
    $valid_emails = $filtered['allowed'];

    if (!isset($schedulable[$report])) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please choose a valid report to schedule.';
    } elseif (!isset($valid_frequencies[$frequency])) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please choose a valid frequency.';
    } elseif (!empty($filtered['rejected'])) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Recipients must be email addresses of active staff users. Not accepted: ' . implode(', ', array_map('htmlspecialchars', $filtered['rejected']));
    } elseif (empty($valid_emails)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please enter at least one valid recipient email address.';
    } else {
        $report_esc     = mysqli_real_escape_string($mysqli, $report);
        $frequency_esc  = mysqli_real_escape_string($mysqli, $frequency);
        // Store the cleaned, comma-separated recipient list.
        $recipients_esc = mysqli_real_escape_string($mysqli, implode(', ', $valid_emails));

        mysqli_query($mysqli,
            "INSERT INTO report_schedules (schedule_report, schedule_frequency, schedule_recipients, schedule_active)
             VALUES ('$report_esc', '$frequency_esc', '$recipients_esc', 1)");

        if (function_exists('logAction')) {
            logAction("Report Schedule", "Create", "Scheduled '{$schedulable[$report]}' ($frequency) to " . implode(', ', $valid_emails));
        }

        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Report schedule added.';
    }

    header('Location: schedules.php');
    exit;
}

// ---- Delete a schedule ----------------------------------------------------
if (isset($_GET['delete'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    $schedule_id = intval($_GET['delete']);
    $sched_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT schedule_report FROM report_schedules WHERE schedule_id = $schedule_id"));
    $requireScheduleWrite($sched_row['schedule_report'] ?? '');
    mysqli_query($mysqli, "DELETE FROM report_schedules WHERE schedule_id = $schedule_id");

    if (function_exists('logAction')) {
        logAction("Report Schedule", "Delete", "Deleted report schedule #$schedule_id");
    }

    $_SESSION['alert_type'] = 'success';
    $_SESSION['alert_message'] = 'Report schedule deleted.';
    header('Location: schedules.php');
    exit;
}

// ---- Toggle active/paused -------------------------------------------------
if (isset($_GET['toggle'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    $schedule_id = intval($_GET['toggle']);
    $sched_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT schedule_report FROM report_schedules WHERE schedule_id = $schedule_id"));
    $requireScheduleWrite($sched_row['schedule_report'] ?? '');
    mysqli_query($mysqli, "UPDATE report_schedules SET schedule_active = IF(schedule_active = 1, 0, 1) WHERE schedule_id = $schedule_id");

    $_SESSION['alert_type'] = 'success';
    $_SESSION['alert_message'] = 'Report schedule updated.';
    header('Location: schedules.php');
    exit;
}

// ---- Render (chrome + list) ----------------------------------------------
require_once "includes/inc_all_reports.php";

$sql_schedules = mysqli_query($mysqli, "SELECT * FROM report_schedules ORDER BY schedule_created_at DESC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-paper-plane me-2"></i>Scheduled &amp; Emailed Reports</h3>
    </div>
    <div class="card-body p-0">

        <div class="px-3 pt-3 pb-1">
            <small class="text-muted">
                Recipients must be active staff users. Each active schedule emails a headline summary of the chosen report to its recipients at the selected cadence.
                Delivery runs from the RivetMSP cron (the same scheduler that sends other queued mail); a schedule is sent again once its frequency has elapsed since the last send.
            </small>
        </div>

 <?php if (!$can_schedule) { ?>
            <div class="px-3 pb-2"><small class="text-muted">You have read-only access to reporting, so schedules can be viewed but not changed.</small></div>
        <?php } else { ?>
        <!-- Add schedule -->
        <form method="post" class="p-3 form-row align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="add_schedule" value="1">
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">Report</label>
                <select class="form-control" name="schedule_report" required>
                    <?php foreach ($schedulable as $key => $label) { ?>
                        <option value="<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2 col-6 mb-2">
                <label class="mb-1">Frequency</label>
                <select class="form-control" name="schedule_frequency" required>
                    <?php foreach ($valid_frequencies as $key => $label) { ?>
                        <option value="<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-5 col-8 mb-2">
                <label class="mb-1">Recipients</label>
                <input type="text" class="form-control" name="schedule_recipients" placeholder="ops@example.com, owner@example.com" required>
            </div>
            <div class="col-md-2 col-4 mb-2">
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-fw fa-plus me-1"></i>Add</button>
            </div>
        </form>
        <?php } ?>

        <!-- Existing schedules -->
        <div class="table-responsive-sm px-3 pb-3">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Frequency</th>
                        <th>Recipients</th>
                        <th>Status</th>
                        <th>Last sent</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$sql_schedules || mysqli_num_rows($sql_schedules) === 0) { ?>
                        <tr><td colspan="6" class="text-center text-muted">No report schedules yet.</td></tr>
                    <?php } else {
                        while ($row = mysqli_fetch_assoc($sql_schedules)) {
                            $schedule_id = intval($row['schedule_id']);
                            $report_key  = $row['schedule_report'];
                            if (!$can_schedule_report($report_key)) {
                                continue; // never list recipients/figures of a report the viewer could not open
                            }
                            $report_name = $schedulable[$report_key] ?? ($report_key . ' (unavailable)');
                            $frequency   = $valid_frequencies[$row['schedule_frequency']] ?? ucfirst((string) $row['schedule_frequency']);
                            $active      = intval($row['schedule_active']) === 1;
                            $last_sent   = !empty($row['schedule_last_sent']) ? nullable_htmlentities($row['schedule_last_sent']) : '<span class="text-muted">Never</span>';
                            ?>
                        <tr>
                            <td><?php echo nullable_htmlentities($report_name); ?></td>
                            <td><?php echo nullable_htmlentities($frequency); ?></td>
                            <td><?php echo nullable_htmlentities($row['schedule_recipients']); ?></td>
                            <td>
                                <?php if ($active) { ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php } else { ?>
                                    <span class="badge text-bg-secondary">Paused</span>
                                <?php } ?>
                            </td>
                            <td><?php echo $last_sent; ?></td>
                            <td class="text-end">
                                <?php if ($can_schedule) { ?>
                                <a class="btn btn-sm btn-outline-secondary" href="schedules.php?toggle=<?php echo $schedule_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-fw fa-<?php echo $active ? 'pause' : 'play'; ?>"></i><?php echo $active ? ' Pause' : ' Resume'; ?>
                                </a>
                                <a class="btn btn-sm btn-outline-danger confirm-link" href="schedules.php?delete=<?php echo $schedule_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-fw fa-trash"></i> Delete
                                </a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } } ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
