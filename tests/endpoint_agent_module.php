<?php
/*
 * The optional RMM module's switch and its edges, on a scratch database with the real front controller (php -S):
 *   - a fresh RivetMSP install is OFF (settings.config_core_rmm_enabled = 0, endpoint_agent_settings.enabled = 0); the 2.6.77 updater step leaves
 *     an upgraded install OFF and equal in shape to a fresh one, and never turns it on
 *   - module OFF: the five device endpoints answer 503 module_disabled (Retry-After 3600, no-store) and endpoint_devices 404 disabled FROM THE STATE
 *     FILE ALONE: zero database connections and zero statements (counted on the server), before config.php is even loaded
 *   - the module is on only when BOTH switches are on (edition flag and master); either one off turns every device endpoint away
 *   - disabling and re-enabling keeps every row; enrolled devices carry on with the same credential
 *   - a missing or damaged state file means "unknown", never "off"; the cron housekeeping corrects a stale file
 *   - the administration page switches both together, refuses to switch on without $config_settings_enc_key, and the device page follows the switch
 * Same scratch rules and environment as tests/endpoint_agent_lib.php (a throwaway MariaDB and Redis; this file also creates and drops scratch databases).
 */
$sd = sys_get_temp_dir() . '/ea_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");   // config.php of the scratch install turns this into RMM_STATE_DIR
putenv("RMM_GATE_STATE_DIR=$sd");   // the pre-bootstrap gate reads the environment
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\RmmStateFile;

ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$stateFile = RmmStateFile::path($sd);
$state = fn() => RmmStateFile::read($sd);
$counters = function () use ($db): array {
    $o = [];
    foreach ($db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Connections')") as $r) { $o[$r['Variable_name']] = (int) $r['Value']; }
    return $o;
};
$setEdition = function (int $v) use ($q, $rmm): void { $q("UPDATE settings SET config_core_rmm_enabled = $v WHERE company_id = 1"); $rmm->syncState(); };

// ============================================================ default: off
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings WHERE id=1') === 0 && (int) $one('SELECT config_core_rmm_enabled FROM settings WHERE company_id=1') === 0, 'after the reset both switches are off (the column defaults)');
$rmm->syncState();
$ok($rmm->enabled() === false && rivetRmmEnabled($db) === false, 'rivetRmmEnabled() and RmmModule::enabled() say off');
$ok($state() !== null && $state()['enabled'] === false && $state()['master'] === false && $state()['edition'] === false, 'the state file exists and records the module as off');
$ok(substr(sprintf('%o', fileperms($stateFile)), -4) === '0640', 'the state file is mode 0640');
$GLOBALS['config_core_rmm_enabled'] = 0;
$sdBefore = $state();
$ok(rivetRmmEnabled($db) === false, 'with the edition flag off in memory (load_global_settings.php) rivetRmmEnabled() is false without a file read or a query');

// ============================================================ OFF: the gate answers with zero database work
$hdr = fn(array $h, string $n) => $h[$n][0] ?? null;
$deviceCalls = [['POST', '/api/v1/agent_enroll'], ['POST', '/api/v1/agent_checkin'], ['GET', '/api/v1/agent_jobs'], ['POST', '/api/v1/agent_jobs'], ['GET', '/api/v1/agent_update?arch=amd64&version=1.0.0'], ['POST', '/api/v1/agent_installer']];
$bodyOff = '{"error":"The RMM service is disabled on this server.","code":"module_disabled"}';
$c0 = $counters(); $c1 = $counters(); $overhead = $c1['Questions'] - $c0['Questions'];   // what reading the counters itself costs
$before = $counters();
foreach ($deviceCalls as [$m, $path]) {
    [$c, $h, , $raw] = http($m, $path, str_repeat('a', 64), $m === 'POST' ? ['x' => 1] : null);
    $ok($c === 503 && $raw === $bodyOff && $hdr($h, 'retry-after') === '3600' && $hdr($h, 'cache-control') === 'no-store' && stripos((string) $hdr($h, 'content-type'), 'application/json') === 0, "OFF: $m " . strtok($path, '?') . ' -> 503 module_disabled, Retry-After 3600, no-store');
}
[$c, , , $raw] = http('GET', '/api/v1/agent_checkin.php');
$ok($c === 503, 'OFF: the legacy .php spelling of the URL is gated too');
$after = $counters();
$ok($after['Connections'] === $before['Connections'], 'OFF: ' . (count($deviceCalls) + 1) . ' requests opened zero database connections');
$ok($overhead === 1 && $after['Questions'] - $before['Questions'] === $overhead, 'OFF: and ran zero statements on the server (the counter moved only by the one read of the counter itself)');
[$c] = http('GET', '/api/v1/tickets'); $ok($c === 401, 'OFF: other API endpoints are untouched (401 without a token)');
[$c, , $j] = http('GET', '/api/v1/endpoint_devices'); $ok($c === 401, 'OFF: endpoint_devices authenticates first (401 without a token), so an anonymous caller learns nothing about the module');

// ============================================================ the two switches
function Enrollment_token($rmm, $admin): string
{
    $r = $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'module test');
    return (string) $r->data['token'];
}

