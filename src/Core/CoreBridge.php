<?php

declare(strict_types=1);

namespace RivetMSP\Core;

use RivetCore\Audit\AuditService;
use RivetCore\Automation\AutomationRuleEvaluator;
use RivetCore\Health\ReadinessChecker;
use RivetCore\ITSM\ChangeService;
use RivetCore\ITSM\ProblemService;
use RivetCore\Jobs\JobQueue;
use RivetCore\Redis\CronGuard;
use RivetCore\Redis\LockManager;
use RivetCore\Redis\RateLimiter;
use RivetCore\Support\SystemClock;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Workflow\WorkflowService;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetMSP\Core\Adapter\Http\ServerRequestContext;
use RivetMSP\Core\Adapter\Itsm\TicketsProblemLink;
use RivetMSP\Core\Adapter\Redis\GlobalRedisClientProvider;
use RivetMSP\Core\Adapter\Settings\SettingsTableSettings;
use RivetMSP\Core\Adapter\Webhooks\WebhooksTableSubscriptions;

/**
 * The single seam between the legacy procedural app and RivetCore. Everything here is fail-safe: if the package
 * is not installed, the module's feature flag is off, or a call fails, legacy behaviour is untouched.
 *
 * Flags live in `settings` (config_core_<module>_enabled) and default to OFF; a Composer update never turns a
 * module on by itself. Each accessor returns null while its flag is off, so a caller can write
 * `CoreBridge::jobs()?->enqueue(...)` and nothing happens until the module is switched on.
 */
final class CoreBridge
{
    private const REDIS_PREFIX = 'rivetmsp:';

    private static ?MysqliDatabaseAdapter $database = null;
    private static ?SettingsTableSettings $settings = null;
    /** @var array<string,object> */
    private static array $services = [];

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

    /** Forget cached services and flags (used by tests and long-running workers after a setting changes). */
    public static function reset(): void
    {
        self::$database = null;
        self::$settings = null;
        self::$services = [];
    }

    // ---- audit (core.audit.enabled)

    /** Every recorded audit event also goes to the event bus: webhooks subscribed to it, and event automation rules. */
    private static function auditListener(): \Closure
    {
        return static function (string $eventType, ?int $actor, ?string $entityType, ?string $entityId, string $action, ?string $summary, array $metadata): void {
            $file = dirname(__DIR__, 2) . '/includes/event_bus.php';
            if (is_file($file)) {
                require_once $file;
                rivetEmitEvent($eventType, ['actor_user_id' => $actor, 'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => $action, 'summary' => $summary, 'metadata' => $metadata]);
            }
        };
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
            (new AuditService(self::database(), new ServerRequestContext(), self::auditListener()))->log(
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

    /**
     * The audit service for recording other events (for example compliance changes), or null while the audit switch is off.
     * $force skips the switch: used to record the change that turns auditing off, which must itself be on the record.
     */
    public static function audit(bool $force = false): ?AuditService
    {
        if ($force) {
            try {
                return class_exists(AuditService::class) && self::connection() instanceof \mysqli
                    ? new AuditService(self::database(), new ServerRequestContext(), self::auditListener())
                    : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return self::service('core.audit.enabled', 'audit', static fn () => new AuditService(self::database(), new ServerRequestContext(), self::auditListener()));
    }

    // ---- redis (core.redis.enabled)

    public static function locks(): ?LockManager
    {
        return self::service('core.redis.enabled', 'locks', static fn () => new LockManager(new GlobalRedisClientProvider(), self::REDIS_PREFIX));
    }

    public static function rateLimiter(): ?RateLimiter
    {
        return self::service('core.redis.enabled', 'ratelimiter', static fn () => new RateLimiter(new GlobalRedisClientProvider(), self::REDIS_PREFIX));
    }

    public static function cronGuard(): ?CronGuard
    {
        $locks = self::locks();

        return $locks === null ? null : self::service('core.redis.enabled', 'cronguard', static fn () => new CronGuard($locks));
    }

    // ---- jobs (core.jobs.enabled)

    public static function jobs(): ?JobQueue
    {
        return self::service('core.jobs.enabled', 'jobs', static fn () => new JobQueue(self::database()));
    }

    // ---- itsm (core.itsm.enabled)

    public static function problems(): ?ProblemService
    {
        return self::service('core.itsm.enabled', 'problems', static fn () => new ProblemService(self::database(), new TicketsProblemLink(self::database())));
    }

    public static function changes(): ?ChangeService
    {
        return self::service('core.itsm.enabled', 'changes', static fn () => new ChangeService(self::database()));
    }

    // ---- webhooks (core.webhooks.enabled)

    public static function webhooks(): ?WebhookDispatcher
    {
        return self::service('core.webhooks.enabled', 'webhooks', static fn () => new WebhookDispatcher(
            self::database(),
            new WebhooksTableSubscriptions(self::database()),
            new SystemClock(),
            ['X-ITFlow', 'X-RivetMSP']
        ));
    }

    // ---- workflow / automation

    public static function workflow(): ?WorkflowService
    {
        return self::service('core.workflow.enabled', 'workflow', static fn () => new WorkflowService(self::database()));
    }

    public static function automation(): ?AutomationRuleEvaluator
    {
        return self::service('core.automation.enabled', 'automation', static fn () => new AutomationRuleEvaluator(self::database()));
    }

    // ---- health (core.health.enabled)

    public static function readiness(callable $schemaIsCurrent): ?ReadinessChecker
    {
        if (!self::enabled('core.health.enabled')) {
            return null;
        }

        return new ReadinessChecker(self::database(), $schemaIsCurrent, new GlobalRedisClientProvider());
    }

    // ---- plumbing

    /** @template T of object @param callable():T $make @return T|null */
    private static function service(string $flag, string $key, callable $make): ?object
    {
        if (!self::enabled($flag)) {
            return null;
        }
        try {
            return self::$services[$key] ??= $make();
        } catch (\Throwable) {
            return null;
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
