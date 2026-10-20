<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * RivetCore rc.25 adoption (26.10.38, DB 2.6.102), on a SCRATCH database (same rules as tests/endpoint_agent_lib.php):
 * migrations 0030-0032 and their replay, db.sql parity, the stored-schedule os upgrade, per-OS schedule builder, reconcile + alert resolution (no ticket), the cleanup button
 * (CSRF, admin only, audited, idempotent), the clock notice (escaping), metrics_extended, change_monitor switch + Changes tab, the agent_changes route, the menu, "Allow Linux devices to enroll".
 */
$sd = sys_get_temp_dir() . '/rmm_rc25_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd"); putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php"; require_once "$root/includes/database_version.php";
require_once "$root/includes/rmm_checks_simple.php";
require_once "$root/includes/rmm_ui_render.php";
use RivetCore\Rmm\Authz\RmmPrincipal;
register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset(); $tokens = ea_seed_users();
$rmm = rivetRmmModule($db); $admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on'); $rmm->syncState();
$sdir = sys_get_temp_dir() . '/rmm_rc25_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');
$sidA = ea_forge_session($sdir, 1); $sidT = ea_forge_session($sdir, 10);
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$post = fn (array $f, string $sid = null, string $csrf = 'csrftok1') => web($wb, 'POST', '/admin/post.php', $sid ?? $sidA, $f + ['csrf_token' => $csrf], $REF);

echo "\n--- migrations, db.sql parity ---\n";
foreach (['0030_rmm_change_monitor', '0031_rmm_clock_skew', '0032_rmm_check_set'] as $m) { $ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id = '$m'") === 1, "migration $m applied once"); }
foreach (['endpoint_agent_change_records', 'endpoint_agent_change_rules', 'endpoint_agent_change_settings', 'endpoint_agent_change_state', 'rmm_device_checkset'] as $t) {
    $mine = (string) ($rows("SHOW CREATE TABLE `$t`")[0]['Create Table'] ?? '');
    $sql = (string) file_get_contents("$root/db.sql");
    preg_match('/CREATE TABLE `' . $t . '` \((.*?)\n\) ENGINE/s', $sql, $m1); preg_match('/CREATE TABLE `' . $t . '` \((.*?)\n\) ENGINE/s', $mine, $m2);
    $ok(isset($m1[1], $m2[1]) && trim($m1[1]) === trim($m2[1]), "db.sql carries $t exactly as the migration made it");
}
$cols = array_column($rows('SHOW COLUMNS FROM endpoint_agent_devices'), 'Type', 'Field');
$ok(isset($cols['clock_skew_s'], $cols['clock_skew_at'], $cols['clock_offset_s'], $cols['clock_skew_event_at'], $cols['last_reject_reason'], $cols['last_reject_at']) && str_contains((string) file_get_contents("$root/db.sql"), '`last_reject_reason` varchar(200)'), 'six clock columns exist and db.sql has them');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.102', '>='), 'LATEST_DATABASE_VERSION is 2.6.102 or later');

echo "\n--- updater step 2.6.102 replay: stored schedule upgrade + one reconcile ---\n";
$q("UPDATE settings SET config_core_rmm_enabled = 1");
$sched = [['key' => 'svc_chronyd', 'type' => 'service', 'params' => ['name' => 'chronyd', 'expected' => 'running'], 'interval_s' => 900],
    ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expected' => 'running'], 'interval_s' => 900],
    ['key' => 'svc_nginx', 'type' => 'service', 'params' => ['name' => 'nginx', 'expected' => 'running'], 'interval_s' => 900],
    ['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron', 'expected' => 'running'], 'interval_s' => 900, 'os' => 'windows'],
    ['key' => 'disk_free', 'type' => 'disk', 'params' => ['warn_free_pct' => 15, 'fail_free_pct' => 7], 'interval_s' => 900]];
