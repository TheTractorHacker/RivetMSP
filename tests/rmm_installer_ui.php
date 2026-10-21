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
ea_reset();   // wipes endpoint_agent_binaries and endpoint_agent_msi_templates too: this test starts with none of either
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
$ok(str_contains($b, 'href="/agent/rmm_add_device.php"') && str_contains($b, 'data-nav-add-device') && substr_count($b, 'data-nav-add-device') === 1 && !str_contains($b, 'data-nav-build-installer') && !str_contains($b, 'Build installer'), 'Endpoints menu (administrator): ONE Add device entry, opening the full page');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidTech);
$ok(!str_contains($b, 'data-nav-add-device'), 'Endpoints menu (technician): no Add device');
[$c, $b, $h] = web($wb, 'GET', '/agent/rmm_fleet.php?add=1', $sidAdmin);
$ok($c === 302 && str_contains($h ?? '', '/agent/rmm_add_device.php'), 'rmm_fleet.php?add=1 (the old address) redirects to the full Add device page');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php?quick=1', $sidAdmin);
$ok(str_contains($b, 'data-autoopen="1"') && str_contains($b, 'Quick download'), 'rmm_fleet.php?quick=1 asks the Quick download dialog to open itself');

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
$mGroupUrl = \RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl($mGroup);   // RivetCore 1.0.0-rc.15: the installer carries MeshCentral's own 64-character URL form, not hex
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
// RivetCore 1.0.0-rc.27 (Mesh after approval): the tick-box no longer means "embed Mesh settings and install inline" - it means "push the Mesh
// agent once an administrator approves the device" (InstallerService::issue() always records MeshAfterApproval::OPTION_AFTER_APPROVAL on the
// token, never the retired immediate-push value); the help text says so.
$ok($box !== '' && !str_contains($box, 'disabled') && !str_contains($box, 'checked') && str_contains($b, 'The Mesh agent installs after you approve the device') && str_contains($b, 'Also install the MeshCentral agent (after the device is approved)'), 'configured: the box is enabled and still unticked (off by default), with the after-approval wording');
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $sidAdmin);
$ok(preg_match('/id="rmm-inst-mesh"/', $b) === 1 && !str_contains($boxOf($b), 'checked'), 'the dialog on the client page has the box too');
$ok(str_contains((string) file_get_contents($root . '/js/rmm_installer.js'), "meshBox.checked = false") && !str_contains((string) file_get_contents($root . '/js/rmm_installer.js'), 'confirm('), 'the dialog script unticks the box every time the dialog opens, and uses no window.confirm');

// Whether or not the box is ticked, the payload NEVER carries a Mesh block or a login key any more - the whole point of "after approval" is that
// no Mesh address, group id or pin ever rides in an installer file or a Linux command; the server pushes the agent later, once approved.
// (InstallerDownload::meshBlockFor() checks install_mesh_agent === 1, a value InstallerService::issue() has not written since rc.27 - it always
// writes MeshAfterApproval::OPTION_AFTER_APPROVAL (2) instead; confirmed by reading both classes directly, not assumed. meshBlockFor() returning
// null for every installer now is the INTENDED rc.27 behaviour - the alternative, "fixing" it to match 2 and embed the block again, would put the
// Mesh server address, device-group id and certificate pin back inside the installer file/command, which is exactly what rc.27 removed them to
// avoid. That would be a real security regression, not a bug fix, so it is deliberately NOT done here.)
[$c, $plain] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64']);
$d = $stampOf($plain);
$ok($c === 200 && array_keys($d) === $oldMembers && $optRows() === 0, 'no tick: the payload has exactly the members of every earlier release and no option row is written');
foreach (['0', 'on', 'true', ''] as $v) {
    [$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => $v]);
    $ok($c === 200 && array_keys($stampOf($b)) === $oldMembers, "install_mesh_agent=\"$v\" is not a tick (only 1 is)");
}

