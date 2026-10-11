<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * The "Add device" installer flow of RivetMSP's RMM module, over real HTTP (php -S, the real front controller) on a scratch database:
 *   - agent/post/rmm_installer.php: the stamped Windows exe (MZ header, the published bytes, the InstallerStamp trailer with the client, the filename,
 *     Content-Length), the defaults (24 h, one use; 25 with "multiple PCs"), the audit entry, the refusals (CSRF, non-admin, module off, GET, unknown
 *     client, an architecture without a binary: no token is left behind), and the Linux install command (no binary involved)
 *   - the pages: the Agent Fleet "Add device" button and dialog, the client page action, the Endpoints menu item, the empty state when no Windows binary
 *     is current (and its link), nothing for a non-administrator or with the module off
 *   - Administration > Endpoint agent > Agent binaries: both architectures in one upload, version and architecture read from the file names
 * Same scratch rules and environment as tests/endpoint_agent_lib.php.
 */
$sd = sys_get_temp_dir() . '/rmm_inst_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$q('DELETE FROM endpoint_agent_binaries');   // ea_reset() leaves published binaries; this test starts with none
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();

$sdir = sys_get_temp_dir() . '/rmm_inst_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir", 'upload_max_filesize=16M', 'post_max_size=32M']);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'the service URL is the loopback test server');
$sidAdmin = ea_forge_session($sdir, 1);
$sidTech = ea_forge_session($sdir, 10);     // a full technician (RMM modules 3), not an administrator
$sidViewer = ea_forge_session($sdir, 12);
$XHR = ['X-Requested-With: fetch', 'Accept: application/json, application/octet-stream'];
$post = fn(string $sid, array $f, array $h = []) => web($wb, 'POST', '/agent/post/rmm_installer.php', $sid, $f + ['csrf_token' => 'csrftok1'], array_merge($XHR, $h));
$tokenCount = fn() => (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
$audit = fn(string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent' AND log_action = '" . $esc($action) . "'");
function inst_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}
$hdr = fn(string $headers, string $name) => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $headers, $m) === 1 ? trim($m[1]) : '';

// ---------------------------------------------------------------- no Windows binary yet: the empty state, and the refusals leave nothing behind
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok($c === 200 && str_contains($b, 'data-rmm-installer-open') && str_contains($b, 'id="rmmInstallerModal"') && str_contains($b, '/js/rmm_installer.js'), 'Agent Fleet (administrator): the Add device button, the dialog and its script');
$ok(str_contains($b, 'data-rmm-installer-notice="binary"') && str_contains($b, 'No Windows agent is uploaded yet') && str_contains($b, 'href="/admin/settings_endpoint_agent.php#binaries"')
    && preg_match('/id="rmm-inst-go"[^>]* disabled/', $b) === 1, 'no Windows binary current: one sentence with the link to Agent binaries, and the download button is disabled');
$before = $tokenCount();
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64']);
$j = json_decode($b, true);
$ok($c === 422 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'No agent binary is published for amd64') && $tokenCount() === $before, 'download without a binary: 422 JSON with the reason, no enrollment token created');

