<?php

/*
 * ITFlow - Pax8 cost sync worker (roadmap #25). Pull-only - see includes/pax8_functions.php.
 *
 * require_once'd from cron/cron.php. Expects $mysqli to be in scope. Pax8 subscription costs
 * change far less often than QBO/Xero invoices do, so this only actually syncs once every 24
 * hours (tracked via pax8_integrations.last_synced_at) even though cron.php itself may run far
 * more often than that - no value in hammering the Pax8 API every 15-60 minutes for data that
 * realistically changes a few times a month.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    return;
}

require_once dirname(__DIR__) . '/includes/pax8_functions.php';
require_once dirname(__DIR__) . '/includes/class_pax8_client.php';

$integration = getPax8Integration($mysqli);
if (!$integration || intval($integration['enabled']) !== 1 || empty($integration['client_id']) || empty($integration['client_secret_enc'])) {
    return; // not connected / nothing to do
}

$last_sync = $integration['last_synced_at'] ?? null;
if (!empty($last_sync) && strtotime($last_sync) > (time() - 86400)) {
    return; // synced within the last 24h - nothing to do yet
}

$integration_id = intval($integration['id']);

try {
    $pax8 = makePax8Client($mysqli, $integration_id);
    $result = pax8SyncAll($mysqli, $pax8, $integration_id);
    logApp("Pax8", "info", "Pax8 sync: {$result['companies']} companies seen, {$result['subscriptions']} subscriptions pulled across {$result['mapped_companies']} mapped companies");
} catch (Throwable $e) {
    logApp("Pax8", "error", "Pax8 sync failed: " . $e->getMessage());
}

// Roadmap #25 part 2: billing-back. Separate try/catch so a billing-sync problem never masks
// (or is masked by) the cost-sync result above. Gated inside pax8BillingSyncAll() itself - a
// no-op unless the global switch AND at least one client's own switch are both on.
try {
    require_once dirname(__DIR__) . '/includes/pax8_functions.php';
    $billing_result = pax8BillingSyncAll($mysqli);
    if ($billing_result['clients'] > 0) {
        logApp("Pax8", "info", "Pax8 billing sync: {$billing_result['added']} line item(s) added, {$billing_result['updated']} updated, {$billing_result['archived']} archived across {$billing_result['clients']} client(s) ({$billing_result['skipped_no_rule']} subscription(s) skipped - no billing rule)");
    }
} catch (Throwable $e) {
    logApp("Pax8", "error", "Pax8 billing sync failed: " . $e->getMessage());
}
