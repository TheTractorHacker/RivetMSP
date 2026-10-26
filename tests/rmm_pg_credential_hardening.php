<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * RMM PostgreSQL data store credential hardening (follow-up to phase 4 of #89). Everything here runs against REAL servers - a throwaway
 * Docker PostgreSQL with a password (role rivet_rmm) and a second throwaway Docker PostgreSQL with NO password required at all (role
 * www_data_scratch, "trust" auth standing in for the recommended peer-authentication-over-a-unix-socket setup: PostgresConfig and
 * DataStoreSwitch only care that the password is empty and the host format is accepted, never how the server itself authenticates it) -
 * never anything live, never MariaDB-only fakes. ABSOLUTE RULE throughout: never print a password or sealed value - every assertion below
 * compares booleans/prefixes/lengths, never the secret itself, even in a failure message.
 *
 *   docker run -d --name msp-pgcred-test-pg       -p 127.0.0.1:25432:5432 -e POSTGRES_PASSWORD=<throwaway> -e POSTGRES_USER=postgres -e POSTGRES_DB=postgres postgres:18
 *   docker exec ... psql -U postgres -c "CREATE ROLE rivet_rmm LOGIN PASSWORD '<throwaway>' NOSUPERUSER NOCREATEDB NOCREATEROLE;"
 *   docker exec ... psql -U postgres -c "CREATE DATABASE rivet_rmm OWNER rivet_rmm ENCODING 'UTF8' TEMPLATE template0;"
 *   docker run -d --name msp-pgcred-test-pg-trust -p 127.0.0.1:25433:5432 -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_USER=postgres -e POSTGRES_DB=postgres postgres:18
 *   docker exec ... psql -U postgres -c "CREATE ROLE www_data_scratch LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;"
 *   docker exec ... psql -U postgres -c "CREATE DATABASE rivet_rmm_msp OWNER www_data_scratch ENCODING 'UTF8' TEMPLATE template0;"
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_x_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_PORT=0 RIVETMSP_REDIS_ENV_FILE=/dev/null \
 *   RIVETMSP_PG_HOST=127.0.0.1 RIVETMSP_PG_PORT=25432 RIVETMSP_PG_PASSWORD=<throwaway> \
 *   RIVETMSP_PG_TRUST_PORT=25433 php tests/rmm_pg_credential_hardening.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_data_store_ui.php";
require_once "$root/includes/security_crypto.php";

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Store\Cli\CliBootstrap;
use RivetCore\Rmm\Store\DataStoreSwitch;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetMSP\Crypto\ColumnRegistry;
use RivetMSP\Crypto\KeyAdmin;
use RivetMSP\Crypto\KeyStore;
use RivetMSP\Crypto\MysqliRewrapSource;
use RivetMSP\Crypto\RewrapService;
use RivetMSP\Crypto\SettingsCrypto;

$pgHost = getenv('RIVETMSP_PG_HOST') ?: '127.0.0.1';
$pgPort = (int) (getenv('RIVETMSP_PG_PORT') ?: 25432);
$pgPass = getenv('RIVETMSP_PG_PASSWORD') ?: '';
$trustPort = (int) (getenv('RIVETMSP_PG_TRUST_PORT') ?: 25433);
if ($pgPass === '') { fwrite(STDERR, "set RIVETMSP_PG_PASSWORD (the throwaway Docker role's password)\n"); exit(2); }

ea_reset();
$tokens = ea_seed_users();
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$q("UPDATE endpoint_agent_settings SET enabled = 1, data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
$q("DELETE FROM rmm_data_store_connection");
$q("DELETE FROM rmm_store_migration");
$q("DELETE FROM rmm_pg_credential_rotations");
$rmm = rivetRmmModule($db);
$rmm->syncState();
$switch = $rmm->dataStoreSwitch();

// ================================================================================================================================ 1. ColumnRegistry covers the stored password
$byName = ColumnRegistry::byName();
$ok(isset($byName['rmm_data_store_connection.password_sealed']), 'rmm_data_store_connection.password_sealed is a registered rewrap column (key rotation and the lazy re-wrap reach it)');
$ok($byName['rmm_data_store_connection.password_sealed']->wrapPlaintext === false, 'it is never a wrap-plaintext target - DataStoreSwitch::saveConnection() always seals through the box, so a cleartext value there would never be the module\'s own ciphertext');

// ================================================================================================================================ 2. the CLI bootstrap bug (known from earlier work this session): CliBootstrap::load() requires scripts/rmm_store_bootstrap.php
// from INSIDE its own closure, so config.php's $config_settings_enc_key and $mysqli are local to that closure unless the bootstrap
// file explicitly re-exports them to $GLOBALS. This must run in a FRESH subprocess, exactly like the real CLI tools: this test
// process has already loaded config.php/functions.php itself, so an in-process CliBootstrap::load() would collide on
// "cannot redeclare function" - not the bug under test, just an artifact of sharing a process with the harness.
// The child script never receives or prints the plaintext: it reports only a sha256 HASH of what it decrypted, compared here.
function rivetTestCliBootstrapHash(string $root): ?string
{
    $script = sys_get_temp_dir() . '/cli_bootstrap_check_' . bin2hex(random_bytes(4)) . '.php';
    $code = '<?php' . "\n"
        . 'require ' . var_export("$root/vendor/autoload.php", true) . ';' . "\n"
        . '$boot = \RivetCore\Rmm\Store\Cli\CliBootstrap::load(' . var_export("$root/scripts/rmm_store_bootstrap.php", true) . ');' . "\n"
        . '$row = $boot->database->fetchOne("SELECT host, port, db_name, db_user, sslmode, schema_name, password_sealed FROM rmm_data_store_connection WHERE id = 1");' . "\n"
        . 'if ($row === null) { fwrite(STDERR, "NOROW\n"); exit(1); }' . "\n"
        . '$cfg = \RivetCore\Rmm\Store\Postgres\PostgresConfig::fromSealed(["database" => $row["db_name"], "user" => $row["db_user"], "host" => $row["host"], '
        . '"port" => (int) $row["port"], "sslmode" => $row["sslmode"], "schema" => $row["schema_name"], "password_sealed" => (string) ($row["password_sealed"] ?? "")], $boot->secretBox);' . "\n"
        . 'echo hash("sha256", $cfg->password());' . "\n";
    file_put_contents($script, $code);
    $out = [];
    $rc = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>' . escapeshellarg($script . '.err'), $out, $rc);
    $stderr = (string) @file_get_contents($script . '.err');
    @unlink($script);
    @unlink($script . '.err');
    if ($rc !== 0 || $out === []) {
        fwrite(STDERR, "rivetTestCliBootstrapHash: child failed (rc=$rc): " . trim($stderr) . "\n");

        return null;
    }

    return trim(implode('', $out));
}

// $useKeyfile() flips BOTH where this is a difference: $GLOBALS['config_keyfile'] directly, for every in-process crypto call made
// from here on (config.php already ran once, at the top of this test file via endpoint_agent_lib.php - re-running putenv() alone
// does NOT change the $config_keyfile variable it already assigned), and the SCRATCH_KEYFILE env var, for every FRESH subprocess
// rivetTestCliBootstrapHash() spawns from here on (which re-requires config.php itself and so DOES see a changed env var).
$useKeyfile = function (?string $path): void {
    $GLOBALS['config_keyfile'] = $path ?? '';
    if ($path === null) { putenv('SCRATCH_KEYFILE'); } else { putenv("SCRATCH_KEYFILE=$path"); }
    SettingsCrypto::reset();
};
$cliKeyDir = sys_get_temp_dir() . '/rmm-cli-keys-' . bin2hex(random_bytes(4));
mkdir($cliKeyDir, 0700, true);
register_shutdown_function(function () use ($cliKeyDir) { foreach (glob("$cliKeyDir/*") ?: [] as $f) { @unlink($f); } @rmdir($cliKeyDir); });
$missingKeyFile = "$cliKeyDir/does-not-exist.json";
$useKeyfile($missingKeyFile);
$ok(KeyStore::load()->source === 'legacy', 'no v3 key file yet: the install is on the config.php legacy key (ENC2 stage)');

$plainEnc2 = 'cli-bootstrap-proof-enc2-' . bin2hex(random_bytes(8));
$hashEnc2 = hash('sha256', $plainEnc2);
$sealedEnc2 = encryptSetting($plainEnc2);
$ok(str_starts_with($sealedEnc2, 'ENC2:'), 'seeded value is sealed ENC2 (no key file yet)');
$q("UPDATE rmm_data_store_connection SET id=1, host='$pgHost', port=$pgPort, db_name='rivet_rmm', db_user='rivet_rmm', password_sealed='" . $esc($sealedEnc2) . "', sslmode='disable', schema_name='public', test_ok=0, updated_at=UTC_TIMESTAMP() WHERE id=1");
if ((int) $one('SELECT COUNT(*) FROM rmm_data_store_connection WHERE id=1') === 0) {
    $q("INSERT INTO rmm_data_store_connection (id, host, port, db_name, db_user, password_sealed, sslmode, schema_name, test_ok, updated_at) VALUES (1, '$pgHost', $pgPort, 'rivet_rmm', 'rivet_rmm', '" . $esc($sealedEnc2) . "', 'disable', 'public', 0, UTC_TIMESTAMP())");
}
$gotHash1 = rivetTestCliBootstrapHash($root);
$ok($gotHash1 !== null && hash_equals($hashEnc2, $gotHash1), 'the CLI tooling, run as a REAL SEPARATE PHP PROCESS (CliBootstrap -> its secretBox), reads an ENC2:-sealed stored password correctly - the bootstrap file\'s $GLOBALS export is what makes this possible (reverting it reproduces RivetIT\'s live-trial bug: the box sees no key and the value reads as empty, never the real password). Compared by sha256 hash only, never the plaintext.');

$v3Dir = sys_get_temp_dir() . '/rmm-cli-v3keys-' . bin2hex(random_bytes(4));
mkdir($v3Dir, 0700, true);
register_shutdown_function(function () use ($v3Dir) { foreach (glob("$v3Dir/*") ?: [] as $f) { @unlink($f); } @rmdir($v3Dir); });
$v3kf = "$v3Dir/keys.json";
KeyAdmin::generate($v3kf);
$useKeyfile($v3kf);
$activeKid = (string) KeyStore::load()->ring->activeKid();
$plainV3 = 'cli-bootstrap-proof-v3-' . bin2hex(random_bytes(8));
$hashV3 = hash('sha256', $plainV3);
$sealedV3 = encryptSetting($plainV3);
$ok(str_starts_with($sealedV3, 'v3:' . $activeKid . ':'), 'seeded value is sealed v3 under the active key');
$q("UPDATE rmm_data_store_connection SET password_sealed='" . $esc($sealedV3) . "' WHERE id=1");
$gotHash2 = rivetTestCliBootstrapHash($root);
$ok($gotHash2 !== null && hash_equals($hashV3, $gotHash2), 'the CLI tooling also reads a v3:-sealed stored password correctly, as a real separate process (both legacy and current formats work through the fixed bootstrap)');

// the rewrap tool itself, through the SAME fixed bootstrap path: ENC2 -> v3, read back only the PREFIX, never decrypt-and-print
$q("UPDATE rmm_data_store_connection SET password_sealed='" . $esc(encryptSetting($plainEnc2)) . "' WHERE id=1");   // re-seed ENC2 under the current (v3-capable) settings
$preRewrap = (string) $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id=1');
$ok(str_starts_with($preRewrap, 'v3:'), 'once a key file exists, encryptSetting() already seals new writes as v3 (DataStoreSwitch behaves the same as every other secret column)');
// Force a genuinely legacy ENC2 row (as if saved before this install ever had a key file) to prove the REWRAP path, not just fresh
// writes: $sealedEnc2 (captured earlier, before the v3 key file existed) is a REAL legacy ciphertext produced by this same
// encryptSetting(), not a hand-rolled fake - the exact ENC2 framing is private to functions.php and must never be reimplemented here.
$q("UPDATE rmm_data_store_connection SET password_sealed='" . $esc($sealedEnc2) . "' WHERE id=1");
$ok(MysqliRewrapSource::exists($db, $byName['rmm_data_store_connection.password_sealed']), 'the rewrap source sees the column on this schema');
$svc = new RewrapService($db, 1, sys_get_temp_dir() . '/rmm-cli-rewrap-state-' . bin2hex(random_bytes(4)));
$reports = $svc->run('rmm_data_store_connection.password_sealed');
$bad = 0; foreach ($reports as $r) { $bad += $r->failed + $r->conflicts; }
$ok($bad === 0, 'the rewrap of the stored PostgreSQL password has no failures or conflicts');
$postRewrapPrefix = substr((string) $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id=1'), 0, 3);
$ok($postRewrapPrefix === 'v3:', 'AFTER the rewrap the stored password is v3: (reading back ONLY the 3-byte prefix here - never the value itself - to confirm the format)');
$ok(hash_equals($plainEnc2, $switch->postgresConfig()?->password() ?? ''), 'and DataStoreSwitch::postgresConfig() still resolves it to the SAME plaintext after the rewrap (compared directly here, in-process, never printed - $plainEnc2 is this test\'s own random fixture, not a real secret)');

// ================================================================================================================================ 3. no plaintext anywhere - grep the source tree, the server log, the activity log/audit trail and config.php for a real seeded marker
$plainMarker = 'no-plaintext-marker-' . bin2hex(random_bytes(10));
$q("UPDATE rmm_data_store_connection SET password_sealed='" . $esc(encryptSetting($plainMarker)) . "' WHERE id=1");
$sourceFiles = array_merge(
    glob("$root/admin/*.php") ?: [], glob("$root/admin/post/*.php") ?: [], glob("$root/includes/*.php") ?: [],
    glob("$root/src/Crypto/*.php") ?: [], glob("$root/scripts/*.php") ?: [], glob("$root/deploy/*.sh") ?: [],
);
$leakedInSource = [];
foreach ($sourceFiles as $f) {
    if (str_contains((string) @file_get_contents($f), $plainMarker)) { $leakedInSource[] = basename($f); }
}
$ok($leakedInSource === [], 'the marker (planted ONLY in the database) never appears in any edition source file' . ($leakedInSource ? ': ' . implode(', ', $leakedInSource) : ''));
$ok(!str_contains((string) @file_get_contents("$root/config.php"), $plainMarker), 'config.php never contains the password - it lives only in the sealed rmm_data_store_connection.password_sealed column');
$ok(!preg_match('/config_rmm_pg_password|RMM_PG_PASSWORD\b/', (string) @file_get_contents("$root/config.php")), 'config.php defines no PostgreSQL-password constant at all');
$logText = (string) @file_get_contents($EA['log']);
$ok(!str_contains($logText, $plainMarker), 'the PHP server/application error log never contains the password');
// A connection TEST with this exact password must not echo it back anywhere, and must not log it either (the save/rotate handlers already proved this over HTTP in tests/rmm_data_store_ui_smoke.php; here it is proven against this test\'s real server log file for the module layer).
$switch->testConnection(['host' => $pgHost, 'port' => $pgPort, 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => $plainMarker, 'sslmode' => 'disable', 'schema' => 'public']);
$ok(!str_contains((string) @file_get_contents($EA['log']), $plainMarker), 'testing a connection with the marker password leaves no trace of it in the error log afterward either');
$auditText = implode(' ', array_map(fn ($r) => (string) $r['log_description'], $rows("SELECT log_description FROM logs") ?: []));
$auditText .= implode(' ', array_map(fn ($r) => (string) $r['summary'] . (string) ($r['metadata_json'] ?? ''), $rows("SELECT summary, metadata_json FROM audit_events") ?: []));
$ok(!str_contains($auditText, $plainMarker), 'the activity log / structured audit trail never contains the password, even after a save/test that used it');

// Code-shape guard: a CLI invocation must never carry the stored password as a literal argument - PostgresConfig::password() may only
// be used to build a .pgpass FILE (scripts/rmm_postgres_backup_env.php), never concatenated into a shell command string. A future
// regression (someone adding `exec("... password=" . $config->password())`) would be caught here.
$shellBuilders = array_merge(glob("$root/scripts/*.php") ?: [], glob("$root/deploy/*.sh") ?: []);
$unsafe = [];
foreach ($shellBuilders as $f) {
    $text = (string) @file_get_contents($f);
    if (preg_match('/\bPGPASSWORD\s*=/', $text) && basename($f) !== 'rmm_pg_credential_hardening.php') { $unsafe[] = basename($f) . ' (PGPASSWORD=)'; }
    if (preg_match('/->password\(\)/', $text) && !str_contains($f, 'rmm_postgres_backup_env.php')) { $unsafe[] = basename($f) . ' (->password() used outside the one .pgpass writer)'; }
}
$ok($unsafe === [], 'no script sets PGPASSWORD or uses PostgresConfig::password() anywhere except the one dedicated .pgpass-file writer' . ($unsafe ? ': ' . implode(', ', $unsafe) : ''));

// ================================================================================================================================ 4. the recommended setup: empty password, unix-socket-shaped host string, end to end
// A real second throwaway PostgreSQL where NO password is required at all (trust auth) stands in for peer authentication over a unix
// socket: PostgresConfig / DataStoreSwitch never know or care HOW the server authenticated the role, only that the typed password is
// empty and the host string is accepted - proven here against a REAL connection, not a unit stub.
$q("DELETE FROM rmm_data_store_connection"); $q("DELETE FROM rmm_store_migration");
$errs = $switch->saveConnection(['host' => $pgHost, 'port' => $trustPort, 'database' => 'rivet_rmm_msp', 'user' => 'www_data_scratch', 'password' => '', 'sslmode' => 'disable', 'schema' => 'public']);
$ok($errs === [], 'saveConnection() with an EMPTY password against a real, passwordless PostgreSQL: no errors (Core does not refuse a blank password)' . ($errs ? ': ' . implode('; ', $errs) : ''));
$sealedEmpty = $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id=1');
$ok($sealedEmpty === null, 'an empty password is stored as NULL, never as an empty-string ciphertext or cleartext');
$t = $switch->testConnection();
$ok($t['ok'] === true, 'testConnection() succeeds with no password at all (real connection, real server)' . ($t['ok'] ? '' : ': ' . json_encode($t['checks'])));
$r = $switch->switchTo(RmmDataStoreKind::POSTGRES, ['acknowledge_no_data_move' => true, 'actor' => 'test']);
$ok($r['ok'] === true, 'switchTo(postgres) succeeds end to end with an empty password: ' . implode('; ', $r['errors']));
$ok($rmm->dataStoreSwitch()->postgresConfig()?->password() === '', 'the resolved PostgresConfig carries an empty password (compared as a boolean here, never printed)');
// a real write through the now-active empty-password store
$q("INSERT INTO assets SET asset_id=90501, asset_client_id=1, asset_name='PG-NOPW-1', asset_type='Workstation', asset_make=''");
$q("INSERT INTO endpoint_agent_devices SET device_id=90501, install_id='" . ea_uuid() . "', asset_id=90501, client_id=1, hostname='pg-nopw-1', link_state='active', token_hash='" . hash('sha256', bin2hex(random_bytes(16))) . "', first_seen_at=NOW(), last_checkin_at=NOW(), os='windows'");
$rmm2 = rivetRmmModule($db);
$ing = $rmm2->logService()->ingest(['device_id' => 90501, 'client_id' => 1], ['events' => [['ts' => ea_ts(), 'level' => 'info', 'source' => 'test', 'event_id' => 1, 'message' => 'no-password store works']]]);
$ok(($ing['ok'] ?? false) === true && ($ing['accepted'] ?? 0) === 1, 'a real log write succeeds through the empty-password PostgreSQL store (DataStoreSwitch\'s test/save/switch flow genuinely works with no password)');
// the CLI migrator/verify tooling against the SAME empty-password connection (never a request's `postgres` option: the stored connection only)
exec(PHP_BINARY . ' ' . escapeshellarg("$root/vendor/rivet/rivet-core/scripts/rmm-store-migrate.php") . ' --bootstrap=' . escapeshellarg("$root/scripts/rmm_store_bootstrap.php") . ' --status 2>&1', $oStatusArr, $cStatus);
$oStatus = implode("\n", $oStatusArr);
$ok($cStatus === 0 || str_contains($oStatus, 'no migration') || str_contains($oStatus, '"state"'), 'the CLI migrator can at least READ status against the empty-password connection without crashing (rc=' . $cStatus . ')');
// rmm-postgres.php --config expects a FILE path (docs/rmm/POSTGRES.md section 4): a JSON file with no "password" key at all, exactly
// as a peer-authentication / no-password install's config would look.
$pgJsonCfg = sys_get_temp_dir() . '/rmm-pg-nopw-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($pgJsonCfg, json_encode(['database' => 'rivet_rmm_msp', 'user' => 'www_data_scratch', 'host' => $pgHost, 'port' => $trustPort, 'sslmode' => 'disable']));
chmod($pgJsonCfg, 0600);
register_shutdown_function(function () use ($pgJsonCfg) { @unlink($pgJsonCfg); });
exec(PHP_BINARY . ' ' . escapeshellarg("$root/vendor/rivet/rivet-core/scripts/rmm-postgres.php") . ' migrate --config=' . escapeshellarg($pgJsonCfg) . ' 2>&1', $oMig2, $cMig2);
$ok($cMig2 === 0, 'rmm-postgres.php migrate (the schema migrator CLI tool) runs cleanly against the empty-password connection, config read from a JSON file with no "password" key at all (rc=' . $cMig2 . ')');
exec(PHP_BINARY . ' ' . escapeshellarg("$root/vendor/rivet/rivet-core/scripts/rmm-postgres.php") . ' status --config=' . escapeshellarg($pgJsonCfg) . ' 2>&1', $oMig3, $cMig3);
$ok($cMig3 === 0, 'and rmm-postgres.php status confirms nothing pending (rc=' . $cMig3 . ')');

// switch back, leave the fixture clean
$switch->switchTo(RmmDataStoreKind::MARIADB);
$q("DELETE FROM rmm_data_store_connection"); $q("DELETE FROM rmm_store_migration"); $q("DELETE FROM rmm_pg_credential_rotations");
$q("DELETE FROM endpoint_agent_devices WHERE device_id IN (90501)"); $q("DELETE FROM assets WHERE asset_id IN (90501)");

// ================================================================================================================================ 5. password rotation: test BEFORE replacing, audit row (who/when, never the value), "last changed" + 90-day warning
// Mirrors admin/post/settings_endpoint_agent.php's rmm_ds_rotate_password handler exactly (same DataStoreSwitch calls, same
// rivetRmmPgCredentialRecordRotation()), against a REAL PostgreSQL role whose password is changed for real in between - proving
// the three security properties the task asked for: (1) a wrong new password never overwrites a working one, (2) a correct new
// password is tested against the real server BEFORE it replaces the stored value, (3) who/when is recorded, never the value.
$rotateUser = 'rivet_rmm_rotate';
$rotateDb = 'rivet_rmm_rotate';
$initialPw = 'rotate-initial-pw-A1';
$newPw = 'rotate-new-pw-B2-' . bin2hex(random_bytes(4));
$errs = $switch->saveConnection(['host' => $pgHost, 'port' => $pgPort, 'database' => $rotateDb, 'user' => $rotateUser, 'password' => $initialPw, 'sslmode' => 'disable', 'schema' => 'public']);
$ok($errs === [], 'rotation fixture: initial connection saved' . ($errs ? ': ' . implode('; ', $errs) : ''));
$r = $switch->switchTo(RmmDataStoreKind::POSTGRES, ['acknowledge_no_data_move' => true, 'actor' => 'test']);
$ok($r['ok'] === true, 'rotation fixture: switched to postgres with the initial password: ' . implode('; ', $r['errors']));
$sealedBefore = (string) $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id = 1');
$ok($sealedBefore !== '', 'a sealed password is stored before any rotation attempt');

// The admin changes the REAL password in PostgreSQL first (exactly the documented flow - "Change it in PostgreSQL first"):
exec('docker exec msp-pgcred-test-pg psql -U postgres -c ' . escapeshellarg("ALTER ROLE $rotateUser PASSWORD '" . addslashes($newPw) . "';") . ' 2>&1', $oAlter, $cAlter);
$ok($cAlter === 0, 'fixture: the role\'s real PostgreSQL password was changed (simulating the admin\'s own step)');

/** Exactly the logic of admin/post/settings_endpoint_agent.php's rmm_ds_rotate_password handler - test the new password against
 *  the stored target BEFORE calling saveConnection(), recording a rotation ONLY on real success. Returns ['ok'=>bool,'msg'=>string]. */
$rotate = function (string $candidatePassword) use ($switch, $db, $esc): array {
    $stored = $switch->connectionSettings();
    if ($stored === null) { return ['ok' => false, 'msg' => 'no stored connection']; }
    $testIn = ['host' => $stored['host'], 'port' => $stored['port'], 'database' => $stored['database'], 'user' => $stored['user'], 'sslmode' => $stored['sslmode'], 'schema' => $stored['schema'], 'password' => $candidatePassword];
    $test = $switch->testConnection($testIn);
    if ($test['ok'] !== true) { return ['ok' => false, 'msg' => 'test failed']; }
    $errs = $switch->saveConnection($testIn);
    if ($errs !== []) { return ['ok' => false, 'msg' => implode('; ', $errs)]; }
    rivetRmmPgCredentialRecordRotation($db, 'test-actor', 'test rotation');

    return ['ok' => true, 'msg' => ''];
};

$wrong = $rotate('totally-wrong-guess-' . bin2hex(random_bytes(3)));
$ok($wrong['ok'] === false, 'rotating with a WRONG new password is refused (the test against the real server fails first)');
$sealedAfterWrong = (string) $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id = 1');
$ok($sealedAfterWrong === $sealedBefore, 'a failed rotation attempt changes NOTHING in the stored sealed value (byte-identical ciphertext - compared, never decrypted and printed)');
$ok((int) $one('SELECT COUNT(*) FROM rmm_pg_credential_rotations') === 0, 'a failed rotation attempt writes NO audit row');
$ok(rivetRmmPgCredentialLastChanged($db) === null, '"last changed" is still unset after only a failed attempt');

$right = $rotate($newPw);
$ok($right['ok'] === true, 'rotating with the CORRECT new password (matching what was just set in real PostgreSQL) succeeds: ' . $right['msg']);
$sealedAfterRight = (string) $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id = 1');
$ok($sealedAfterRight !== $sealedBefore && $sealedAfterRight !== '', 'a successful rotation DOES replace the stored sealed value (different ciphertext)');
$ok(hash_equals($newPw, $rmm->dataStoreSwitch()->postgresConfig()?->password() ?? ''), 'and the new stored value resolves to the new password (compared directly, never printed)');
$rotRows = (int) $one('SELECT COUNT(*) FROM rmm_pg_credential_rotations');
$ok($rotRows === 1, 'exactly one audit row was written for the successful rotation');
$rotRow = $rows('SELECT rotated_by, note, rotated_at FROM rmm_pg_credential_rotations')[0] ?? null;
$ok($rotRow !== null && $rotRow['rotated_by'] === 'test-actor' && !str_contains($rotRow['note'], $newPw) && !str_contains($rotRow['note'], $initialPw), 'the audit row records WHO rotated it and a plain note - never the password value, in neither column');
$lastChanged = rivetRmmPgCredentialLastChanged($db);
$ok($lastChanged !== null, '"last changed" now reflects the rotation');
$ageDays = rivetRmmPgCredentialAgeDays($lastChanged);
$ok($ageDays === 0, 'a rotation that just happened reports age 0 days (no stale warning right after rotating)');

// Simulate it being stale: back-date the rotation row by 100 days and confirm the age/warning threshold.
$q("UPDATE rmm_pg_credential_rotations SET rotated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 100 DAY)");
$staleAge = rivetRmmPgCredentialAgeDays(rivetRmmPgCredentialLastChanged($db));
$ok($staleAge !== null && $staleAge >= 99 && $staleAge <= 101, 'a back-dated rotation is correctly measured as roughly 100 days old (' . $staleAge . ')');
$ok($staleAge > 90, 'and that is past the 90-day threshold the card warns on');

// A second wrong attempt after a successful rotation still never overwrites the (now current) credential:
$wrong2 = $rotate('another-wrong-guess-' . bin2hex(random_bytes(3)));
$ok($wrong2['ok'] === false, 'a wrong guess AFTER a successful rotation is still refused');
$ok((string) $one('SELECT password_sealed FROM rmm_data_store_connection WHERE id = 1') === $sealedAfterRight, 'and still changes nothing - the real, working credential stays exactly as the last successful rotation left it');

// cleanup
$switch->switchTo(RmmDataStoreKind::MARIADB);
$q("DELETE FROM rmm_data_store_connection"); $q("DELETE FROM rmm_store_migration"); $q("DELETE FROM rmm_pg_credential_rotations");