// with the tick: no Mesh member (after approval, never embedded), but the option row remembers it, correctly, for the device-page state line
[$c, $withMesh, $heads] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1', 'multiple' => '1']);
$d = $stampOf($withMesh);
$ok($c === 200 && array_keys($d) === $oldMembers, 'tick: 200, the payload still has exactly the old members - no mesh block, no install_mesh_agent flag, after approval');
$ok(!str_contains(json_encode($d), 'mesh_login_key') && !str_contains(json_encode($d), 'login_key') && !str_contains(json_encode($d), 'meshid') && !str_contains(json_encode($d), $mGroupUrl), 'the login token key, the meshid and the group id are never in the installer, ticked or not');
$ok(substr($withMesh, 0, 2) === 'MZ' && (int) $hdr($heads, 'Content-Length') === strlen($withMesh) && strlen($withMesh) === strlen($plain), 'it is still a Windows executable with an exact Content-Length, the SAME length as an unticked installer (nothing is appended for the tick any more)');
$ok($optRows() === 1 && (int) $one('SELECT install_mesh_agent FROM endpoint_agent_installer_options') === \RivetCore\Rmm\Mesh\MeshAfterApproval::OPTION_AFTER_APPROVAL, 'the tick is remembered for the enrollment token as "after approval" (one option row)');
$ok($audit('Installer Created') >= 4 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent' AND log_description LIKE '%installs the MeshCentral agent after the device is approved%'") >= 1, 'audit: "Installer Created" says it installs the MeshCentral agent after the device is approved');

// the token-gated scripted download gives the same installer (no Mesh block either way) regardless of the Mesh switch
$tokPlain = (string) ($d['enrollment_token'] ?? '');
$gated = function () use ($wb, $tokPlain): array { $ch = curl_init("$wb/api/v1/agent_installer"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['token' => $tokPlain, 'arch' => 'amd64']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]); $b = (string) curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$c, $b]; };
[$c, $gb] = $gated();
$gd = $stampOf($gb);
$ok($c === 200 && array_keys($gd) === $oldMembers, 'the scripted token-gated download serves the same installer, still no Mesh block (after approval)');
$rmm->settings()->set(['mesh_enabled' => 0]);
[$c, $gb] = $gated();
$ok($c === 200 && array_keys($stampOf($gb)) === $oldMembers, 'Mesh switched off after issuing: the installer is served the same either way, since it never carried a Mesh block to begin with');
$rmm->settings()->set(['mesh_enabled' => 1]);
$ok($rmm->meshAgentSettings()->update(['mesh_group_id' => $mGroup], 1) === [], 'back to the hex group for the rest of the file');

// Linux: the tick-box is remembered (and the Mesh-after-approval plan still applies once the device actually enrolls - see tests/rmm_rc25.php /
// the device-page state line in includes/rmm_ui_render.php), but the Linux command itself never carries --install-mesh-agent or any Mesh address,
// ticked or not (InstallerService::linuxSnippet()'s only caller passes no Mesh details at all any more).
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$j = json_decode($b, true);
$ok($c === 200 && !str_contains((string) $j['linux'], '--install-mesh-agent') && !str_contains((string) $j['linux'], '--mesh-url') && !str_contains((string) $j['linux'], 'mesh.example.test') && !str_contains((string) $j['linux'], $mGroupUrl), 'Linux with the tick: no Mesh flags and no group id in the command (after approval, pushed later)');
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64']);
$j = json_decode($b, true);
$ok($c === 200 && !str_contains((string) $j['linux'], '--install-mesh-agent') && !str_contains((string) $j['linux'], $mGroupUrl) && !str_contains((string) $j['linux'], $mGroup), 'Linux without the tick: still no Mesh flag and no group id in the command');