$q("UPDATE endpoint_agent_settings SET checks_json = '" . $esc(json_encode(\RivetCore\Rmm\Settings\ChecksValidator::toWire($sched))) . "' WHERE id = 1");
$tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'rc25')->data['token'];
[$c, , $j] = ea_enroll($tok, ea_dev(['hostname' => 'WIN-RC25', 'os' => 'windows']));
$winId = (int) ($j['device_id'] ?? 0); $ok($c === 201 && $winId > 0, 'a Windows device enrolls');
$q("INSERT INTO rmm_alerts SET client_id = 1, integration_id = " . (int) $one("SELECT id FROM rmm_integrations WHERE type='rivetit_agent'") . ", tactical_alert_id = 'agent:$winId:svc_chronyd:1', severity = 'error', status = 'new', message = 'chronyd not found'");
$aid = (int) $db->insert_id;
$q("INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail, alert_id, last_reported_at, last_changed_at) VALUES ($winId, 'svc_chronyd', 'fail', 'service chronyd not found', $aid, NOW(), NOW())");
$q("INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail, last_reported_at, last_changed_at) VALUES ($winId, 'svc_eventlog', 'ok', 'running', NOW(), NOW())");
$tickets0 = (int) $one('SELECT COUNT(*) FROM tickets');
$q("UPDATE settings SET config_current_database_version = '2.6.101'");
for ($i = 0; $i < 2; $i++) { shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1'); }
$ok((string) $one('SELECT config_current_database_version FROM settings') === LATEST_DATABASE_VERSION, 'the updater reaches the latest version (run twice)');
$st = json_decode((string) $one('SELECT checks_json FROM endpoint_agent_settings WHERE id = 1'), true); $by = array_column($st, null, 'key');
$ok(($by['svc_chronyd']['os'] ?? '') === 'linux' && ($by['svc_eventlog']['os'] ?? '') === 'windows', 'stored schedule: chronyd is linux, EventLog windows');
$ok(!isset($by['svc_nginx']['os']) && !isset($by['disk_free']['os']) && ($by['svc_cron']['os'] ?? '') === 'windows', 'a custom service and other types are untouched; an os already set is never changed');
$ok(count($st) === 5 && ($by['disk_free']['params']['warn_free_pct'] ?? 0) === 15, 'the rest of the schedule is intact');
$ok($one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id = $winId AND check_key = 'svc_chronyd'") == 0 && $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id = $winId AND check_key = 'svc_eventlog'") == 1, 'reconcile removed the Linux check row of the Windows PC and kept its own');
$ok($one("SELECT status FROM rmm_alerts WHERE id = $aid") === 'resolved', 'its open alert was resolved');
$ok((int) $one('SELECT COUNT(*) FROM tickets') === $tickets0, 'no ticket was created');
// rc.27 added 0033/0034 after this test was written (also matched by LIKE '003%'); name the three this test owns explicitly so a later rc adoption adding more 003x migrations doesn't make this assertion stale again.
foreach (['0030_rmm_change_monitor', '0031_rmm_clock_skew', '0032_rmm_check_set'] as $m) { $ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id = '$m'") === 1, "replay: migration $m recorded once"); }
$ok($rmm->reconcileChecks()['removed'] === 0, 'reconcileChecks is idempotent');

echo "\n--- schedule builder: os ---\n";
$ok(RivetChecksSimple::serviceOs('sshd') === 'linux' && RivetChecksSimple::serviceOs('Spooler') === 'windows' && RivetChecksSimple::serviceOs('nginx') === null, 'serviceOs from the table');
[$up, $n] = RivetChecksSimple::upgradeStored($sched); [$up2, $n2] = RivetChecksSimple::upgradeStored($up);
$ok($n === 2 && $n2 === 0 && $up2 === $up, 'upgradeStored: 2 upgraded, then idempotent');
$stored = json_decode((string) $one('SELECT checks_json FROM endpoint_agent_settings WHERE id = 1'), true);
$sv = static fn (array $l): array => array_column(array_filter($l, fn ($c) => $c['type'] === 'service'), 'os', 'key');
[$l, $e] = RivetChecksSimple::compose(['present' => '1', 'every' => '900', 'disk_free' => '15', 'svc' => ['sshd', 'EventLog'], 'svc_extra' => "MSSQLSERVER\nnginx", 'svc_extra_os' => 'windows'], $stored);
$o = $sv($l ?? []);
$ok($e === [] && ($o['svc_sshd'] ?? '') === 'linux' && ($o['svc_eventlog'] ?? '') === 'windows' && ($o['svc_mssqlserver'] ?? '') === 'windows', 'ticked services take the table os; a new extra name takes the chosen os (Windows)');
[$l, $e] = RivetChecksSimple::compose(['present' => '1', 'every' => '900', 'disk_free' => '15', 'svc' => [], 'svc_extra' => 'redis', 'svc_extra_os' => 'linux'], $stored);
$ok($e === [] && ($sv($l)['svc_redis'] ?? '') === 'linux', 'add another service: Linux chosen');
[$l, $e] = RivetChecksSimple::compose(['present' => '1', 'every' => '900', 'disk_free' => '15', 'svc' => [], 'svc_extra' => 'redis'], $stored);
$ok($e === [] && ($sv($l)['svc_redis'] ?? '') === 'windows', 'default is Windows');
[$l, $e] = RivetChecksSimple::compose(['present' => '1', 'every' => '900', 'disk_free' => '15', 'svc' => [], 'svc_extra' => 'redis', 'svc_extra_os' => 'solaris'], $stored);
$ok($l === null && $e !== [], 'an unknown os is refused');
[$l, $e] = RivetChecksSimple::compose(['present' => '1', 'every' => '900', 'disk_free' => '15', 'svc' => [], 'svc_extra' => 'nginx', 'svc_extra_os' => 'linux'], $stored);
$ok($l !== null && !array_key_exists('os', array_column($l, null, 'key')['svc_nginx']), 'a service already scheduled for both keeps both when retyped');
$page = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidA)[1];
$ok(str_contains($page, 'A check ticked for one OS is only sent to devices of that OS.') && str_contains($page, 'name="cs[svc_extra_os]"') && str_contains($page, 'Clean up checks that no longer apply'), 'admin page: OS note, OS choice, cleanup button');
$ok(str_contains(rivetRmmPolCheckText(['type' => 'service', 'interval_s' => 60, 'os' => 'linux']), 'Linux only'), 'policy check text says Linux only');
$ok(rivetRmmAlrReasonText('not_applicable') === 'This check no longer applies to the device' && rivetRmmAlrReasonText('device_retired') === 'device retired', 'resolve reason text');