// Master on, edition flag still off: still OFF. (Switching the master on mints the signing key through the secret box, so it needs $config_settings_enc_key.)
$ok(\RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox::keyConfigured(), 'the scratch config has the settings encryption key (the module cannot be switched on without it)');
$r = $rmm->admin()->enable($admin);
$ok($r->ok && (int) $one('SELECT enabled FROM endpoint_agent_settings WHERE id=1') === 1, 'RmmAdmin::enable() sets the master switch');
$ok($rmm->enabled() === false && $state()['enabled'] === false && $state()['master'] === true && $state()['edition'] === false, 'master ON + edition flag OFF = module OFF (the state file says so)');
[$c, , , $raw] = http('POST', '/api/v1/agent_checkin', str_repeat('a', 64), ['x' => 1]);
$ok($c === 503 && $raw === $bodyOff, 'master ON + edition flag OFF: device endpoints still answer 503 module_disabled');
$ok(rivetRmmHousekeeping($db) === [], 'and cron housekeeping does nothing');
$setEdition(1);
$ok($rmm->enabled() === true && $state()['enabled'] === true, 'edition flag ON + master ON = module ON');
$setEdition(0);
$ok($rmm->enabled() === false && $state()['enabled'] === false, 'the edition flag is a kill switch: off again, the state file follows after syncState()');
// no state file at all (a fresh install before the first cron tick): the module is still off, answered by DeviceApi itself with the same 503
$setEdition(0);
unlink($stateFile);
[$c, $h, , $raw] = http('POST', '/api/v1/agent_checkin', str_repeat('a', 64), ['x' => 1]);
$ok($c === 503 && $raw === $bodyOff && $hdr($h, 'retry-after') === '3600', 'OFF with no state file: DeviceApi answers 503 module_disabled after the database lookup (the gate only skips the lookup)');
[$c, , , $raw] = http('POST', '/api/v1/agent_enroll', null, ['enrollment_token' => 'rvte1.aaaaaaaaaaaa.' . str_repeat('b', 40), 'device' => []]);
$ok($c === 503 && $raw === $bodyOff, 'OFF with no state file: enrollment is refused with the same 503');
$setEdition(1);