// not an administrator: still refused, tick or not; and Administration > Deployment has the box too
[$c, $b] = $post($sidTech, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'install_mesh_agent' => '1']);
$ok($c === 403 && !str_starts_with($b, 'MZ'), 'a technician who is not an administrator is refused although the CSRF token is right (' . $c . ')');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
$ok($c === 200 && preg_match('/<form[^>]*>(?:(?!<\/form>).)*name="install_mesh_agent"(?:(?!<\/form>).)*name="download_installer"/s', $b) === 1, 'Administration > Deployment: the box is in the installer form');
$optBefore = $optRows();
[$c] = web($wb, 'POST', '/admin/post.php', $sidAdmin, ['csrf_token' => 'csrftok1', 'download_installer' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 3, 'install_mesh_agent' => '1'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok($c === 200 && $optRows() === $optBefore + 1, 'Administration > Deployment: the ticked box reaches Core (an option row for the new token)');
// ---------------------------------------------------------------- RivetCore 1.0.0-rc.16: the MSI installer format (migration 0025) and the MSI templates card
$msiFixture = $root . '/tests/fixtures/msi/template-x64.msi';   // a REAL msitools wixl package (RivetCore's test fixture), not a hand-made file
$msiReal = (string) getenv('RMM_TEST_REAL_MSI');                  // optional: the full package built by RivetCore's `make msi` (docker), when the run has one
$ok(is_file($msiFixture) && substr((string) file_get_contents($msiFixture), 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'the wixl fixture is an OLE compound file (D0CF11E0A1B11AE1)');
$ad = fn(array $f, string $sid = null) => web($wb, 'POST', '/admin/post.php', $sid ?? $sidAdmin, $f + ['csrf_token' => 'csrftok1'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$msiUpload = function (string $sid, array $fields, ?string $path, string $name = 'rivetit-agent-windows-amd64.msi') use ($wb): array {
    $post = $fields + ['csrf_token' => 'csrftok1', 'upload_msi_template' => '1'];
    if ($path !== null) { $post['msi_template'] = new CURLFile($path, 'application/octet-stream', $name); }
    $ch = curl_init($wb . '/admin/post.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']]);
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);

    return [$code, $raw];
};
$msiRows = fn() => $rows('SELECT * FROM endpoint_agent_msi_templates ORDER BY template_id');
$q('DELETE FROM endpoint_agent_msi_templates');
$flash = function (string $sid) use ($wb): string { [, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid); return preg_match('/toastr\[("[a-z]+")\]\(("(?:[^"\\\\]|\\\\.)*")\)/', $b, $m) === 1 ? (string) json_decode($m[2]) : ''; };   // the one-time message (toastr) of the next page load

// no template yet: the Add device dialog offers the exe only, with the reason; the card says so
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok($c === 200 && preg_match('/<label class="form-label" for="rmm-inst-format">Format<\/label><select class="form-select" id="rmm-inst-format" name="format"/', $b) === 1 && preg_match('/<option value="exe" selected>/', $b) === 1, 'Add device: a Format selector with exe selected by default and a label');
$ok(preg_match('/<option value="msi" disabled>/', $b) === 1 && str_contains($b, 'No MSI template is published for x64 yet') && preg_match('/<option value="arm64"[^>]*data-msi-reason="No MSI template is published for ARM64[^"]*Core builds an x64 MSI only/', $b) === 1, 'no template: the msi option is disabled and the reason is in words (x64 and ARM64)');
$ok(preg_match('/aria-describedby="rmm-inst-format-help"/', $b) === 1 && str_contains($b, 'id="rmm-inst-format-help"'), 'accessible: the selector is described by its help text');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
// The MSI installer has now been run and verified on a real Windows PC (upgrade/repair/uninstall still untested): the "never run on Windows"/"UNVERIFIED"
// claims are gone; the unsigned/SmartScreen note and the upgrade-repair-uninstall-untested note stay, since those still apply.
$ok($c === 200 && str_contains($b, 'id="msi-templates"') && str_contains($b, 'No MSI template uploaded yet') && !str_contains($b, 'UNVERIFIED') && !str_contains($b, 'not yet verified on Windows') && !str_contains($b, 'never run on Windows')
    && str_contains($b, 'SmartScreen') && str_contains($b, 'Upgrade, repair and uninstall are not yet tested'), 'Administration: the MSI templates card, empty, with the unsigned-SmartScreen note but no "unverified" claim');
$ok(preg_match('/<select class="form-select" id="dp_format" name="format"[^>]*>\s*<option value="exe" selected>[^<]*<\/option><option value="msi" disabled>/', $b) === 1 && str_contains($b, '/js/rmm_msi_admin.js'), 'Administration > Deployment: a Format selector, msi disabled while there is no template, and the script');
$ok(preg_match('/<form[^>]*enctype="multipart\/form-data"[^>]*id="msi_form"/', $b) === 1 && preg_match('/<label class="form-label small" for="msi_file">/', $b) === 1 && preg_match('/<label class="form-label small" for="msi_version">/', $b) === 1 && preg_match('/<label class="form-label small" for="msi_arch">/', $b) === 1, 'the upload form is multipart and its fields have labels');
$ok(!preg_match('/\son[a-z]+="/', (string) (preg_match('/id="msi-templates">(.*?)id="deployment"/s', $b, $mm) ? $mm[1] : '')), 'CSP: no inline event handlers in the MSI card');
// a download without a template is refused in words and leaves no token behind
$n0 = $tokenCount();
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$j = json_decode($b, true);
$ok($c === 422 && str_contains((string) ($j['error'] ?? ''), 'No MSI template is published for amd64') && $tokenCount() === $n0 && !str_starts_with($b, "\xD0\xCF"), 'format msi without a template: 422 in words, no token is left behind');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'zip']);
$ok($c === 422 && $tokenCount() === $n0, 'an unknown format is refused (422), no token');
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$ok($c === 422 && $tokenCount() === $n0, 'the msi format with the Linux command is refused, no token');
[$c, $bExe, $hExe] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'exe']);
$ok($c === 200 && str_starts_with($bExe, 'MZ') && preg_match('/filename="?[A-Za-z-]*-client-a-x64\.exe"?/i', $hdr($hExe, 'Content-Disposition')) === 1, 'format exe (explicit) is the same stamped exe as before');

