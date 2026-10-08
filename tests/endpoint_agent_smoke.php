<?php
/*
 * End-to-end smoke test of the optional RMM module on RivetMSP: enroll -> link -> check-in -> check alert -> MSP ticket -> alert clears -> ticket
 * auto-closes -> signed job -> update download -> offline flip, through the real front controller (php -S) and the real MSP code paths. Also proves
 * the vendor RMM sync / pages leave the built-in agent's integration row alone and that the role matrix holds over HTTP.
 * Same scratch rules and environment as tests/endpoint_agent_lib.php.
 */
$sd = sys_get_temp_dir() . '/ea_smoke_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_functions.php";
require_once "$root/includes/rmm_client_factory.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_rmm_auto_ticket_severities = 'warning,error,critical', config_rmm_auto_close_on_clear = 1 WHERE company_id = 1");
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$r = $rmm->admin()->enable($admin);
$ok($r->ok && $rmm->enabled(), 'the module is switched on (edition flag + master)');
$H = fn(string $t) => $tokens[$t];
$r = $rmm->admin()->saveSettings($admin, ['service_url' => $base]);   // hosted updates and installers are served from the service URL
$ok($r->ok, 'the service URL is set (loopback http is allowed by EA_ALLOW_INSECURE_HTTP in the scratch config)' . ($r->ok ? '' : ' [' . $r->message . ']'));