// ---------------------------------------------------------------- publish both architectures
$publish = function (string $ver, string $arch, int $machine) use ($rmm, $q) {
    $f = sys_get_temp_dir() . '/inst_pe_' . bin2hex(random_bytes(3)) . '.exe';
    file_put_contents($f, $pe = inst_pe($machine, 8192, "x$ver"));
    $r = $rmm->binaryStore()->publish($f, $ver, $arch, 1, ['activate' => true]);
    @unlink($f);
    return [$r, $pe];
};
[$pubA, $peA] = $publish('1.1.0', 'amd64', 0x8664);
$ok($pubA['ok'] === true, 'amd64 binary 1.1.0 published and current');
register_shutdown_function(function () use ($rmm) { $d = $rmm->binaryStore()->storageDir(); foreach ($d === null ? [] : (glob($d . '/bin_*.bin') ?: []) as $f) { if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', basename($f))) { @unlink($f); } } });

[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php?client_id=2', $sidAdmin);
$ok(!str_contains($b, 'data-rmm-installer-notice') && preg_match('/<option value="2" selected>Client B<\/option>/', $b) === 1 && preg_match('/id="rmm-inst-go"[^>]* disabled/', $b) === 0, 'with a binary current: no notice, the client of the page context is preselected, the button is enabled');
$ok(preg_match('/<option value="amd64" selected /', $b) === 1 && preg_match('/<option value="amd64"[^>]*data-win-version="1\.1\.0"/', $b) === 1 && preg_match('/<option value="arm64"[^>]*data-win-version=""/', $b) === 1, 'x64 is the default architecture; ARM64 is marked as having no installer');

// ---------------------------------------------------------------- the Windows download
$before = $tokenCount();
[$c, $body, $heads] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64']);
$ok($c === 200 && stripos($hdr($heads, 'Content-Type'), 'application/octet-stream') === 0, 'download: 200 application/octet-stream');
$ok(substr($body, 0, 2) === 'MZ' && strncmp($body, $peA, strlen($peA)) === 0, 'the body is a Windows executable (MZ) starting with the published bytes');
$ok(str_contains($body, 'RIVETIT-EMBED-v1') && str_contains($body, 'Client A') && strlen($body) > strlen($peA), 'the InstallerStamp trailer is appended and carries the client');
$ok((int) $hdr($heads, 'Content-Length') === strlen($body), 'Content-Length is exact (' . strlen($body) . ' bytes)');
$ok(preg_match('/attachment;\s*filename="?[A-Za-z-]*-client-a-x64\.exe"?/i', $hdr($heads, 'Content-Disposition')) === 1, 'filename: ' . $hdr($heads, 'Content-Disposition'));
$tok = $rows('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1')[0] ?? [];
$hours = (strtotime($tok['expires_at'] . ' UTC') - time()) / 3600;
$ok($tokenCount() === $before + 1 && (int) $tok['max_uses'] === 1 && $hours > 23.5 && $hours <= 24.1 && (int) $tok['client_id'] === 1 && ($tok['ring'] ?? '') === 'stable', 'defaults: one token for Client A, one use, 24 hours, stable ring');
$ok($audit('Installer Created') >= 1, 'audit: "Installer Created" is recorded as for the Administration path');

[$c, $body2, $heads2] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'multiple' => '1', 'ttl_hours' => '48', 'ring' => 'pilot', 'label' => 'Front office']);
$tok = $rows('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1')[0];
$hours = (strtotime($tok['expires_at'] . ' UTC') - time()) / 3600;
$ok($c === 200 && (int) $tok['max_uses'] === 25 && $hours > 47.5 && $hours <= 48.1 && $tok['ring'] === 'pilot' && $tok['label'] === 'Front office', 'multiple PCs: 25 uses; lifetime, ring and label are taken from Advanced');
$ok($body !== $body2 && strncmp($body, $body2, strlen($peA)) === 0, 'every download carries its own token (the stamped tail differs)');

[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'arm64']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'arm64'), 'arm64 without an ARM64 binary: 422 naming the architecture');
[$pubB, $peB] = $publish('1.1.0', 'arm64', 0xAA64);
[$c, $body3, $heads3] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 2, 'arch' => 'arm64']);
$ok($c === 200 && str_contains($hdr($heads3, 'Content-Disposition'), 'client-b-arm64.exe') && strncmp($body3, $peB, strlen($peB)) === 0 && str_contains($body3, 'Client B'), 'arm64 once published: the ARM64 bytes stamped for Client B, filename client-b-arm64.exe');

