<?php
/*
 * Escalation policies (RMM Phase 3): who is told, and when, when an alert stays open. Reads and writes go through RivetCore's technician API as the signed-in user
 * (includes/rmm_alr_ui.php); creating, changing and deleting policies is for administrators (Core decides again). Chat and webhook targets are not offered:
 * RivetMSP notifies in the app and by email; other channels use event subscriptions.
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_alr_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'alerting', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
$rmm_auto_extra_js = ['rmm_alr.js'];
echo rivetRmmAlrEscalationPage(rivetRmmAlrEscalationView($ctx, $_GET));

require_once "../includes/footer.php";
