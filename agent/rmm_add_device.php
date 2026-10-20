<?php
/*
 * Add device / Build installer: one page for every way to put the endpoint agent on a machine. Choose the client; take the stamped Windows .exe, the stamped .msi, the PowerShell
 * deployment commands, or the Linux commands (a script that downloads and verifies the agent, a .deb command, an .rpm command) with copy buttons.
 *
 * Nothing is decided here. The buttons post to agent/post/rmm_installer.php (CSRF, RivetCore RmmAdmin::downloadInstaller / deploymentCommands: rmm.token.manage for the client,
 * an audited token each); the Linux files come from the release an administrator published under Administration > Endpoint agent > Agent updates. The dialog on the Agent Fleet
 * page (Quick download) stays the quick way for one Windows PC; it is also a button here.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_ui_render.php';
require_once dirname(__DIR__) . '/includes/rmm_agent_feed_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$rmm_denied = static function (string $title, string $detail): void {
    echo '<div class="card card-dark"><div class="card-body text-center py-5"><h3 class="text-secondary"><i class="fas fa-fw fa-power-off me-2"></i>' . nullable_htmlentities($title)
        . '</h3><p class="text-muted mb-0">' . nullable_htmlentities($detail) . '</p></div></div>';
    require_once "../includes/footer.php";
};

if (!$config_core_rmm_enabled || !rivetRmmEnabled($mysqli)) {
    $rmm_denied('RMM module is turned off', 'The RMM module is switched off, so installers cannot be created. An administrator can switch it on under Administration > Endpoint agent (the RMM module card).');
    return;
}
$rmm_installer = rivetRmmUiInstaller($mysqli, (int) $session_user_id, null, (int) ($_GET['client_id'] ?? 0) ?: null);
if ($rmm_installer === null) {
    $rmm_denied('No access', 'Your role lacks the permission "Create enrollment tokens" (rmm.token.manage), so it cannot build installers. Ask an administrator to grant it under Administration > Roles.');
    return;
}
$rmm_feed = new \RivetMsp\AgentFeed\Feed($mysqli);
$rmm_linux = $rmm_feed->ready() ? $rmm_feed->linuxRecord() : null;
$rmm_add_device_scripts = true;   // includes/footer.php links js/rmm_add_device.js only when this is set
$rmm_installer_scripts = true;    // and js/rmm_installer.js for the Quick download dialog
echo rivetAgentAddDevicePage($rmm_installer, (string) ($_SESSION['csrf_token'] ?? ''), $rmm_linux, !empty($session_is_admin));
echo rivetRmmUiInstallerModal($rmm_installer, (string) ($_SESSION['csrf_token'] ?? ''));

require_once "../includes/footer.php";
