<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * Writes the activity-log rows of the RMM module through logAction() ("Endpoint Agent" entries; settings changes as Settings / Edit, the same
 * type the other settings pages use). logAction() mirrors both types into the structured audit trail (CoreBridge::AUDITED_TYPES).
 */
final class EndpointAudit implements RmmAuditInterface
{
    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
        if (!function_exists('logAction')) {
            return;
        }
        if ($action === 'Settings') {
            logAction('Settings', 'Edit', $description, $clientId, $entityId);

            return;
        }
        logAction('Endpoint Agent', $action, $description, $clientId, $entityId);
    }
}