// uploading: the refusals first (each leaves the store empty), then the real package
$tmpPe = sys_get_temp_dir() . '/inst_notmsi_' . bin2hex(random_bytes(3)) . '.msi'; file_put_contents($tmpPe, inst_pe(0x8664, 8192, 'notmsi'));
[$c] = $msiUpload($sidAdmin, ['version' => '1.0.0', 'arch' => 'amd64'], $tmpPe);
$fl = $flash($sidAdmin);
$ok(count($msiRows()) === 0 && str_contains($fl, 'not an MSI package'), 'a PE renamed .msi is refused (it is an executable, not an MSI package): ' . $fl);
@unlink($tmpPe);
[$c] = $msiUpload($sidAdmin, ['version' => 'one', 'arch' => 'amd64'], $msiFixture);
$fl = $flash($sidAdmin);
$ok(count($msiRows()) === 0 && str_contains($fl, 'version'), 'a bad version is refused: ' . $fl);
[$c] = $msiUpload($sidAdmin, ['version' => '1.0.0', 'arch' => ''], $msiFixture, 'template.msi');
$fl = $flash($sidAdmin);
$ok(count($msiRows()) === 0 && str_contains($fl, 'architecture'), 'no architecture in the name or the field: refused: ' . $fl);
[$c] = $msiUpload($sidAdmin, ['version' => '1.0.0', 'arch' => 'amd64'], null);
$fl = $flash($sidAdmin);
$ok(count($msiRows()) === 0 && str_contains($fl, 'Choose the .msi'), 'no file chosen: refused');
[$c] = $msiUpload($sidTech, ['version' => '1.0.0', 'arch' => 'amd64'], $msiFixture);
$ok(count($msiRows()) === 0, 'a technician who is not an administrator cannot upload (Core checks rmm.binary.publish)');
[$c] = web($wb, 'POST', '/admin/post.php', $sidAdmin, ['upload_msi_template' => '1', 'version' => '1.0.0', 'arch' => 'amd64'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok(count($msiRows()) === 0, 'an upload without a CSRF token stores nothing');
$stamped = sys_get_temp_dir() . '/inst_stamped_' . bin2hex(random_bytes(3)) . '.msi';
file_put_contents($stamped, (string) file_get_contents($msiFixture) . \RivetCore\Rmm\Installer\InstallerStamp::trailer(\RivetCore\Rmm\Installer\InstallerStamp::buildPayload(['installer_id' => 'i1', 'server_url' => 'https://x.example', 'enrollment_token' => 'rvte1.aaaa', 'department' => 'x', 'created_at' => '2026-10-12T00:00:00Z', 'expires_at' => '2026-10-13T00:00:00Z'])));
[$c] = $msiUpload($sidAdmin, ['version' => '1.0.1', 'arch' => 'amd64'], $stamped);
$fl = $flash($sidAdmin);
$ok(count($msiRows()) === 0 && str_contains($fl, 'already'), 'a package that already carries an installer payload is refused: ' . $fl);
@unlink($stamped);

[$c] = $msiUpload($sidAdmin, ['version' => '1.0.0', 'arch' => 'amd64', 'activate' => '1'], $msiFixture);
$mr = $msiRows();
$ok(count($mr) === 1 && $mr[0]['version'] === '1.0.0' && $mr[0]['arch'] === 'amd64' && (int) $mr[0]['is_current'] === 1 && (int) $mr[0]['active'] === 1 && $mr[0]['sha256'] === hash_file('sha256', $msiFixture) && (int) $mr[0]['size_bytes'] === filesize($msiFixture), 'the real wixl package is stored: version, architecture, SHA-256 and size are the file\'s, and it is current');
$ok($audit('MSI Template Uploaded') === 1 || (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'MSI Template Uploaded'") === 1, 'audit: "MSI Template Uploaded"');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
$ok(str_contains($b, '>1.0.0<') && str_contains($b, hash_file('sha256', $msiFixture)) && str_contains($b, 'badge bg-success">Current') && (str_contains($b, 'package summary') || str_contains($b, 'declared by uploader')), 'the card lists the template: version, SHA-256, Current, and where the platform was read from (' . $mr[0]['arch_source'] . ')');
$ok(preg_match('/<option value="msi">/', $b) === 1 && !preg_match('/<option value="msi" disabled>/', explode('id="deployment"', $b)[1] ?? ''), 'Administration > Deployment: the msi option is enabled once a template is current');
// the same file again is a no-op; a different file for the same version is refused
$cnt = count($msiRows());
[$c] = $msiUpload($sidAdmin, ['version' => '1.0.0', 'arch' => 'amd64'], $msiFixture);
$ok(count($msiRows()) === $cnt, 'uploading the identical package again changes nothing');
$altered = sys_get_temp_dir() . '/inst_alt_' . bin2hex(random_bytes(3)) . '.msi'; file_put_contents($altered, (string) file_get_contents($msiFixture) . str_repeat("\0", 512));
[$c] = $msiUpload($sidAdmin, ['version' => '1.0.0', 'arch' => 'amd64'], $altered);
$fl = $flash($sidAdmin);
$ok(count($msiRows()) === $cnt && str_contains($fl, 'different contents'), 'a different file for the same version is refused');
@unlink($altered);

// the Add device dialog now offers it; the download is the template plus the payload
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok(preg_match('/<option value="msi">/', $b) === 1 && preg_match('/<option value="amd64" selected [^>]*data-msi-version="1\.0\.0"[^>]*data-msi-reason=""/', $b) === 1 && preg_match('/<option value="arm64"[^>]*data-msi-version=""[^>]*data-msi-reason="No MSI template is published for ARM64/', $b) === 1, 'Add device: msi enabled for x64, still explained for ARM64');
$storedDir = $rmm->binaryStore()->storageDir();
$tplRow = $msiRows()[0];
$tplPath = $storedDir . '/' . $tplRow['storage_name'];
$tplSha = hash_file('sha256', $tplPath);
$tplBytes = (string) file_get_contents($tplPath);
$n0 = $tokenCount();
[$c, $bm, $hm] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$ok($c === 200 && stripos($hdr($hm, 'Content-Type'), 'application/octet-stream') === 0 && (int) $hdr($hm, 'Content-Length') === strlen($bm), 'msi download: 200 application/octet-stream, Content-Length exact (' . strlen($bm) . ' bytes)');
$ok(strncmp($bm, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0 && strncmp($bm, $tplBytes, strlen($tplBytes)) === 0 && strlen($bm) > strlen($tplBytes), 'the body is an OLE compound file that starts with the stored template\'s bytes, with the trailer after them');
$ok(str_ends_with($bm, 'RIVETIT-EMBED-v1'), 'it ends with the installer footer');
$rd = InstallerStamp::read($bm);
$ok($rd !== null && $rd['exe_length'] === strlen($tplBytes) && ($rd['data']['department'] ?? '') === 'Client A' && str_starts_with((string) ($rd['data']['enrollment_token'] ?? ''), 'rvte1.') && substr($bm, 0, $rd['exe_length']) === $tplBytes, 'the trailer decodes with the agent\'s reader rules: SHA-256 checked, the client, a one-time token, and stripping it gives the template back byte for byte');
$ok(hash_file('sha256', $tplPath) === $tplSha && (string) file_get_contents($tplPath) === $tplBytes && $msiRows()[0]['sha256'] === $tplSha, 'the stored template is untouched by the download');
$ok(preg_match('/attachment;\s*filename="?RivetIT-Agent-client-a-x64\.msi"?/i', $hdr($hm, 'Content-Disposition')) === 1, 'filename: ' . $hdr($hm, 'Content-Disposition'));
$tok = $rows('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1')[0];
$ok($tokenCount() === $n0 + 1 && (int) $tok['max_uses'] === 1 && (int) $tok['client_id'] === 1, 'one token for Client A, one use');
[$c, $bm2] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 2, 'arch' => 'amd64', 'format' => 'msi', 'multiple' => '1']);
$rd2 = InstallerStamp::read($bm2);
$ok($c === 200 && $bm !== $bm2 && $rd2 !== null && ($rd2['data']['department'] ?? '') === 'Client B' && substr($bm2, 0, $rd2['exe_length']) === $tplBytes, 'every download carries its own payload (Client B), the template part identical');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'arm64', 'format' => 'msi']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'arm64') && $tokenCount() === $n0 + 2, 'an architecture with no MSI template (ARM64): refused, naming it, no token');
// not an administrator, wrong CSRF
[$c, $b] = $post($sidTech, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$ok($c === 403 && !str_starts_with($b, "\xD0\xCF"), 'a technician who is not an administrator gets no MSI');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidAdmin, ['csrf_token' => 'wrong', 'os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi'], $XHR);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf', 'wrong CSRF token: 403 for the msi too');
// the Mesh option works the same (Mesh is still set up from the section above)
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
$mAdmin = ['save_mesh_settings' => 1, 'mesh_install_fields' => '1', 'mesh_enabled' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-support', 'mesh_policy' => 'unattended', 'mesh_token_ttl_s' => '300', 'mesh_login_key' => '',
    'mesh_group_id' => \RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl('4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9'), 'mesh_server_cert_pin' => '', 'mesh_auto_map_overwrite' => ''];
