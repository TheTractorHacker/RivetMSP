<?php
/*
 * Patch compliance across the fleet: how many devices are missing critical or security updates, by client, site or group, and which devices need attention.
 * Needs the RMM module, the `patching` sub-switch (Administration > Endpoint agent) and the rmm.patch.view ability. Every read and write goes through RivetCore's
 * technician API as the signed-in user; see includes/rmm_patch_ui.php.
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_patch_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmPatchPage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
echo rivetRmmPatchCompliancePage(rivetRmmPatchComplianceView($ctx, $_GET));

require_once "../includes/footer.php";
