<?php
/*
 * Approvals: the queue of runs and schedules waiting for a second person, with what each will do, and approve / reject / cancel.
 * Needs the RMM module and the `scripts` sub-switch (Administration > Endpoint agent). Every read and write goes through RivetCore's technician API as the
 * signed-in user; see includes/rmm_scr_ui.php.
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_scr_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

// The queue belongs to the `scripts` switch; with the script library off but patch management on (RMM Phase 4) the page still opens, showing only requests to approve patches.
$patchOnly = !rivetRmmFeatureOn('scripts', $mysqli) && rivetRmmFeatureOn('patching', $mysqli);
if ($patchOnly) {
    $_GET['kind'] = 'patch';
}
$ctx = rivetRmmAutoPage($mysqli, $patchOnly ? 'patching' : 'scripts', (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
$rmm_auto_extra_js = ['rmm_scr.js'];
echo rivetRmmScrApprovalsPage(rivetRmmScrApprovalsView($ctx, $_GET), $ctx);

require_once "../includes/footer.php";
