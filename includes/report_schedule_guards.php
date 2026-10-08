<?php

/*
 * Guards for scheduled / emailed reports (agent/reports/schedules.php, cron/report_scheduler.php). Pure PHP so
 * tests/report_schedule_guards.php can run them without a database.
 */

/**
 * The module a user needs to read this report on screen. A schedule must not hand out figures the creator
 * could not open themselves (the headline summaries are company-wide, e.g. income, expense, AR aging, MRR).
 * Unknown keys fall back to the strictest module.
 */
function reportScheduleRequiredModule($report_key): string
{
    $financial = ['mrr', 'income_summary', 'expense_summary', 'clients_with_balance'];
    $support = ['service_desk', 'technician_performance', 'ticket_summary', 'csat'];
    if (in_array((string) $report_key, $support, true)) {
        return 'module_support';
    }
    return 'module_financial'; // financial reports and anything unknown
}

/**
 * Split a recipient string and keep only valid addresses that belong to active staff accounts.
 * Returns ['allowed' => [...], 'rejected' => [...]] (comparison is case-insensitive; order and first spelling kept).
 */
function reportScheduleFilterRecipients($recipients, array $staff_emails): array
{
    $staff = [];
    foreach ($staff_emails as $e) {
        $staff[strtolower(trim((string) $e))] = true;
    }
    $allowed = [];
    $rejected = [];
    $seen = [];
    foreach (preg_split('/[,;\s]+/', (string) $recipients, -1, PREG_SPLIT_NO_EMPTY) as $to) {
        $key = strtolower($to);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (filter_var($to, FILTER_VALIDATE_EMAIL) && isset($staff[$key])) {
            $allowed[] = $to;
        } else {
            $rejected[] = $to;
        }
    }
    return ['allowed' => $allowed, 'rejected' => $rejected];
}

/**
 * Email addresses of active, non-archived staff (user_type 1) accounts.
 */
function reportScheduleStaffEmails($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT user_email FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_email != ''");
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[] = $row['user_email'];
    }
    return $out;
}