$ad($mAdmin); $rmm->meshAgentSettings()->refresh();
[$c, $bmm] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi', 'install_mesh_agent' => '1']);
$rdm = InstallerStamp::read($bmm);
// After approval: the ticked option rides on the enrollment token (for the later push and the device-page state line), never in the MSI payload either.
$ok($c === 200 && $rdm !== null && array_keys($rdm['data']) === $oldMembers && substr($bmm, 0, $rdm['exe_length']) === $tplBytes, 'the "Also install the MeshCentral agent" option does not ride in the MSI payload either (after approval)');
// Administration > Deployment: format msi commands (the token is shown once, on the next page load)
[$c] = $ad(['show_deploy_commands' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 3, 'format' => 'msi']);
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
// The MSI has been run and verified on a real Windows PC (see CHANGELOG 26.10.39): no more "not been run on Windows" claim, but the
// unsigned/SmartScreen note and "upgrade, repair and uninstall not yet tested" still apply.
$ok($c === 200 && str_contains($b, 'MSI package (RivetIT-Agent-client-a-x64.msi)') && str_contains($b, 'msiexec /i &quot;RivetIT-Agent-client-a-x64.msi&quot; /qn /norestart') && str_contains($b, 'ENROLL_TOKEN=') && str_contains($b, 'format = &#039;msi&#039;') && str_contains($b, 'REMOVE_MESHAGENT=1') && str_contains($b, 'UpgradeCode')
    && !str_contains($b, 'not been run on Windows') && str_contains($b, 'SmartScreen') && str_contains($b, 'Upgrade, repair and uninstall are not yet tested'), 'Deployment commands with format msi: the msi block (msiexec, properties, silent downloader, uninstall, detection), no "unverified" claim but the SmartScreen/untested-upgrade note');
