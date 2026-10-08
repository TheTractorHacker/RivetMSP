<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;

/**
 * RivetMSP's clients, locations and per-user client scope for the RMM module. The scope rule is the one every agent page applies
 * (enforceClientAccess() / $client_access_string): administrators and users without any user_client_permissions row see every client;
 * anyone else sees the clients listed for them.
 */
final class EndpointTenancy implements RmmTenancyInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function visibleClientIds(int $userId): ?array
    {
        if ($userId <= 0) {
            return [];
        }
        $user = $this->database->fetchOne(
            'SELECT u.user_id, r.role_is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ?',
            [$userId]
        );
        // A user id that is not in the users table has no scope rows either, and is answered as "unrestricted" like any user without rows: the
        // access policy, not this scope, is what refuses a user that does not exist.
        if ($user !== null && (int) ($user['role_is_admin'] ?? 0) === 1) {
            return null;
        }
        $rows = $this->database->fetchAll('SELECT client_id FROM user_client_permissions WHERE user_id = ?', [$userId]);
        if ($rows === []) {
            return null;
        }

        // Rows exist, so the user is restricted. Client 0 ("no client") is visible to everyone and is not listed: a user whose only row is
        // client 0 is restricted to nothing and gets [].
        return array_values(array_filter(array_map(static fn (array $r): int => (int) $r['client_id'], $rows), static fn (int $id): bool => $id > 0));
    }

    public function clientName(int $clientId): ?string
    {
        $row = $this->database->fetchOne('SELECT client_name FROM clients WHERE client_id = ?', [$clientId]);

        return $row === null ? null : (string) $row['client_name'];
    }

    public function locationInClient(int $locationId, int $clientId): bool
    {
        return $this->database->fetchOne('SELECT location_id FROM locations WHERE location_id = ? AND location_client_id = ?', [$locationId, $clientId]) !== null;
    }
}
