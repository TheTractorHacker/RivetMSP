<?php

declare(strict_types=1);

namespace RivetMSP\Audit;

use RivetCore\Audit\AuditService as CoreAuditService;
use RivetMSP\Platform\PlatformSettings;

/**
 * RivetCore's AuditService only inserts a row. This edition-side wrapper adds the hash chain (AuditChain): the insert and the sealing of
 * the new row happen under one named lock so rows are sealed in audit_id order, and the sealed row is copied to the optional JSON-lines /
 * syslog sink (AuditSink). CoreBridge hands this out instead of the bare Core service, so every audit writer in the app is chained.
 *
 * Fail-safe: before the database update (columns missing) or with the chain switched off the row is still written, unsealed; the nightly
 * cron seals it later. Auditing never breaks the audited action.
 */
final class ChainedAudit
{
    private static bool $chainBroken = false;
    private static ?bool $chainOn = null;

    public function __construct(private CoreAuditService $core, private \mysqli $db)
    {
    }

    /** Forget the cached "chain switched on" answer (tests, long-running workers). */
    public static function reset(): void
    {
        self::$chainBroken = false;
        self::$chainOn = null;
    }

    /** @param mixed $entityId @param array<string,mixed> $metadata */
    public function log(string $eventType, ?int $actorUserId, ?string $entityType, mixed $entityId, string $action, ?string $summary = null, array $metadata = []): void
    {
        $chain = self::chainFor($this->db);
        $locked = false;
        if ($chain !== null) {
            try {
                $locked = $chain->lock();
            } catch (\Throwable $e) {
                self::$chainBroken = true;
                $chain = null;
            }
        }
        try {
            $this->core->log($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);
            if ($chain !== null) {
                try {
                    AuditSink::write($this->db, $chain->seal(), dirname(__DIR__, 2));
                } catch (\Throwable $e) {
                    self::$chainBroken = true;   // columns missing (database not updated yet) or similar: stop trying for this process; the cron seals later
                }
            }
        } finally {
            if ($locked) {
                try { $chain?->unlock(); } catch (\Throwable $e) { /* the lock ends with the connection */ }
            }
        }
    }

    private static function chainFor(\mysqli $db): ?AuditChain
    {
        if (self::$chainBroken) {
            return null;
        }
        self::$chainOn ??= PlatformSettings::bool($db, 'audit_chain_seal_enabled');

        return self::$chainOn ? new AuditChain($db) : null;
    }
}
