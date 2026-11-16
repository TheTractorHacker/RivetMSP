<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Audit trail: verify the hash chain now, and the optional copy of every entry to a JSON-lines file / syslog.
 * See src/Audit/AuditChain.php and src/Audit/AuditSink.php.
 */

if (isset($_POST['verify_audit_chain'])) {
    validateCSRFToken($_POST['csrf_token']);

    $verify = \RivetMSP\Platform\Nightly::verifyAuditChain($mysqli);
    logAction('Settings', 'Edit', "$session_name ran the audit trail hash chain check: " . $verify['status']);
    if ($verify['status'] === 'ok' || $verify['status'] === 'empty') {
        flash_alert('Audit trail checked: ' . ($verify['status'] === 'ok' ? number_format($verify['checked']) . ' entries, the chain is intact' : 'nothing sealed yet'));
    } else {
        flash_alert('Audit trail check: ' . htmlspecialchars((string) $verify['status'], ENT_QUOTES, 'UTF-8') . '. See the Integrity panel.', 'error');
    }

    redirect();
}

if (isset($_POST['reanchor_audit_chain'])) {
    validateCSRFToken($_POST['csrf_token']);

    // MRR-21: accepting the current rows as the new baseline hides a cut if misused, so it needs the administrator's password again
    if (!secReauthPassword($mysqli, (int) $session_user_id, (string) ($_POST['admin_current_password'] ?? ''))) {
        flash_alert('Your current password is required (and must be correct) to re-anchor the audit trail.', 'error');
        redirect();
    }
    $re = (new \RivetMSP\Audit\AuditChain($mysqli))->reanchor();
    logAction('Settings', 'Edit', "$session_name re-anchored the audit trail hash chain (previous: " . $re['previous'] . '; new head ' . ($re['head_id'] !== null ? '#' . $re['head_id'] : 'none') . ', ' . $re['checked'] . ' entries' . ($re['upgraded'] ? ', plain chain upgraded to keyed' : '') . ')' . ($re['ok'] ? '' : ': refused, ' . $re['reason']));
    if ($re['ok']) {
        \RivetMSP\Recovery\RecoveryAlerts::clear($mysqli, 'audit_chain.broken');
        \RivetMSP\Recovery\RecoveryAlerts::clear($mysqli, 'audit_chain.key');
        flash_alert('Audit trail re-anchored: ' . number_format($re['checked']) . ' entries accepted as the new baseline. Who, when and the previous head are in the audit log.');
    } else {
        flash_alert('The audit trail was not re-anchored: ' . htmlspecialchars((string) $re['reason'], ENT_QUOTES, 'UTF-8'), 'error');
    }

    redirect();
}

if (isset($_POST['save_audit_sink'])) {
    validateCSRFToken($_POST['csrf_token']);

    $sink_path = trim((string) ($_POST['audit_sink_path'] ?? ''));
    $sink_syslog = isset($_POST['audit_sink_syslog']) ? '1' : '0';
    $problem = \RivetMSP\Audit\AuditSink::pathProblem($sink_path, dirname(__DIR__, 2));

    if ($problem !== null) {
        flash_alert('The audit copy file was not saved: ' . htmlspecialchars($problem, ENT_QUOTES, 'UTF-8'), 'error');
    } else {
        \RivetMSP\Platform\PlatformSettings::set($mysqli, 'audit_sink_path', $sink_path);
        \RivetMSP\Platform\PlatformSettings::set($mysqli, 'audit_sink_syslog', $sink_syslog);
        logAction('Settings', 'Edit', "$session_name set the audit trail copy: file " . ($sink_path === '' ? 'off' : $sink_path) . ', syslog ' . ($sink_syslog === '1' ? 'on' : 'off'));
        flash_alert('Audit trail copy settings saved');
    }

    redirect();
}
