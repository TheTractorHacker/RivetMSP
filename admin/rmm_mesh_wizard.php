<?php
/*
 * Administration > Remote access (MeshCentral) setup: a five-step guided page with live status. It reads the saved settings (RivetCore read models) and posts to
 * admin/post/rmm_mesh_wizard.php (CSRF, administrators only, RmmAdmin::saveMesh). The login key and the device group id are never printed back as text.
 */
require_once "includes/inc_all_admin.php";

require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__) . '/includes/rmm_scale_admin.php';   // rivetRmmScaleTake(), rivetRmmScaleMsgHtml()
require_once dirname(__DIR__) . '/includes/rmm_mesh_wizard.php';

mysqli_report(MYSQLI_REPORT_OFF);
$csrf = (string) $_SESSION['csrf_token'];

$res = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_binaries'");
if (!$res || mysqli_num_rows($res) === 0) {
    echo '<div class="alert alert-warning">Run the database update first (Administration &rarr; Updates), then reload this page.</div>';
    require_once "../includes/footer.php";
    return;
}
$rmm = rivetRmmModule();
$cfg = $rmm->readModel()->settingsSummary();
$who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);
$view = rivetRmmMeshInstallView($rmm, $who);

$cnt = static function (string $sql) use ($mysqli): int {
    $r = @mysqli_query($mysqli, $sql);
    $row = $r ? mysqli_fetch_row($r) : null;
    return $row ? (int) $row[0] : 0;
};
$counts = [
    'devices' => $cnt("SELECT COUNT(*) FROM endpoint_agent_devices WHERE retired_at IS NULL"),
    'mapped' => $cnt("SELECT COUNT(*) FROM endpoint_agent_mesh_nodes n JOIN endpoint_agent_devices d ON d.device_id = n.device_id WHERE d.retired_at IS NULL"),
    'installed' => $cnt("SELECT COUNT(*) FROM endpoint_agent_mesh_agents a JOIN endpoint_agent_devices d ON d.device_id = a.device_id WHERE a.installed = 1 AND d.retired_at IS NULL"),
];
[$msg, $old] = rivetRmmScaleTake('mesh_wizard');
$probe = $_SESSION['rmm_mesh_wiz_probe'] ?? null;
unset($_SESSION['rmm_mesh_wiz_probe']);
$probe = is_array($probe) ? $probe : null;
$test = $_SESSION['rmm_mesh_wiz_test'] ?? null;
$test = is_array($test) && time() - (int) ($test['at'] ?? 0) < 3600 ? ['ok' => !empty($test['ok']), 'text' => (string) ($test['text'] ?? '')] : null;

$steps = rivetRmmMeshWizardSteps($cfg, $view, $counts, $probe, $test);
$rmm_mesh_scripts = true;   // includes/footer.php links js/rmm_mesh_admin.js (show the group id, put the proposed pin in the field)
echo rivetRmmMeshWizardPage($cfg, $view, $steps, $old, $msg, $probe, $csrf, $rmm->enabled());

require_once "../includes/footer.php";
