<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Company time on the real RMM / Endpoint agent / admin pages (26.10.36): the pages are fetched over HTTP with a forged admin session on a scratch database
 * whose company time zone is NOT UTC. Core columns (UTC) are converted; the edition's NOW() columns (company-local) are left alone and only labelled.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_x ... EA_TEST_LINUX=1 php tests/rmm_time_pages.php      (same environment as tests/rmm_ui.php)
 */
$sd = sys_get_temp_dir() . '/rmm_time_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require __DIR__ . '/support/rmm_ui_seed.php';
register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });

$S = rmm_ui_seed();
$D = $S['dev'];
$A = $S['asset'];
$sdir = sys_get_temp_dir() . '/rmm_time_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RIVETMSP_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$admin = ea_forge_session($sdir, 1);
$q("UPDATE settings SET config_module_enable_rmm=1 WHERE company_id=1");

// ---- fixtures with KNOWN stored instants. UTC-stored columns (Core): 2026-07-04 15:42:10 (summer) and 2026-01-15 15:42:10 (winter).
$SUMMER = '2026-07-04 15:42:10'; $WINTER = '2026-01-15 15:42:10';
$q("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES ('9.9.9', 'amd64', '" . str_repeat('a', 64) . "', 10, 'bin_" . str_repeat('a', 32) . ".bin', 1, 1, 0, '$SUMMER')");
$q("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES ('9.9.8', 'amd64', '" . str_repeat('b', 64) . "', 10, 'bin_" . str_repeat('b', 32) . ".bin', 1, 1, 0, '$WINTER')");
$q("UPDATE endpoint_agent_enrollment_tokens SET expires_at = '$SUMMER' ORDER BY token_id LIMIT 1");
$q("UPDATE endpoint_agent_devices SET last_checkin_at = '$SUMMER', first_seen_at = '$WINTER', last_inventory_at = '$WINTER' WHERE device_id = " . (int) $D['PEND']);
$q("UPDATE rmm_device_state SET updated_at = updated_at WHERE device_id = " . (int) $D['PEND']);
// company-local columns (the edition's tables, written with NOW()): 10:42 on the wall clock already
$q("INSERT INTO rmm_alerts SET integration_id = 0, asset_id = " . (int) $A['WIN1'] . ", client_id = 1, tactical_alert_id = 'tz-1', severity = 'error', status = 'new', message = 'tz local alert', created_at = '2026-07-04 10:42:10'");

$page = function (string $path) use ($wb, $admin): string { [$c, $b] = web($wb, 'GET', $path, $admin); return $c === 200 ? $b : "HTTP $c"; };
$zones = [
    'America/Chicago' => ['s' => '2026-07-04 10:42 CDT', 'w' => '2026-01-15 09:42 CST', 'l' => '10:42am CDT'],
    'Asia/Kolkata'    => ['s' => '2026-07-04 21:12 IST', 'w' => '2026-01-15 21:12 IST', 'l' => '10:42am IST'],
    'Europe/London'   => ['s' => '2026-07-04 16:42 BST', 'w' => '2026-01-15 15:42 GMT', 'l' => '10:42am BST'],
];
foreach ($zones as $zone => $x) {
    $q("UPDATE settings SET config_timezone = '" . $esc($zone) . "' WHERE company_id = 1");
    $a = $page('/admin/settings_endpoint_agent.php');
    $ok(strlen($a) > 5000 && !str_starts_with($a, 'HTTP'), "$zone: Endpoint agent settings page loads");
    $ok(str_contains($a, $x['s']) && str_contains($a, $x['w']), "$zone: agent binaries 'Uploaded' shows summer and winter UTC instants as {$x['s']} / {$x['w']} (DST-correct)");
    $ok(!preg_match('~<th[^>]*>[^<]*\(UTC\)~', $a) && !str_contains($a, ' UTC<'), "$zone: no '(UTC)' header and no ' UTC' suffix on the Endpoint agent page");
    $ok(preg_match('~<th[^>]*>Uploaded</th>~', $a) === 1 && preg_match('~<th[^>]*>Expires</th>~', $a) === 1 && preg_match('~<th[^>]*>Last check-in</th>~', $a) === 1, "$zone: headers are plain 'Uploaded', 'Expires', 'Last check-in'");
    $ok(str_contains($a, $x['s']) && substr_count($a, $x['s']) >= 3, "$zone: the token expiry and the device check-in use the same format as the binary (" . substr_count($a, $x['s']) . ' hits)');

    $d = $page('/agent/rmm_agent_device.php?device_id=' . (int) $D['PEND']);
    $ok(!str_starts_with($d, "HTTP"), "$zone: device page loads " . substr($d, 0, 40));
    $ok(str_contains($d, substr($x['s'], 0, 16) . ':10 ' . substr($x['s'], 17)), "$zone: device page shows the check-in with seconds and the zone label");
    $ok(!str_contains($d, ' UTC') && !str_contains($d, '(UTC)'), "$zone: device page says UTC nowhere");

    $al = $page('/agent/rmm_alerts.php');
    $ok(str_contains($al, 'tz local alert') && str_contains($al, $x['l']), "$zone: a company-local alert time is only labelled ({$x['l']}), not converted a second time");
    $ok(!str_contains($al, '05:42') && !str_contains($al, '5:42am'), "$zone: and was not shifted");
}
$GLOBALS['fails'] = $fails;