// ============================================================ ON: enrollment, check-in, counters move
$ok((string) $one('SELECT signing_public_key FROM endpoint_agent_settings') !== '' && (string) $one('SELECT signing_private_key_enc FROM endpoint_agent_settings') !== '', 'the first switch-on minted the signing key');
$ok(strncmp((string) $one('SELECT signing_private_key_enc FROM endpoint_agent_settings'), 'ENC2:', 5) === 0, 'the private signing key is stored as an ENC2: ciphertext, never plaintext');
$ok((int) $one("SELECT COUNT(*) FROM rmm_integrations WHERE type='rivetit_agent'") === 1, 'the synthetic rmm_integrations row (type rivetit_agent) exists');
$tok = Enrollment_token($rmm, $admin);
[$c, , $j] = ea_enroll($tok, ea_dev(['hostname' => 'MODULE-PC', 'serial' => 'MOD-SER-1']));
$ok($c === 201 && isset($j['device_token']), 'ON: enrollment works (201)');
$devTok = $j['device_token']; $devId = (int) $j['device_id'];
$qb = $counters();
[$c, , $j] = ea_checkin($devTok);
$qa = $counters();
$ok($c === 200 && ($j['ok'] ?? false) === true, 'ON: check-in works (200)');
$ok($qa['Connections'] > $qb['Connections'] && $qa['Questions'] - $qb['Questions'] > 5, 'ON: the same counters do move for a real request (the zero above is a measurement, not a blind spot)');
$rowsBefore = [(int) $one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_checkins')];

// ============================================================ switch off again: nothing is lost, agents back off, same credential works after
$r = $rmm->admin()->disable($admin);
$ok($r->ok && (int) $one('SELECT enabled FROM endpoint_agent_settings WHERE id=1') === 0 && $state()['enabled'] === false, 'RmmAdmin::disable() switches it off and rewrites the state file');
[$c, $h, $j] = http('POST', '/api/v1/agent_checkin', $devTok, ['seq' => 99, 'collected_at' => ea_ts()]);
$ok($c === 503 && $hdr($h, 'retry-after') === '3600', 'OFF: an enrolled agent is told to back off (503, Retry-After 3600), not refused with 403');
$ok([(int) $one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_checkins')] === $rowsBefore, 'disabling deleted nothing');
$ok(!$rmm->authorizer()->allowed(1, \RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW, 0), 'OFF: the technician side is denied (not enabled)');
$ok(rivetRmmHousekeeping($db) === [], 'OFF: cron housekeeping does nothing and loads nothing');
$rmm->admin()->enable($admin);
[$c, , $j] = ea_checkin($devTok);
$ok($c === 200 && ($j['ok'] ?? false) === true, 'ON again: the same device credential checks in (200); no re-enrollment needed');

// ============================================================ the technician REST endpoint: authentication first, then the module
[$c, , $j] = http('GET', '/api/v1/endpoint_devices', $tokens['admin']);
$ok($c === 200 && isset($j['data']), 'ON: endpoint_devices lists devices for an administrator');
[$c, , $j] = http('GET', '/api/v1/endpoint_devices', $tokens['stock']);
$ok($c === 403, 'ON: a stock technician (no rmm module rows on a fresh install) is refused (403): only administrators hold RMM until a role is granted it');
[$c, , $j] = http('GET', '/api/v1/endpoint_devices', $tokens['tech']);
$ok($c === 200 && count($j['data'] ?? []) === 1, 'ON: a technician whose role has module_rmm sees the device');
[$c, , $j] = http('GET', '/api/v1/endpoint_devices', $tokens['clientb']);
$ok($c === 200 && ($j['data'] ?? null) === [], 'ON: a technician restricted to Client B sees no device of Client A');
[$c] = http('GET', "/api/v1/endpoint_devices/$devId", $tokens['clientb']);
$ok($c === 404, 'ON: and gets the same 404 as for a device that does not exist');
$q("INSERT INTO api_tokens SET token_user_id=1, token_hash='" . hash('sha256', 'legacy-not-used') . "', token_created_at=NOW()");
$q("INSERT INTO api_keys SET api_key_name='k', api_key_secret='" . hash('sha256', 'legacykey123') . "', api_key_expire=DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_permission='write', api_key_client_id=0");
[$c, , $j] = http('GET', '/api/v1/endpoint_devices', null, null, ['X-Api-Key: legacykey123']);
$ok($c === 403, 'ON: the instance-wide legacy X-Api-Key is refused by endpoint_devices (403)');
$rmm->admin()->disable($admin);
[$c, , $j] = http('GET', '/api/v1/endpoint_devices', $tokens['admin']);
$ok($c === 404 && ($j['code'] ?? '') === 'disabled', 'OFF: an authenticated caller gets 404 disabled from endpoint_devices');
$rmm->admin()->enable($admin);

// ============================================================ the state file is a cache: unknown is never off
file_put_contents($stateFile, '{not json');
[$c, , $j] = ea_checkin($devTok);
$ok($c === 200, 'a garbled state file is "unknown": the request proceeds (200)');
unlink($stateFile);
[$c, , $j] = ea_checkin($devTok);
$ok($c === 200, 'a missing state file is "unknown": the request proceeds (200)');
file_put_contents($stateFile, json_encode(['v' => 99, 'enabled' => false]));
[$c] = ea_checkin($devTok);
$ok($c === 200, 'a state file of another schema version is "unknown": the request proceeds (200)');
// a stale OFF verdict (e.g. a backup restored over the database) is corrected by the next cron tick
$rmm->syncState();
$s = $state(); $s['enabled'] = false; $s['master'] = false; unset($s['v'], $s['written_at']);
RmmStateFile::write($sd, $s, time() + 7200);
$ok($state()['enabled'] === false && (int) $one('SELECT enabled FROM endpoint_agent_settings') === 1, 'precondition: the file says off while the database says on');
[$c] = ea_checkin($devTok); $ok($c === 503, 'a stale OFF file does gate requests until it is corrected (documented trade-off of the fast path)');
$out = rivetRmmHousekeeping($db);
$ok($state()['enabled'] === true && is_array($out), 'the cron housekeeping notices the mismatch and rewrites the file');
[$c] = ea_checkin($devTok); $ok($c === 200, 'and the next request passes');

// ============================================================ pages
$sdir = sys_get_temp_dir() . '/ea_msessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = ea_forge_session($sdir, 1);
$post = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$rmm->admin()->disable($admin); $q('UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1'); $rmm->syncState();
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'Switch the RMM module on') !== false && strpos($b, 'off by default') !== false, 'OFF: the administration page stays reachable and offers the switch');
[$c, $b] = web($wb, 'GET', '/admin/settings.php', $sid);
$ok($c === 200 && strpos($b, 'settings_endpoint_agent.php') !== false, 'the Endpoint agent tile is in the settings directory (the switch lives there)');
[$c, $b] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$devId", $sid);
$ok($c === 200 && strpos($b, 'MODULE-PC') === false && stripos(html_entity_decode(strip_tags($b)), 'turned off') !== false, 'OFF: the device page shows the module-off notice and none of the device');
[$c, $b] = web($wb, 'POST', '/admin/post.php', $sid, ['csrf_token' => 'csrftok1', 'rmm_module_switch' => 'on'], $post);
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings') === 1 && (int) $one('SELECT config_core_rmm_enabled FROM settings WHERE company_id=1') === 1 && $state()['enabled'] === true, 'the page switch turns BOTH switches on (database and state file)');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'Switch the RMM module off') !== false, 'ON: the page offers to switch it off');
[$c, $b] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$devId", $sid);
$ok($c === 200 && strpos($b, 'MODULE-PC') !== false, 'ON: the device page renders');
$assetId = (int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = $devId");
if ($assetId === 0) { $assetId = (int) $one("SELECT asset_id FROM asset_rmm_links LIMIT 1"); }
if ($assetId === 0) {   // the module test's device may be unlinked: give it an asset to show
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='ASSET-NAME-CHECK', asset_client_id=1, asset_status='Active', asset_created_at=NOW()"); $assetId = (int) $db->insert_id;
    $q("UPDATE endpoint_agent_devices SET asset_id = $assetId WHERE device_id = $devId");
}
if ($assetId > 0) {
    // asset_name comes from RmmReadModel::listDevices() through EndpointAssets::assetNames() (RivetCore 1.0.0-rc.5), not from a query of the page's own
    $assetNm = (string) $one("SELECT asset_name FROM assets WHERE asset_id = $assetId");
    [$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
    $ok($c === 200 && $assetNm !== '' && strpos($b, '>' . htmlspecialchars($assetNm) . '</a>') !== false, 'the administration page lists the device with its asset name (batched lookup through the assets adapter)');
} else { $ok(false, 'no asset linked to the device to check the asset name on the administration page'); }
web($wb, 'POST', '/admin/post.php', $sid, ['csrf_token' => 'csrftok1', 'rmm_module_switch' => 'off'], $post);
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings') === 0 && (int) $one('SELECT config_core_rmm_enabled FROM settings WHERE company_id=1') === 0 && $state()['enabled'] === false, 'the page switch turns both off again');
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_devices') === 1, 'and nothing was deleted');

// RivetCore 1.0.0-rc.5: when sealing a NEW signing key fails (no $config_settings_enc_key) RmmAdmin::enable() answers a failed result instead of throwing,
// and nothing is switched on or written. (The page's own guard still covers the case where a sealed key already exists.)
$savedKey = $GLOBALS['config_settings_enc_key'] ?? ''; $savedEnc = $one('SELECT signing_private_key_enc FROM endpoint_agent_settings'); $savedPub = $one('SELECT signing_public_key FROM endpoint_agent_settings');
$q("UPDATE endpoint_agent_settings SET enabled = 0, signing_private_key_enc = NULL, signing_public_key = ''"); $GLOBALS['config_settings_enc_key'] = '';
$rmmFresh = rivetRmmModule();
$res = $rmmFresh->admin()->enable($admin);
$ok(!$res->ok && $res->http === 500 && $res->code === 'secret_box_unavailable' && (int) $one('SELECT enabled FROM endpoint_agent_settings') === 0 && (string) $one("SELECT COALESCE(signing_private_key_enc, '') FROM endpoint_agent_settings") === '', 'RmmAdmin::enable() without the encryption key: failed result (500 secret_box_unavailable), module stays off, no key written');
$GLOBALS['config_settings_enc_key'] = $savedKey; $q("UPDATE endpoint_agent_settings SET signing_private_key_enc = " . ($savedEnc === null ? 'NULL' : "'" . $esc((string) $savedEnc) . "'") . ", signing_public_key = '" . $esc((string) $savedPub) . "'");

// the switch needs the settings encryption key
$webNoKey = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd, 'RMM_TEST_NO_ENC_KEY' => '1'], ["session.save_path=$sdir"]);
$wnb = "http://127.0.0.1:{$webNoKey['port']}";
[$c, $b] = web($wnb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'settings encryption key is not set') !== false && preg_match('/name="rmm_module_switch" value="on"[^>]*disabled/', $b) === 1, 'no $config_settings_enc_key: the page says so and the switch-on button is disabled');
web($wnb, 'POST', '/admin/post.php', $sid, ['csrf_token' => 'csrftok1', 'rmm_module_switch' => 'on'], ['Referer: ' . $wnb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings') === 0 && (int) $one('SELECT config_core_rmm_enabled FROM settings WHERE company_id=1') === 0 && (string) $one('SELECT COALESCE(signing_private_key_enc, \'\') FROM endpoint_agent_settings') !== 'x', 'and a forged POST cannot switch it on either (both switches stay off)');
$ok(!preg_match('/^(?!ENC2?:).+$/', (string) $one('SELECT COALESCE(signing_private_key_enc, \'\') FROM endpoint_agent_settings')), 'no plaintext key is ever stored');

// ============================================================ fresh install vs upgraded install
$mk = function (string $suffix, bool $withRmm) use ($db, $root): array {
    $name = getenv('RIVETMSP_TEST_DB_NAME') . '_' . $suffix . bin2hex(random_bytes(2));
    $db->query("DROP DATABASE IF EXISTS `$name`"); $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $c = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), $name);
    $c->query("SET SESSION sql_mode=''"); $c->query('SET FOREIGN_KEY_CHECKS=0');
    $sql = (string) file_get_contents("$root/db.sql");
    if (!$withRmm) {
        // The 2.6.76 shape: db.sql without the RMM module (its ten tables, the settings flag, the singleton row and the three ledger rows).
        $sql = (string) preg_replace('/DROP TABLE IF EXISTS `endpoint_agent_[a-z_]+`;\n.*?\/\*!40101 SET character_set_client = @saved_cs_client \*\/;\n/s', '', $sql);
        $sql = str_replace("  `config_core_rmm_enabled` tinyint(1) NOT NULL DEFAULT 0,\n", '', $sql);
        $sql = preg_replace("/INSERT INTO `rivet_core_migrations` VALUES \('00(14|15|16)_[a-z_]+','[^']+'\);\n/", '', $sql);
        $sql = preg_replace('/INSERT IGNORE INTO `endpoint_agent_settings` \(`id`\) VALUES \(1\);\n/', '', $sql);
    }
    $c->multi_query($sql);
    do { if ($r = $c->store_result()) { $r->free(); } } while ($c->more_results() && $c->next_result());
    return [$name, $c];
};
$tableCount = fn(mysqli $c) => (int) $c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'endpoint\_agent\_%'")->fetch_row()[0];
$shape = function (mysqli $c): array {
    $o = [];
    foreach ($c->query("SELECT CONCAT_WS('|', table_name, column_name, column_type, is_nullable, IFNULL(column_default,'NULL'), extra, IFNULL(collation_name,'')) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1") as $r) { $o[] = array_values($r)[0]; }
    foreach ($c->query("SELECT CONCAT_WS('|', table_name, index_name, non_unique, seq_in_index, column_name, IFNULL(sub_part,'')) FROM information_schema.statistics WHERE table_schema = DATABASE() ORDER BY 1") as $r) { $o[] = array_values($r)[0]; }
    return $o;
};
[$fresh, $cf] = $mk('fresh', true);
$ok($tableCount($cf) === 10, 'FRESH install (db.sql): the ten endpoint_agent_* tables exist');
$ok((int) $cf->query('SELECT COUNT(*) FROM endpoint_agent_settings')->fetch_row()[0] === 1 && (int) $cf->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 0, 'FRESH install: one settings row, master switch off');
$ok((int) $cf->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name = 'config_core_rmm_enabled' AND column_default = '0' AND is_nullable = 'NO'")->fetch_row()[0] === 1, 'FRESH install: settings.config_core_rmm_enabled exists and defaults to 0');
$cf->query("INSERT INTO companies SET company_id = 1, company_name = 'x'"); $cf->query("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.77'");
$ok((int) $cf->query('SELECT config_core_rmm_enabled FROM settings WHERE company_id=1')->fetch_row()[0] === 0, 'FRESH install: a new settings row has the edition flag off');
$ok((int) $cf->query("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id IN ('0014_endpoint_agent_core','0015_endpoint_agent_converge','0016_rmm_module_switches')")->fetch_row()[0] === 3, 'FRESH install: the three RMM migrations are recorded (tables and ledger rows travel together)');
(new \RivetCore\Migration\MigrationRunner(new \RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter($cf), \RivetCore\Migration\CoreMigrations::all(), new \RivetCore\Support\SystemClock()))->run();
$ok($tableCount($cf) === 10 && (int) $cf->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 0, 'FRESH install: running the Core migrations again changes nothing (still off)');

// the real updater (scripts/update_cli.php --update_db) on a 2.6.76-shaped install
[$up, $cu] = $mk('upgrade', false);
$ok($tableCount($cu) === 0, 'UPGRADE precondition: the 2.6.76-shaped database has no RMM tables');
$cu->query("INSERT INTO companies SET company_id = 1, company_name = 'x'"); $cu->query("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.76'");
$ok((int) $cu->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name = 'config_core_rmm_enabled'")->fetch_row()[0] === 0, 'UPGRADE precondition: no config_core_rmm_enabled column yet');
$run = proc_open([PHP_BINARY, "$root/scripts/update_cli.php", '--update_db'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$root/scripts", array_merge(getenv(), ['RMM_TEST_DATABASE' => $up]));
$updOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); proc_close($run);
// Later migrations (security 2.6.78, mail intake 2.6.79, recovery 2.6.80, ...) follow 2.6.77 in the same pass, so the pin is "at least 2.6.77", not "exactly 2.6.77".
$ok(strpos($updOut, 'from version 2.6.76') !== false, 'UPGRADE: update_cli --update_db reports the update from 2.6.76');
$ok(preg_match_all('/2\.6\.(\d+)/', $updOut, $um) > 0 && max(array_map('intval', $um[1])) >= 77, 'UPGRADE: update_cli --update_db reports 2.6.77 or later');
$ok(version_compare((string) $cu->query('SELECT config_current_database_version FROM settings WHERE company_id=1')->fetch_row()[0], '2.6.77', '>='), 'UPGRADE: the database is at 2.6.77 or later');
$ok($tableCount($cu) === 10 && (int) $cu->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 0 && (int) $cu->query('SELECT config_core_rmm_enabled FROM settings WHERE company_id=1')->fetch_row()[0] === 0, 'UPGRADE: the ten tables exist and the module is OFF (neither switch is turned on by the update)');
$ok($shape($cf) === $shape($cu), 'UPGRADE: the upgraded schema equals a fresh install from db.sql (' . count($shape($cu)) . ' column/index facts compared)');
$ok(is_file($sd . '/rmm_state.json') && ($state()['enabled'] ?? null) === false, 'UPGRADE: the updater wrote a state file that says disabled (the gate answers without the database from the first request)');
$cu->query('UPDATE endpoint_agent_settings SET enabled = 1 WHERE id = 1');
(new \RivetCore\Migration\MigrationRunner(new \RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter($cu), \RivetCore\Migration\CoreMigrations::all(), new \RivetCore\Support\SystemClock()))->run();
$ok((int) $cu->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 1, 'a repeat of the migrations never touches the master switch (an installed, enabled module stays enabled)');
foreach ([[$cf, $fresh], [$cu, $up]] as [$c, $n]) { $c->close(); $db->query("DROP DATABASE `$n`"); }
$q('UPDATE endpoint_agent_settings SET enabled = 0 WHERE id = 1');
$q('UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1');
$rmm->syncState();
