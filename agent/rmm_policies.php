<?php
/*
 * Agent policies: what a group of devices is told (check-in and collection intervals, three feature toggles, the update ring and check templates),
 * who it is assigned to, its versions, and an "explain a device" box. Everything is read and written through RivetCore's technician API as the
 * signed-in user (includes/rmm_pol_ui.php): administrators edit, everyone who can view devices reads.
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__) . '/includes/rmm_pol_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'policies', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
$vm = rivetRmmPolPoliciesView($ctx, $_GET);
echo rivetRmmPolPoliciesPage($vm, $ctx);

// rc.27: the "Starter policies" card (includes/rmm_policy_pack_ui.php) - administrators only, on the list page only (not the new/detail views).
if ($vm['mode'] === 'list' && !empty($ctx['perm']['admin'])) {
    $ppRmm = rivetRmmModule($mysqli);
    $ppWho = rivetRmmPrincipal((int) $ctx['uid'], (string) $ctx['name']);
    $ppVm = rivetRmmPolicyPackView($ppRmm, $ppWho);
    $ppFlash = $_SESSION[RIVET_RMM_PP_SESSION] ?? null;
    unset($_SESSION[RIVET_RMM_PP_SESSION]);
    echo rivetRmmPolicyPackCard($ppVm, (string) $ctx['csrf'], is_array($ppFlash) ? $ppFlash : null);
}

require_once "../includes/footer.php";
