<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Install apps when the agent installs (RivetCore 1.0.0-rc.26/27, migration 0033), section 4c of the rc.27 adoption: includes/rmm_auto_install_ui.php
 * (catalog scope form, vendor parameter panel, hosted files card, starter templates card, device "Apps installed with the agent" section),
 * agent/post/rmm_auto_install.php (the handler), api/v1/agent_software.php (the device route), app checkboxes threaded through
 * agent/post/rmm_installer.php. Same scratch rules and environment as tests/endpoint_agent_lib.php.
 */
$sd = sys_get_temp_dir() . '/rmm_ia_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_auto_install_ui.php";
require_once "$root/includes/rmm_ui_render.php";   // rmmH()
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$f = $rmm->settings()->features(); $f['software_deploy'] = true; $rmm->admin()->saveSettings($admin, ['features_json' => $f]);
$rmm->syncState();

$sdir = sys_get_temp_dir() . '/rmm_ia_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');
$sidA = ea_forge_session($sdir, 1); $sidT = ea_forge_session($sdir, 10); $sidV = ea_forge_session($sdir, 12);
$iaCtx = ['uid' => 1, 'name' => 'admin', 'mysqli' => $db, 'csrf' => ''];
$post = fn (array $f, string $sid = null, string $csrf = 'csrftok1') => web($wb, 'POST', '/agent/post/rmm_auto_install.php', $sid ?? $sidA, $f + ['csrf_token' => $csrf], ['Referer: ' . $wb . '/agent/rmm_software_catalog.php']);

echo "\n--- a package + a Windows version with a vendor-parameter placeholder ---\n";
$swHost = 'downloads.example.test';
rivetRmmSoftwareApi($iaCtx, 'POST', ['software_hosts'], [], ['host' => $swHost]);
$r = rivetRmmSoftwareApi($iaCtx, 'POST', ['software_packages'], [], ['name' => 'Agent Test App', 'publisher' => 'Vendor']);
$ok($r['ok'], 'package created: ' . json_encode($r));
$pid = (int) ($r['data']['package_id'] ?? 0);
$r = rivetRmmSoftwareApi($iaCtx, 'POST', ['software_packages', $pid, 'versions'], [], ['os' => 'windows', 'fmt' => 'exe', 'version' => '1.0.0', 'url' => "https://$swHost/app.exe", 'sha256' => str_repeat('ab', 32),
    'size_bytes' => 1048576, 'silent_args' => '/ACCT={{account_key}} /S', 'detect_kind' => 'uninstall_name', 'detect_value' => 'Agent Test App', 'detect_min' => '1.0.0']);
$ok($r['ok'], 'version created: ' . json_encode($r));
$q("UPDATE rmm_packages SET enabled = 1 WHERE package_id = $pid");

echo "\n--- selectable: missing parameter blocks the tick ---\n";
$sel = rivetRmmAutoInstallResult($rmm->autoInstallActions()->selectable($admin, 1, 0, 'windows'));
$ok($sel['ok'], 'selectable ok');
$found = null; foreach ((array) ($sel['data']['apps'] ?? []) as $a) { if ((int) $a['package_id'] === $pid) { $found = $a; } }
$ok($found !== null && $found['missing_params'] === ['account_key'] && $found['auto'] === false, 'the app is listed, not auto, and blocked on the missing account_key parameter');

echo "\n--- catalog page: the auto-install scope form appears for a manager ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_software_catalog.php?package_id=' . $pid, $sidA);
$ok($c === 200 && str_contains($b, 'data-ia-scope-form') && str_contains($b, '>Install with the agent<') && str_contains($b, '<code>account_key</code>'), 'the scope form and the declared parameter appear');

echo "\n--- set_auto: scope all, required ---\n";
[$c] = $post(['ia_op' => 'set_auto', 'package_id' => $pid, 'scope' => 'all', 'install_order' => '50', 'required' => '1']);
$ok($c === 302, 'set_auto redirects');
$row = $rows("SELECT * FROM rmm_package_auto WHERE package_id = $pid")[0] ?? null;
$ok($row !== null && $row['scope'] === 'all' && (int) $row['install_order'] === 50 && (int) $row['required'] === 1, 'rmm_package_auto was written correctly');
$beforeScope = (string) $one("SELECT scope FROM rmm_package_auto WHERE package_id = $pid");
[$c, $b] = $post(['ia_op' => 'set_auto', 'package_id' => $pid, 'scope' => 'off'], $sidV);
$ok((string) $one("SELECT scope FROM rmm_package_auto WHERE package_id = $pid") === $beforeScope, 'a view-only role cannot change the auto-install policy (form handler always redirects; the DB row is the proof)');

