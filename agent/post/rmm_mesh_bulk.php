<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Fleet page: install the MeshCentral agent on every device of a target (RivetCore 1.0.0-rc.16, RemoteActions::installMeshAgentBulk(), the code behind
 * `POST endpoint_devices/mesh/install`). A direct JSON endpoint like agent/post/rmm_remote_tools.php. POST only, CSRF-checked, signed-in users only.
 *
 * ONE AUTHORIZATION PATH. Core checks the role, the clients the user may reach (a device of another client is never reached or counted), rmm.remote.manage per client, the
 * remote-tools switch, that MeshCentral install is set up, the size cap (bulk_run_max) and the approval threshold, and it needs `confirm`. This file only turns the form into
 * Core's request: it takes no client id as a trust anchor. The first call (no `confirm`) answers 422 `confirmation_required` with the `device_count` it would reach.
 *
 * Fields: csrf_token, target = "client:<id>" | "tag:<id>" | "group:<id>" | "all:0", force=1 (optional), confirm=1.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$reply = static function (int $http, bool $ok, string $code, string $text, array $extra = []): never {
    http_response_code($http);
    echo json_encode(['success' => $ok, 'code' => $code, ($ok ? 'message' : 'error') => $text] + $extra, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reply(405, false, 'method', 'Use a POST with the CSRF token.');
}
$tok = $_POST['csrf_token'] ?? null;
$own = $_SESSION['csrf_token'] ?? null;
if (!is_string($tok) || !is_string($own) || $own === '' || !hash_equals($own, $tok)) {
    $reply(403, false, 'csrf', 'Invalid CSRF token');
}
if (!rivetRmmEnabled($mysqli)) {
    $reply(404, false, 'module_disabled', 'The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.');
}

$target = (string) ($_POST['target'] ?? '');
if (preg_match('/^(client|tag|group|all):(\d{1,9})$/', $target, $m) !== 1) {
    $reply(422, false, 'invalid', 'Choose who gets the MeshCentral agent.');
}
$flag = static fn (string $k): bool => isset($_POST[$k]) && is_string($_POST[$k]) && in_array($_POST[$k], ['1', 'true', 'on'], true);
$rmm = rivetRmmModule($mysqli);
$r = $rmm->remoteActions()->installMeshAgentBulk(rivetRmmPrincipal((int) $session_user_id, (string) $session_name),
    ['target' => $m[1] === 'all' ? ['type' => 'all'] : ['type' => $m[1], 'id' => (int) $m[2]], 'force' => $flag('force'), 'confirm' => $flag('confirm')]);

$extra = [];
foreach (['device_count', 'created', 'skipped_denied', 'skipped_installed', 'skipped_queued', 'skipped_unsupported', 'skipped_error'] as $k) {
    if (isset($r->data[$k])) {
        $extra[$k] = (int) $r->data[$k];
    }
}
if (!empty($r->data['errors']) && is_array($r->data['errors'])) {
    $extra['errors'] = array_values(array_map('strval', array_slice($r->data['errors'], 0, 5)));
}
$reply($r->ok ? 200 : $r->http, $r->ok, $r->code, $r->message, $extra);