echo "\n--- cleanup button ---\n";
$q("INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail, last_reported_at, last_changed_at) VALUES ($winId, 'svc_rsyslog', 'fail', 'x', NOW(), NOW())");
[$c] = $post(['reconcile_checks' => '1'], null, 'wrongtoken'); $ok($one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='svc_rsyslog'") == 1, 'a bad CSRF token changes nothing');
$post(['reconcile_checks' => '1'], $sidT); $ok($one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='svc_rsyslog'") == 1, 'a non-administrator cannot run it');
$logs0 = (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%cleaned up checks%'");
$post(['reconcile_checks' => '1']); $ok($one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='svc_rsyslog'") == 0, 'the administrator cleans up');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%cleaned up checks%'") === $logs0 + 1, 'and it is audited');
$post(['reconcile_checks' => '1']); $ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%cleaned up checks%'") === $logs0 + 2, 'pressing again is harmless');

echo "\n--- clock ---\n";
$n = rivetRmmUiClockNotice(['skew_s' => 300, 'last_reject' => null], 'windows');
$ok($n !== null && str_contains($n['text'], "This PC's clock is 5 min ahead of the server") && str_contains($n['text'], 'tzutil /s') && str_contains($n['text'], 'w32tm /resync'), 'ahead notice (Windows commands)');
$ok(str_contains(rivetRmmUiClockNotice(['skew_s' => -600, 'last_reject' => null], 'linux')['text'], '10 min behind the server') && str_contains(rivetRmmUiClockNotice(['skew_s' => -600, 'last_reject' => null], 'linux')['text'], 'timedatectl'), 'behind notice (Linux commands)');
$ok(rivetRmmUiClockNotice(['skew_s' => 120, 'last_reject' => null], 'windows') === null && rivetRmmUiClockNotice(['skew_s' => -90, 'last_reject' => null], 'windows') === null && rivetRmmUiClockNotice(null, 'windows') === null, 'no notice at or under 120 s');
$ok(rivetRmmUiClockNotice(['skew_s' => null, 'last_reject' => ['reason' => 'collected_at is in the future', 'at' => null]], 'windows')['reject'] === 'collected_at is in the future', 'a refusal alone shows');
$evil = '<img src=x onerror=alert(1)>';
$tok2 = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'rc25b')->data['token'];
[$c, , $j] = ea_enroll($tok2, ea_dev(['hostname' => 'CLOCK-PC'])); $clkId = (int) $j['device_id'];
$q("INSERT INTO assets SET asset_name = 'CLOCK-PC', asset_type = 'Desktop', asset_client_id = 1"); $clkAsset = (int) $db->insert_id;
$q("UPDATE endpoint_agent_devices SET link_state = 'linked', asset_id = $clkAsset, clock_skew_s = 420, last_reject_reason = '" . $esc($evil) . "', last_reject_at = NOW() WHERE device_id = $clkId");
$fleet = web($wb, 'GET', '/agent/rmm_fleet.php', $sidA)[1];
$ok(str_contains($fleet, 'data-rmm-clock-badge') && !str_contains($fleet, $evil), 'fleet: Clock off badge, nothing raw');
$ok(str_contains(rivetRmmUiClockBadge(rivetRmmUiClockNotice(['skew_s' => null, 'last_reject' => ['reason' => $evil, 'at' => null]], 'windows')), '&lt;img') && !str_contains(rivetRmmUiClockBadge(rivetRmmUiClockNotice(['skew_s' => null, 'last_reject' => ['reason' => $evil, 'at' => null]], 'windows')), $evil), 'badge: a refusal reason is escaped');
$ok(str_contains(rivetRmmUiClockBadge(rivetRmmUiClockNotice(['skew_s' => 300, 'last_reject' => null], 'windows')), 'Clock off 5 min') && rivetRmmUiClockBadge(null) === '', 'badge helper');
$asset = (int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = $clkId");
$q("INSERT INTO asset_rmm_links SET asset_id = $asset, integration_id = " . (int) $one("SELECT id FROM rmm_integrations WHERE type='rivetit_agent'") . ", tactical_agent_id = 'rivetit:$clkId', hostname = 'CLOCK-PC'");
if ($asset > 0) {
    [$pc, $pg] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $asset, $sidA);
    $ok(str_contains($pg, "This PC&#039;s clock is 7 min ahead of the server") || str_contains($pg, "This PC's clock is 7 min ahead of the server"), 'asset page: clock notice');
    $ok(!str_contains($pg, $evil) && str_contains($pg, 'No check-in accepted yet: &lt;img') && !str_contains($pg, 'OS and agent not reported yet'), 'asset page: refusal reason replaces "not reported yet", escaped');
} else { $ok(true, 'asset page skipped (device has no asset in this harness)'); }
$ok(\RivetCore\Webhooks\EventCatalog::get('rmm.device.clock_skew') !== null, 'event rmm.device.clock_skew is in the event catalog');

echo "\n--- metrics_extended ---\n";
$limit = fn () => (int) ($rmm->readModel()->settingsSummary()['limits']['metrics_extended'] ?? -1);
$ok($limit() === 1, 'default is on');
$post(['save_inventory_settings' => '1', 'metrics_extended_present' => '1']); $ok($limit() === 0, 'unticked: off');
$post(['save_inventory_settings' => '1', 'metrics_extended_present' => '1', 'metrics_extended' => '1']); $ok($limit() === 1, 'ticked: on again');
$ok(str_contains(web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidA)[1], 'Send extra health details'), 'switch is on the page');
foreach (['cpu.utilization', 'memory.utilization', 'disk.utilization', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s'] as $k) { $ok(in_array($k, \RivetCore\Rmm\Support\DatabaseMetricSink::ROLLUP_KEYS, true), "the sink still writes $k (read by the Performance view)"); }

echo "\n--- change monitoring ---\n";
$feat = fn () => (bool) (json_decode((string) $one('SELECT features_json FROM endpoint_agent_settings WHERE id = 1'), true)['change_monitor'] ?? false);
$ok(!$feat(), 'off by default');
$post(['save_change_monitor' => '1', 'change_monitor' => '1']); $ok(!$feat(), 'turning it on without the acknowledgement is refused');
$post(['save_change_monitor' => '1', 'change_monitor' => '1', 'accept_change_storage' => '1'], $sidT); $ok(!$feat(), 'a technician cannot');
$post(['save_change_monitor' => '1', 'change_monitor' => '1', 'accept_change_storage' => '1']); $ok($feat(), 'administrator + acknowledgement turns it on');
[$c, , $j] = http('POST', '/api/v1/agent_changes', null, ['records' => []]); $ok($c === 401 || $c === 403, "agent_changes is routed (answers $c without a device token, not 404)");
if ($asset > 0) {
    $q("INSERT INTO endpoint_agent_change_records (device_id, occurred_at, received_at, kind, watch, change_type, target, item, summary, dedupe) VALUES ($clkId, NOW(), NOW(), 'file', 'w1', 2, '/etc/x', '', '" . $esc($evil) . "', UNHEX(REPLACE(UUID(),'-','')))");
    $pg = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $asset, $sidA)[1];
    $ok(str_contains($pg, 'id="rmm-tab-changes"') && str_contains($pg, '&lt;img src=x') && !str_contains($pg, $evil), 'Changes tab shows the record, escaped');
    $post(['save_change_monitor' => '1']); 
    $post(['save_change_monitor' => '1', 'change_monitor' => '0']);
    $pg = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $asset, $sidA)[1];
    $ok(!str_contains($pg, 'rmm-tab-changes'), 'switch off: no Changes tab');
}

