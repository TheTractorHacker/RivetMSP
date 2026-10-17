<?php
/*
 * Software deployment, $n page. Needs the RMM module, the `software_deploy` sub-switch (Administration > Endpoint agent) and the rmm.software.view ability. Every read and write goes
 * through RivetCore's technician API as the signed-in user; see includes/rmm_software_ui.php.
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_software_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmSoftwarePage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
echo rivetRmmSoftwareCatalogPage(rivetRmmSoftwareCatalogView($ctx, $_GET), $ctx);

require_once "../includes/footer.php";