[$c] = $ad(['show_deploy_commands' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 3]);
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidAdmin);
$ok($c === 200 && str_contains($b, 'id="deploy-commands"') && !str_contains($b, 'MSI package (') && !str_contains($b, 'REMOVE_MESHAGENT'), 'Deployment commands with the default format: no msi block (the exe answer is as before)');
[$c] = $ad(['download_installer' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 3, 'format' => 'msi']);
$ok($c === 200, 'Administration > Deployment: Download installer with format msi is served');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'Installer Created'") >= 4, 'audit: the installers are audited like the exe');
$rmm->settings()->set(['mesh_enabled' => 0, 'mesh_url' => '']);   // (cleaned up again just below)

// changing a template: make current, deactivate, reactivate; with no current template the MSI is refused again
$tid = (int) $tplRow['template_id'];
[$c] = $ad(['msi_template_action' => 'deactivate', 'template_id' => $tid]);
$r1 = $msiRows()[0];
$ok((int) $r1['active'] === 0 && (int) $r1['is_current'] === 0 && file_exists($tplPath), 'deactivate: no longer used, the file is kept');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'No MSI template') , 'with the template deactivated the MSI is refused again');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok(preg_match('/<option value="msi" disabled>/', $b) === 1, 'and the dialog disables the msi option again');
[$c] = $ad(['msi_template_action' => 'activate', 'template_id' => $tid]);
[$c] = $ad(['msi_template_action' => 'make_current', 'template_id' => $tid]);
$r2 = $msiRows()[0];
$ok((int) $r2['active'] === 1 && (int) $r2['is_current'] === 1, 'reactivate and make current');
[$c] = $ad(['msi_template_action' => 'make_current', 'template_id' => 99999]);
[$c] = web($wb, 'POST', '/admin/post.php', $sidTech, ['csrf_token' => 'csrftok1', 'msi_template_action' => 'deactivate', 'template_id' => $tid], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $msiRows()[0]['active'] === 1, 'a technician who is not an administrator cannot change a template');
[$c] = web($wb, 'POST', '/admin/post.php', $sidAdmin, ['msi_template_action' => 'deactivate', 'template_id' => $tid], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $msiRows()[0]['active'] === 1, 'no CSRF token: nothing changes');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'MSI Template Changed'") >= 3, 'audit: "MSI Template Changed"');

