<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

/**
 * Permission and client-scope checks for an explicit user id (no $_SESSION), shared by the read tools, the write
 * tools and the approval workflow. Factored out of ReadTools so a write can never be authorized by a weaker copy
 * of the read-side rules.
 *
 * Client scope is the SAME rule a signed-in technician's own pages use: no rows in user_client_permissions means
 * every client, otherwise only the listed ones. It deliberately has NO administrator bypass (unlike
 * enforceClientAccess() for a session admin): an agent that has been restricted stays restricted over MCP.
 * clients.client_ai_opt_out is checked in addition, for every caller.
 */
final class McpAccess
{
    /** Mirrors lookupUserPermission()/enforceUserPermission() for an explicit user id. Fails closed to 0. */
    public static function modulePermission(\mysqli $db, int $userId, string $module): int
    {
        $u = self::rows($db, 'SELECT u.user_role_id, r.role_is_admin
            FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
            WHERE u.user_id = ? AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL', 'i', [$userId]);
        if (!$u) {
            return 0;
        }
        if ((int) ($u[0]['role_is_admin'] ?? 0) === 1) {
            return 3;
        }
        $roleId = (int) ($u[0]['user_role_id'] ?? 0);
        if ($roleId <= 0) {
            return 0;
        }
        $lvl = self::rows($db, 'SELECT p.user_role_permission_level AS lvl FROM user_role_permissions p
            JOIN modules m ON m.module_id = p.module_id WHERE p.user_role_id = ? AND m.module_name = ?', 'is', [$roleId, $module]);

        return (int) ($lvl[0]['lvl'] ?? 0);
    }

    public static function can(\mysqli $db, int $userId, string $module, int $level = 1): bool
    {
        return self::modulePermission($db, $userId, $module) >= $level;
    }

    /** SQL fragment; binds the user id TWICE ('ii'). */
    public static function clientScope(string $clientColumn): string
    {
        return "(NOT EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = ?)
                 OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = ? AND ucp.client_id = $clientColumn))";
    }

    /** A client that opted out of AI access is excluded for every caller. */
    public static function aiOptOutExcluded(string $clientAlias = 'c'): string
    {
        return "IFNULL($clientAlias.client_ai_opt_out, 0) = 0";
    }

    /** True when the user may act on this (existing, not archived, not AI-opted-out) client under their client scope. */
    public static function mayTouchClient(\mysqli $db, int $userId, int $clientId): bool
    {
        if ($userId < 1 || $clientId < 1) {
            return false;
        }
        $r = self::rows($db, 'SELECT c.client_id FROM clients c WHERE c.client_id = ? AND c.client_archived_at IS NULL AND '
            . self::clientScope('c.client_id') . ' AND ' . self::aiOptOutExcluded(), 'iii', [$clientId, $userId, $userId]);

        return $r !== [];
    }

    /** Prepared SELECT; $types/$params bind in order. */
    public static function rows(\mysqli $db, string $sql, string $types, array $params): array
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('MCP query failed to prepare');
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $out;
    }
}
