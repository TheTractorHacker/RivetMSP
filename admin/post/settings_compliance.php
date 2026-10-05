<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use RivetMSP\Core\CoreBridge;
use RivetCore\Compliance\RetentionPolicy;

if (isset($_POST['save_compliance_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $profile = (string) ($_POST['compliance_profile'] ?? '');
    if (!RetentionPolicy::isValidProfile($profile)) {
        flash_alert('Choose one of the listed presets.', 'error');
        redirect();
    }
    $audit_days = max(0, min(36500, intval($_POST['audit_retention_days'] ?? 0)));
    $log_days = max(0, min(36500, intval($_POST['log_retention_days'] ?? 0)));

    $row = null;
    $res = @mysqli_query($mysqli, "SELECT config_compliance_profile, config_audit_retention_days, config_log_retention, config_core_audit_enabled FROM settings WHERE company_id = 1");
    if ($res) {
        $row = mysqli_fetch_assoc($res) ?: null;
    }
    if (!$row) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }

    // A preset is a floor: a positive value below it is raised to it. 0 (keep forever) is always allowed.
    $raised = [];
    if (RetentionPolicy::isBelowFloor($profile, $audit_days)) {
        $raised[] = 'audit trail';
        $audit_days = RetentionPolicy::floorDays($profile);
    }
    if (RetentionPolicy::isBelowFloor($profile, $log_days)) {
        $raised[] = 'activity log';
        $log_days = RetentionPolicy::floorDays($profile);
    }

    // Recording the audit trail is a switch on RivetMSP; any preset requires it, so it cannot be left off under one.
    $audit_recording = ($profile !== RetentionPolicy::NONE || isset($_POST['audit_recording'])) ? 1 : 0;

    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_compliance_profile = ?, config_audit_retention_days = ?, config_log_retention = ?, config_core_audit_enabled = ? WHERE company_id = 1");
    mysqli_stmt_bind_param($stmt, 'siii', $profile, $audit_days, $log_days, $audit_recording);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction('Settings', 'Edit', "$session_name changed compliance settings");
    // Record the change if the audit trail was on before or is on now (so turning it off is itself on the record). Never fatal.
    CoreBridge::reset();
    try {
        CoreBridge::audit(!empty($row['config_core_audit_enabled']) || $audit_recording === 1)?->log('compliance.settings_changed', (int) $session_user_id, 'settings', 'compliance', 'update', 'Compliance settings changed', [
            'before' => ['profile' => $row['config_compliance_profile'], 'audit_days' => (int) $row['config_audit_retention_days'], 'log_days' => (int) $row['config_log_retention'], 'audit_recording' => (int) $row['config_core_audit_enabled']],
            'after' => ['profile' => $profile, 'audit_days' => $audit_days, 'log_days' => $log_days, 'audit_recording' => $audit_recording],
        ]);
    } catch (\Throwable $e) {
        error_log('Compliance audit event not recorded: ' . $e->getMessage());
    }

    $message = 'Compliance settings saved.';
    if ($audit_recording && empty($row['config_core_audit_enabled'])) {
        $message .= ' Audit recording is now on.';
    }
    if ($raised) {
        $message .= ' The ' . implode(' and ', $raised) . ' retention was raised to ' . RetentionPolicy::floorDays($profile) . ' days, the minimum for ' . RetentionPolicy::PROFILES[$profile]['label'] . '.';
    }
    flash_alert($message, $raised ? 'warning' : 'success');
    redirect();
}
