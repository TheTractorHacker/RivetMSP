<?php
// GET /api/v1/agent_software/{job_id}/{secret}   (no device credential: the secret in the path IS the credential)
// The device-facing download of a HOSTED installer (RivetCore\Rmm\Http\HostedFileApi; section 14.3 of docs/rmm/SOFTWARE_DEPLOY.md in rivet/rivet-core).
// Not part of the frozen device protocol - an edition-mounted route, exactly like agent_linux_file.php. Every refusal (no such job, wrong secret,
// expired, finished, the file gone or damaged, the module or software_deploy switched off) is the SAME 404, so the route cannot be used to find out
// which jobs exist. TLS required unless the edition allows plain http (loopback tests). Dispatched from index.php before any Bearer parsing.
defined('FROM_API') || die();
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

global $session_user_id, $session_ip, $session_user_agent, $segments;
$session_user_id = 0;
$session_ip = getIP();
$session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $api = rivetRmmModule()->hostedFileApi(static fn (string $bucket, int $limit, int $window): bool => api_rate_limit($bucket, $limit, $window));
    $req = rivetRmmRequest('agent_software', [(string) ($segments[1] ?? ''), (string) ($segments[2] ?? '')]);
    $response = $api->handle($req);
} catch (\Throwable $e) {
    error_log('agent_software: ' . get_class($e));
    $response = \RivetCore\Rmm\Http\RmmResponse::json(500, ['error' => 'Internal error.', 'code' => 'internal']);
}
(new \RivetCore\Rmm\Http\SapiEmitter())->emit($response);
exit;
