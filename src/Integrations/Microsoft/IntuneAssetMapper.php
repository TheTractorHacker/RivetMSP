<?php

namespace RivetMSP\Integrations\Microsoft;

use RivetMSP\Assets\AssetInventorySync;

/**
 * IntuneAssetMapper - matches Microsoft Intune managed devices (pulled via
 * GraphClient::listAllManagedDevices() for ONE client's own tenant) to RivetMSP assets,
 * SCOPED TO THAT CLIENT. Ported from RivetIT's src/Integrations/Microsoft/IntuneAssetMapper.php
 * (single global tenant, unscoped assets) for RivetMSP's per-client-tenant model (roadmap #10
 * slice 2): every microsoft_integrations row belongs to exactly one client_id (slice 1's UNIQUE
 * key), so every device pulled through it is that client's device, and every asset this class
 * touches - matched or newly created - is confined to that same client_id. This is the key
 * adaptation from RivetIT's version, which left new assets unassigned (asset_client_id = 0)
 * because it had no per-device client signal; here the client is never ambiguous, so it is
 * enforced directly, including refusing to touch (and counting as skipped) any asset matched by
 * serial/hostname that belongs to a DIFFERENT client - a cross-tenant match must never relabel
 * another client's asset.
 *
 * Match priority (within the integration's own client only):
 *   1. intune_device_id already in asset_intune_links for this integration (already linked)
 *   2. asset_serial match, scoped to asset_client_id = this integration's client_id
 *   3. case-insensitive hostname match on asset_name, scoped the same way (only if unique)
 */
class IntuneAssetMapper
{
    private \mysqli $mysqli;
    private int $microsoftIntegrationId;
    private int $clientId;
    private int $triggeredBy;

    public function __construct(\mysqli $mysqli, int $microsoftIntegrationId, int $clientId, int $triggeredBy = 0)
    {
        $this->mysqli = $mysqli;
        $this->microsoftIntegrationId = $microsoftIntegrationId;
        $this->clientId = $clientId;
        $this->triggeredBy = $triggeredBy;
    }

