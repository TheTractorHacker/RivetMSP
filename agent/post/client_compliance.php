<?php

/*
 * Per-customer compliance: chosen standards, checklist reviews, snapshots and sharing with the client's portal users.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use RivetMSP\Compliance\ComplianceService;
use RivetMSP\Core\CoreBridge;

/** Common gate for every action here: CSRF, permission, a real client the user may access, and the migrated schema. */
$client_compliance_gate = static function () use ($mysqli): int {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $client_id = intval($_POST['client_id'] ?? 0);
    $exists = $client_id > 0 ? mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id = $client_id") : false;
    if (!$exists || mysqli_num_rows($exists) === 0) {
        flash_alert('Client not found.', 'error');
        redirect('clients.php');
    }
    $GLOBALS['client_id'] = $client_id;
    enforceClientAccess();
    if (!ComplianceService::subjectsReady($mysqli)) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }

    return $client_id;
};

$client_compliance_audit = static function (string $event, int $client_id, string $action, string $summary, array $meta = []) use ($session_user_id): void {
    try {
        CoreBridge::audit()?->log($event, (int) $session_user_id, 'client', $client_id, $action, $summary, $meta);
    } catch (\Throwable $e) {
        error_log('Compliance audit event not recorded: ' . $e->getMessage());
    }
};

if (isset($_POST['set_client_compliance_frameworks'])) {
    $client_id = $client_compliance_gate();
    $posted = array_map('strval', (array) ($_POST['frameworks'] ?? []));
    $stored = ComplianceService::subjects($mysqli)->setFrameworks($client_id, $posted);

    logAction('Client', 'Edit', "$session_name set compliance standards for client $client_id", $client_id, $client_id);
    $client_compliance_audit('compliance.client_frameworks_set', $client_id, 'update', 'Client compliance standards changed', ['frameworks' => $stored]);
    flash_alert($stored ? 'Standards saved.' : 'No standards selected: the checklist is hidden.');
    redirect();
}

if (isset($_POST['record_client_compliance_review'])) {
    $client_id = $client_compliance_gate();
    $item_id = (string) ($_POST['item_id'] ?? '');
    try {
        ComplianceService::subjects($mysqli)->record(
            $client_id,
            $item_id,
            (int) $session_user_id,
            (string) ($_POST['reviewer_name'] ?? ''),
            (string) ($_POST['reviewed_on'] ?? ''),
            (string) ($_POST['next_due_on'] ?? '') ?: null,
            (string) ($_POST['note'] ?? '') ?: null
        );
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect();
    }

    logAction('Client', 'Edit', "$session_name recorded a compliance review ($item_id) for client $client_id", $client_id, $client_id);
    $client_compliance_audit('compliance.client_review_recorded', $client_id, 'create', 'Client compliance review recorded', ['item' => $item_id]);
    flash_alert('Review recorded.');
    redirect();
}

if (isset($_POST['take_client_compliance_snapshot'])) {
    $client_id = $client_compliance_gate();
    $svc = ComplianceService::subjects($mysqli);
    if (!$svc->frameworks($client_id)) {
        flash_alert('Choose at least one standard first.', 'error');
        redirect();
    }
    $id = $svc->snapshot($client_id, (int) $session_user_id);

    logAction('Client', 'Create', "$session_name saved a compliance snapshot for client $client_id", $client_id, $client_id);
    $client_compliance_audit('compliance.client_snapshot_taken', $client_id, 'create', 'Client compliance snapshot saved', ['snapshot_id' => $id]);
    flash_alert('Snapshot saved.');
    redirect();
}

if (isset($_POST['share_client_compliance'])) {
    $client_id = $client_compliance_gate();
    $snapshot_id = intval($_POST['snapshot_id'] ?? 0);
    try {
        ComplianceService::subjects($mysqli)->share($client_id, $snapshot_id, (string) ($_POST['note'] ?? '') ?: null, (int) $session_user_id);
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect();
    }

    logAction('Client', 'Edit', "$session_name shared compliance snapshot $snapshot_id with client $client_id", $client_id, $client_id);
    $client_compliance_audit('compliance.client_report_shared', $client_id, 'update', 'Client compliance report shared with the client portal', ['snapshot_id' => $snapshot_id]);
    flash_alert('Shared. The client\'s portal users now see it under Security.');
    redirect();
}

if (isset($_POST['unshare_client_compliance'])) {
    $client_id = $client_compliance_gate();
    ComplianceService::subjects($mysqli)->unshare($client_id);

    logAction('Client', 'Edit', "$session_name stopped sharing compliance with client $client_id", $client_id, $client_id);
    $client_compliance_audit('compliance.client_report_unshared', $client_id, 'update', 'Client compliance report removed from the client portal');
    flash_alert('No longer shared.');
    redirect();
}