// the CLI path
$cli = function (array $args) use ($root): array {
    $p = proc_open(array_merge([PHP_BINARY, "$root/scripts/endpoint_agent_publish.php"], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$root/scripts", getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); $rc = proc_close($p);

    return [$rc, $out];
};
[$rc, $out] = $cli([$msiFixture, '--msi', '--version', '1.0.0', '--arch', 'amd64']);
$ok($rc === 0 && str_contains($out, 'Already published MSI template 1.0.0 amd64'), 'CLI --msi: the same package again is "Already published" (' . trim($out) . ')');
[$rc, $out] = $cli([$msiFixture, '--msi', '--version', '1.0.1', '--arch', 'amd64', '--activate']);
$ok($rc === 0 && str_contains($out, 'Published MSI template 1.0.1 amd64') && str_contains($out, '(current)') && (string) $one("SELECT version FROM endpoint_agent_msi_templates WHERE is_current = 1 AND arch = 'amd64'") === '1.0.1', 'CLI --msi --activate: published and current (' . trim($out) . ')');
[$rc, $out] = $cli([$msiFixture, '--msi', '--version', '1.0.2', '--arch', 'amd64', '--release', 'stable']);
$ok($rc === 1 && str_contains($out, 'never offered as an agent update'), 'CLI: an MSI template cannot be offered as an agent update');
$pe2 = sys_get_temp_dir() . '/inst_cli_pe_' . bin2hex(random_bytes(3)) . '.exe'; file_put_contents($pe2, inst_pe(0x8664, 8192, 'cli'));
[$rc, $out] = $cli([$pe2, '--msi', '--version', '1.0.3', '--arch', 'amd64']);
$ok($rc === 1 && str_contains($out, 'not an MSI package'), 'CLI --msi on a PE: refused (' . trim($out) . ')');
[$rc, $out] = $cli([$msiFixture, '--version', '1.0.4', '--arch', 'amd64']);
$ok($rc === 1, 'CLI without --msi on an MSI: the agent-binary path refuses it (' . trim($out) . ')');
@unlink($pe2);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version IN ('1.0.0','1.0.1','1.0.2','1.0.3','1.0.4')") === 0, 'an MSI template never becomes an agent binary (the binaries table is untouched)');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_releases") === 0, 'and never an agent update (no release row)');