// ---------------------------------------------------------------- a published agent binary (fake PE, golden-style) so updates have something to serve
function smoke_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}
$tmpPe = sys_get_temp_dir() . '/smoke_pe_' . bin2hex(random_bytes(3)) . '.exe';
file_put_contents($tmpPe, $pe = smoke_pe(0x8664, 4096, 'smoke'));
$pub = $rmm->binaryStore()->publish($tmpPe, '1.1.0', 'amd64', 1, ['activate' => true, 'release_ring' => 'stable', 'rollout_pct' => 100, 'notes' => 'smoke']);
@unlink($tmpPe);
$ok($pub['ok'] === true, 'an agent binary 1.1.0 (amd64) is published and offered on the stable ring' . ($pub['ok'] ? '' : ' [' . ($pub['error'] ?? '?') . ']'));
register_shutdown_function(function () use ($rmm, $db) { $d = $rmm->binaryStore()->storageDir(); foreach ($d === null ? [] : (glob($d . '/bin_*.bin') ?: []) as $f) { if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', basename($f))) { @unlink($f); } } $db->query('DELETE FROM endpoint_agent_binaries'); });

// ---------------------------------------------------------------- enroll: the asset in MSP is matched by serial and linked
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='SMOKE-ASSET', asset_make='Dell', asset_serial='SMOKE-SER-9', asset_client_id=1, asset_status='Active'");
$assetId = (int) $db->insert_id;
$tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 5, 'smoke')->data['token'];
[$c, , $j] = ea_enroll($tok, ea_dev(['hostname' => 'SMOKE-PC', 'serial' => 'SMOKE-SER-9', 'agent_version' => '1.0.0']));
$ok($c === 201 && ($j['status'] ?? '') === 'linked' && (int) ($j['matched_asset_id'] ?? 0) === $assetId, 'enroll: 201, linked to the existing MSP asset by serial number');
$devTok = (string) $j['device_token']; $devId = (int) $j['device_id'];
$link = $rows("SELECT l.*, i.type FROM asset_rmm_links l JOIN rmm_integrations i ON i.id = l.integration_id WHERE l.asset_id = $assetId")[0] ?? [];
$ok(($link['type'] ?? '') === 'rivetit_agent' && $link['tactical_agent_id'] === "rivetit:$devId" && $link['hostname'] === 'SMOKE-PC' && $link['rmm_status'] === 'unknown', 'MSP asset_rmm_links row: integration type rivetit_agent, key rivetit:<device>, status unknown');

// ---------------------------------------------------------------- check-in: the link goes online and carries the health columns
$q("UPDATE asset_rmm_links SET rmm_status_changed_at = '2000-01-01 00:00:00' WHERE asset_id = $assetId");
[$c, , $j] = ea_checkin($devTok, ['inventory' => ['os' => ['name' => 'Windows 11 Pro', 'version' => '23H2'], 'hardware' => ['cpu' => 'i7', 'ram_gb' => 16], 'logged_in_user' => 'jdoe']]);
$ok($c === 200 && ($j['ok'] ?? false) === true, 'check-in: 200');
$link = $rows("SELECT * FROM asset_rmm_links WHERE asset_id = $assetId")[0];
$ok($link['rmm_status'] === 'online' && $link['rmm_status_changed_at'] > '2000-01-02' && (int) $link['rmm_cpu_percent'] === 11 && $link['last_seen'] !== null, 'link: online, status-changed time moved, cpu percent from the check-in (rounded), last seen set');
$met = json_decode((string) $one("SELECT last_metrics_json FROM endpoint_agent_devices WHERE device_id = $devId"), true);
$ok(is_array($met) && isset($met['cpu_pct']), 'the latest metrics stay on the device row (no metrics store in RivetMSP: the null sink keeps nothing)');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'device_metric%'") === 0, 'and no device_metric_* table exists or was created');

// ---------------------------------------------------------------- a failing check opens an rmm_alerts row; MSP auto-ticketing turns it into a ticket
foreach ([1, 2, 3] as $n) { ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 97% used']]]); }
$alert = $rows("SELECT * FROM rmm_alerts WHERE asset_id = $assetId")[0] ?? [];
$ok(($alert['status'] ?? '') === 'new' && (int) $alert['client_id'] === 1 && strpos((string) $alert['tactical_alert_id'], "agent:$devId:disk_c:") === 0 && empty($alert['ticket_id']), 'three failures open one new rmm_alerts row for the asset and client (key agent:<device>:disk_c:<episode>)');
ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 98% used']]]);
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE asset_id = $assetId") === 1, 'a further failure does not open a second alert (one per episode)');
$sevs = explode(',', (string) $one('SELECT config_rmm_auto_ticket_severities FROM settings WHERE company_id = 1'));
$ok(in_array($alert['severity'], $sevs, true), "the alert severity ({$alert['severity']}) is one MSP's cron auto-ticketing picks up");
$tk = createTicketFromRmmAlert($db, $alert, 0, 'RMM Automation');   // exactly what cron/cron.php's auto-ticketing block calls for such a row
$tid = (int) $tk['ticket_id'];
$ok($tid > 0 && $tk['existing'] === false && (int) $one("SELECT ticket_id FROM rmm_alerts WHERE id = {$alert['id']}") === $tid && (int) $one("SELECT ticket_client_id FROM tickets WHERE ticket_id = $tid") === 1, 'MSP creates a ticket from the agent alert for the right client and links it');
$ok(strpos((string) $one("SELECT ticket_details FROM tickets WHERE ticket_id = $tid"), 'SMOKE-PC') !== false, 'the ticket carries the device context from the RMM link');
// ---------------------------------------------------------------- recovery resolves the alert and runs MSP's conservative auto-close of the ticket
ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: 70% used']]]);
ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: 70% used']]]);
$ok((string) $one("SELECT status FROM rmm_alerts WHERE id = {$alert['id']}") === 'resolved' && $one("SELECT resolved_at FROM rmm_alerts WHERE id = {$alert['id']}") !== null, 'two passing check-ins resolve the alert');
$ok($one("SELECT ticket_closed_at FROM tickets WHERE ticket_id = $tid") !== null && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $tid") === 5 && (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tid AND ticket_reply = 'Auto-closed: RMM alert cleared'") === 1, 'the untouched ticket is auto-closed by RmmAssetMapper::autoCloseAlertTicket (status Closed, system note)');
// a second episode, this time a human worked the ticket: it stays open
foreach ([1, 2, 3] as $n) { ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: 99% used']]]); }
$alert2 = $rows("SELECT * FROM rmm_alerts WHERE asset_id = $assetId AND status = 'new'")[0] ?? [];
$ok($alert2 !== [] && $alert2['tactical_alert_id'] !== $alert['tactical_alert_id'], 'a new failure episode opens a NEW alert (the old one stays resolved)');
$tid2 = (int) createTicketFromRmmAlert($db, $alert2, 0, 'RMM Automation')['ticket_id'];
$q("INSERT INTO ticket_replies SET ticket_reply = 'looking into it', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = 1, ticket_reply_ticket_id = $tid2");
ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'ok']]]);
ea_checkin($devTok, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => 'ok']]]);
$ok((string) $one("SELECT status FROM rmm_alerts WHERE id = {$alert2['id']}") === 'resolved' && $one("SELECT ticket_closed_at FROM tickets WHERE ticket_id = $tid2") === null, 'when a person worked the ticket, the alert resolves but the ticket is left open (the conservative rule)');

