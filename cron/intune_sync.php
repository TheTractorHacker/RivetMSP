<?php

/*
 * RivetMSP - Intune device sync worker (GitHub roadmap #10, slice 2). Pull-only, PER CLIENT TENANT.
 *
 * require_once'd from cron/cron.php. Expects $mysqli to be in scope. Modeled on cron/pax8_sync.php's
 * shape (a pull-based sync worker scoped across multiple clients' own stored credentials), but unlike
 * Pax8 (one global API credential) there is one microsoft_integrations row PER CLIENT (slice 1) - so
 * this loops over every client's own tenant and syncs each one independently, through its own
 * GraphClient built from that row's own tenant_id/azure_client_id/client_secret_enc. One client's
 * Intune tenant being down, unreachable, or missing admin consent never blocks another client's sync -
 * each row's failure is caught and logged (IntuneSyncService::run already writes its own
 * intune_sync_log row) and the loop moves on.
 *
 * Only rows with enabled=1 AND intune_sync_enabled=1 are synced - slice 1's settings page has had
 * both switches since it shipped, with intune_sync_enabled explicitly labelled "not yet implemented"
 * until now. A row synced within the last hour is skipped (Intune device inventory does not change
 * minute to minute, and this keeps a frequent cron.php from hammering every client's tenant).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    return;
}

require_once dirname(__DIR__) . '/src/Integrations/Microsoft/GraphException.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/GraphTokenCache.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/InMemoryGraphTokenCache.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/MysqliGraphTokenCache.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/GraphClient.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/GraphClientFactory.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/IntuneAssetMapper.php';
require_once dirname(__DIR__) . '/src/Integrations/Microsoft/IntuneSyncService.php';

use RivetMSP\Integrations\Microsoft\IntuneSyncService;

$rows = mysqli_query($mysqli, "SELECT * FROM microsoft_integrations
    WHERE enabled = 1 AND intune_sync_enabled = 1
    AND (last_sync_at IS NULL OR last_sync_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))");

if (!$rows) {
    return;
}

$synced = 0;
$failed = 0;

while ($row = mysqli_fetch_assoc($rows)) {
    $clientName = sanitizeInput(getFieldById('clients', (int) $row['client_id'], 'client_name'));
    try {
        $result = IntuneSyncService::run($mysqli, $row, 0, 120);
        if ($result['ok']) {
            $synced++;
            logApp("Intune", "info", "Intune sync for $clientName: " . $result['message']);
        } else {
            $failed++;
            logApp("Intune", "error", "Intune sync for $clientName failed: " . $result['message']);
        }
    } catch (Throwable $e) {
        $failed++;
        logApp("Intune", "error", "Intune sync for $clientName threw: " . $e->getMessage());
    }
}

logApp("Intune", "info", "Intune sync cron: $synced client tenant(s) synced, $failed failed");