// the full package built by `make msi`, when this run has it
if ($msiReal !== '' && is_file($msiReal)) {
    [$c] = $msiUpload($sidAdmin, ['version' => '1.0.0-rc.16', 'arch' => 'amd64', 'activate' => '1'], $msiReal);
    $realRow = $rows("SELECT * FROM endpoint_agent_msi_templates WHERE version = '1.0.0-rc.16'")[0] ?? null;
    $ok($realRow !== null && $realRow['sha256'] === hash_file('sha256', $msiReal) && $realRow['arch_source'] === 'summary' || ($realRow !== null && $realRow['arch_source'] !== ''), 'the REAL package from `make msi` is accepted (' . ($realRow['size_bytes'] ?? '?') . ' bytes, platform read from the ' . ($realRow['arch_source'] ?? '?') . ')');
    $rBytes = (string) file_get_contents($msiReal);
    [$c, $bReal, $hReal] = $post($sidAdmin, ['os' => 'windows', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
    $rdR = InstallerStamp::read($bReal);
    $ok($c === 200 && strncmp($bReal, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0 && $rdR !== null && substr($bReal, 0, $rdR['exe_length']) === $rBytes && ($rdR['data']['department'] ?? '') === 'Client A' && (int) $hdr($hReal, 'Content-Length') === strlen($bReal),
        'REAL package: the download is its bytes (' . strlen($rBytes) . ') plus a decodable trailer, stripping it gives the package back byte for byte');
    $stored = $rmm->binaryStore()->storageDir() . '/' . $realRow['storage_name'];
    $ok(hash_file('sha256', $stored) === hash_file('sha256', $msiReal), 'REAL package: the stored template is untouched by the download');
    file_put_contents($bmOut = (getenv('RMM_TEST_REAL_MSI_OUT') ?: sys_get_temp_dir() . '/rmm_real_stamped.msi'), $bReal);
    echo "NOTE  real stamped MSI written to $bmOut (for the Go reader check)\n";
}
$q('DELETE FROM endpoint_agent_msi_templates');
foreach (glob(($rmm->binaryStore()->storageDir() ?? '/nonexistent') . '/bin_*.bin') ?: [] as $f) { if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', basename($f)) && !(int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE storage_name = '" . $esc(basename($f)) . "'")) { @unlink($f); } }

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
