<?php
/*
 * One hypervisor host: its storage, cluster state and the VM table (search, state/type/missing filters). "Ignore this host" needs
 * rmm.hypervisor.manage; see includes/rmm_hypervisor_ui.php.
 */

ob_start();
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_hypervisor_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmHypervisorPage($mysqli, (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    require_once "../includes/footer.php";
    return;
}
$deviceId = (int) ($_GET['device_id'] ?? 0);
echo rivetRmmHypervisorHostPage(rivetRmmHypervisorHostView($ctx, $deviceId, $_GET), $ctx['csrf'], $ctx['perm']['manage']);

require_once "../includes/footer.php";