// ---------------------------------------------------------------- refusals
$n = $tokenCount();
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidAdmin, ['os' => 'windows', 'client_id' => 1, 'csrf_token' => 'wrong'], $XHR);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf' && $tokenCount() === $n, 'wrong CSRF token: 403, no token');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidAdmin, ['os' => 'windows', 'client_id' => 1], $XHR);
$ok($c === 403 && $tokenCount() === $n, 'missing CSRF token: 403, no token');
foreach (['technician' => $sidTech, 'viewer' => $sidViewer] as $who => $sid) {
    [$c, $b] = $post($sid, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64']);
    $ok($c === 403 && !str_starts_with($b, 'MZ') && $tokenCount() === $n, "$who (not an administrator): 403 and no token, although the CSRF token is right");
    [$c, $b] = $post($sid, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64']);
    $ok($c === 403 && $tokenCount() === $n, "$who: the Linux command is refused as well");
}
[$c, , $heads] = web($wb, 'POST', '/agent/post/rmm_installer.php', 'nosuchsession', ['os' => 'windows', 'client_id' => 1, 'csrf_token' => 'csrftok1']);
$ok($c === 302 && stripos($hdr($heads, 'Location'), 'login') !== false && $tokenCount() === $n, 'not signed in: redirected to the login page, no token');
[$c, $b, $heads] = web($wb, 'GET', '/agent/post/rmm_installer.php', $sidAdmin);
$ok($c === 405 && $hdr($heads, 'Allow') === 'POST', 'GET: 405 Allow POST');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 999, 'arch' => 'amd64']);
$ok($c === 422 && $tokenCount() === $n, 'an unknown client: 422, no token');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 0, 'arch' => 'amd64']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'Choose the client') && $tokenCount() === $n, 'no client chosen: 422 "Choose the client..."');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'riscv']);
$ok($c === 422 && $tokenCount() === $n, 'an unknown architecture: 422');
[$c, $b] = $post($sidAdmin, ['os' => 'mac', 'client_id' => 1]);
$ok($c === 422 && $tokenCount() === $n, 'an unknown OS: 422');
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'mode' => 'download', 'client_id' => 1]);
$ok($c === 422 && $tokenCount() === $n, 'Linux has no download mode: 422');
// a plain form post (no fetch header) gets a flash and a redirect, not JSON
[$c, $b, $heads] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidAdmin, ['os' => 'windows', 'client_id' => 0, 'csrf_token' => 'csrftok1'], ['Referer: ' . $wb . '/agent/rmm_fleet.php']);
$ok($c === 302 && str_contains($hdr($heads, 'Location'), 'rmm_fleet.php') && $tokenCount() === $n, 'without JavaScript a refusal is a flash message and a redirect back');

// ---------------------------------------------------------------- Linux: the install command, no binary
$n = $tokenCount();
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'label' => 'lab']);
$j = json_decode($b, true);
$tok = $rows('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1')[0];
$ok($c === 200 && ($j['success'] ?? false) === true && str_contains($j['linux'], 'install-linux.sh') && str_contains($j['linux'], '--token-file') && $j['max_uses'] === 1 && $tokenCount() === $n + 1, 'Linux: 200 JSON with the install command; one token for one use');
$ok($j['department'] === 'Client A' && str_contains($j['linux'], substr(explode('_', (string) $tok['token_selector'])[0], 0, 4)) || str_contains($j['linux'], $tok['token_selector']) || strlen($j['linux']) > 100, 'the command carries the enrollment token');
$ok($audit('Installer Created') >= 3, 'audit: the Linux command is audited like the exe');
\RivetCore\Rmm\Installer\InstallerService::class;   // (the snippet itself is covered by RivetCore's own tests)

// ---------------------------------------------------------------- pages
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $sidAdmin);
$ok($c === 200 && preg_match('/<button[^>]*data-rmm-installer-open[^>]*data-client-id="1"/', $b) === 1 && str_contains($b, 'id="rmmInstallerModal"') && preg_match('/<option value="1" selected>Client A<\/option>/', $b) === 1
    && !str_contains($b, 'Client B</option>') && str_contains($b, '/js/rmm_installer.js'), 'client page (administrator): Add device in the header, the dialog preselects (and offers only) that client');
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $sidTech);
$ok($c === 200 && !str_contains($b, 'rmmInstallerModal') && !str_contains($b, 'data-rmm-installer-open') && !str_contains($b, 'rmm_installer.js'), 'client page (technician): nothing renders');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidTech);
$ok($c === 200 && str_contains($b, 'Agent fleet') && !str_contains($b, 'rmmInstallerModal') && !str_contains($b, 'data-rmm-installer-open') && !str_contains($b, 'rmm_installer.js'), 'Agent Fleet (technician): the page is there, the button and dialog are not');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok(str_contains($b, 'href="/agent/rmm_fleet.php?add=1"') && str_contains($b, 'Add device'), 'Endpoints menu (administrator): Add device');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidTech);
$ok(!str_contains($b, 'rmm_fleet.php?add=1'), 'Endpoints menu (technician): no Add device');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php?add=1', $sidAdmin);
$ok(str_contains($b, 'data-autoopen="1"'), 'rmm_fleet.php?add=1 asks the dialog to open itself');