echo "\n--- menu / Add device ---\n";
$fleet = web($wb, 'GET', '/agent/rmm_fleet.php', $sidA)[1];
$ok(substr_count($fleet, 'data-nav-add-device') === 1 && !str_contains($fleet, 'Build installer') && str_contains($fleet, 'Quick download'), 'one Add device entry, Quick download on the fleet page');
[$c, , $h] = web($wb, 'GET', '/agent/rmm_fleet.php?add=1&client_id=1', $sidA); $ok($c === 302 && str_contains($h, 'rmm_add_device.php?client_id=1'), 'old ?add=1 redirects, keeping the client');
$pg = web($wb, 'GET', '/agent/rmm_add_device.php', $sidA)[1]; $ok(str_contains($pg, 'Quick download') && str_contains($pg, 'id="rmmInstallerModal"'), 'full page has Quick download and its dialog');

echo "\n--- Allow Linux devices to enroll ---\n";
$ok((int) $one('SELECT config_rmm_allow_linux FROM settings') === 1, 'default ON');
$lin = fn (string $h) => ea_enroll((string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'lin')->data['token'], ea_dev(['hostname' => $h, 'os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'arch' => 'amd64']));
[$c, , $j] = $lin('LIN-ON'); $ok($c === 201 && (string) $one("SELECT os FROM endpoint_agent_devices WHERE hostname = 'LIN-ON'") === 'linux', 'on: a Linux device enrolls');
$l0 = (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%Allow Linux devices to enroll%'");
$post(['save_allow_linux' => '1'], $sidT); $ok((int) $one('SELECT config_rmm_allow_linux FROM settings') === 1, 'a technician cannot switch it');
$post(['save_allow_linux' => '1']);
$ok((int) $one('SELECT config_rmm_allow_linux FROM settings') === 0 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%Allow Linux devices to enroll%'") === $l0 + 1, 'off: stored and audited');
[$c, , $j, $raw] = $lin('LIN-OFF'); $ok($c === 422 && str_contains($raw, 'device.os') && (int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE hostname = 'LIN-OFF'") === 0, 'off: refused with the existing 422');
[$c] = ea_enroll((string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'win')->data['token'], ea_dev(['hostname' => 'WIN-STILL'])); $ok($c === 201, 'off: Windows still enrolls');
$post(['save_allow_linux' => '1', 'allow_linux_enroll' => '1']); [$c] = $lin('LIN-BACK'); $ok($c === 201, 'on again: Linux enrolls');
