<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * "Add device" installer flow of the endpoint agent: streams the stamped Windows installer, or creates the one-time Linux install command.
 * A direct endpoint (like rmm_agent.php). POST only, CSRF-checked, signed-in users only. The decision is RivetCore\Rmm\Admin\RmmAdmin's, the same
 * code and the same audit entry ("Installer Created") as Administration > Endpoint agent > Deployment: downloadInstaller() for Windows (rmm.token.manage
 * for the client; the token is revoked again when the file cannot be served), deploymentCommands() for Linux. Hiding the button is cosmetic.
 *
 * Fields: csrf_token, os=windows|linux, mode=download|commands (default by os), client_id, location_id, arch=amd64|arm64, ring=stable|pilot,
 * ttl_hours (default 24), multiple=1 (25 uses, else 1), label, install_mesh_agent=1 (optional; also install the MeshCentral agent, RivetCore 1.0.0-rc.12), format=exe|msi (optional, default exe, Windows only; RivetCore 1.0.0-rc.16). A fetch request (X-Requested-With: fetch) gets JSON errors; a plain form post gets a
 * flash message and a redirect back.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);

$inst_json = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$inst_fail = static function (int $status, string $code, string $message) use ($inst_json): void {
    if ($inst_json) {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'code' => $code, 'error' => $message]);
        exit;
    }
    flash_alert(nullable_htmlentities($message), 'error');
    redirect();
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$inst_token = $_POST['csrf_token'] ?? null;
if (!is_string($inst_token) || $inst_token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $inst_token)) {
    $inst_fail(403, 'csrf', 'Your session token has expired. Reload the page and try again.');
}
// Module off: nothing is served, and the database is not asked anything beyond the state file / the edition flag.
if (!rivetRmmEnabled($mysqli)) {
    $inst_fail(404, 'module_disabled', 'The RMM module is switched off.');
}

$inst_post_int = static function (string $k, int $min, int $max, int $default): int {
    $v = $_POST[$k] ?? null;
    return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
};
$inst_os = (string) ($_POST['os'] ?? 'windows');
if (!in_array($inst_os, ['windows', 'linux'], true)) {
    $inst_fail(422, 'invalid', 'Choose Windows or Linux.');
}
$inst_mode = (string) ($_POST['mode'] ?? ($inst_os === 'linux' ? 'commands' : 'download'));
// 26.10.28: Windows may also ask for its deployment commands (PowerShell, msiexec) on the Build installer page; Linux has no download, only commands.
if (!in_array($inst_mode, ['download', 'commands'], true)) {
    $inst_fail(422, 'invalid', 'Choose download or commands.');
}
if ($inst_os === 'linux' && $inst_mode !== 'commands') {
    $inst_fail(422, 'invalid', 'Linux has no installer download; create the install command instead.');
}
$inst_arch = (string) ($_POST['arch'] ?? 'amd64');
$inst_ring = (string) ($_POST['ring'] ?? 'stable');
if (!in_array($inst_arch, ['amd64', 'arm64'], true) || !in_array($inst_ring, ['stable', 'pilot'], true)) {
    $inst_fail(422, 'invalid', 'Choose a valid architecture and update ring.');
}
$inst_rmm = rivetRmmModule();
$inst_admin = $inst_rmm->admin();
$inst_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);
$inst_ttl_max = max(1, (int) ($inst_rmm->readModel()->settingsSummary()['enroll_max_ttl_h'] ?? 720));
// RivetCore 1.0.0-rc.12: the optional "Also install the MeshCentral agent" box. Off unless ticked; Core refuses it, creating nothing, when Mesh is not switched on and
// configured (an https address and a device group id), and its sentence is what the person reads.
$inst_mesh = ($_POST['install_mesh_agent'] ?? '') === '1';
// RivetCore 1.0.0-rc.16: `format` = exe (the default, byte for byte as before) or msi (a copy of the published MSI template with the same payload appended). Windows downloads only:
// a Linux install command has no format. Core refuses an MSI whose template is not published, in words, and leaves no token behind.
$inst_format = (string) ($_POST['format'] ?? 'exe');
if (!in_array($inst_format, ['exe', 'msi'], true)) {
    $inst_fail(422, 'invalid', 'Choose exe or msi.');
}
if ($inst_format === 'msi' && $inst_os !== 'windows') {
    $inst_fail(422, 'invalid', 'The MSI format is for Windows only.');
}
// rc.27: install-apps-with-the-agent (migration 0033) - the ticked catalog apps (needs rmm.software.deploy for this client; Core refuses it, creating
// nothing, when software_deploy is off or a ticked app's vendor parameters have no value for this client).
$inst_app_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['app_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
// rc.29: the installer's "Detect automatically / Laptop / Desktop / Server / Virtual machine / Hypervisor" dropdown - stored on the
// enrollment token (never in the installer file), used only when a NEW asset is created for a device that enrolls with it; otherwise
// the agent's own hardware-based classification decides, same as before this feature existed. An unknown value is passed through as
// 'auto' rather than guessed at (Core itself also validates and would refuse a bad label).
$inst_device_type_raw = (string) ($_POST['device_type'] ?? 'auto');
$inst_device_type = in_array($inst_device_type_raw, ['auto', ...\RivetCore\Rmm\Assets\DeviceTypeClassifier::LABELS], true) ? $inst_device_type_raw : 'auto';
$inst_args = [intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), $inst_ring, $inst_post_int('ttl_hours', 1, $inst_ttl_max, min(24, $inst_ttl_max)),
    !empty($_POST['multiple']) ? 25 : 1, trim((string) ($_POST['label'] ?? '')), $inst_arch, $inst_mesh, $inst_format, $inst_app_ids, $inst_device_type];

