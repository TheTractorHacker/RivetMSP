<?php

declare(strict_types=1);

namespace RivetMSP\Core;

use RivetCore\Audit\AuditService;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetMSP\Core\Adapter\Http\ServerRequestContext;
use RivetMSP\Core\Adapter\Settings\SettingsTableSettings;

/**
 * The single seam between the legacy procedural app and RivetCore. Everything here is fail-safe: if the
 * package is not installed, the feature flag is off, or a call fails, legacy behaviour is untouched.
 * Flags default to OFF; a Composer update never turns a module on by itself.
 */
final class CoreBridge
{
    private static ?MysqliDatabaseAdapter $database = null;
    private static ?SettingsTableSettings $settings = null;

    public static function enabled(string $flag): bool
    {
        try {
            if (!class_exists(AuditService::class) || !self::connection() instanceof \mysqli) {
                return false;
            }
            self::$settings ??= new SettingsTableSettings(self::database());

            return (int) self::$settings->get($flag, 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Maps the legacy Login log entries onto RivetCore audit events (names match RivetIT). */
    public static function recordLogin(string $logType, string $logAction, string $description, int $userId): void
    {
        $event = match ($logType . '|' . $logAction) {
            'Login|Success' => 'auth.login_success',
            'Login|Failed' => 'auth.login_failed',
            'Login|Blocked' => 'auth.login_blocked',
            'Login|MFA Failed' => 'auth.mfa_failed',
            default => null,
        };
        if ($event === null || !self::enabled('core.audit.enabled')) {
            return;
        }
        try {
            (new AuditService(self::database(), new ServerRequestContext()))->log(
                $event,
                $userId > 0 ? $userId : null,
                'user',
                $userId > 0 ? $userId : null,
                strtolower($logAction),
                $description
            );
        } catch (\Throwable) {
            // Auditing must never break sign-in.
        }
    }

    private static function connection(): ?\mysqli
    {
        global $mysqli;

        return $mysqli instanceof \mysqli ? $mysqli : null;
    }

    private static function database(): MysqliDatabaseAdapter
    {
        return self::$database ??= new MysqliDatabaseAdapter(self::connection());
    }
}