// ---------------------------------------------------------------- a signed job over the technician REST API, then the device reports it
[$c, , $j] = http('POST', "/api/v1/endpoint_devices/$devId/jobs", $H('admin'), ['type' => 'reboot', 'confirm' => true]);
$ok(in_array($c, [200, 201, 202], true) && !empty($j['job_id'] ?? ($j['data']['job_id'] ?? null)), 'technician API: a reboot job is queued (' . $c . ')');
$jobId = (string) ($j['job_id'] ?? ($j['data']['job_id'] ?? ''));
[$c, , $j] = http('POST', "/api/v1/endpoint_devices/$devId/jobs", $H('viewer'), ['type' => 'reboot', 'confirm' => true]);
$ok($c === 403, 'technician API: a viewer (module_rmm 1) may not queue a job (403)');
[$c, , $j] = http('POST', "/api/v1/endpoint_devices/$devId/jobs", $H('clientb'), ['type' => 'reboot', 'confirm' => true]);
$ok($c === 404, 'technician API: a technician restricted to another client gets 404 for the device');
[$c, , $j] = http('GET', '/api/v1/agent_jobs', $devTok);
$offered = $j['jobs'][0] ?? null;
$ok($c === 200 && is_array($offered) && ($offered['job_id'] ?? '') === $jobId && !empty($offered['signature']), 'device: the job is offered with an Ed25519 signature');
$sig = base64_decode((string) $offered['signature'], true);
$msg = $offered; unset($msg['signature']);
$pk = base64_decode((string) $one('SELECT signing_public_key FROM endpoint_agent_settings WHERE id = 1'), true);
$ok($sig !== false && $pk !== false && sodium_crypto_sign_verify_detached($sig, \RivetCore\Rmm\Crypto\CanonicalJson::encode($msg), $pk), 'the signature verifies against the instance public key over the canonical job JSON');
[$c, , $j] = http('POST', '/api/v1/agent_jobs', $devTok, ['job_id' => $jobId, 'attempt' => 1, 'state' => 'running']);
[$c, , $j] = http('POST', '/api/v1/agent_jobs', $devTok, ['job_id' => $jobId, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'rebooting']);
$ok($c === 200 && (string) $one("SELECT state FROM endpoint_agent_jobs WHERE job_id = '$jobId'") === 'succeeded', 'device: the job report is accepted and the job is succeeded');

// ---------------------------------------------------------------- update: the manifest in the check-in, then the download
[$c, , $j] = ea_checkin($devTok, ['agent_version' => '1.0.0']);
$up = $j['update'] ?? null;
$ok($c === 200 && is_array($up) && ($up['version'] ?? '') === '1.1.0' && !empty($up['signature']), 'check-in: the device on 1.0.0 is offered 1.1.0 with a signed manifest');
[$c, $h, , $raw] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $devTok);
$ok($c === 200 && stripos((string) ($h['content-type'][0] ?? ''), 'application/octet-stream') === 0 && $raw === $pe && hash('sha256', $raw) === ($up['sha256'] ?? ''), 'update download: 200 application/octet-stream, the exact published bytes, SHA-256 equal to the manifest');

