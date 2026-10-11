<?php

declare(strict_types=1);

namespace RivetMSP\Links;

/**
 * Who is asking: user id, admin flag, module levels and the clients the user is limited to. Built from the signed-in session
 * (pages), from an agent user id (API, MCP, cron) or by hand (tests). The link service takes this instead of reading globals, so
 * the same rules apply to the web pages and the API.
 */
final class LinkActor
{
    /**
     * @param array<string,int> $levels module name => level (0 = none)
     * @param list<int>|null    $clientIds null = every client; otherwise only these (client 0, the global records, is not open to a restricted user: the lists join clients)
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $admin,
        private array $levels,
        private ?array $clientIds = null,
        private ?int $apiKeyClient = null,
    ) {
    }

    /** The signed-in web session (functions.php: lookupUserPermission, user_client_permissions). */
    public static function fromSession(\mysqli $db): self
    {
        $uid = (int) ($GLOBALS['session_user_id'] ?? 0);
        $admin = !empty($GLOBALS['session_is_admin']);
        $levels = [];
        foreach (['module_assets', 'module_support', 'module_client', 'module_kb', 'module_credential', 'module_financial'] as $m) {
            $levels[$m] = $admin ? 3 : (int) lookupUserPermission($m);
        }

        return new self($uid, $admin, $levels, self::clientIdsFor($db, $uid));
    }

    /** Any agent user, by id (no session needed). */
    public static function forUser(\mysqli $db, int $userId, ?int $apiKeyClient = null): self
    {
        $levels = [];
        $admin = false;
        $res = $db->query('SELECT u.user_role_id, r.role_is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ' . $userId . ' LIMIT 1');
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $admin = (int) ($row['role_is_admin'] ?? 0) === 1;
            $r2 = $db->query('SELECT m.module_name, urp.user_role_permission_level AS lvl FROM user_role_permissions urp JOIN modules m ON m.module_id = urp.module_id WHERE urp.user_role_id = ' . (int) $row['user_role_id']);
            while ($r2 && ($x = $r2->fetch_assoc())) {
                $levels[(string) $x['module_name']] = (int) $x['lvl'];
            }
        }

        return new self($userId, $admin, $levels, $row ? self::clientIdsFor($db, $userId) : [], $apiKeyClient);
    }

    /** @return list<int>|null */
    private static function clientIdsFor(\mysqli $db, int $userId): ?array
    {
        $res = $db->query('SELECT client_id FROM user_client_permissions WHERE user_id = ' . $userId);
        $ids = [];
        while ($res && ($x = $res->fetch_row())) {
            $ids[] = (int) $x[0];
        }

        return $ids === [] ? null : $ids;
    }

    /** The clients this user is limited to, or null when every client is open to them (admins, users with no restriction). */
    public function restrictedClientIds(): ?array
    {
        $own = $this->admin ? null : $this->clientIds;
        if ($this->apiKeyClient !== null && $this->apiKeyClient > 0) {
            // an API key limited to one client: that client only (and only if the user may reach it)
            return $own === null || in_array($this->apiKeyClient, $own, true) ? [$this->apiKeyClient] : [];
        }

        return $own;
    }

    public function level(string $module): int
    {
        return $this->admin ? 3 : (int) ($this->levels[$module] ?? 0);
    }

    /** @param list<string> $anyOf */
    public function hasAny(array $anyOf, int $level): bool
    {
        foreach ($anyOf as $m) {
            if ($this->level($m) >= $level) {
                return true;
            }
        }

        return false;
    }

    public function canRead(string $type): bool
    {
        $spec = EntityTypes::spec($type);

        return $spec !== null && $this->hasAny($spec['modules'], 1);
    }

    public function canWrite(string $type): bool
    {
        $spec = EntityTypes::spec($type);
        if ($spec === null) {
            return false;
        }
        // Locations change under Clients (module_client); everything else under the module that owns it.
        $mods = $type === 'location' ? ['module_client'] : $spec['modules'];

        return $this->hasAny($mods, 2);
    }

    /**
     * Client 0 is the global bucket (a global vendor, a global KB article). RivetMSP shows those lists to administrators and to users with
     * no client restriction only (the lists join clients, and enforceClientAccess() refuses an empty client), so a client-restricted user
     * gets no access to client 0 here either. An API key limited to one client reaches that client only (api_client_scope_sql()).
     */
    public function canAccessClient(int $clientId): bool
    {
        if ($this->apiKeyClient !== null && $this->apiKeyClient > 0 && $clientId !== $this->apiKeyClient) {
            return false;
        }
        if ($this->admin || $this->clientIds === null) {
            return true;
        }

        return in_array($clientId, $this->clientIds, true);
    }
}
