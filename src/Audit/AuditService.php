<?php

declare(strict_types=1);

namespace RivetMSP\Audit;

use RivetMSP\Core\CoreBridge;

/**
 * The call the platform features (entity links, vendor roles, stale-asset retire, ...) use to record an audit event. RivetMSP's audit trail
 * is the RivetCore audit module behind its switch (Administration > Compliance > audit recording, settings.config_core_audit_enabled):
 * while the switch is off this does nothing, exactly like every other audit call in the app (CoreBridge::audit()). Never throws.
 */
final class AuditService
{
    public function __construct(private ?\mysqli $db = null)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function log(string $eventType, ?int $actorUserId, ?string $entityType, $entityId, string $action, ?string $summary = null, array $metadata = []): void
    {
        try {
            CoreBridge::audit()?->log($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);
        } catch (\Throwable $e) {
            // auditing never breaks the action
        }
    }
}