// ---------------------------------------------------------------- installer download (token gated, stamped per client)
$tok2 = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 5, 'installer')->data['token'];
[$c, $h, , $raw] = http('POST', '/api/v1/agent_installer', null, ['token' => $tok2, 'arch' => 'amd64']);
$ok($c === 200 && stripos((string) ($h['content-type'][0] ?? ''), 'application/octet-stream') === 0 && strncmp($raw, $pe, strlen($pe)) === 0 && strpos($raw, 'RIVETIT-EMBED-v1') !== false && strpos($raw, 'Client A') !== false, 'installer: the current binary with the stamp trailer carrying the MSP client name');

// ---------------------------------------------------------------- the asset page link and the device page (web, forged admin session)
$sdir = sys_get_temp_dir() . '/ea_ssessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = ea_forge_session($sdir, 1);
$q('UPDATE settings SET config_module_enable_rmm = 0 WHERE company_id = 1');
[$c, $b] = web($wb, 'GET', "/agent/asset_details.php?asset_id=$assetId", $sid);
$ok($c === 200 && strpos($b, 'rmm_agent_device.php?device_id=' . $devId) !== false && strpos($b, 'SMOKE-PC') !== false, 'asset page (vendor RMM integrations OFF, agent module ON): the RMM card and the Agent device link are there');
[$c, $b] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$devId", $sid);
$ok($c === 200 && strpos($b, 'SMOKE-PC') !== false && strpos($b, 'disk_c') !== false, 'device page: renders the device and its check');
$q('UPDATE settings SET config_module_enable_rmm = 1 WHERE company_id = 1');
[$c, $b] = web($wb, 'GET', "/agent/asset_details.php?asset_id=$assetId", $sid);
$ok($c === 200 && strpos($b, 'rmm_agent_device.php?device_id=' . $devId) !== false, 'asset page (both on): still shows the Agent device link');
$q('UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1'); $rmm->syncState();
[$c, $b] = web($wb, 'GET', "/agent/asset_details.php?asset_id=$assetId", $sid);
$ok($c === 200 && strpos($b, 'rmm_agent_device.php') === false, 'asset page (edition flag OFF): the Agent device link is gone');
$q('UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1'); $rmm->syncState();

// device page actions (agent/post/rmm_agent.php, a JSON endpoint): the same TechnicianActions as the REST API, CSRF-checked, authorized server-side
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_agent.php', $sid, ['csrf_token' => 'csrftok1', 'action' => 'submit_job', 'device_id' => $devId, 'type' => 'collect']);
$jj = json_decode($b, true);
$ok($c === 200 && ($jj['success'] ?? false) === true && !empty($jj['job_id']), 'device page action: an administrator queues a collect job');
$sidViewer = ea_forge_session($sdir, 12);
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_agent.php', $sidViewer, ['csrf_token' => 'csrftok1', 'action' => 'submit_job', 'device_id' => $devId, 'type' => 'collect']);
$ok($c === 403 && (json_decode($b, true)['success'] ?? true) === false, 'device page action: a viewer is refused (403), hiding the button is only cosmetic');
$sidB = ea_forge_session($sdir, 16);
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_agent.php', $sidB, ['csrf_token' => 'csrftok1', 'action' => 'submit_job', 'device_id' => $devId, 'type' => 'collect']);
$ok($c === 404, 'device page action: a user restricted to another client gets 404 (the device does not exist for them)');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_agent.php', $sid, ['csrf_token' => 'wrong', 'action' => 'submit_job', 'device_id' => $devId, 'type' => 'collect']);
$ok($c !== 200 || (json_decode($b, true)['success'] ?? false) !== true, 'device page action: a wrong CSRF token does nothing');

