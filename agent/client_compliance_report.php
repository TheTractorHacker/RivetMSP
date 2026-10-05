<?php

// Export of one client's compliance: ?client_id=N&format=csv|html[&snapshot=ID]. Needs access to the client.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

enforceUserPermission('module_client');

use RivetCore\Compliance\ReportRenderer;
use RivetMSP\Compliance\ComplianceService;
use RivetMSP\Core\CoreBridge;

$client_id = intval($_GET['client_id'] ?? 0);
$res = $client_id > 0 ? mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id") : false;
$client = $res ? mysqli_fetch_assoc($res) : null;
if (!$client) {
    http_response_code(404);
    exit('Client not found.');
}
enforceClientAccess();
if (!ComplianceService::subjectsReady($mysqli)) {
    http_response_code(503);
    exit('Run the database update first.');
}

$svc = ComplianceService::subjects($mysqli);
$snapshot_id = intval($_GET['snapshot'] ?? 0);
if ($snapshot_id > 0) {
    $taken = $svc->snapshots($client_id)->get($snapshot_id);
    if ($taken === null) {
        http_response_code(404);
        exit('Snapshot not found.');
    }
    $assessment = $taken['assessment'];
} else {
    if (!$svc->frameworks($client_id)) {
        http_response_code(404);
        exit('No standards are selected for this client.');
    }
    $assessment = $svc->assess($client_id);
}

$format = ($_GET['format'] ?? 'html') === 'csv' ? 'csv' : 'html';
$renderer = new ReportRenderer();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
try {
    CoreBridge::audit()?->log('compliance.client_report_exported', (int) $session_user_id, 'client', $client_id, 'export', 'Client compliance report exported (' . $format . ')', ['format' => $format, 'snapshot_id' => $snapshot_id ?: null]);
} catch (\Throwable $e) {
    error_log('Compliance audit event not recorded: ' . $e->getMessage());
}

$stamp = $assessment->generatedAt->format('Ymd-His');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="client-' . $client_id . '-compliance-' . $stamp . '.csv"');
    echo $renderer->csv($assessment);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
echo $renderer->html($assessment, (string) $client['client_name'], null, defined('APP_VERSION') ? (string) APP_VERSION : null);