echo "\n--- set_param / clear_param: sealed, never plaintext ---\n";
[$c] = $post(['ia_op' => 'set_param', 'package_id' => $pid, 'name' => 'account_key', 'value' => 'VERY-SECRET-123']);
$ok($c === 302, 'set_param redirects');
$prow = $rows("SELECT * FROM rmm_package_params WHERE package_id = $pid AND name = 'account_key'")[0] ?? null;
$ok($prow !== null && !str_contains((string) $prow['value_enc'], 'VERY-SECRET-123'), 'the value is stored sealed, never in plaintext');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%VERY-SECRET-123%'") === 0, 'the raw value never reaches the audit log');
$sel2 = rivetRmmAutoInstallResult($rmm->autoInstallActions()->selectable($admin, 1, 0, 'windows'));
$found2 = null; foreach ((array) ($sel2['data']['apps'] ?? []) as $a) { if ((int) $a['package_id'] === $pid) { $found2 = $a; } }
$ok($found2 !== null && $found2['missing_params'] === [] && $found2['auto'] === true, 'with the value set, the app is no longer blocked, and scope "all" makes it auto for every client');
[$c, $b] = web($wb, 'GET', '/agent/rmm_software_catalog.php?package_id=' . $pid, $sidA);
$ok(str_contains($b, 'badge bg-success">set (global)') && !str_contains($b, 'VERY-SECRET-123'), 'the page shows where it is set, never the value');
[$c] = $post(['ia_op' => 'clear_param', 'package_id' => $pid, 'name' => 'account_key']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_package_params WHERE package_id = $pid AND name = 'account_key'") === 0, 'clear_param removed it');
[$c] = $post(['ia_op' => 'set_param', 'package_id' => $pid, 'name' => 'account_key', 'value' => 'VERY-SECRET-123']);   // put it back for the enrollment test below

echo "\n--- publish a Windows agent binary (deploymentCommands() needs one published, even for the Linux commands it also returns) ---\n";
function ia_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}
$peTmp = sys_get_temp_dir() . '/ia_pe_' . bin2hex(random_bytes(3));
file_put_contents($peTmp, ia_pe(0x8664, 1048576, 'amd64'));
$peR = $rmm->admin()->uploadBinary($admin, $peTmp, '1.0.0', 'amd64', ['activate' => true]);
@unlink($peTmp);
$ok($peR->ok, 'a Windows amd64 binary is published and current: ' . $peR->message);

echo "\n--- the installer: ticking the app, enrollment plans it, the device page shows it ---\n";
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidA, ['csrf_token' => 'csrftok1', 'os' => 'windows', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'app_ids' => [$pid]], ['Referer: ' . $wb . '/agent/rmm_add_device.php', 'X-Requested-With: fetch']);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false), 'installer commands created with app_ids ticked: ' . $b);
$tokIdRow = $rows("SELECT token_id FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1")[0] ?? null;
$ok($tokIdRow !== null && (int) $one("SELECT COUNT(*) FROM rmm_installer_apps WHERE token_id = " . (int) $tokIdRow['token_id'] . " AND package_id = $pid") === 1, 'the tick is remembered on the enrollment token (rmm_installer_apps)');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%Installer Apps Chosen%'") >= 1 || (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent'") > 0, 'something was audited for the installer (at least the usual Installer Created row)');

