<?php
/*
 * Hypervisors across the fleet: Proxmox VE and Hyper-V hosts, their cluster/quorum state, and a roll-up of their guests and storage health.
 * Needs the RMM module, the `hypervisor` sub-switch (Administration > Endpoint agent) and the rmm.hypervisor.view ability. Every read goes
 * through RivetCore's HypervisorActions as the signed-in user; see includes/rmm_hypervisor_ui.php.
 *
 * Covers Proxmox VE and Hyper-V only. Nothing here has been run against a real hypervisor host (vendor/rivet/rivet-core/docs/rmm/HYPERVISORS.md section 9).
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_hypervisor_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmHypervisorPage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    require_once "../includes/footer.php";   // the notice is printed; close the page
    return;
}
echo rivetRmmHypervisorHostsPage(rivetRmmHypervisorHostsView($ctx, $_GET));

require_once "../includes/footer.php";
