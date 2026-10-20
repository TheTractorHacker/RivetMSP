<?php
/*
 * One VM: its facts, open alerts and state-change history, and "Ignore this VM" (needs rmm.hypervisor.manage). See includes/rmm_hypervisor_ui.php.
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
$vmId = (int) ($_GET['vm_id'] ?? 0);
echo rivetRmmHypervisorVmPage(rivetRmmHypervisorVmView($ctx, $vmId), $ctx['csrf'], $ctx['perm']['manage']);

require_once "../includes/footer.php";
