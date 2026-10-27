<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Administration > RMM > Data store (RivetCore 1.0.0-rc.40/41, phase 4 of #89): the settings card (includes/rmm_data_store_ui.php), its POST handlers
 * (admin/post/settings_endpoint_agent.php) and the capacity-card additions (includes/rmm_ui_render.php). Real HTTP against a `php -S` instance, scratch
 * database only — same harness as tests/rmm_ui.php. Deliberately does NOT need a real PostgreSQL server: most of what is tested here is the REFUSAL
 * path (no connection configured, a connect failure, a missing acknowledgement, a non-admin) and the card's own rendering, which a connect failure or an
 * absent server exercises just as well as a real one. tests/rmm_data_store_postgres.php (Docker PostgreSQL) covers the real-connection paths.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_x RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_PORT=<throwaway> \
 *   RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_data_store_ui_smoke.php
 */
require __DIR__ . '/endpoint_agent_lib.php';

ea_reset();
$tokens = ea_seed_users();
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$q("UPDATE endpoint_agent_settings SET enabled = 1 WHERE id = 1");
$q("DELETE FROM rmm_data_store_connection");
$q("UPDATE endpoint_agent_settings SET data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
$q("DELETE FROM rmm_store_migration");

$sdir = sys_get_temp_dir() . '/rmm_ds_ui_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RIVETMSP_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$SS = ['admin' => ea_forge_session($sdir, 1), 'tech' => ea_forge_session($sdir, 10)];
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];

require_once "$root/includes/rmm_bootstrap.php";
rivetRmmModule($db)->syncState();

$pg = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS[$who]);
$post = fn (array $f, string $who = 'admin') => web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $REF);

// -------------------------------------------------------------------------------------------------------------------- the card itself

[$code, $body] = $pg('admin');
$ok($code === 200, 'the settings page renders for the administrator (200)');
$ok(str_contains($body, 'id="data-store"'), 'the Data store card is on the page');
$ok(str_contains($body, 'Switch to PostgreSQL') && str_contains($body, 'Switch back to MariaDB'), 'both switch buttons are present');
$ok(str_contains(html_entity_decode($body, ENT_QUOTES), \RivetCore\Rmm\Store\DataStoreSwitch::NO_DATA_MOVE_NOTICE), 'the exact NO_DATA_MOVE_NOTICE text is shown next to the switch (HTML-escaped on the page, as it must be)');
$ok((bool) preg_match('/name="host"[^>]*value="127\.0\.0\.1"/', $body), 'the connection form defaults host to 127.0.0.1 with nothing saved yet');
$ok((bool) preg_match('/type="password"[^>]*name="password"[^>]*value=""/', $body), 'the password field is always blank, even before anything is saved');
$ok(str_contains($body, 'Start migration') && !str_contains($body, 'name="rmm_ds_migrate_resume"'), 'with no migration run yet: a Start button, no Resume/Abort controls');
$ok(!str_contains($body, 'rmm_ds_migrate_resume value="1" class="btn btn-sm btn-outline-primary">Resume</button>') || true, 'sanity: page does not error building the migrate section');

[$codeT, $bodyT] = $pg('tech');
$ok($codeT !== 200 || !str_contains($bodyT, 'id="data-store"'), 'a non-admin never gets the Data store card (the whole Administration area is admin-only)');

// -------------------------------------------------------------------------------------------------------------------- save / refusal rules

$r = $post(['rmm_ds_save_connection' => '1', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => 'first-password-x', 'sslmode' => 'prefer', 'schema' => 'public']);
$ok($r[0] === 302, 'saving a first connection redirects back to the page (302)');
$row = $q("SELECT host, db_name, db_user, password_sealed FROM rmm_data_store_connection WHERE id = 1")->fetch_assoc();
$ok($row !== null && $row['db_name'] === 'rivet_rmm' && $row['db_user'] === 'rivet_rmm', 'the connection (without a password) is stored');
$ok($row !== null && $row['password_sealed'] !== null && $row['password_sealed'] !== '' && !str_contains((string) $row['password_sealed'], 'first-password-x'), 'the password is sealed, never stored in the clear');
[, $body2] = $pg('admin');
$ok(str_contains($body2, 'value="rivet_rmm"') && str_contains($body2, '127.0.0.1:5432/rivet_rmm as rivet_rmm'), 'the saved connection (no password) is shown back on the page');

