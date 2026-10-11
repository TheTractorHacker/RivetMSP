# Redis in RivetMSP

Redis is optional. It holds live ticket and chat updates, rate limits (sign-in and REST API) and cron job locks, never the only copy of anything. If it is down the app keeps working and those extras pause.

## Where the connection comes from

In this order, per setting:

1. The environment: `RIVETMSP_REDIS_*` variables in the process environment, **or** the same keys in the env file `/etc/rivetmsp/redis.env`.
2. Administration > Redis (stored in the `settings` table, the password encrypted).
3. The built-in default `127.0.0.1:6380`.

A setting that the environment provides is shown read-only on the Redis page ("Set by the server") and always wins over the stored value.

### Keys

| Key | Meaning |
| --- | --- |
| `RIVETMSP_REDIS_HOST`, `_PORT`, `_DB` | Server address and database number (0 to 15) |
| `RIVETMSP_REDIS_PASSWORD` | Password (`requirepass`, or the ACL user's password) |
| `RIVETMSP_REDIS_USERNAME` | ACL user name. Needs a password. Empty means the `default` user |
| `RIVETMSP_REDIS_TLS` | `1` to connect with TLS (`tls-port` on the server) |
| `RIVETMSP_REDIS_TLS_VERIFY` | `0` to skip certificate verification (testing only). Default `1` |
| `RIVETMSP_REDIS_TLS_CA_FILE` | CA certificate that signed the server certificate |
| `RIVETMSP_REDIS_TLS_CERT_FILE`, `_TLS_KEY_FILE` | Client certificate and key, if the server asks for one (mutual TLS) |

### The env file (what the installer writes)

PHP-FPM clears the environment of its workers and cron runs with a bare one, so a plain `export` does not reach the app. The app therefore also reads one file, `/etc/rivetmsp/redis.env` (override the path with `RIVETMSP_REDIS_ENV_FILE`). Format: one `KEY=VALUE` per line, `#` comments, optional quotes. Only `RIVETMSP_REDIS_*` keys are read. A key present in the real environment beats the same key in the file.

The installer should write it (only when it sets up a non-default Redis), owned `root:www-data`, mode `0640`, directory `/etc/rivetmsp` `root:www-data` mode `0750` or `0755` (the web user must be able to traverse it; the Core key file `keys.json` lives in the same directory, see `docs/KEY_MANAGEMENT.md`):

```
RIVETMSP_REDIS_HOST=127.0.0.1
RIVETMSP_REDIS_PORT=6380
RIVETMSP_REDIS_PASSWORD=...
RIVETMSP_REDIS_USERNAME=rivetmsp
RIVETMSP_REDIS_TLS=1
RIVETMSP_REDIS_TLS_CA_FILE=/etc/rivetmsp/redis-ca.pem
```

Certificate files must be readable by `www-data`. With none of the keys present, nothing changes: the Redis page and the default apply.

## Administration > Redis

Host, port, database, password, ACL username, TLS, certificate verification and the CA / client certificate / client key paths. The password is stored encrypted and is never shown or echoed (leave the box empty to keep it). **Test only** and **Test and save** try a real connection and say why a failure happened:

| Reason | Meaning | Fix shown |
| --- | --- | --- |
| auth | The server wants a password, or the username/password is wrong | Enter the Redis password and ACL user |
| tls | The TLS handshake failed (server has no TLS on that port, CA does not match) | Check `tls-port`, CA file, or untick verification briefly |
| unreachable | Nothing answered | Check host, port, that Redis runs |
| invalid | A field is not acceptable, for example a certificate file that cannot be read | Correct the field |

The database columns are added by migration 2.6.74 (`config_redis_username`, `config_redis_tls`, `config_redis_tls_verify`, `config_redis_tls_ca_file`, `config_redis_tls_cert_file`, `config_redis_tls_key_file`). Until it has run, the page says so and only the environment can set these.

## REST API rate limit

`config_api_rate_limit_per_minute` (default 120, `0` = off), edited on Administration > API Keys. Each API key or app token gets that many requests a minute, and one IP address three times that. Over the limit the API answers `429` with a `Retry-After` header and `{"error":"Rate limit exceeded","retry_after":N}`, and records one `api.rate_limited` audit event per key per minute. Counters live in Redis under `rivetmsp:rl:api:*` (clearable on the Redis page). If Redis is down the limit is not enforced (fail open), except for the sign-in endpoint, which fails closed.

## Queue worker

`cron/integration_worker.php` runs the RivetCore job worker (webhook deliveries, event-rule actions) and is listed in the Cron Manager. The installer's cron.d file should run it every minute:

```
* * * * * www-data /usr/bin/php <app>/cron/integration_worker.php >> /var/log/itflow-cron.log 2>&1
```

`cron/cron.php` also makes a bounded pass every five minutes, so jobs are never lost without it, only slower to retry. A Redis lock (`cron:integration_worker`) stops two copies overlapping.
