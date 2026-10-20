<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Device type (RivetCore 1.0.0-rc.29, migration 0035, Rmm\Assets\{DeviceTypes,DeviceTypeClassifier}): the installer's "Detect automatically / Laptop /
 * Desktop / Server / Virtual machine / Hypervisor" dropdown, threaded through agent/post/rmm_installer.php as the new last argument of
 * downloadInstaller()/deploymentCommands(); src/Core/Adapter/Endpoint/EndpointAssets::createForDevice()/fillBlanks() honouring the hint/detected type;
 * the token list showing device_type; the approval screen showing suggested_type. Same scratch rules and environment as tests/endpoint_agent_lib.php.
 * The createForDevice()/fillBlanks() unit-level behaviour itself is covered by tests/core/EndpointAssetsConformanceTest.php (RmmAssetsConformanceTestCase) -
 * this file proves the edition wiring around it: the installer dropdown reaching a real enrollment, and the admin UI showing the right things.
 */
$sd = sys_get_temp_dir() . '/rmm_dt_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();

$sdir = sys_get_temp_dir() . '/rmm_dt_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');
$sidA = ea_forge_session($sdir, 1);
$XHR = ['X-Requested-With: fetch', 'Accept: application/json'];
$post = fn (array $f) => web($wb, 'POST', '/agent/post/rmm_installer.php', $sidA, $f + ['csrf_token' => 'csrftok1'], $XHR);

echo "\n--- publish a Windows agent binary (deploymentCommands() needs one published, even for the Linux commands it also returns) ---\n";
function dt_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}
$peTmp = sys_get_temp_dir() . '/dt_pe_' . bin2hex(random_bytes(3));
file_put_contents($peTmp, dt_pe(0x8664, 1048576, 'amd64'));
$peR = $rmm->admin()->uploadBinary($admin, $peTmp, '1.0.0', 'amd64', ['activate' => true]);
@unlink($peTmp);
$ok($peR->ok, 'a Windows amd64 binary is published and current: ' . $peR->message);

echo "\n--- the installer dialog offers the dropdown ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidA);
$ok($c === 200 && str_contains($b, 'name="device_type"') && str_contains($b, '>Detect automatically<') && str_contains($b, 'value="Virtual Machine"') && str_contains($b, 'value="Hypervisor"'), 'the Add-device dialog has the device type dropdown with all the labels');

echo "\n--- a Linux command issued with device_type=Server ---\n";
[$c, $b] = $post(['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'device_type' => 'Server']);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false), 'issuing with a device type succeeds: ' . $b);
$optRow = $rows("SELECT device_type FROM endpoint_agent_installer_options ORDER BY token_id DESC LIMIT 1")[0] ?? null;
$ok($optRow !== null && $optRow['device_type'] === 'Server', 'the choice is stored on the enrollment token (endpoint_agent_installer_options.device_type)');

echo "\n--- an unknown device_type value is treated as auto, not stored as garbage ---\n";
[$c, $b] = $post(['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'device_type' => '<script>alert(1)</script>']);
$ok($c === 200, 'still succeeds (' . $c . ')');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_installer_options WHERE device_type = '<script>alert(1)</script>'") === 0, 'the hostile value was never stored');

echo "\n--- end to end: a device enrolling with the Server hint creates a Server asset ---\n";
[$c, $b] = $post(['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'device_type' => 'Virtual Machine']);
$j = json_decode($b, true);
preg_match('/rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) ($j['linux'] ?? ''), $mt);
$enrollToken = $mt[0] ?? null;
$ok($enrollToken !== null, 'recovered the plaintext enrollment token from the Linux command');
if ($enrollToken !== null) {
    $rmm->admin()->saveSettings($admin, ['unmatched_policy' => 'auto_create']);
    [$c, , $jr] = ea_enroll($enrollToken, ea_dev(['hostname' => 'DT-VM1', 'os' => 'linux', 'serial' => 'DT-VM1-SERIAL-' . bin2hex(random_bytes(3))]));
    $ok($c === 201, 'the device enrolls (' . $c . '): ' . json_encode($jr));
    $devId = (int) ($jr['device_id'] ?? 0);
    $assetRow = $rows("SELECT asset_type FROM assets WHERE asset_name = 'DT-VM1'")[0] ?? null;
    $ok($assetRow !== null && $assetRow['asset_type'] === 'Virtual Machine', 'the new asset got the installer\'s hinted type "Virtual Machine", not the Linux-server default (' . json_encode($assetRow) . ')');
}

echo "\n--- the token list shows the device type ---\n";
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidA);
$ok($c === 200 && str_contains($b, '<th>Device type</th>') && (str_contains($b, '>Server<') || str_contains($b, '>Virtual Machine<')), 'the Enrollment tokens table has a Device type column with real values');

echo "\n--- the approval screen shows the suggested type ---\n";
// A serial-less, no-hostname-match device waits for approval; "auto" + a Windows-server os_version should suggest Server.
$rmm->admin()->saveSettings($admin, ['unmatched_policy' => 'approval']);
[$c, $b] = $post(['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'device_type' => 'auto']);
$j = json_decode($b, true);
preg_match('/rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) ($j['linux'] ?? ''), $mt2);
$enrollToken2 = $mt2[0] ?? null;
$ok($enrollToken2 !== null, 'recovered a second plaintext enrollment token');
if ($enrollToken2 !== null) {
    [$c4, , $jr4] = ea_enroll($enrollToken2, ea_dev(['hostname' => 'DT-PENDING1', 'os' => 'windows', 'os_version' => '10.0.20348 Windows Server 2022', 'serial' => '']));
    $ok($c4 === 201 || $c4 === 200, 'a serial-less device enrolls and waits for approval (' . $c4 . '): ' . json_encode($jr4));
    [$c5, $b5] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidA);
    $ok($c5 === 200 && str_contains($b5, 'DT-PENDING1') && str_contains($b5, 'as Server'), 'the pending-approval card names the device and says what type "Create a new asset" would make (Server, from the Windows Server os_version)');
}