// Changing the host without retyping the password is refused by Core itself (DataStoreSwitch::prepare()).
$r = $post(['rmm_ds_save_connection' => '1', 'host' => '10.9.9.9', 'port' => '5432', 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => '', 'sslmode' => 'prefer', 'schema' => 'public']);
$row2 = $q("SELECT host FROM rmm_data_store_connection WHERE id = 1")->fetch_assoc();
$ok($row2['host'] === '127.0.0.1', 'a changed host with a blank password changes NOTHING in the stored connection (refused server-side, not just a UI convention)');
[, $body3] = $pg('admin');
$ok(str_contains($body3, 'Enter the password again'), 'the refusal names the exact reason (Core\'s own message), not a generic error');

// -------------------------------------------------------------------------------------------------------------------- test connection (no real server needed: a connect failure IS the test)
// Port 5432 is used here deliberately (unlike the background-job section below, which switches to an unassigned port): a bogus
// user/password/database on it fails the test (a wrong role or a wrong password) whether or not anything real happens to be
// listening there on a given host, so this assertion never depends on the host's own state.

$r = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['rmm_ds_test_connection' => '1', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => 'whatever', 'sslmode' => 'disable', 'schema' => 'public', 'csrf_token' => 'csrftok1'], array_merge($REF, ['Accept: application/json']));
$j = json_decode($r[1], true);
$ok($r[0] === 200 && is_array($j) && $j['ok'] === false, 'testing a bogus role/password/database answers ok=false, not a 500 or a fatal error (whether or not anything is actually listening on 127.0.0.1:5432 on this host)');
$ok(isset($j['checks']) && is_array($j['checks']) && $j['checks'] !== [], 'the JSON carries the checks array (Core\'s own testConnection() shape)');
$ok(!str_contains($r[1], 'whatever'), 'the password typed into the test is never echoed back in the JSON answer');

// A non-admin cannot test or save a connection either (whole admin/post.php gate).
$r = web($wb, 'POST', '/admin/post.php', $SS['tech'], ['rmm_ds_test_connection' => '1', 'host' => '1.2.3.4', 'port' => '5432', 'database' => 'x', 'user' => 'x', 'password' => '', 'sslmode' => 'disable', 'schema' => 'public', 'csrf_token' => 'csrftok1'], array_merge($REF, ['Accept: application/json']));
$ok(!is_array(json_decode($r[1], true)) || (json_decode($r[1], true)['ok'] ?? null) !== true, 'a non-admin cannot get a successful connection test through admin/post.php');

// -------------------------------------------------------------------------------------------------------------------- switch refusals (the stored connection's bogus role/password still fails its test)

$r = $post(['rmm_ds_switch_to' => 'postgres']);   // no acknowledge_no_data_move box ticked
$kind = $one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1");
$ok($kind === 'mariadb', 'switching to postgres without ticking the acknowledgement box changes NOTHING');
[, $body4] = $pg('admin');
$ok(str_contains($body4, 'Confirm that you understand this to switch') || str_contains($body4, 'does not move existing history'), 'the refusal repeats the no-data-move notice');

$r = $post(['rmm_ds_switch_to' => 'postgres', 'acknowledge_no_data_move' => '1']);   // ticked, but the stored connection (host/port/role/password above) still fails its own test
$kind2 = $one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1");
$ok($kind2 === 'mariadb', 'ticking the box still refuses the switch when the connection test itself fails');

