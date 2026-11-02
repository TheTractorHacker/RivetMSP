<?php

namespace RivetMSP\Integrations\Microsoft;

/**
 * One Intune device sync run for ONE CLIENT's own microsoft_integrations row - used by cron
 * (and, later, a Sync Now button) so both behave and log identically. Ported from RivetIT's
 * src/Integrations/Microsoft/IntuneSyncService.php (single global tenant) for RivetMSP's
 * per-client-tenant model (roadmap #10 slice 2): takes a lock scoped to this row (no overlapping
 * runs for the SAME client; a different client's sync is never blocked by this one), writes an
 * intune_sync_log row carrying this row's client_id, fetches that client's own tenant's devices
 * with a hard time limit via GraphClientFactory::forRow($row), upserts them through
 * IntuneAssetMapper scoped to that same client_id, and on failure records a classified error
 * (auth failed / admin consent missing / throttled ...) in intune_sync_log.errors/.error_code.
 */
final class IntuneSyncService
{
    /** A 'running' row younger than this blocks a new run for the SAME integration (a crashed run stops blocking after it). */
    public const LOCK_SECONDS = 300;

    /**
     * @param array<string,mixed> $row a microsoft_integrations row (client_id, tenant_id, azure_client_id, client_secret_enc, ...)
     * @param array<string,mixed> $clientOptions passed to GraphClientFactory (tests point the endpoints at a mock via env vars)
     * @return array{ok:bool,message:string,error_code:?string,stats:?array,log_id:int,locked:bool}
     */
    public static function run(\mysqli $mysqli, array $row, int $triggeredBy = 0, int $timeLimitSeconds = 120, array $clientOptions = []): array
    {
        $id = (int) $row['microsoft_integration_id'];
        $client_id = (int) ($row['client_id'] ?? 0);
        $fail = static fn (string $m, ?string $code = null, int $log = 0, bool $locked = false) => ['ok' => false, 'message' => $m, 'error_code' => $code, 'stats' => null, 'log_id' => $log, 'locked' => $locked];

        if ($client_id < 1) {
            return $fail('This tenant has no client assigned; nothing to scope the sync to.');
        }
        if (empty($row['client_secret_enc']) || empty($row['tenant_id']) || empty($row['azure_client_id'])) {
            return $fail('Save a tenant ID, application (client) ID, and client secret before syncing.');
        }

        $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT id FROM intune_sync_log WHERE microsoft_integration_id=$id
             AND started_at > DATE_SUB(NOW(), INTERVAL " . self::LOCK_SECONDS . " SECOND) AND status='running' LIMIT 1"
        ));
        if ($recent) {
            return $fail('A sync is already running for this client. Please wait for it to finish.', null, 0, true);
        }

        $timeLimitSeconds = max(10, min(600, $timeLimitSeconds));
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($timeLimitSeconds + 30);
        }
        $client = GraphClientFactory::forRow($mysqli, $row, time() + $timeLimitSeconds, $clientOptions);
        $mapper = new IntuneAssetMapper($mysqli, $id, $client_id, $triggeredBy);
        $logId = $mapper->startSyncLog();

        try {
            $devices = $client->listAllManagedDevices();
            $stats = $mapper->syncDevices($devices);
            $mapper->finishSyncLog($logId, $stats);

            return [
                'ok' => empty($stats['errors']),
                'message' => "Intune sync complete: {$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched, {$stats['skipped']} skipped",
                'error_code' => null, 'stats' => $stats, 'log_id' => $logId, 'locked' => false,
            ];
        } catch (GraphException $e) {
            $code = $e->errorCode;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $code = GraphException::OTHER;
            $message = 'Sync failed: ' . $e->getMessage();
        }

        $m = mysqli_real_escape_string($mysqli, substr($message, 0, 4000));
        $c = mysqli_real_escape_string($mysqli, $code);
        mysqli_query($mysqli, "UPDATE intune_sync_log SET finished_at=NOW(), status='failed', errors='$m', error_code='$c' WHERE id=$logId");

        return $fail($message, $code, $logId);
    }
}
