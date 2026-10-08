<?php
// Technician API for the built-in endpoint agent (user API token required; the legacy shared X-Api-Key is refused).
//   GET  /api/v1/endpoint_devices                      list devices in the caller's clients   (module_rmm >= 1)
//   GET  /api/v1/endpoint_devices/{id}                 device detail, checks, recent jobs
//   GET  /api/v1/endpoint_devices/{id}/jobs            job history (output only with module_rmm_scripts >= 2)
//   POST /api/v1/endpoint_devices/{id}/jobs            {type: powershell|reboot|collect, script|script_id, params, timeout_s, destructive, confirm}
//   POST /api/v1/endpoint_devices/{id}/jobs/{job}/cancel
//   POST /api/v1/endpoint_devices/{id}/remote          {force?} -> {url, session_id}              (module_rmm_remote_connect >= 1)
// Routing, authorization (role, then client scope, decided on every call) and the responses are RivetCore\Rmm\Http\TechnicianApi in rivet/rivet-core;
// this file authenticates the user token and hands the principal over.
defined('FROM_API') || die();
require_once $DOCUMENT_ROOT . '/vendor/autoload.php';
require_once $DOCUMENT_ROOT . '/includes/rmm_bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);
// A device list and device jobs are per-user decisions (role, then client scope), so the instance-wide legacy X-Api-Key is refused.
if (!empty($legacy_api_key_auth)) {
    api_error(403, 'This endpoint requires a user API token');
}
$session_ip = getIP();
$session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

$ea_response = rivetRmmModule()->technicianApi()->handle(
    rivetRmmRequest('endpoint_devices', array_slice($segments, 1)),
    rivetRmmPrincipal(intval($api_user_id), (string) $session_name)
);
(new \RivetCore\Rmm\Http\SapiEmitter())->emit($ea_response);
exit;
