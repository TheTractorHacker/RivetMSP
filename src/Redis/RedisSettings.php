<?php

namespace RivetMSP\Redis;

use Predis\Client;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisConnectionConfig;

/**
 * Where RivetMSP finds Redis, and the admin tools around it. The connection comes from, in order: the
 * RIVETMSP_REDIS_* environment variables, the values saved in Administration > Redis, then the built-in
 * default (127.0.0.1:6380). Redis stays optional and never holds the only copy of anything.
 * A Redis ACL username and TLS are environment-only (RIVETMSP_REDIS_USERNAME, RIVETMSP_REDIS_TLS=1, RIVETMSP_REDIS_TLS_VERIFY=0,
 * RIVETMSP_REDIS_TLS_CA_FILE): the settings page has no fields for them, as they would need new columns.
 */
final class RedisSettings
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 6380;

    /** Key patterns the admin page may clear, and nothing else. Pub/sub channels hold no keys. */
    public const CLEARABLE = [
        'rate_limits' => ['label' => 'Rate-limit counters', 'patterns' => ['rivetmsp:rl:*', 'api_rl:*']],
        'locks' => ['label' => 'Job locks', 'patterns' => ['rivetmsp:lock:*']],
    ];

    public const POLICIES = ['allkeys-lru', 'volatile-lru', 'allkeys-lfu', 'volatile-lfu', 'noeviction'];

    /** @return array{host:string, port:int, password:?string, db:int, username:?string, tls:bool, tls_verify:bool, tls_ca_file:?string, from_env:array<string,bool>, schema_ready:bool, stored_host:string, stored_port:int, stored_db:int, has_stored_password:bool} */
    public static function resolve(?\mysqli $db = null): array
    {
        $row = [];
        $schema = false;
        if ($db) {
            try {
                $res = $db->query('SELECT * FROM settings WHERE company_id = 1');
                $row = $res ? ($res->fetch_assoc() ?: []) : [];
                $schema = array_key_exists('config_redis_host', $row);
            } catch (\Throwable) { /* before the migration, or no database: defaults apply */ }
        }
        $env = static fn(string $k) => ($v = getenv($k)) === false || $v === '' ? null : $v;
        $storedHost = trim((string) ($row['config_redis_host'] ?? ''));
        $storedPort = (int) ($row['config_redis_port'] ?? 0);
        $storedDb = (int) ($row['config_redis_db'] ?? 0);
        $storedPassEnc = (string) ($row['config_redis_password'] ?? '');

        $storedPass = null;
        if ($storedPassEnc !== '' && function_exists('decryptSetting')) {
            try { $storedPass = decryptSetting($storedPassEnc) ?: null; } catch (\Throwable) { $storedPass = null; }
        }

        return [
            'host' => $env('RIVETMSP_REDIS_HOST') ?? ($storedHost !== '' ? $storedHost : self::DEFAULT_HOST),
            'port' => (int) ($env('RIVETMSP_REDIS_PORT') ?? ($storedPort > 0 ? $storedPort : self::DEFAULT_PORT)),
            'password' => $env('RIVETMSP_REDIS_PASSWORD') ?? $storedPass,
            'db' => (int) ($env('RIVETMSP_REDIS_DB') ?? $storedDb),
            'username' => $env('RIVETMSP_REDIS_USERNAME'),
            'tls' => filter_var($env('RIVETMSP_REDIS_TLS') ?? false, FILTER_VALIDATE_BOOLEAN),
            'tls_verify' => filter_var($env('RIVETMSP_REDIS_TLS_VERIFY') ?? true, FILTER_VALIDATE_BOOLEAN),
            'tls_ca_file' => $env('RIVETMSP_REDIS_TLS_CA_FILE'),
            'from_env' => [
                'host' => $env('RIVETMSP_REDIS_HOST') !== null, 'port' => $env('RIVETMSP_REDIS_PORT') !== null,
                'password' => $env('RIVETMSP_REDIS_PASSWORD') !== null, 'db' => $env('RIVETMSP_REDIS_DB') !== null,
            ],
            'schema_ready' => $schema,
            'stored_host' => $storedHost, 'stored_port' => $storedPort, 'stored_db' => $storedDb,
            'has_stored_password' => $storedPassEnc !== '',
        ];
    }

    /** @return ?string an error message, or null when the values are acceptable */
    public static function validate(string $host, int $port, int $db, string $password): ?string
    {
        return RedisAdmin::validate($host, $port, $db, $password);
    }

    /** @param array<string,mixed> $p resolve() output (or the settings form's values) */
    public static function client(array $p, float $timeout = 1.0): Client
    {
        return self::admin()->client(RedisConnectionConfig::fromArray($p), $timeout);
    }

    /** @return array{ok:bool, message:string} */
    public static function test(array $p): array
    {
        return self::admin()->test($p);
    }

    public static function stats(Client $c): array
    {
        return self::admin()->stats($c);
    }

    /** Count keys per clearable group. @return array<string,int> */
    public static function groupCounts(Client $c, int $cap = 5000): array
    {
        return self::admin()->groupCounts($c, $cap);
    }

    /** Delete one allowlisted group of keys. Returns how many were removed. */
    public static function clear(Client $c, string $group): int
    {
        return self::admin()->clear($c, $group);
    }

    /** @return array{ok:bool, persisted:bool, message:string} */
    public static function setMemory(Client $c, int $megabytes, string $policy): array
    {
        return self::admin()->setMemory($c, $megabytes, $policy);
    }

    private static function admin(): RedisAdmin
    {
        return new RedisAdmin(self::CLEARABLE);
    }
}
