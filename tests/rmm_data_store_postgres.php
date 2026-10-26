<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * RMM data store, PostgreSQL phase (RivetCore 1.0.0-rc.40/41, phase 4 of #89): the real-connection paths tests/rmm_data_store_ui_smoke.php cannot
 * cover without pdo_pgsql — connection test success, switching for real, migrating a small synthetic fleet both directions, rollback, and the
 * backup/restore round trip. Runs INSIDE a throwaway PHP 8.5 + pdo_pgsql container (this host's PHP has no pdo_pgsql by design — Part B item 2) against
 * a throwaway Docker PostgreSQL 16 container, on the scratch MariaDB database over TCP (RIVETMSP_TEST_DB_HOST). See the coordinator's final report for
 * the exact docker commands. Never run against anything live.
 *
 *   docker run -d --name rivetmsp-pg-test --network rivetmsp-pg-test-net -p 127.0.0.1:15432:5432 \
 *     -e POSTGRES_PASSWORD=... -e POSTGRES_USER=rivet_rmm -e POSTGRES_DB=rivet_rmm postgres:16
 *   docker run --rm --network host -v <repo>:/app -w /app -e RIVETMSP_TEST_DB_HOST=127.0.0.1 \
 *     -e RIVETMSP_TEST_DB=1 -e RIVETMSP_TEST_DB_NAME=... -e RIVETMSP_TEST_DB_USER=... -e RIVETMSP_TEST_DB_PASS=... \
 *     -e RIVETMSP_REDIS_PORT=0 -e RIVETMSP_REDIS_ENV_FILE=/dev/null \
 *     rivetmsp-pg-test-php:latest php tests/rmm_data_store_postgres.php --phase=<up1|down|up2>
 *
 * --phase=up1 (PostgreSQL container running): connection save+test (real success), switch to postgres (real), capacity card on a real postgres
 *             store, a dry-run and a real migration of a tiny synthetic fleet, verified through the module's own read API, rollback, backup+restore
 *             round trip, and (MSP-specific extra, part 1) a device transfer while PostgreSQL is reachable.
 * --phase=down (PostgreSQL container stopped by the caller first): a device transfer while PostgreSQL is unreachable — HotStoreUnavailableException
 *             is caught and the move is recorded in rmm_pending_client_moves, not lost.
 * --phase=up2 (PostgreSQL container started again): the normal cron housekeeping path (rivetRmmHousekeeping(), which calls ClientMoves::
 *             retryPending() — RivetCore 1.0.0-rc.40's own wiring, confirmed by reading Housekeeping.php, no edition change needed) completes the
 *             pending move and removes it from the queue.
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_data_store_ui.php";

use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\DataStoreSwitch;

$opts = getopt('', ['phase:']);
$phase = $opts['phase'] ?? 'up1';
$fixtureFile = "$root/backups/rmm_ds_pg_fixture.json";
$pgHost = getenv('RIVETMSP_PG_HOST') ?: '127.0.0.1';
$pgPort = (int) (getenv('RIVETMSP_PG_PORT') ?: 15432);
$pgPass = getenv('RIVETMSP_PG_PASSWORD') ?: 'throwaway_pg_pw_x9';

require_once "$root/includes/rmm_bootstrap.php";

if ($phase === 'up1') {
    ea_reset();
    ea_seed_users();
    $q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
    $q("UPDATE endpoint_agent_settings SET enabled = 1, data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
    $q("DELETE FROM rmm_data_store_connection");
    $q("DELETE FROM rmm_store_migration");
    $q("DELETE FROM rmm_pending_client_moves");
    $rmm = rivetRmmModule($db);
    $rmm->syncState();
    $switch = $rmm->dataStoreSwitch();

    // ---------------------------------------------------------------------------------------------------- connection + real test
    $errs = $switch->saveConnection(['host' => $pgHost, 'port' => $pgPort, 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => $pgPass, 'sslmode' => 'disable', 'schema' => 'public']);
    $ok($errs === [], 'saveConnection() against the real throwaway PostgreSQL: no errors');
    $t = $switch->testConnection();
    $ok($t['ok'] === true, 'testConnection() against the real server: ok=true (' . ($t['checks'][0]['message'] ?? '') . ')');
    $ok($t['server_version'] !== null && (int) $t['server_version'] >= 14, 'server_version is reported and meets the minimum (14+)');
    $ok($t['storage_mode'] === 'native', 'storage mode is native (no TimescaleDB extension in this throwaway image)');
    $ok(in_array('The role is a superuser. Use a dedicated role that owns only this database; the RMM needs no superuser rights.', $t['warnings'], true), 'the superuser warning fires (this throwaway role IS the Postgres bootstrap superuser - a real install must use a dedicated non-superuser role per docs/rmm/POSTGRES.md section 3)');

    // ---------------------------------------------------------------------------------------------------- switch to postgres, for real
    $r = $switch->switchTo(RmmDataStoreKind::POSTGRES, ['acknowledge_no_data_move' => true, 'actor' => 'test']);
    $ok($r['ok'] === true, 'switchTo(postgres): ' . implode('; ', $r['errors']));
    $ok($one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1") === 'postgres', 'the setting is now postgres');
    $rmm = rivetRmmModule($db);   // rebuild: settings cache may be stale
    $ok($rmm->dataStores()->kind() === 'postgres', 'RmmModule::dataStores()->kind() agrees');

    // ---------------------------------------------------------------------------------------------------- a fresh device, real log events through LogService::ingest() (the production write path)
    $q("INSERT INTO assets SET asset_id=90001, asset_client_id=1, asset_name='PG-TEST-1', asset_type='Workstation', asset_make=''");
    $devToken = bin2hex(random_bytes(16));
    $q("INSERT INTO endpoint_agent_devices SET device_id=90001, install_id='" . ea_uuid() . "', asset_id=90001, client_id=1, hostname='pg-test-1', link_state='active', token_hash='" . hash('sha256', $devToken) . "', first_seen_at=NOW(), last_checkin_at=NOW(), os='windows'");
    $events = [];
    for ($i = 0; $i < 5; $i++) {
        $events[] = ['ts' => ea_ts(-$i), 'level' => 'info', 'source' => 'test', 'event_id' => 1000 + $i, 'message' => "pg log $i"];
    }
    $ing = $rmm->logService()->ingest(['device_id' => 90001, 'client_id' => 1], ['events' => $events]);
    $ok($ing['ok'] === true && $ing['accepted'] === 5, 'LogService::ingest() accepted all 5 events into the (now active) PostgreSQL store (' . json_encode($ing) . ')');

    // ---------------------------------------------------------------------------------------------------- capacity card on a real postgres store
    $cap = $rmm->capacity()->build();
    $ok(($cap['store_sizes']['kind'] ?? null) === 'postgres' && ($cap['store_sizes']['available'] ?? false) === true, 'capacity()->build()[\'store_sizes\'] reads the REAL PostgreSQL table sizes (kind=postgres, available=true)');
    // StoreSizes::report() is documented as an ENGINE ESTIMATE (pg_class.reltuples), updated by autovacuum/ANALYZE, not a live COUNT(*) - it can
    // legitimately read 0 seconds after an insert. Force it so this assertion is meaningful instead of flaky.
    $pdo = new \PDO("pgsql:host=$pgHost;port=$pgPort;dbname=rivet_rmm", 'rivet_rmm', $pgPass);
    $pdo->exec('ANALYZE endpoint_agent_log_events');
    $cap = $rmm->capacity()->build();
    $ok((int) ($cap['store_sizes']['total_rows'] ?? 0) >= 5, 'the five log rows just written are counted (after ANALYZE: ' . (int) ($cap['store_sizes']['total_rows'] ?? 0) . ')');

    // ---------------------------------------------------------------------------------------------------- migrate: dry run, then a real migration back to mariadb and verify
    $started = rivetRmmDsLaunchJob(['--to=mariadb', '--dry-run', '--actor=test']);
    $ok($started, 'a dry-run migration job was launched');
    $m = null;
    for ($i = 0; $i < 100; $i++) { usleep(100000); $log = rivetRmmDsJobLogTail(50); if (str_contains($log, '"summary"')) { $m = $log; break; } }
    $ok($m !== null && str_contains($m, '"dry_run":true'), 'the dry-run reports its summary with dry_run:true');

    $started = rivetRmmDsLaunchJob(['--to=mariadb', '--sample=all', '--actor=test']);
    $ok($started, 'a real migration (postgres -> mariadb) job was launched');
    $done = false;
    for ($i = 0; $i < 300; $i++) {
        usleep(200000);
        $row = $q("SELECT state FROM rmm_store_migration ORDER BY run_id DESC LIMIT 1")->fetch_assoc();
        if ($row !== null && in_array($row['state'], ['cutover', 'failed', 'rolled_back'], true)) { $done = true; break; }
    }
    $row = $q("SELECT state, last_error FROM rmm_store_migration ORDER BY run_id DESC LIMIT 1")->fetch_assoc();
    $ok($done && $row['state'] === 'cutover', 'the migration reached cutover (state=' . ($row['state'] ?? 'none') . ($row['last_error'] ? '; ' . $row['last_error'] : '') . ')');
    $ok($one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1") === 'mariadb', 'after cutover the setting itself is mariadb again');
    $mariaCount = (int) $one("SELECT COUNT(*) FROM endpoint_agent_log_events WHERE device_id = 90001");
    $ok($mariaCount >= 5, 'the 5 log rows written to PostgreSQL are now readable in MariaDB after the migration (' . $mariaCount . ' rows)');

    // roll back within the window: back to postgres, same rows still there (not lost, not duplicated).
    // rivetRmmModule() caches one module PER mysqli CONNECTION OBJECT for this process's lifetime (correct for a real request,
    // which never outlives one connection) - RmmSettings then caches the settings row inside that cached module, so simply
    // calling rivetRmmModule($db) again here would hand back the pre-migration module and its stale "postgres" reading, even
    // though the migration (a separate detached PHP process) already changed the row to "mariadb" in the database. A fresh
    // connection is what every real request gets, so it is what proves the real behaviour here too.
    $db2 = new mysqli($GLOBALS['dbhost'] ?? '127.0.0.1', $GLOBALS['dbusername'], $GLOBALS['dbpassword'], $GLOBALS['database']);
    $rmm = rivetRmmModule($db2);
    $ok($rmm->settings()->dataStore() === 'mariadb', 'a module built from a FRESH connection sees the post-migration setting correctly (mariadb)');
    // The migrate command holds a session advisory lock (RmmStoreMigrator::lock()) until it exits, which can be a beat after the
    // run's row already shows state=cutover (the poll loop above breaks on the row, not on the detached process actually exiting).
    // Retry briefly rather than race it.
    $rb = null;
    for ($i = 0; $i < 30; $i++) {
        try {
            $rb = $rmm->storeMigrator()->rollback(['actor' => 'test']);
            break;
        } catch (\RivetCore\Rmm\Store\Migration\MigrationException $e) {
            if (!str_contains($e->getMessage(), 'Another process')) { throw $e; }
            usleep(200000);
        }
    }
    $ok(($rb['ok'] ?? false) === true, 'rollback() to postgres: ' . json_encode($rb));
    $ok($one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1") === 'postgres', 'after rollback the setting is postgres again');

    // ---------------------------------------------------------------------------------------------------- backup / restore round trip (deploy's own pieces)
    putenv('RIVETCORE_PG_DATABASE=rivet_rmm'); putenv('RIVETCORE_PG_USER=rivet_rmm'); putenv("RIVETCORE_PG_HOST=$pgHost"); putenv("RIVETCORE_PG_PORT=$pgPort"); putenv('RIVETCORE_PG_SSLMODE=disable'); putenv('RIVETCORE_PG_SCHEMA=public');
    $passfile = sys_get_temp_dir() . '/rmm_pg_test.pgpass';
    file_put_contents($passfile, "$pgHost:$pgPort:rivet_rmm:rivet_rmm:$pgPass\n");
    chmod($passfile, 0600);
    putenv("PGPASSFILE=$passfile");
    $dumpFile = sys_get_temp_dir() . '/rmm_pg_test.dump';
    exec("$root/vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh > " . escapeshellarg($dumpFile) . " 2>" . escapeshellarg($dumpFile . '.err'), $o, $rc);
    $ok($rc === 0 && filesize($dumpFile) > 0, 'rmm-postgres-backup.sh produced a non-empty dump (rc=' . $rc . ', ' . (int) @filesize($dumpFile) . ' bytes; ' . trim((string) @file_get_contents($dumpFile . '.err')) . ')');
    $before = $rmm->dataStores()->logs()->oldestEventAt(90001) !== null;
    exec("RMM_PG_RESTORE_CLEAN=1 $root/vendor/rivet/rivet-core/scripts/rmm-postgres-restore.sh < " . escapeshellarg($dumpFile) . " 2>" . escapeshellarg($dumpFile . '.restore.err'), $o2, $rc2);
    $ok($rc2 === 0, 'rmm-postgres-restore.sh restored the dump cleanly (rc=' . $rc2 . '; ' . trim((string) @file_get_contents($dumpFile . '.restore.err')) . ')');
    $db3 = new mysqli($GLOBALS['dbhost'] ?? '127.0.0.1', $GLOBALS['dbusername'], $GLOBALS['dbpassword'], $GLOBALS['database']);
    $rmm = rivetRmmModule($db3);   // fresh connection + fresh module: the restore replaced the PostgreSQL schema, and reusing a cached module/settings would read stale state (see the rollback comment above)
    $after = $rmm->dataStores()->logs()->oldestEventAt(90001) !== null;
    $ok($before === true && $after === true, 'the log row for device 90001 is still readable after the restore (round trip preserved the data)');
    @unlink($passfile); @unlink($dumpFile); @unlink($dumpFile . '.err'); @unlink($dumpFile . '.restore.err');

    // ---------------------------------------------------------------------------------------------------- MSP extra, part 1: a transfer while PostgreSQL IS reachable (sanity before the down/up phases)
    $q("INSERT INTO clients SET client_id=3, client_name='Client C' ON DUPLICATE KEY UPDATE client_name=client_name");
    $who = new \RivetCore\Rmm\Authz\RmmPrincipal(1, 'admin');
    $tr = $rmm->technician()->transfer($who, 90001, 3, 0);
    $ok($tr->ok, 'transfer() while PostgreSQL is reachable: ' . $tr->message);
    // The active store is PostgreSQL: a raw MariaDB query against endpoint_agent_log_events would read the WRONG table (empty/stale) - go through
    // a real PostgreSQL connection, same as the product's own PostgresLogStore does.
    $pgClientId = (int) $pdo->query('SELECT client_id FROM endpoint_agent_log_events WHERE device_id = 90001 LIMIT 1')->fetchColumn();
    $ok($pgClientId === 3, 'the log rows\' client_id actually moved to 3 in PostgreSQL (reassignClient, not a copy; got ' . $pgClientId . ')');

    file_put_contents($fixtureFile, json_encode(['device_id' => 90001, 'from_client' => 3, 'to_client' => 1]));
    $ok(true, 'fixture state written for --phase=down / --phase=up2');
} elseif ($phase === 'down') {
    $fx = json_decode((string) @file_get_contents($fixtureFile), true);
    if (!is_array($fx)) { fwrite(STDERR, "no fixture from --phase=up1\n"); exit(2); }
    $q("DELETE FROM rmm_pending_client_moves");
    $rmm = rivetRmmModule($db);
    $ok($rmm->dataStores()->kind() === 'postgres', 'still configured for postgres (the setting lives in MariaDB, unaffected by PostgreSQL being down)');
    $who = new \RivetCore\Rmm\Authz\RmmPrincipal(1, 'admin');
    $tr = $rmm->technician()->transfer($who, (int) $fx['device_id'], (int) $fx['to_client'], 0);
    $ok($tr->ok, 'transfer() still reports ok while PostgreSQL is down — the control-database move (devices.client_id) is NOT blocked by the hot store being unreachable: ' . $tr->message);
    $ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = " . (int) $fx['device_id']) === (int) $fx['to_client'], 'the device row itself (control database) did move');
    $pending = $q("SELECT device_id, client_id FROM rmm_pending_client_moves WHERE device_id = " . (int) $fx['device_id'])->fetch_assoc();
    $ok($pending !== null && (int) $pending['client_id'] === (int) $fx['to_client'], 'the HISTORY move was deferred: rmm_pending_client_moves has exactly the pending move (HotStoreUnavailableException caught by ClientMoves::apply(), nothing lost)');
} elseif ($phase === 'up2') {
    $fx = json_decode((string) @file_get_contents($fixtureFile), true);
    if (!is_array($fx)) { fwrite(STDERR, "no fixture from --phase=up1\n"); exit(2); }
    $before = (int) $one("SELECT COUNT(*) FROM rmm_pending_client_moves WHERE device_id = " . (int) $fx['device_id']);
    $ok($before === 1, 'the pending move is still queued before housekeeping runs');
    $out = rivetRmmHousekeeping($db);   // the real cron entry point (includes/rmm_bootstrap.php), not a hand-built ClientMoves
    $after = (int) $one("SELECT COUNT(*) FROM rmm_pending_client_moves WHERE device_id = " . (int) $fx['device_id']);
    $ok($after === 0, 'after housekeeping runs (PostgreSQL reachable again), the pending move is gone — retried and completed');
    $rmm = rivetRmmModule($db);
    // The active store is PostgreSQL: query it directly (a raw MariaDB query would read the wrong table, as it did in phase=up1 before that was fixed).
    $pdo = new \PDO("pgsql:host=$pgHost;port=$pgPort;dbname=rivet_rmm", 'rivet_rmm', $pgPass);
    $movedClient = (int) $pdo->query('SELECT client_id FROM endpoint_agent_log_events WHERE device_id = ' . (int) $fx['device_id'] . ' LIMIT 1')->fetchColumn();
    $ok($movedClient === (int) $fx['to_client'], 'the log rows in PostgreSQL now carry the NEW client_id (' . $movedClient . ') - the deferred move actually happened, not just the queue entry disappearing');
} else {
    fwrite(STDERR, "unknown --phase\n");
    exit(2);
}
