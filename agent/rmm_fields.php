<?php
/*
 * Custom fields: typed facts per client, site or device that scripts can use as {{field.name}}. Definitions are administrators'; values follow
 * RivetCore's rules (device management for the client, administrators for secrets). Device values are edited on the asset page.
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_pol_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
$vm = rivetRmmPolFieldsView($ctx, $_GET);
echo rivetRmmPolFieldsPage($vm, $ctx);

require_once "../includes/footer.php";
