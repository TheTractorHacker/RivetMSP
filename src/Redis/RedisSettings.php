<?php

namespace RivetMSP\Redis;

use Predis\Client;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisConnectionConfig;

/**
 * Where RivetMSP finds Redis, and the admin tools around it. The connection comes from, in order: the
 * RIVETMSP_REDIS_* environment variables (the process environment, or the env file below), the values saved in
 * Administration > Redis, then the built-in default (127.0.0.1:6380). Redis stays optional and never holds the only copy
 * of anything.
 *
 * Keys: RIVETMSP_REDIS_HOST, _PORT, _DB, _PASSWORD, _USERNAME (ACL user), _TLS (1/0), _TLS_VERIFY (1/0), _TLS_CA_FILE,
 * _TLS_CERT_FILE, _TLS_KEY_FILE. A key set in the environment wins over the stored value and is read-only on the page.
 *
 * Env file: PHP-FPM clears the environment and cron runs with a bare one, so the installer writes the same keys as
 * KEY=VALUE lines to /etc/rivetmsp/redis.env (override the path with RIVETMSP_REDIS_ENV_FILE). A key in the real process
 * environment beats the same key in the file. Only RIVETMSP_REDIS_* keys are read from it.
 */
final class RedisSettings
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 6380;
    public const ENV_FILE = '/etc/rivetmsp/redis.env';

    /** Key patterns the admin page may clear, and nothing else. Pub/sub channels hold no keys. */
    public const CLEARABLE = [
        'rate_limits' => ['label' => 'Rate-limit counters', 'patterns' => ['rivetmsp:rl:*', 'api_rl:*']],
        'locks' => ['label' => 'Job locks', 'patterns' => ['rivetmsp:lock:*']],
    ];

    public const POLICIES = ['allkeys-lru', 'volatile-lru', 'allkeys-lfu', 'volatile-lfu', 'noeviction'];

    /** @return array<string,mixed> host, port, password, db, username, tls, tls_verify, tls_ca_file, tls_cert_file, tls_key_file, from_env (per field), schema_ready, stored_* and has_stored_password */
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
        $file = self::envFile();
        $env = static fn(string $k) => ($v = getenv($k)) !== false && $v !== '' ? $v : (($file[$k] ?? '') !== '' ? $file[$k] : null);
        $storedHost = trim((string) ($row['config_redis_host'] ?? ''));
        $storedPort = (int) ($row['config_redis_port'] ?? 0);
        $storedDb = (int) ($row['config_redis_db'] ?? 0);
        $storedPassEnc = (string) ($row['config_redis_password'] ?? '');
        $str = static fn(string $k): string => trim((string) ($row[$k] ?? ''));

        $storedPass = null;
        if ($storedPassEnc !== '' && function_exists('decryptSetting')) {
            try { $storedPass = decryptSetting($storedPassEnc) ?: null; } catch (\Throwable) { $storedPass = null; }
        }
        $opt = static fn(string $envKey, string $stored): ?string => $env($envKey) ?? ($stored !== '' ? $stored : null);

        $tlsStored = (int) ($row['config_redis_tls'] ?? 0) === 1;
        $verifyStored = (int) ($row['config_redis_tls_verify'] ?? 1) !== 0;

        return [
            'host' => $env('RIVETMSP_REDIS_HOST') ?? ($storedHost !== '' ? $storedHost : self::DEFAULT_HOST),
            'port' => (int) ($env('RIVETMSP_REDIS_PORT') ?? ($storedPort > 0 ? $storedPort : self::DEFAULT_PORT)),
            'password' => $env('RIVETMSP_REDIS_PASSWORD') ?? $storedPass,
            'db' => (int) ($env('RIVETMSP_REDIS_DB') ?? $storedDb),
            'username' => $opt('RIVETMSP_REDIS_USERNAME', $str('config_redis_username')),
            'tls' => filter_var($env('RIVETMSP_REDIS_TLS') ?? $tlsStored, FILTER_VALIDATE_BOOLEAN),
            'tls_verify' => filter_var($env('RIVETMSP_REDIS_TLS_VERIFY') ?? $verifyStored, FILTER_VALIDATE_BOOLEAN),
            'tls_ca_file' => $opt('RIVETMSP_REDIS_TLS_CA_FILE', $str('config_redis_tls_ca_file')),
            'tls_cert_file' => $opt('RIVETMSP_REDIS_TLS_CERT_FILE', $str('config_redis_tls_cert_file')),
            'tls_key_file' => $opt('RIVETMSP_REDIS_TLS_KEY_FILE', $str('config_redis_tls_key_file')),
            'from_env' => [
                'host' => $env('RIVETMSP_REDIS_HOST') !== null, 'port' => $env('RIVETMSP_REDIS_PORT') !== null,
                'password' => $env('RIVETMSP_REDIS_PASSWORD') !== null, 'db' => $env('RIVETMSP_REDIS_DB') !== null,
                'username' => $env('RIVETMSP_REDIS_USERNAME') !== null, 'tls' => $env('RIVETMSP_REDIS_TLS') !== null,
                'tls_verify' => $env('RIVETMSP_REDIS_TLS_VERIFY') !== null, 'tls_ca_file' => $env('RIVETMSP_REDIS_TLS_CA_FILE') !== null,
                'tls_cert_file' => $env('RIVETMSP_REDIS_TLS_CERT_FILE') !== null, 'tls_key_file' => $env('RIVETMSP_REDIS_TLS_KEY_FILE') !== null,
            ],
            'schema_ready' => $schema,
            'tls_schema_ready' => array_key_exists('config_redis_tls', $row),
            'stored_host' => $storedHost, 'stored_port' => $storedPort, 'stored_db' => $storedDb,
            'stored_username' => $str('config_redis_username'), 'stored_tls' => $tlsStored, 'stored_tls_verify' => $verifyStored,
            'stored_tls_ca_file' => $str('config_redis_tls_ca_file'), 'stored_tls_cert_file' => $str('config_redis_tls_cert_file'),
            'stored_tls_key_file' => $str('config_redis_tls_key_file'),
            'has_stored_password' => $storedPassEnc !== '',
        ];
    }

    /** RIVETMSP_REDIS_* lines from the env file (missing, unreadable or malformed lines are ignored). @return array<string,string> */
    public static function envFile(): array
    {
        $path = getenv('RIVETMSP_REDIS_ENV_FILE') ?: self::ENV_FILE;
        if (!is_string($path) || !@is_file($path) || !@is_readable($path)) {
            return [];
        }
        $out = [];
        foreach ((array) @file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_starts_with($line, 'export ')) $line = ltrim(substr($line, 7));
            if (!preg_match('/^(RIVETMSP_REDIS_[A-Z_]+)\s*=\s*(.*)$/', $line, $m)) continue;
            $v = trim($m[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
            $out[$m[1]] = $v;
        }
        return $out;
    }

    /** The connection config with certificate-file checks, for validation and tests. @param array<string,mixed> $p */
    public static function config(array $p): RedisConnectionConfig
    {
        return RedisConnectionConfig::fromArray($p);
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

    /** @return array{ok:bool, message:string, reason:string} reason: ok, invalid, auth, tls, unreachable, unexpected */
    public static function test(array $p): array
    {
        return self::admin()->test($p);
    }

    /** A test() result as one plain-English message with what to try next. Never contains the password. @param array{ok?:bool,message?:string,reason?:string} $test */
    public static function friendly(array $test): string
    {
        $msg = (string) ($test['message'] ?? 'Could not connect.');
        $fix = match ($test['reason'] ?? '') {
            'auth' => 'Fix: enter the same password (and ACL username, if the server uses one) that Redis has, from requirepass or ACL SETUSER in redis.conf.',
            'tls' => 'Fix: make sure Redis has a tls-port open, point the CA file at the certificate that signed the server certificate, or untick "Verify the certificate" only for a short test.',
            'invalid' => 'Fix: correct the field named above and try again.',
            'unreachable' => 'Fix: check the host and port, that Redis is running, and that TLS is ticked only if the server expects it.',
            default => '',
        };
        return $fix !== '' ? $msg . ' ' . $fix : $msg;
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
