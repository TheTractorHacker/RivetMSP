<?php
/*
 * The deployed-versus-expected report as a CSV download (the "Download CSV" button of agent/rmm_software_report.php).
 *
 *   GET /agent/rmm_software_report_csv.php?group_by=package|client|ring|device&client_id=&package_id=&ring=&state=
 *
 * A read-only endpoint on the session, like agent/rmm_job_output.php. Core builds the file (RFC 4180, CRLF; a cell that starts with = + - @ tab or CR gets a leading apostrophe because
 * host names and package names come from devices and administrators) and decides who may read it: module off or no software view 403, a client outside the user's clients the same 404
 * as a missing one. The export is audited by Core. Nothing is written or changed here.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_software_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$fail = static function (int $code, string $error): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $error;
    exit;
};
if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('software_deploy', $mysqli)) {
    $fail(404, 'Not found.');
}
// only the filters the report page knows, in their own types (Core validates again)
$q = [];
if (is_string($_GET['group_by'] ?? null) && in_array($_GET['group_by'], RIVET_RMM_SW_GROUPS, true)) {
    $q['group_by'] = $_GET['group_by'];
}
foreach (['client_id', 'package_id'] as $k) {
    if (is_string($_GET[$k] ?? null) && ctype_digit($_GET[$k]) && strlen($_GET[$k]) <= 10 && (int) $_GET[$k] > 0) {
        $q[$k] = $_GET[$k];
    }
}
if (is_string($_GET['ring'] ?? null) && $_GET['ring'] !== '') {
    $q['ring'] = mb_substr($_GET['ring'], 0, 40);
}
if (is_string($_GET['state'] ?? null) && in_array($_GET['state'], RIVET_RMM_SW_STATES, true)) {
    $q['state'] = $_GET['state'];
}
$q['limit'] = '1000';
try {
    $resp = rivetRmmSoftwareRawApi($mysqli, (int) $session_user_id, (string) ($session_name ?? ''), 'GET', ['software_report', 'csv'], $q);
} catch (\Throwable $e) {
    error_log('RMM software report CSV failed: ' . get_class($e) . ': ' . $e->getMessage());
    $fail(503, 'The report is not available. The database update for software deployment (2.6.97) may not have run yet.');
}
if ($resp->status !== 200) {
    $j = json_decode((string) $resp->body, true);
    $fail($resp->status === 403 ? 403 : 404, $resp->status === 403 ? (string) ($j['error'] ?? 'Not allowed.') : 'Not found.');
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="software-deployment.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo (string) $resp->body;