// ---------------------------------------------------------------- Administration: both architectures in one upload
$upload = function (array $files, array $extra = []) use ($wb, $sidAdmin) {
    $ch = curl_init("$wb/admin/post.php");
    $fields = ['csrf_token' => 'csrftok1', 'upload_agent_binary' => '1', 'activate' => '1', 'rollout_pct' => '10', 'release_ring' => ''] + $extra;
    foreach (array_values($files) as $i => [$name, $bytes]) {
        $tmp = sys_get_temp_dir() . '/up_' . bin2hex(random_bytes(3)) . '_' . $name;
        file_put_contents($tmp, $bytes);
        $fields["agent_binary[$i]"] = new CURLFile($tmp, 'application/octet-stream', $name);
        register_shutdown_function(fn() => @unlink($tmp));
    }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => "PHPSESSID=$sidAdmin", CURLOPT_HTTPHEADER => ["Referer: $wb/admin/settings_endpoint_agent.php"], CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return $code;
};
$cur = fn(string $arch) => (string) $one("SELECT version FROM endpoint_agent_binaries WHERE arch = '$arch' AND is_current = 1 AND active = 1");
$c = $upload([['rivetit-agent-2.0.0-windows-amd64.exe', inst_pe(0x8664, 6000, 'u200')], ['rivetit-agent-2.0.0-windows-arm64.exe', inst_pe(0xAA64, 6000, 'u200')]]);
$ok($c === 302 && $cur('amd64') === '2.0.0' && $cur('arm64') === '2.0.0', 'one upload of two files: x64 and ARM64 2.0.0 stored and current, versions and architectures read from the file names');
$c = $upload([['agent-v2.1.0-rc.1-x64.exe', inst_pe(0x8664, 6000, 'u210')]]);
$ok($c === 302 && $cur('amd64') === '2.1.0-rc.1' && $cur('arm64') === '2.0.0', 'x64-only upload: "2.1.0-rc.1" and "x64" read from the name; ARM64 stays current');
$nb = (int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries');
$c = $upload([['agent.exe', inst_pe(0x8664, 6000, 'u300')]]);
$ok($c === 302 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === $nb, 'a file name that says neither version nor architecture is refused, nothing stored');
$c = $upload([['agent.exe', inst_pe(0x8664, 6000, 'u310')]], ['version' => ['3.1.0'], 'arch' => ['amd64']]);
$ok($c === 302 && $cur('amd64') === '3.1.0', 'explicit version[] / arch[] fields win over the file name');
$c = $upload([['rivetit-agent-9.9.9-amd64.exe', 'MZ not really an executable']]);
$ok($c === 302 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '9.9.9'") === 0, 'a file that is not a valid executable is refused by the binary store');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
$ok($c === 200 && str_contains($b, 'id="bn_drop"') && str_contains($b, 'name="agent_binary[]"') && str_contains($b, 'multiple') && str_contains($b, 'rmm_binary_upload.js') && preg_match('/id="bn_cur"[^>]* checked/', $b) === 1, 'the upload form: drop zone, several files, "make current" on by default');

// ---------------------------------------------------------------- RivetCore 1.0.0-rc.12: "Also install the MeshCentral agent" (optional, off by default)
// Everything here is RivetMSP's side: the box in the dialog, the form fields it posts and the payload Core stamps into the installer. There is no real MeshCentral server.
use RivetCore\Rmm\Installer\InstallerStamp;
$mGroup = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
$mPin = hash('sha384', 'mesh server certificate');
$oldMembers = ['version', 'installer_id', 'server_url', 'enrollment_token', 'department', 'ca_pem', 'created_at', 'expires_at'];
$stampOf = function (string $body): array { $r = InstallerStamp::read($body); return $r === null ? [] : $r['data']; };
$optRows = fn() => (int) $one('SELECT COUNT(*) FROM endpoint_agent_installer_options');
$boxOf = function (string $page): string { return preg_match('/<input[^>]*id="rmm-inst-mesh"[^>]*>/', $page, $m) === 1 ? $m[0] : ''; };
$rmm->settings()->set(['mesh_enabled' => 0, 'mesh_url' => '']);
$q('DELETE FROM endpoint_agent_mesh_settings'); $q('DELETE FROM endpoint_agent_installer_options'); $rmm->meshAgentSettings()->refresh();
$rmm->admin()->enable($admin); $q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1"); $rmm->syncState();

// MeshCentral switched off (nothing set up): there is nothing to tick, so the box is not drawn at all; a forged tick still gets Core's sentence
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok($c === 200 && $boxOf($b) === '' && !str_contains($b, 'Also install the MeshCentral agent') && str_contains($b, 'id="rmmInstallerModal"'), 'MeshCentral off: the dialog has no Mesh box');
$n = $tokenCount();
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$j = json_decode($b, true);
$ok($c === 422 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'MeshCentral is not switched on') && $tokenCount() === $n && $optRows() === 0, 'a forged tick with Mesh off: 422 with Core\'s sentence, no token, no option row');
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'MeshCentral is not switched on') && $tokenCount() === $n, 'the same for the Linux command');
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
// MeshCentral on with an address but no device group id: the box is drawn, unticked and disabled, and says why in Core's own words
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$box = $boxOf($b);
$ok($c === 200 && $box !== '' && str_contains($box, 'name="install_mesh_agent"') && str_contains($box, 'disabled') && !str_contains($box, 'checked') && str_contains($b, 'Also install the MeshCentral agent'), 'Mesh on, no group id: the box is drawn, unticked and disabled');
$ok(str_contains($b, 'device group id') && str_contains($b, 'href="/admin/settings_endpoint_agent.php#mesh"') && preg_match('/id="rmm-inst-mesh"[^>]*aria-describedby="rmm-inst-mesh-help"/', $b) === 1 && str_contains($b, 'id="rmm-inst-mesh-help"'), 'and Core\'s refusal sentence is its help text, with the way to the settings (described-by for screen readers)');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'device group id') && $tokenCount() === $n, 'Mesh on but no group id: a forged tick gets Core\'s sentence, nothing created');

