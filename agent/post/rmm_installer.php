<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * "Add device" installer flow of the endpoint agent: streams the stamped Windows installer, or creates the one-time Linux install command.
 * A direct endpoint (like rmm_agent.php). POST only, CSRF-checked, signed-in users only. The decision is RivetCore\Rmm\Admin\RmmAdmin's, the same
 * code and the same audit entry ("Installer Created") as Administration > Endpoint agent > Deployment: downloadInstaller() for Windows (rmm.token.manage
 * for the client; the token is revoked again when the file cannot be served), deploymentCommands() for Linux. Hiding the button is cosmetic.
 *
 * Fields: csrf_token, os=windows|linux, mode=download|commands (default by os), client_id, location_id, arch=amd64|arm64, ring=stable|pilot,
 * ttl_hours (default 24), multiple=1 (25 uses, else 1), label. A fetch request (X-Requested-With: fetch) gets JSON errors; a plain form post gets a
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
if (($inst_os === 'linux') !== ($inst_mode === 'commands')) {
    $inst_fail(422, 'invalid', $inst_os === 'linux' ? 'Linux has no installer download; create the install command instead.' : 'A Windows installer is a download.');
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
$inst_args = [intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), $inst_ring, $inst_post_int('ttl_hours', 1, $inst_ttl_max, min(24, $inst_ttl_max)),
    !empty($_POST['multiple']) ? 25 : 1, trim((string) ($_POST['label'] ?? '')), $inst_arch];

if ($inst_mode === 'commands') {
    $r = $inst_admin->deploymentCommands($inst_who, ...$inst_args);
    if (!$r->ok) {
        $inst_fail($r->http >= 400 ? $r->http : 422, $r->code, $r->message);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'linux' => $r->data['commands']['linux'], 'department' => $r->data['department'], 'expires_at' => str_replace('T', ' ', rtrim((string) $r->data['token']['expires_at'], 'Z')),
        'max_uses' => (int) $r->data['token']['max_uses'], 'arch' => $inst_arch]);
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
