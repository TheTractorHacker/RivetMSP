<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

/**
 * Settings for the MCP write path (propose-then-approve). Stored in the mcp_oauth_config key/value table
 * (the `settings` row is close to its size limit):
 *   write_enabled        '1' = write tools exist and mcp:write may be granted (default OFF; anything but '1' is off)
 *   write_self_approve   '1' = the agent who queued an action may approve it themselves (default OFF)
 *   write_expiry_hours   hours a pending action stays approvable (default 24, 1..168)
 * Every reader fails closed: a missing table or key means "off".
 */
final class WriteConfig
{
    public const SCOPE = 'mcp:write';
    public const DEFAULT_EXPIRY_HOURS = 24;
    public const MAX_EXPIRY_HOURS = 168;
    public const MAX_PENDING_PER_USER = 25;

    /** @return array<string,string> */
    private static function values(\mysqli $db): array
    {
        $out = [];
        try {
            $res = $db->query("SELECT setting_key, setting_value FROM mcp_oauth_config WHERE setting_key IN ('write_enabled','write_self_approve','write_expiry_hours')");
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $row) {
                $out[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) { /* table missing: everything off */
        }

        return $out;
    }

    public static function enabled(\mysqli $db): bool
    {
        return (self::values($db)['write_enabled'] ?? '0') === '1' && self::tableReady($db);
    }

    public static function selfApprove(\mysqli $db): bool
    {
        return (self::values($db)['write_self_approve'] ?? '0') === '1';
    }

    public static function expiryHours(\mysqli $db): int
    {
        $h = (int) (self::values($db)['write_expiry_hours'] ?? self::DEFAULT_EXPIRY_HOURS);

        return $h >= 1 && $h <= self::MAX_EXPIRY_HOURS ? $h : self::DEFAULT_EXPIRY_HOURS;
    }

    public static function tableReady(\mysqli $db): bool
    {
        try {
            $res = $db->query("SHOW TABLES LIKE 'mcp_pending_actions'");

            return (bool) ($res && $res->fetch_row());
        } catch (\Throwable) {
            return false;
        }
    }
}
