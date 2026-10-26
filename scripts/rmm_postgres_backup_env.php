#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Bridges deploy/backup.sh / deploy/restore.sh (plain bash, no access to the sealed PostgreSQL password or
 * RivetCore\Rmm\Store\DataStoreSwitch) to the RMM data store's active connection (docs/rmm/POSTGRES.md
 * section 8). Prints `export KEY='value'` lines for RIVETCORE_PG_DATABASE/_USER/_HOST/_PORT/_SSLMODE/_SCHEMA
 * plus PGPASSFILE (a freshly written 0600 file holding the unsealed password, in libpq's `.pgpass` format)
 * to STDOUT, meant to be `eval`'d by the bash caller. The password is NEVER printed to STDOUT, a command-line
 * argument, or any log — only into that one 0600 file, which the caller must register_tmpfile() / shred.
 *
 * Usage: php scripts/rmm_postgres_backup_env.php --app-dir=/path --passfile=/path/to/.pgpass
 *
 * Output and exit status:
 *   - data_store = mariadb: NO output, exit 0. There is nothing to dump/restore; the caller skips the RMM step.
 *   - data_store = postgres, a connection is stored: `export ...` lines, exit 0.
 *   - data_store = postgres, no connection stored, or the sealed password cannot be unsealed: nothing on
 *     STDOUT, an error on STDERR, exit 1 — the caller must treat this as a backup/restore FAILURE (a silent
 *     skip here would mean an unencrypted-but-unnoticed gap in the backup, which deploy/backup.sh's whole
 *     design otherwise refuses to allow).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(app-dir|passfile)=(.+)$/D', $a, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unknown argument: $a\n");
        exit(2);
    }
}
if (!isset($opts['app-dir'], $opts['passfile'])) {
    fwrite(STDERR, "Usage: php rmm_postgres_backup_env.php --app-dir=/path --passfile=/path/to/.pgpass\n");
    exit(2);
}
$appDir = rtrim($opts['app-dir'], '/');
if (!is_file($appDir . '/config.php')) {
    fwrite(STDERR, "No config.php found under {$appDir}.\n");
    exit(2);
}

require $appDir . '/config.php';
require $appDir . '/functions.php';
require $appDir . '/includes/rmm_bootstrap.php';

if (!($mysqli instanceof \mysqli)) {
    fwrite(STDERR, "config.php did not produce a database connection.\n");
    exit(1);
}

try {
    $rmm = rivetRmmModule($mysqli);
    $kind = $rmm->settings()->dataStore();
} catch (\Throwable $e) {
    // The module/data_store column may not exist yet on an older database (code deployed, migration not
    // run yet) — exactly the "code before migration is safe" case POSTGRES.md section 8.5 describes:
    // there is nothing PostgreSQL to back up until the setting itself can be read as "postgres".
    exit(0);
}
if ($kind !== \RivetCore\Rmm\Store\RmmDataStoreKind::POSTGRES) {
    exit(0);   // mariadb: nothing to do, deliberately no output
}

$config = $rmm->dataStoreSwitch()->postgresConfig();
if ($config === null) {
    fwrite(STDERR, "The data store is \"postgres\" but no PostgreSQL connection is stored (or its sealed password could not be unsealed with the current settings-encryption key). Fix this in Administration > RMM > Data store before backing up / restoring.\n");
    exit(1);
}

$passfile = $opts['passfile'];
// libpq .pgpass format: hostname:port:database:username:password — ':' and '\' in a field are escaped with '\'.
$escape = static fn (string $s): string => str_replace([':', '\\'], ['\\:', '\\\\'], $s);
$line = implode(':', [
    $config->host === '' ? '*' : $escape($config->host),
    (string) $config->port,
    $escape($config->database),
    $escape($config->user),
    $escape($config->password()),
]) . "\n";
$old = umask(0077);
$ok = file_put_contents($passfile, $line) !== false;
umask($old);
if (!$ok) {
    fwrite(STDERR, "Could not write {$passfile}.\n");
    exit(1);
}
chmod($passfile, 0600);

$shellQuote = static fn (string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";
echo 'export RIVETCORE_PG_DATABASE=' . $shellQuote($config->database) . "\n";
echo 'export RIVETCORE_PG_USER=' . $shellQuote($config->user) . "\n";
echo 'export RIVETCORE_PG_HOST=' . $shellQuote($config->host) . "\n";
echo 'export RIVETCORE_PG_PORT=' . $shellQuote((string) $config->port) . "\n";
echo 'export RIVETCORE_PG_SSLMODE=' . $shellQuote($config->sslMode) . "\n";
echo 'export RIVETCORE_PG_SCHEMA=' . $shellQuote($config->schema) . "\n";
echo 'export PGPASSFILE=' . $shellQuote($passfile) . "\n";
exit(0);