$tokPlain = $rows("SELECT token_plain FROM endpoint_agent_enrollment_tokens WHERE token_id = " . (int) $tokIdRow['token_id'])[0]['token_plain'] ?? null;
// token_plain is never stored; mint a fresh token with the same app tick instead, through the real enrollment token table directly is not possible (it only stores the hash),
// so issue a brand new installer and read the plaintext from the JSON response (the installer page shows it once).
$j2 = json_decode((string) $b, true);
$enrollToken = null;
if (isset($j2['token']) && is_string($j2['token'])) { $enrollToken = $j2['token']; } else { preg_match('/rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) ($j2['linux'] ?? ''), $mt); $enrollToken = $mt[0] ?? null; }
if ($enrollToken !== null) {
    [$c, , $jr] = ea_enroll($enrollToken, ea_dev(['hostname' => 'IA-WIN1', 'os' => 'windows']));
    $devId = (int) ($jr['device_id'] ?? 0);
    $ok($c === 201 && $devId > 0, 'a device enrolls with the ticked-apps token');
    $planned = $rows("SELECT * FROM rmm_device_apps WHERE device_id = $devId AND package_id = $pid")[0] ?? null;
    $ok($planned !== null, 'the app was planned for this device (rmm_device_apps)');
    $section = rivetRmmAutoInstallDeviceSection($rmm, $admin, $devId);
    $ok($section !== '' && str_contains($section, 'Agent Test App') && str_contains($section, 'Apps installed with the agent'), 'the device section shows the planned app');
} else {
    $ok(false, 'could not recover the plaintext enrollment token from the installer response to prove end-to-end planning');
}

echo "\n--- hosted files ---\n";
$tmpExe = sys_get_temp_dir() . '/ia_test_' . bin2hex(random_bytes(3)) . '.exe';
file_put_contents($tmpExe, 'MZ' . str_repeat("\0", 2048));
$r = $rmm->autoInstallActions()->uploadFile($admin, $tmpExe, 'vendor-installer.exe');
@unlink($tmpExe);
$ok($r->ok, 'an MZ file is accepted as a hosted installer: ' . $r->message);
$fileId = (int) ($r->data['file_id'] ?? 0);
[$c, $b] = web($wb, 'GET', '/agent/rmm_software_catalog.php', $sidA);
$ok(str_contains($b, 'vendor-installer.exe') && str_contains($b, 'Hosted installer files'), 'the hosted file appears on the catalog page');
$card = rivetRmmAutoInstallHostedFilesCard($rmm, $admin, 'csrftok1');
$ok(str_contains($card, 'vendor-installer.exe'), 'the card function itself lists it');

echo "\n--- the device route: every refusal is the same 404 ---\n";
[$c] = web($wb, 'GET', '/api/v1/agent_software/nope/alsonope', 'nosession');
$ok($c === 404, 'an unknown job id and secret: 404 (' . $c . ')');
$q('UPDATE settings SET config_core_rmm_enabled = 0'); $rmm->syncState();
[$c] = web($wb, 'GET', '/api/v1/agent_software/nope/alsonope', 'nosession');
$ok($c === 503 || $c === 404, 'module off: refused, not a crash (' . $c . ')');
$q('UPDATE settings SET config_core_rmm_enabled = 1'); $rmm->syncState();

echo "\n--- starter templates ---\n";
$tr = rivetRmmAutoInstallResult($rmm->autoInstallActions()->templates($admin));
$ok($tr['ok'] && count((array) ($tr['data']['templates'] ?? [])) >= 4, 'templates() lists at least the four documented keys');
$keys = array_column((array) $tr['data']['templates'], 'key');
$ok(in_array('huntress_agent', $keys, true) && in_array('custom_exe', $keys, true) && !in_array('sentinelone', $keys, true) && !in_array('sophos', $keys, true), 'huntress_agent/custom_exe are offered; the deliberately-omitted vendors are not');
[$c] = $post(['ia_op' => 'from_template', 'template_key' => 'custom_exe', 't_version' => '2.0.0', 't_url' => "https://$swHost/custom.exe", 't_sha256' => str_repeat('cd', 32), 't_size_bytes' => '2048', 't_detect_value' => 'Custom App']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_packages WHERE description LIKE '%unverified%'") >= 1, 'from_template created a package marked unverified');

echo "\n--- CSRF ---\n";
$hdr = fn (string $headers, string $name) => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $headers, $m) === 1 ? trim($m[1]) : '';
[$c, , $hh] = $post(['ia_op' => 'set_auto', 'package_id' => $pid, 'scope' => 'off'], null, 'wrongtoken');
$ok($c === 302 && stripos($hdr($hh, 'Location'), 'index.php') !== false, 'a wrong CSRF token is refused (validateCSRFToken\'s own redirect)');