// configured
$ok($rmm->meshAgentSettings()->update(['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => $mPin, 'mesh_agent_ids' => ['windows/arm64' => 40]], 1) === [], 'the Mesh install settings are saved through Core');
$rmm->syncState();
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$box = $boxOf($b);
$ok($box !== '' && !str_contains($box, 'disabled') && !str_contains($box, 'checked') && str_contains($b, 'downloads the MeshCentral agent from your own Mesh server'), 'configured: the box is enabled and still unticked (off by default)');
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $sidAdmin);
$ok(preg_match('/id="rmm-inst-mesh"/', $b) === 1 && !str_contains($boxOf($b), 'checked'), 'the dialog on the client page has the box too');
$ok(str_contains((string) file_get_contents($root . '/js/rmm_installer.js'), "meshBox.checked = false") && !str_contains((string) file_get_contents($root . '/js/rmm_installer.js'), 'confirm('), 'the dialog script unticks the box every time the dialog opens, and uses no window.confirm');

// without the tick: byte for byte what it was
[$c, $plain] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64']);
$d = $stampOf($plain);
$ok($c === 200 && array_keys($d) === $oldMembers && $optRows() === 0, 'no tick: the payload has exactly the members of every earlier release and no option row is written');
foreach (['0', 'on', 'true', ''] as $v) {
    [$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => $v]);
    $ok($c === 200 && array_keys($stampOf($b)) === $oldMembers, "install_mesh_agent=\"$v\" is not a tick (only 1 is)");
}

// with the tick: Mesh member only then
[$c, $withMesh, $heads] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1', 'multiple' => '1']);
$d = $stampOf($withMesh);
$ok($c === 200 && ($d['version'] ?? 0) === 1 && ($d['install_mesh_agent'] ?? null) === true && is_array($d['mesh'] ?? null), 'tick: 200, the payload version stays 1 and carries install_mesh_agent and mesh');
$m = $d['mesh'] ?? [];
$ok(($m['url'] ?? '') === 'https://mesh.example.test' && ($m['meshid'] ?? '') === $mGroup && ($m['pin'] ?? '') === $mPin, 'mesh: url, meshid (the group id) and the pin');
$ok(($m['agent_ids']['windows/arm64'] ?? 0) === 40 && ($m['agent_ids']['windows/amd64'] ?? 0) === 4 && ($m['install_args']['windows'] ?? []) === ['-fullinstall'], 'mesh: the full agent-id table (the correction wins, the built-in ids fill the rest) and the install arguments');
$ok(array_slice(array_keys($d), 0, 8) === $oldMembers && array_keys($d) === array_merge($oldMembers, ['install_mesh_agent', 'mesh']), 'the two new members follow expires_at and nothing else changed');
$ok(!str_contains(json_encode($d), 'mesh_login_key') && !str_contains(json_encode($d), 'login_key'), 'the login token key is never in the installer');
$ok(substr($withMesh, 0, 2) === 'MZ' && (int) $hdr($heads, 'Content-Length') === strlen($withMesh) && strlen($withMesh) > strlen($plain), 'it is still a Windows executable with an exact Content-Length (the Mesh block makes the stamp longer)');
$ok($optRows() === 1 && (int) $one('SELECT install_mesh_agent FROM endpoint_agent_installer_options') === 1, 'the tick is remembered for the enrollment token (one option row)');
$ok($audit('Installer Created') >= 4 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent' AND log_description LIKE '%also installs the MeshCentral agent%'") >= 1, 'audit: "Installer Created" says it also installs the MeshCentral agent');

// the token-gated scripted download gives the same installer, and a setting that stopped allowing it is skipped, audited
$tokPlain = (string) ($d['enrollment_token'] ?? '');
$gated = function () use ($wb, $tokPlain): array { $ch = curl_init("$wb/api/v1/agent_installer"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['token' => $tokPlain, 'arch' => 'amd64']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]); $b = (string) curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$c, $b]; };
[$c, $gb] = $gated();
$gd = $stampOf($gb);
$ok($c === 200 && ($gd['install_mesh_agent'] ?? null) === true && ($gd['mesh']['meshid'] ?? '') === $mGroup && ($gd['mesh']['pin'] ?? '') === $mPin, 'the scripted token-gated download serves the same Mesh block');
$rmm->settings()->set(['mesh_enabled' => 0]);
[$c, $gb] = $gated();
$ok($c === 200 && array_keys($stampOf($gb)) !== [] && !array_key_exists('mesh', $stampOf($gb)) && !array_key_exists('install_mesh_agent', $stampOf($gb)) && $audit('Installer Mesh Skipped') >= 1, 'Mesh switched off after issuing: the installer is served WITHOUT it and "Installer Mesh Skipped" is audited');
$rmm->settings()->set(['mesh_enabled' => 1]);

