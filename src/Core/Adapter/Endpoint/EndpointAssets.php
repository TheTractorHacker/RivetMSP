<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetMSP\Assets\AssetInventorySync;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;

/** The asset side of RMM identity matching and linking, over RivetMSP's `assets` and `asset_interfaces` tables. */
final class EndpointAssets implements RmmAssetsInterface, RmmAssetNamesInterface
{
    /**
     * The asset type names this edition actually offers (mirrors $asset_types_array in includes/load_global_settings.php, the
     * keys an administrator sees in the Asset type dropdown), narrowed to the ones DeviceTypeClassifier::resolve() can produce.
     * No "Hypervisor" type exists here, so a Hypervisor-labelled device resolves to Server via the fallback chain.
     */
    private const ASSET_TYPE_NAMES = ['Laptop', 'Desktop', 'Server', 'Tablet', 'Virtual Machine', 'Other'];

    public function __construct(private DatabaseInterface $database)
    {
    }

    /** @return list<string> */
    public static function assetTypeNames(): array
    {
        return self::ASSET_TYPE_NAMES;
    }

    public function findBySerial(string $serial, int $limit = 10): array
    {
        return $this->shape($this->database->fetchAll(
            'SELECT asset_id, asset_name, asset_client_id, asset_serial FROM assets WHERE asset_serial = ? AND asset_archived_at IS NULL LIMIT ' . max(1, $limit),
            [$serial]
        ));
    }

    public function findByMacs(array $macs, int $limit = 20): array
    {
        if ($macs === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($macs), '?'));

        return $this->shape($this->database->fetchAll(
            "SELECT DISTINCT a.asset_id, a.asset_name, a.asset_client_id, a.asset_serial FROM assets a JOIN asset_interfaces ai ON ai.interface_asset_id = a.asset_id
             WHERE LOWER(REPLACE(ai.interface_mac, '-', ':')) IN ($in) AND a.asset_archived_at IS NULL LIMIT " . max(1, $limit),
            array_values($macs)
        ));
    }

    public function findByHostname(string $hostname, int $limit = 10): array
    {
        return $this->shape($this->database->fetchAll(
            'SELECT asset_id, asset_name, asset_client_id, asset_serial FROM assets WHERE LOWER(asset_name) = LOWER(?) AND asset_archived_at IS NULL LIMIT ' . max(1, $limit),
            [$hostname]
        ));
    }

    public function assetNames(array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->database->fetchAll("SELECT asset_id, asset_name FROM assets WHERE asset_id IN ($in)", $ids) as $r) {
            $out[(int) $r['asset_id']] = (string) $r['asset_name'];
        }

        return $out;
    }

    public function find(int $assetId): ?array
    {
        $r = $this->database->fetchOne('SELECT asset_id, asset_client_id, asset_archived_at FROM assets WHERE asset_id = ?', [$assetId]);

        return $r === null ? null : ['asset_id' => (int) $r['asset_id'], 'client_id' => (int) $r['asset_client_id'], 'archived' => $r['asset_archived_at'] !== null];
    }

    public function createForDevice(array $device, int $clientId, int $locationId): int
    {
        // Core's contract for this method is Windows-only ("Windows <os_version>"), but os_version itself may already start with
        // "Windows" (agents report it that way, e.g. "Windows 11 23H2"): osLabel() avoids writing "Windows Windows 11 23H2".
        $osText = AssetInventorySync::osLabel('Windows', (string) $device['os_version']);
        // Device type (RivetCore 1.0.0-rc.29): the installer's explicit choice (asset_type_hint) wins; else the agent's own
        // classification (asset_type_detected); a label this edition has no asset type for is mapped down the fallback chain
        // (Hypervisor and Virtual Machine -> Server, Tablet, Desktop and Other -> Laptop) - RivetMSP has no "Hypervisor" type,
        // so a Hypervisor-labelled device becomes a Server asset. Neither key present (a pre-rc.29 caller): keep the old rule.
        $assetType = isset($device['asset_type_hint']) || isset($device['asset_type_detected'])
            ? \RivetCore\Rmm\Assets\DeviceTypeClassifier::resolve(
                \RivetCore\Rmm\Assets\DeviceTypeClassifier::choose($device['asset_type_hint'] ?? null, (string) ($device['asset_type_detected'] ?? '')),
                self::assetTypeNames()
            )
            : (stripos((string) $device['os_version'], 'server') !== false ? 'Server' : 'Laptop');
        $res = $this->database->execute(
            "INSERT INTO assets (asset_type, asset_name, asset_make, asset_model, asset_serial, asset_os, asset_status, asset_client_id, asset_location_id, asset_created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?, NOW())",
            [$assetType, $device['hostname'], (string) ($device['manufacturer'] ?? ''), $device['model'], $device['serial'], $osText, $clientId, $locationId]
        );

        $assetId = (int) $res->insertId;
        // The values just written are the agent's own: remember them so a later report may refresh them (a human edit is still never touched).
        if ($assetId > 0 && ($mysqli = $this->mysqli()) !== null) {
            try {
                (new AssetInventorySync($mysqli))->recordWritten($assetId, ['make' => (string) ($device['manufacturer'] ?? ''), 'model' => (string) ($device['model'] ?? ''), 'os' => $osText]);
            } catch (\Throwable $e) {
                error_log('Asset inventory state skipped: ' . $e->getMessage());
            }
        }

        return $assetId;
    }

    /** The edition's mysqli behind the adapter (same connection as the module's transactions); null for an adapter that is not mysqli. */
    private function mysqli(): ?\mysqli
    {
        return $this->database instanceof \RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter ? $this->database->connection() : null;
    }

    public function fillBlanks(int $assetId, array $facts): void
    {
        // Fill blanks only; never overwrite what a human typed.
        $this->database->execute(
            "UPDATE assets SET asset_serial = IF(asset_serial IS NULL OR asset_serial = '', ?, asset_serial), asset_model = IF(asset_model IS NULL OR asset_model = '', ?, asset_model),
             asset_make = IF(asset_make = '', ?, asset_make), asset_os = IF(asset_os IS NULL OR asset_os = '', ?, asset_os) WHERE asset_id = ?",
            [$facts['serial'], $facts['model'], (string) $facts['manufacturer'], $facts['os'], $assetId]
        );
        // Device type (rc.29): only when the asset's type is blank - a device matched to an EXISTING asset never has its type
        // changed. RivetMSP's asset_type is NOT NULL (never actually blank in practice), but honour the contract literally
        // in case some row was created with an empty string.
        if (\RivetCore\Rmm\Assets\DeviceTypeClassifier::validLabel($facts['type'] ?? null)) {
            $this->database->execute(
                "UPDATE assets SET asset_type = ? WHERE asset_id = ? AND (asset_type IS NULL OR asset_type = '')",
                [\RivetCore\Rmm\Assets\DeviceTypeClassifier::resolve((string) $facts['type'], self::ASSET_TYPE_NAMES), $assetId]
            );
        }
    }

    public function moveToClient(int $assetId, int $clientId, int $locationId): void
    {
        $this->database->execute('UPDATE assets SET asset_client_id = ?, asset_location_id = ? WHERE asset_id = ?', [$clientId, $locationId, $assetId]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    private function shape(array $rows): array
    {
        return array_map(static fn (array $a): array => [
            'asset_id' => (int) $a['asset_id'],
            'asset_name' => (string) $a['asset_name'],
            'client_id' => (int) $a['asset_client_id'],
            'serial' => $a['asset_serial'] === null ? null : (string) $a['asset_serial'],
        ], $rows);
    }
}