// ---------------------------------------------------------------- vendor RMM code leaves the built-in agent's integration alone
$intg = (int) $one("SELECT id FROM rmm_integrations WHERE type = 'rivetit_agent'");
$cronSrc = (string) file_get_contents("$root/cron/cron.php");
$ok(preg_match('/\$sql_rmm_integrations = mysqli_query\(\$mysqli, "(SELECT id, name FROM rmm_integrations WHERE [^"]+)"\);/', $cronSrc, $m) === 1, 'cron/cron.php: the vendor RMM sync loop query is there');
$cronRows = $rows($m[1] ?? 'SELECT 1 WHERE 0');
$ok(!in_array($intg, array_map('intval', array_column($cronRows, 'id')), true), "the query cron/cron.php runs for the vendor sync does not return the built-in agent's integration (it returns " . count($cronRows) . ' row(s))');
try { getRmmClient($intg); $ok(false, 'getRmmClient() refuses the agent integration'); } catch (\RuntimeException $e) { $ok(strpos($e->getMessage(), 'device page') !== false, 'getRmmClient() refuses the agent integration with a clear message'); }
[$c, $b] = web($wb, 'GET', '/agent/rmm_dashboard.php', $sid);
$ok($c === 200 && strpos($b, 'RivetIT Endpoint Agent') === false, 'the RMM dashboard (sync target selector) does not offer the agent integration');
[$c, $b] = web($wb, 'GET', '/admin/settings_integrations.php', $sid);
$ok($c === 200 && strpos($b, 'RivetIT Endpoint Agent') === false, 'Administration > Integrations does not list (or let anyone edit/delete) the agent integration');
[$c, $b] = web($wb, 'GET', '/agent/rmm_checks.php', $sid);
$ok($c === 200 && strpos($b, 'RivetIT Endpoint Agent') === false, 'the RMM checks page does not offer the agent integration');

// ---------------------------------------------------------------- offline flip: housekeeping marks the link offline and moves the status-changed time
$q("UPDATE asset_rmm_links SET rmm_status_changed_at = '2000-01-01 00:00:00' WHERE asset_id = $assetId");
$q("UPDATE endpoint_agent_devices SET last_checkin_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE device_id = $devId");
$stats = rivetRmmHousekeeping($db);
$link = $rows("SELECT * FROM asset_rmm_links WHERE asset_id = $assetId")[0];
$ok($link['rmm_status'] === 'offline' && $link['rmm_status_changed_at'] > '2000-01-02', 'offline flip: a device quiet for 2 hours is marked offline and rmm_status_changed_at moves (this feeds the asset_offline automation)');
[$c] = ea_checkin($devTok);
$link = $rows("SELECT * FROM asset_rmm_links WHERE asset_id = $assetId")[0];
$ok($c === 200 && $link['rmm_status'] === 'online', 'and the next check-in brings it back online');

// ---------------------------------------------------------------- the RMM-wide switch off: everything stops, nothing is lost
$counts = [(int) $one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $one('SELECT COUNT(*) FROM rmm_alerts'), (int) $one('SELECT COUNT(*) FROM asset_rmm_links')];
$q('UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1'); $rmm->syncState();
[$c, $h] = http('POST', '/api/v1/agent_checkin', $devTok, ['seq' => 500, 'collected_at' => ea_ts()]);
$ok($c === 503 && ($h['retry-after'][0] ?? '') === '3600', 'edition flag OFF: the enrolled agent is told to back off (503, Retry-After 3600)');
$ok($counts === [(int) $one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $one('SELECT COUNT(*) FROM rmm_alerts'), (int) $one('SELECT COUNT(*) FROM asset_rmm_links')], 'and no row was lost');
$q('UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1'); $rmm->syncState();
[$c] = ea_checkin($devTok);
$ok($c === 200, 'switched back on: the same credential checks in again');