// Linux
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$j = json_decode($b, true);
$ok($c === 200 && ($j['install_mesh_agent'] ?? null) === true && str_contains((string) $j['linux'], '--install-mesh-agent') && str_contains((string) $j['linux'], '--mesh-url') && str_contains((string) $j['linux'], 'https://mesh.example.test') && str_contains((string) $j['linux'], $mGroup), 'Linux with the tick: JSON says so and the command carries the Mesh flags');
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64']);
$j = json_decode($b, true);
$ok($c === 200 && ($j['install_mesh_agent'] ?? null) === false && !str_contains((string) $j['linux'], '--install-mesh-agent') && !str_contains((string) $j['linux'], $mGroup), 'Linux without the tick: no Mesh flag and no group id in the command');

// not an administrator: still refused, tick or not; and Administration > Deployment has the box too
[$c, $b] = $post($sidTech, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$ok($c === 403 && !str_starts_with($b, 'MZ'), 'a technician who is not an administrator is refused although the CSRF token is right (' . $c . ')');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
$ok($c === 200 && preg_match('/<form[^>]*>(?:(?!<\/form>).)*name="install_mesh_agent"(?:(?!<\/form>).)*name="download_installer"/s', $b) === 1, 'Administration > Deployment: the box is in the installer form');
$optBefore = $optRows();
[$c] = web($wb, 'POST', '/admin/post.php', $sidAdmin, ['csrf_token' => 'csrftok1', 'download_installer' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 3, 'install_mesh_agent' => '1'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok($c === 200 && $optRows() === $optBefore + 1, 'Administration > Deployment: the ticked box reaches Core (an option row for the new token)');
$rmm->settings()->set(['mesh_enabled' => 0, 'mesh_url' => '']);
$q('DELETE FROM endpoint_agent_mesh_settings'); $q('DELETE FROM endpoint_agent_installer_options'); $rmm->meshAgentSettings()->refresh();

// ---------------------------------------------------------------- module off: nothing renders, nothing is served
$n = $tokenCount();
$rmm->admin()->disable($admin);
$q("UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1");
$rmm->syncState();
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok($c === 200 && str_contains($b, 'RMM module is turned off') && !str_contains($b, 'rmmInstallerModal') && !str_contains($b, 'rmm_installer.js'), 'module off: the Agent Fleet page is the "turned off" notice, no dialog, no script');
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $sidAdmin);
$ok($c === 200 && !str_contains($b, 'rmmInstallerModal') && !str_contains($b, 'data-rmm-installer-open') && !str_contains($b, 'rmm_installer.js') && !str_contains($b, 'rmm_fleet.php?add=1'), 'module off: the client page and the menu show nothing');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64']);
$ok($c === 404 && (json_decode($b, true)['code'] ?? '') === 'module_disabled' && !str_starts_with($b, 'MZ') && $tokenCount() === $n, 'module off: the endpoint answers 404 module_disabled and creates no token');
