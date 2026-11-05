<?php
/*
 * Demo data for the Integrations guide page (29-integrations.md), Lakeshore Managed IT.
 * EXAMPLE DATA, NOT A LIVE CONNECTION: nothing here talks to Microsoft. Tenant and application IDs are obviously fake;
 * the secrets are made-up placeholders stored through the app's own encryptSetting().
 *
 *   RIVETIT_APP_DIR=/tmp/rivetmsp-user-guide-demo php docs/user-guide/tools/seed/95-integrations.php
 *
 * What it adds (all looked up by NAME; idempotent):
 *   - Outlook calendar sync credentials (Administration > Settings > Calendar sync) so the page shows its "saved" state;
 *   - one per-client Microsoft 365 / Entra ID connection (Cedar & Pine Architects) with a finished Intune sync log and
 *     three Intune-managed devices linked to that client's existing assets (needs seed 20 for the assets).
 * The vendor RMM, UniFi and Comet rows come from seed 69 (G10). Never depends on a later seed.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "95-integrations: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "95-integrations: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($v) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }

// ---- Outlook calendar sync (Azure app credentials) ------------------------------------------------
if (!one("SELECT config_outlook_cal_client_id FROM settings WHERE company_id = 1")) {
    $secret = e(encryptSetting('example-secret-value-not-real'));
    q("UPDATE settings SET config_outlook_cal_tenant_id = '11111111-2222-3333-4444-555555555555',
        config_outlook_cal_client_id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', config_outlook_cal_client_secret = '$secret' WHERE company_id = 1");
}

// ---- Per-client Microsoft 365 / Entra ID tenant (Cedar & Pine Architects) -------------------------
$cid = (int) one("SELECT client_id FROM clients WHERE client_name = 'Cedar & Pine Architects' LIMIT 1");
if ($cid && !one("SELECT microsoft_integration_id FROM microsoft_integrations WHERE client_id = $cid")) {
    $secret = e(encryptSetting('example-tenant-secret-not-real'));
    q("INSERT INTO microsoft_integrations SET client_id = $cid, tenant_id = '99999999-8888-7777-6666-555555555555',
        azure_client_id = 'ffffffff-eeee-dddd-cccc-bbbbbbbbbbbb', client_secret_enc = '$secret', enabled = 1, intune_sync_enabled = 1,
        directory_sync_enabled = 0, last_sync_at = NOW() - INTERVAL 40 MINUTE, last_test_at = NOW() - INTERVAL 40 MINUTE, last_test_success = 1,
        created_by = 1");
}
$mid = (int) one("SELECT microsoft_integration_id FROM microsoft_integrations WHERE client_id = $cid LIMIT 1");
if ($mid) {
    $devices = [
        // asset name, serial, OS, maker, model, compliance, encrypted, primary user, hours since last Intune check-in
        ['CPA-WS-01', 'LKDEMO-WS-0501', 'Windows 11 Pro for Workstations', 'Dell', 'Precision 3660', 'compliant', 1, 'oliver.cedar@cedarpinearch.example', 3],
        ['LT-CPA-31', 'LKDEMO-PR5680-3101', 'Windows 11 Pro', 'Dell', 'Precision 5680', 'compliant', 1, 'wen.li@cedarpinearch.example', 5],
        ['LT-CPA-04', 'LKDEMO-PR5570-0504', 'Windows 11 Pro', 'Dell', 'Precision 5570', 'noncompliant', 0, 'rosa.martinelli@cedarpinearch.example', 30],
    ];
    foreach ($devices as [$name, $serial, $os, $make, $model, $comp, $enc, $upn, $hrs]) {
        $aid = (int) one("SELECT asset_id FROM assets WHERE asset_client_id = $cid AND asset_name = '" . e($name) . "' LIMIT 1");
        if (!$aid || one("SELECT id FROM asset_intune_links WHERE asset_id = $aid AND microsoft_integration_id = $mid")) { continue; }
        $idev = 'intune-' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name));
        q("INSERT INTO asset_intune_links SET asset_id = $aid, microsoft_integration_id = $mid, intune_device_id = '" . e($idev) . "',
            hostname = '" . e($name) . "', serial_number = '" . e($serial) . "', os_name = '" . e($os) . "', manufacturer = '" . e($make) . "',
            model = '" . e($model) . "', management_agent = 'mdm', compliance_state = '" . e($comp) . "', is_encrypted = $enc,
            primary_user_upn = '" . e($upn) . "', enrolled_at = NOW() - INTERVAL 200 DAY, intune_last_sync_at = NOW() - INTERVAL $hrs HOUR,
            last_sync = NOW() - INTERVAL 40 MINUTE");
    }
    if (!one("SELECT id FROM intune_sync_log WHERE microsoft_integration_id = $mid")) {
        q("INSERT INTO intune_sync_log SET microsoft_integration_id = $mid, client_id = $cid, started_at = NOW() - INTERVAL 40 MINUTE,
            finished_at = NOW() - INTERVAL 39 MINUTE, status = 'success', devices_created = 0, devices_updated = 1, devices_matched = 3,
            devices_skipped = 0, triggered_by = 0");
    }
}
echo "95-integrations: done\n";
