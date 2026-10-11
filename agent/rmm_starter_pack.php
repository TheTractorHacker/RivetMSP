<?php
/*
 * Starter pack (RMM, RivetCore 1.0.0-rc.13): browse the ready-made scripts, check sets and policy drafts, preview an install, install the ones you pick, uninstall untouched ones.
 * Needs the RMM module, the `scripts` sub-switch and an administrator (rmm.admin). Every read and write is a call into RivetCore's StarterActions as the signed-in user
 * (includes/rmm_starter_ui.php).
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_starter_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmStarterPage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
echo rivetRmmStarterPageHtml(rivetRmmStarterView($ctx, $_GET), $ctx);

require_once "../includes/footer.php";