if ($inst_mode === 'commands') {
    $r = $inst_admin->deploymentCommands($inst_who, ...$inst_args);
    if (!$r->ok) {
        $inst_fail($r->http >= 400 ? $r->http : 422, $r->code, $r->message);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $inst_out = ['success' => true, 'linux' => $r->data['commands']['linux'], 'department' => $r->data['department'], 'expires_at' => rivetRmmTime($r->data['token']['expires_at'], true, true, 'Y-m-d H:i', (string) $r->data['token']['expires_at'])   /* UTC in storage, company time on screen */,
        'max_uses' => (int) $r->data['token']['max_uses'], 'arch' => $inst_arch, 'install_mesh_agent' => $inst_mesh];
    if ($inst_os === 'windows') {
        // The PowerShell deployment snippet, and for format=msi the msiexec / properties / uninstall commands of the stamped package (RivetCore InstallerService::deploymentCommands).
        $inst_out['windows'] = ['powershell' => $r->data['commands']['powershell'], 'filename' => $r->data['commands']['filename'], 'msi' => $r->data['commands']['msi'] ?? null];
    } else {
        // Self-hosted first (RivetIT's Linux-hosting feature, ported here): when this server hosts install-linux.sh and the agent for this CPU
        // (includes/rmm_linux_store.php), the command downloads both FROM THIS SERVER with a derived, per-file, non-enrolling credential and needs
        // no tarball unpacked and no GitHub release recorded. Falls back to the 26.10.28 GitHub-release-link commands, then the oldest
        // tarball-expected form, so an installation that has not published or hosted anything yet still gets a working (if less convenient) command.
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_linux_store.php';
        $inst_self = \RivetMsp\AgentFeed\LinuxStore::command($mysqli, (string) $r->data['commands']['linux'], $inst_arch);
        if ($inst_self !== null) {
            $inst_out['linux_self'] = $inst_self['command'];
            $inst_out['release'] = $inst_self['version'];
        }
        // 26.10.28: the Linux commands that need no files next to them: built from the Linux assets a published release recorded (Administration > Endpoint agent > Agent updates).
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_agent_feed.php';
        $inst_feed = new \RivetMsp\AgentFeed\Feed($mysqli);
        $inst_rec = $inst_feed->ready() ? $inst_feed->linuxRecord() : null;
        $inst_cfg = $inst_rmm->readModel()->settingsSummary();
        if ($inst_rec !== null) {
            $inst_srv = (string) ($r->data['commands']['server'] ?? '');
            $inst_out['release'] ??= (string) ($inst_rec['version'] ?? '');
            $inst_out['linux_script'] = \RivetMsp\AgentFeed\LinuxInstall::script($inst_rec, (string) $r->data['commands']['linux'], (string) $r->data['department']);
            $inst_out['linux_deb'] = \RivetMsp\AgentFeed\LinuxInstall::package($inst_rec, 'deb', (string) $r->data['commands']['linux'], (string) $r->data['token_plain'], $inst_srv, $inst_cfg['ca_pem'] ?? null, (string) $r->data['department']);
            $inst_out['linux_rpm'] = \RivetMsp\AgentFeed\LinuxInstall::package($inst_rec, 'rpm', (string) $r->data['commands']['linux'], (string) $r->data['token_plain'], $inst_srv, $inst_cfg['ca_pem'] ?? null, (string) $r->data['department']);
        }
    }
    echo json_encode($inst_out);
    exit;
}

$r = $inst_admin->downloadInstaller($inst_who, ...$inst_args);
if (!$r->ok) {
    $inst_fail($r->http >= 400 ? $r->http : 422, $r->code, $r->message);
}
session_write_close();   // the stream can take a while; do not hold the session lock
while (ob_get_level() > 0) {
    ob_end_clean();
}
(new \RivetCore\Rmm\Http\SapiEmitter())->emit($r->data['download']);   // streamed with exact length after the size and SHA-256 check
exit;