    /** @param array[] $devices @return array{created:int,updated:int,matched:int,skipped:int,errors:string[]} */
    public function syncDevices(array $devices): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($devices as $device) {
            try {
                $result = $this->syncDevice($device);
                $stats[$result]++;
            } catch (\Exception $e) {
                $stats['errors'][] = ($device['deviceName'] ?? 'unknown') . ': ' . $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function syncDevice(array $device): string
    {
        $m = $this->mysqli;
        $intg_id = $this->microsoftIntegrationId;
        $client_id = $this->clientId;

        $intune_device_id = trim((string) ($device['id'] ?? ''));
        $device_name = trim((string) ($device['deviceName'] ?? ''));

        if ($intune_device_id === '' || $device_name === '' || $client_id < 1) {
            return 'skipped';
        }

        $intune_device_id_esc = mysqli_real_escape_string($m, $intune_device_id);
        $azure_ad_device_id = trim((string) ($device['azureADDeviceId'] ?? ''));
        $azure_ad_device_id_esc = mysqli_real_escape_string($m, $azure_ad_device_id);
        $hostname_esc = mysqli_real_escape_string($m, $device_name);
        $serial = trim((string) ($device['serialNumber'] ?? ''));
        $serial_esc = mysqli_real_escape_string($m, $serial);
        $os_name = trim((string) ($device['operatingSystem'] ?? ''));
        $os_name_esc = mysqli_real_escape_string($m, $os_name);
        $os_version = trim((string) ($device['osVersion'] ?? ''));
        $manufacturer = trim((string) ($device['manufacturer'] ?? ''));
        $manufacturer_esc = mysqli_real_escape_string($m, $manufacturer);
        $model = trim((string) ($device['model'] ?? ''));
        $model_esc = mysqli_real_escape_string($m, $model);
        $management_agent_esc = mysqli_real_escape_string($m, trim((string) ($device['managementAgent'] ?? '')));
        $compliance_state_esc = mysqli_real_escape_string($m, trim((string) ($device['complianceState'] ?? '')));
        $is_encrypted = !empty($device['isEncrypted']) ? 1 : 0;
        $primary_user_upn = trim((string) ($device['userPrincipalName'] ?? ''));
        $primary_user_upn_esc = mysqli_real_escape_string($m, $primary_user_upn);

        $enrolled_at_val = $this->toDatetimeSql($device['enrolledDateTime'] ?? null);
        $intune_last_sync_val = $this->toDatetimeSql($device['lastSyncDateTime'] ?? null);
        $raw_json_esc = mysqli_real_escape_string($m, (string) json_encode($device));

        $link_fields_sql =
            "intune_device_id='$intune_device_id_esc', " .
            "azure_ad_device_id='$azure_ad_device_id_esc', " .
            "hostname='$hostname_esc', " .
            "serial_number='$serial_esc', " .
            "os_name='$os_name_esc', " .
            "manufacturer='$manufacturer_esc', " .
            "model='$model_esc', " .
            "management_agent='$management_agent_esc', " .
            "compliance_state='$compliance_state_esc', " .
            "is_encrypted=$is_encrypted, " .
            "primary_user_upn='$primary_user_upn_esc', " .
            "enrolled_at=$enrolled_at_val, " .
            "intune_last_sync_at=$intune_last_sync_val, " .
            "last_sync=NOW(), " .
            "raw_data_json='$raw_json_esc'";

        // ----- Step 1: already linked for THIS integration (and therefore this client) -----
        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id, asset_id FROM asset_intune_links
             WHERE microsoft_integration_id=$intg_id AND intune_device_id='$intune_device_id_esc' LIMIT 1"
        ));

        if ($existing) {
            mysqli_query($m, "UPDATE asset_intune_links SET $link_fields_sql WHERE id=" . intval($existing['id']));
            $this->syncAssetFields((int) $existing['asset_id'], $manufacturer, $model, AssetInventorySync::osLabel($os_name, $os_version), $primary_user_upn);

            return 'updated';
        }

        // ----- Step 2: try to match an existing asset, STRICTLY within this client's own assets -----
        $asset_id = 0;

        if (!$asset_id && $serial !== '') {
            $row = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT asset_id FROM assets WHERE asset_serial='$serial_esc' AND asset_client_id=$client_id AND asset_archived_at IS NULL LIMIT 1"
            ));
            if ($row) {
                $asset_id = (int) $row['asset_id'];
            }
        }

        if (!$asset_id) {
            $cnt = (int) (mysqli_fetch_assoc(mysqli_query($m,
                "SELECT COUNT(*) as c FROM assets WHERE LOWER(asset_name)=LOWER('$hostname_esc') AND asset_client_id=$client_id AND asset_archived_at IS NULL"
            ))['c'] ?? 0);
            if ($cnt === 1) {
                $row = mysqli_fetch_assoc(mysqli_query($m,
                    "SELECT asset_id FROM assets WHERE LOWER(asset_name)=LOWER('$hostname_esc') AND asset_client_id=$client_id AND asset_archived_at IS NULL LIMIT 1"
                ));
                if ($row) {
                    $asset_id = (int) $row['asset_id'];
                }
            }
        }

        // ----- Step 3: create a new asset, owned by this integration's client -----
        if (!$asset_id) {
            $asset_type = $this->guessAssetType($os_name);
            $os_combined_esc = mysqli_real_escape_string($m, AssetInventorySync::osLabel($os_name, $os_version));
            mysqli_query($m,
                "INSERT INTO assets SET
                 asset_type='$asset_type',
                 asset_name='$hostname_esc',
                 asset_make='$manufacturer_esc',
                 asset_serial='$serial_esc',
                 asset_os='$os_combined_esc',
                 asset_status='Active',
                 asset_client_id=$client_id,
                 asset_created_at=NOW()"
            );
            $asset_id = (int) mysqli_insert_id($m);
            $outcome = 'created';
        } else {
            $outcome = 'matched';
        }

        // ----- Step 4: insert/update the link row (unique on asset_id + microsoft_integration_id) -----
        $existing_link_for_asset = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id FROM asset_intune_links WHERE asset_id=$asset_id AND microsoft_integration_id=$intg_id LIMIT 1"
        ));

        if ($existing_link_for_asset) {
            mysqli_query($m, "UPDATE asset_intune_links SET $link_fields_sql WHERE id=" . intval($existing_link_for_asset['id']));
        } else {
            mysqli_query($m, "INSERT INTO asset_intune_links SET asset_id=$asset_id, microsoft_integration_id=$intg_id, $link_fields_sql");
        }

        $this->syncAssetFields($asset_id, $manufacturer, $model, AssetInventorySync::osLabel($os_name, $os_version), $primary_user_upn);

        return $outcome;
    }

    /** Make/model/OS onto the asset (never over a human edit) via the shared AssetInventorySync helper. A failure never fails the device. */
    private function syncAssetFields(int $asset_id, string $manufacturer, string $model, string $os, string $upn): void
    {
        try {
            $sync = new AssetInventorySync($this->mysqli);
            $sync->apply($asset_id, ['make' => $manufacturer, 'model' => $model, 'os' => $os], 'intune');
        } catch (\Throwable $e) {
            error_log('Intune asset sync skipped: ' . $e->getMessage());
        }
    }

    private function toDatetimeSql(?string $iso): string
    {
        if (empty($iso)) {
            return 'NULL';
        }
        $ts = strtotime($iso);
        if (!$ts) {
            return 'NULL';
        }

        return "'" . date('Y-m-d H:i:s', $ts) . "'";
    }

    private function guessAssetType(string $os_name): string
    {
        $os = strtolower($os_name);
        if (str_contains($os, 'ios') || str_contains($os, 'ipad') || str_contains($os, 'android')) {
            return 'Mobile';
        }
        if (str_contains($os, 'macos') || str_contains($os, 'mac os')) {
            return 'Desktop';
        }
        if (str_contains($os, 'windows server')) {
            return 'Server';
        }

        return 'Desktop';
    }

    public function startSyncLog(): int
    {
        $m = $this->mysqli;
        mysqli_query($m,
            "INSERT INTO intune_sync_log SET microsoft_integration_id={$this->microsoftIntegrationId}, client_id={$this->clientId}, triggered_by={$this->triggeredBy}"
        );

        return (int) mysqli_insert_id($m);
    }

    public function finishSyncLog(int $logId, array $stats): void
    {
        $m = $this->mysqli;
        $status = empty($stats['errors']) ? 'success' : 'failed';
        $errors = mysqli_real_escape_string($m, implode('; ', $stats['errors']));
        mysqli_query($m,
            "UPDATE intune_sync_log SET
             finished_at=NOW(), status='$status',
             devices_created={$stats['created']}, devices_updated={$stats['updated']},
             devices_matched={$stats['matched']}, devices_skipped={$stats['skipped']},
             errors='$errors'
             WHERE id=$logId"
        );

        mysqli_query($m, "UPDATE microsoft_integrations SET last_sync_at = NOW() WHERE microsoft_integration_id = {$this->microsoftIntegrationId}");
    }
}