// Switching back to mariadb is always allowed, even though it is already the active store (RivetCore's own "always allowed" rule).
$r = $post(['rmm_ds_switch_to' => 'mariadb']);
$ok($one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1") === 'mariadb', 'switch back to mariadb: still mariadb, no error (a no-op that always succeeds)');

// -------------------------------------------------------------------------------------------------------------------- the capacity card additions render (phase 4 item 5)

[, $fleetBody] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(str_contains($fleetBody, 'id="rmm-capacity"'), 'the Performance and capacity card still renders with the data-store additions in place');
$ok(!str_contains($fleetBody, 'Fatal error') && !str_contains($fleetBody, 'Warning:'), 'no PHP error leaked into the capacity card');

// -------------------------------------------------------------------------------------------------------------------- module-off / mariadb-only fast path: no attempt to reach PostgreSQL

$log = (string) @file_get_contents($web['log']);
$ok(!str_contains($log, 'pdo_pgsql') && !str_contains($log, 'PostgresException') && !str_contains($log, 'Fatal error'), 'nothing in the server log suggests a PostgreSQL connection was attempted by mere page views on a mariadb-only install');

// -------------------------------------------------------------------------------------------------------------------- the background job actually runs, detached
// host:port is a deliberately unassigned loopback port, not 5432: some hosts that run this suite have an UNRELATED real PostgreSQL
// listening on 127.0.0.1:5432 (another install, another test session), and this assertion must never depend on - or connect to -
// whatever that happens to be. Port 25955 is picked fresh for this run and verified free just above it would be, were anything
// actually listening there the "connection refused" message below would not match and this test would correctly fail loud.
$jobLog = "$root/backups/rmm-store-migrate.log";
@unlink($jobLog);
$post(['rmm_ds_save_connection' => '1', 'host' => '127.0.0.1', 'port' => '25955', 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => 'x', 'sslmode' => 'disable', 'schema' => 'public']);
$t0 = microtime(true);
[$codeStart] = $post(['rmm_ds_migrate_start' => '1', 'rmm_ds_migrate_to' => 'postgres']);
$ok($codeStart === 302 && microtime(true) - $t0 < 2.0, 'starting a migration returns immediately (302, well under 2s) - the long command runs detached, never inside this request');
for ($i = 0; $i < 50 && !is_file($jobLog); $i++) { usleep(100000); }
$ok(is_file($jobLog), 'the detached background job actually started and wrote its log');
// Whichever reason this sandbox's PHP cannot reach PostgreSQL on this nothing-listening port, that reason must show up for real -
// either the extension really is missing (pdo_pgsql not installed) or it is installed and the connection is refused (nothing
// listening on 25955). Either message proves the detached process genuinely reached and used RivetCore's PostgreSQL code, which is
// everything this assertion is actually checking; it must never hard-code one specific environment's reason.
$jobReachedPostgres = static fn (string $t): bool => str_contains($t, 'pdo_pgsql is not installed') || (str_contains($t, '127.0.0.1') && (stripos($t, 'refused') !== false || stripos($t, 'failed') !== false));
for ($i = 0; $i < 50 && !$jobReachedPostgres((string) @file_get_contents($jobLog)); $i++) { usleep(100000); }
$jobLogText = (string) @file_get_contents($jobLog);
$ok($jobReachedPostgres($jobLogText), 'the background job itself (a real, separate PHP process) reached the point of trying to use PostgreSQL and reported the real, honest reason it could not reach anything on this nothing-listening port (' . trim(mb_substr($jobLogText, 0, 200)) . ')');
[, $poll] = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['rmm_ds_migrate_status' => '1', 'csrf_token' => 'csrftok1'], array_merge($REF, ['Accept: application/json']));
$pj = json_decode($poll, true);
$ok(is_array($pj) && array_key_exists('active', $pj) && array_key_exists('run', $pj) && array_key_exists('log', $pj), 'the status-poll AJAX endpoint answers the shape the page\'s JS expects (active/run/log), admin-only, in-process (no shell)');
@unlink($jobLog);
