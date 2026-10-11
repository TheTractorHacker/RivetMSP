<?php
/*
 * Log rules (RMM Phase 8): phrase rules that raise an alert when collected log lines match often enough. Create, change, switch and delete are calls into RivetCore's
 * LogActions as the signed-in user (needs the alert-management ability); the rule text is plain, never a regular expression (includes/rmm_log_ui.php).
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_log_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmLogPage($mysqli, 'rules', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
echo rivetRmmLogRulesPage(rivetRmmLogRulesView($ctx, $_GET));

require_once "../includes/footer.php";
