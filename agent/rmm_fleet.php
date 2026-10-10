<?php
/*
 * Agent fleet: the endpoint agent's devices at a glance. Counts by status, devices that went quiet, devices waiting for approval,
 * outdated agents and rings, recent job failures, the capacity panel (administrators), and a filtered, paginated device list.
 *
 * Read-only. The data is RivetCore\Rmm\Read\RmmReadModel (fleetCounts, listDevices, pendingApprovals, currentBinaries) and Capacity\CapacityReport,
 * through includes/rmm_ui.php; the user's clients scope every number. With the RMM module off the page is the standard "module off" notice,
 * built without asking the database anything.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_ui_render.php';

mysqli_report(MYSQLI_REPORT_OFF);

$rmm_denied = static function (string $title, string $detail): void {
    echo '<div class="card card-dark"><div class="card-body text-center py-5"><h3 class="text-secondary"><i class="fas fa-fw fa-power-off me-2"></i>' . nullable_htmlentities($title)
        . '</h3><p class="text-muted mb-0">' . nullable_htmlentities($detail) . '</p></div></div>';
    require_once "../includes/footer.php";
};

if (!$config_core_rmm_enabled || !rivetRmmEnabled($mysqli)) {
    $rmm_denied('RMM module is turned off', 'The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.');
    return;
}

$rmm_filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'ring' => (string) ($_GET['ring'] ?? ''),
    'page' => (int) ($_GET['page'] ?? 1),
    // RivetCore 1.0.0-rc.9 filters (tag name, group id, software name) and the Outdated software card's question (name, oldest acceptable version)
    'tag' => is_string($_GET['tag'] ?? null) ? trim($_GET['tag']) : '',
    'group' => (int) ($_GET['group'] ?? 0),
    'software' => is_string($_GET['software'] ?? null) ? trim($_GET['software']) : '',
    'osw' => is_string($_GET['osw'] ?? null) ? trim($_GET['osw']) : '',
    'osv' => is_string($_GET['osv'] ?? null) ? trim($_GET['osv']) : '',
];
if (!in_array($rmm_filters['status'], ['online', 'offline', 'stale', 'never', 'pending_approval', 'linked', 'rejected'], true)) {
    $rmm_filters['status'] = '';
}
if (!in_array($rmm_filters['ring'], ['stable', 'pilot'], true)) {
    $rmm_filters['ring'] = '';
}
$rmm_filters['q'] = mb_substr($rmm_filters['q'], 0, 100);

$rmm_fleet = rivetRmmUiFleet($mysqli, (int) $session_user_id, $rmm_filters);
if ($rmm_fleet === null) {
    $rmm_denied('No access', 'Your role cannot view agent devices.');
    return;
}
// "Add device": null (no button, no dialog) for a user who may neither administer the module nor manage enrollment tokens.
$rmm_installer = rivetRmmUiInstaller($mysqli, (int) $session_user_id, null, (int) ($_GET['client_id'] ?? 0) ?: null);
if ($rmm_installer !== null) {
    $rmm_installer['autoopen'] = isset($_GET['add']);   // the Endpoints menu's "Add device" opens the dialog straight away
}
$rmm_installer_scripts = $rmm_installer !== null;   // includes/footer.php links js/rmm_installer.js only when this is set
echo rivetRmmUiFleetPage($rmm_fleet, (string) ($_SESSION['csrf_token'] ?? ''), $rmm_installer);

require_once "../includes/footer.php";
