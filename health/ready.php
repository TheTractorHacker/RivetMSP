<?php
/*
 * Readiness: the database is reachable and its schema matches this code. Redis is reported but never fails
 * readiness - every Redis feature fails open. External integrations are deliberately not checked.
 * Returns only ok/fail per check (no hostnames, versions, or error text). The checks run in RivetCore.
 * Off (404) until settings.config_core_health_enabled = 1.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database_version.php';

$checker = (isset($mysqli) && $mysqli instanceof mysqli && @$mysqli->ping())
    ? \RivetMSP\Core\CoreBridge::readiness(static function () use ($mysqli): bool {
        $row = $mysqli->query('SELECT config_current_database_version AS v FROM settings WHERE company_id = 1')?->fetch_assoc();
        return $row && version_compare((string) $row['v'], LATEST_DATABASE_VERSION, '>=');
    })
    : null;

if ($checker === null) {
    http_response_code(404);
    echo json_encode(['status' => 'disabled']);
    exit;
}
$report = $checker->check();
http_response_code($report['ready'] ? 200 : 503);
echo json_encode(['status' => $report['ready'] ? 'ready' : 'not_ready', 'checks' => $report['checks']]);
