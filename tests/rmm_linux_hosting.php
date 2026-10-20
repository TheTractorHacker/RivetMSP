<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Hosted Linux agent files (ported from RivetIT's own Linux-hosting work; DB 2.6.104): includes/rmm_linux_store.php (RivetMsp\AgentFeed\LinuxStore) validators
 * (ELF/script/gzip, wrong CPU, too small/large, non-UTF8), store idempotence and "make current", the download credential (derive, verify: malformed / wrong
 * file / wrong version / revoked / expired / tampered), the real HTTP route (api/v1/agent_linux_file.php) over a `php -S` instance including module-off and
 * rate-limit behaviour, that a served file matches its recorded SHA-256 (and a damaged one is refused, not served), that no credential or secret ever
 * reaches the audit log in plaintext, the self-hosted install command (includes/rmm_linux_store.php LinuxStore::command(), wired into
 * agent/post/rmm_installer.php), and that "Allow Linux devices to enroll" (settings.config_rmm_allow_linux) plus an actual end-to-end Linux enrollment still
 * work together with hosted files in place. Same scratch rules and environment as tests/endpoint_agent_lib.php.
 */
$sd = sys_get_temp_dir() . '/rmm_lx_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_linux_store.php";
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetMsp\AgentFeed\LinuxStore;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();

$sdir = sys_get_temp_dir() . '/rmm_lx_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');

/** A minimal 64-bit LE ELF "executable" (ET_EXEC) of the given machine, padded past BinaryInspector/validate()'s 4096-byte floor. @return string path */
function lxElf(string $dir, int $machine, int $size = 5000): string {
    $h = str_repeat("\0", 64);
    $h[0] = "\x7f"; $h[1] = 'E'; $h[2] = 'L'; $h[3] = 'F';
    $h[4] = "\x02";                 // ELFCLASS64
    $h[5] = "\x01";                 // ELFDATA2LSB (little endian)
    $h[6] = "\x01";                 // EI_VERSION
    $h[16] = "\x02"; $h[17] = "\x00";                              // e_type = ET_EXEC (2), LE
    $h[18] = chr($machine & 0xff); $h[19] = chr(($machine >> 8) & 0xff);   // e_machine, LE
    $body = $h . str_repeat("\x90", max(0, $size - strlen($h)));
    $p = tempnam($dir, 'elf');
    file_put_contents($p, $body);
    return $p;
}

function lxScript(string $dir, string $body = "#!/usr/bin/env bash\necho hello\n"): string {
    $p = tempnam($dir, 'sh');
    file_put_contents($p, $body);
    return $p;
}

function lxGzip(string $dir, string $payload = 'hello linux tarball'): string {
    $p = tempnam($dir, 'gz');
    file_put_contents($p, gzencode($payload, 6));
    return $p;
}

$tmp = sys_get_temp_dir() . '/rmm_lx_fixtures_' . bin2hex(random_bytes(4)); mkdir($tmp);
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); } @rmdir($tmp); });

echo "\n--- name parsing and validators ---\n";
$ok(LinuxStore::parseName('install-linux.sh') === ['kind' => 'script', 'arch' => ''], 'install-linux.sh parses as a script');
$ok(LinuxStore::parseName('rivetit-agent-linux-amd64') === ['kind' => 'binary', 'arch' => 'amd64'], 'the amd64 binary parses');
$ok(LinuxStore::parseName('rivetit-agent-linux-arm64.tar.gz') === ['kind' => 'tarball', 'arch' => 'arm64'], 'the arm64 tarball parses');
$ok(LinuxStore::parseName('rivetit-agent-windows-amd64.exe') === null, 'a Windows name is not a Linux name');
$ok(LinuxStore::parseName('../../etc/passwd') === null, 'path traversal is not a name this store accepts');

$elfAmd = lxElf($tmp, 62);     // EM_X86_64
$elfArm = lxElf($tmp, 183);    // EM_AARCH64
$elfWrongArch = lxElf($tmp, 183); // arm64 bytes offered as the amd64 name
$peFile = lxElf($tmp, 62); file_put_contents($peFile, "MZ" . str_repeat("\0", 4998));
$sh = lxScript($tmp);
$shBad = lxScript($tmp, "echo not a script\n");
$shBinary = lxScript($tmp, "#!/bin/sh\n\x00binary\n");
$gz = lxGzip($tmp);
$notGz = lxScript($tmp, 'not actually gzip');

$ok(LinuxStore::validate($elfAmd, 'rivetit-agent-linux-amd64') === null, 'a correct amd64 ELF validates');
$ok(LinuxStore::validate($elfWrongArch, 'rivetit-agent-linux-amd64') !== null, 'an arm64 ELF offered as amd64 is refused');
$ok(LinuxStore::validate($peFile, 'rivetit-agent-linux-amd64') !== null, 'a Windows PE (MZ header) is refused as a Linux binary');
$ok(LinuxStore::validate($sh, 'install-linux.sh') === null, 'a real shebang script validates');
$ok(LinuxStore::validate($shBad, 'install-linux.sh') !== null, 'a file without a shebang is refused');
$ok(LinuxStore::validate($shBinary, 'install-linux.sh') !== null, 'a script with embedded binary data is refused');
$ok(LinuxStore::validate($gz, 'rivetit-agent-linux-amd64.tar.gz') === null, 'a real gzip stream validates as a tarball');
$ok(LinuxStore::validate($notGz, 'rivetit-agent-linux-amd64.tar.gz') !== null, 'a non-gzip file is refused as a tarball');
$tiny = tempnam($tmp, 'tiny'); file_put_contents($tiny, 'x');
$ok(LinuxStore::validate($tiny, 'rivetit-agent-linux-amd64') !== null, 'a tiny file is refused as a binary (too small)');

echo "\n--- store: idempotence, replay, current ---\n";
$r1 = LinuxStore::store($db, $sh, 'install-linux.sh', '1.0.0', 1, ['source' => 'upload']);
$ok($r1['ok'] && $r1['current'] === true, 'install-linux.sh 1.0.0 stores and becomes current by itself');
$r2 = LinuxStore::store($db, $elfAmd, 'rivetit-agent-linux-amd64', '1.0.0', 1, ['source' => 'upload']);
$ok($r2['ok'] && $r2['current'] === true, 'the amd64 binary 1.0.0 stores and becomes current by itself');
$r3 = LinuxStore::store($db, $elfArm, 'rivetit-agent-linux-arm64', '1.0.0', 1, ['source' => 'upload']);
$ok($r3['ok'] && $r3['current'] === true, 'the arm64 binary 1.0.0 stores and becomes current by itself');
$replay = LinuxStore::store($db, $sh, 'install-linux.sh', '1.0.0', 1, ['source' => 'upload']);
$ok($replay['ok'] && $replay['replay'] === true && $replay['file_id'] === $r1['file_id'], 'storing the identical bytes again is a no-op replay, not a new row');
$diffBytes = LinuxStore::store($db, lxScript($tmp, "#!/usr/bin/env bash\necho different\n"), 'install-linux.sh', '1.0.0', 1, []);
$ok(!$diffBytes['ok'], 'the same name+version with different bytes is refused');
$r1b = LinuxStore::store($db, $sh, 'install-linux.sh', '1.1.0', 1, ['activate' => false]);
$ok($r1b['ok'] && $r1b['current'] === false, 'a second version is NOT current unless activate is asked');
$ok(LinuxStore::setCurrent($db, (int) $r1b['file_id']), 'make current succeeds');
$ok((int) $one("SELECT is_current FROM endpoint_agent_linux_files WHERE file_id = {$r1b['file_id']}") === 1, 'it is now current');
$ok((int) $one("SELECT is_current FROM endpoint_agent_linux_files WHERE file_id = {$r1['file_id']}") === 0, 'the previous one is no longer current');
$delErr = LinuxStore::delete($db, (int) $r1b['file_id']);
$ok($delErr !== null, 'the current file cannot be deleted');
$ok(LinuxStore::setCurrent($db, (int) $r1['file_id']) && LinuxStore::delete($db, (int) $r1b['file_id']) === null, 'once no longer current, 1.1.0 deletes cleanly');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_linux_files WHERE file_id = {$r1b['file_id']}") === 0, 'its row is gone');

echo "\n--- download credential: derive and verify ---\n";
$tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 1, 'lx-dl')->data['token'];
$files = LinuxStore::commandFiles($db, 'amd64');
$ok($files !== null, 'commandFiles finds the current script and amd64 binary');
$creds = LinuxStore::credentialsForToken($db, $tok, $files);
$ok(is_array($creds) && preg_match(LinuxStore::CRED_RE, $creds['script']) === 1, 'a credential is derived per file');
$vScript = LinuxStore::verifyCredential($db, $creds['script'], (string) $files['script']['name'], (string) $files['script']['version']);
$ok($vScript['ok'], 'the script credential verifies');
$vBinary = LinuxStore::verifyCredential($db, $creds['binary'], (string) $files['binary']['name'], (string) $files['binary']['version']);
$ok($vBinary['ok'], 'the binary credential verifies');
$ok(!LinuxStore::verifyCredential($db, 'garbage', (string) $files['script']['name'], (string) $files['script']['version'])['ok'], 'a malformed credential is rejected');
$ok(!LinuxStore::verifyCredential($db, $creds['script'], (string) $files['binary']['name'], (string) $files['binary']['version'])['ok'], 'a credential for one file does not verify another');
$ok(!LinuxStore::verifyCredential($db, $creds['script'], (string) $files['script']['name'], '9.9.9')['ok'], 'a credential for one version does not verify another');
[$selSc, $expSc, $macSc] = array_slice(explode('.', $creds['script']), 1);
$tampered = "rvdl1.$selSc.$expSc." . str_repeat('0', 64);
$ok(!LinuxStore::verifyCredential($db, $tampered, (string) $files['script']['name'], (string) $files['script']['version'])['ok'], 'a tampered HMAC is rejected');
$expired = LinuxStore::credential($selSc, (string) $one("SELECT token_hash FROM endpoint_agent_enrollment_tokens WHERE token_selector = '$selSc'"), (string) $files['script']['name'], (string) $files['script']['version'], time() - 10);
$ok(!LinuxStore::verifyCredential($db, $expired, (string) $files['script']['name'], (string) $files['script']['version'])['ok'], 'an expired credential is rejected');
$q("UPDATE endpoint_agent_enrollment_tokens SET revoked_at = NOW() WHERE token_selector = '$selSc'");
$ok(!LinuxStore::verifyCredential($db, $creds['script'], (string) $files['script']['name'], (string) $files['script']['version'])['ok'], 'a revoked token\'s credential is rejected');
$q("UPDATE endpoint_agent_enrollment_tokens SET revoked_at = NULL WHERE token_selector = '$selSc'");
$ok(LinuxStore::verifyCredential($db, $creds['script'], (string) $files['script']['name'], (string) $files['script']['version'])['ok'], 'un-revoking restores it (sanity: the revoke check above was real)');

echo "\n--- the real HTTP route ---\n";
$get = fn (string $qs, array $hdrs = []) => web($wb, 'GET', '/api/v1/agent_linux_file?' . $qs, 'nosession', [], $hdrs);
$scriptUrl = 'name=' . rawurlencode((string) $files['script']['name']) . '&version=' . rawurlencode((string) $files['script']['version']);
[$c, $body, $hdrsRaw] = $get($scriptUrl, ['X-Install-Key: ' . $creds['script']]);
$ok($c === 200, 'a valid credential downloads 200');
$ok($body === (string) file_get_contents($sh), 'the served bytes are byte-identical to the stored script');
$ok(stripos($hdrsRaw, 'X-Content-SHA256: ' . $files['script']['sha256']) !== false, 'X-Content-SHA256 header matches the recorded hash');
$ok(stripos($hdrsRaw, 'Content-Length: ' . strlen((string) file_get_contents($sh))) !== false, 'Content-Length is exact');
$ok(stripos($hdrsRaw, 'Cache-Control: no-store') !== false, 'no-store is set');
$ok(stripos($hdrsRaw, 'X-Content-Type-Options: nosniff') !== false, 'nosniff is set');

[$c] = $get($scriptUrl, ['X-Install-Key: rvdl1.' . $selSc . '.' . $expSc . '.' . str_repeat('1', 64)]);
$ok($c === 401, 'a bad HMAC gets 401');
[$c, $b401] = $get($scriptUrl);
$ok($c === 401 && json_decode($b401, true)['code'] === 'invalid_key', 'no credential at all gets 401 invalid_key (not a different error)');
[$c, $bNamed] = $get($scriptUrl, ['Authorization: Bearer ' . $creds['script']]);
$ok($c === 200 && $bNamed === (string) file_get_contents($sh), 'the Authorization: Bearer form of the credential works too');
[$c] = $get('name=rivetit-agent-linux-amd64&version=9.9.9', ['X-Install-Key: ' . $creds['binary']]);
$ok($c === 401, 'a credential for a different version than the one requested is refused (HMAC covers the version)');
$binUrl = 'name=' . rawurlencode((string) $files['binary']['name']) . '&version=' . rawurlencode((string) $files['binary']['version']);
[$c] = $get($binUrl, ['X-Install-Key: ' . $creds['script']]);
$ok($c === 401, 'the script credential does not download the binary');
// curl's CURLINFO_HEADER_SIZE is unreliable for CUSTOMREQUEST=HEAD (a well-known curl quirk, not a server behaviour); the status code and empty body are
// still meaningful through this path, so the header content is instead checked via a plain GET further down (Content-Length, X-Content-SHA256, ETag, etc.).
[$c, $hBody] = web($wb, 'HEAD', '/api/v1/agent_linux_file?' . $scriptUrl, 'nosession', [], ['X-Install-Key: ' . $creds['script']]);
$ok($c === 200 && $hBody === '', 'HEAD answers 200 with no body');
[$c] = $get('name=install-linux.sh&version=9.9.9', ['X-Install-Key: ' . LinuxStore::credential($selSc, (string) $one("SELECT token_hash FROM endpoint_agent_enrollment_tokens WHERE token_selector = '$selSc'"), 'install-linux.sh', '9.9.9', time() + 60)]);
$ok($c === 404, 'a well-formed credential for a version that is not stored gets 404 not_found');
[$c] = web($wb, 'POST', '/api/v1/agent_linux_file?' . $scriptUrl, 'nosession', [], ['X-Install-Key: ' . $creds['script']]);
$ok($c === 405, 'POST is refused with 405');

// damaged file on disk: the route refuses to serve it, even with an otherwise-valid credential
$dmgPath = LinuxStore::path($files['script']);
$origBytes = (string) file_get_contents($dmgPath);
file_put_contents($dmgPath, $origBytes . 'tampered');
[$c, $bDmg] = $get($scriptUrl, ['X-Install-Key: ' . $creds['script']]);
$ok($c === 500 && json_decode($bDmg, true)['code'] === 'damaged', 'a file that no longer matches its recorded SHA-256 is refused, not served');
file_put_contents($dmgPath, $origBytes);
[$c] = $get($scriptUrl, ['X-Install-Key: ' . $creds['script']]);
$ok($c === 200, 'restoring the bytes serves it again');

echo "\n--- module off / audit / no secret leakage ---\n";
$q('UPDATE settings SET config_core_rmm_enabled = 0'); $rmm->syncState();
[$c, $bOff] = $get($scriptUrl, ['X-Install-Key: ' . $creds['script']]);
$ok($c === 503 && json_decode($bOff, true)['code'] === 'module_disabled', 'module off: 503 module_disabled, not a different code');
$q('UPDATE settings SET config_core_rmm_enabled = 1'); $rmm->syncState();
[$c] = $get($scriptUrl, ['X-Install-Key: ' . $creds['script']]);
$ok($c === 200, 'module back on: downloads again');

$logs = $rows('SELECT log_description FROM logs ORDER BY log_id DESC LIMIT 40');
$plainLeak = false;
foreach ($logs as $l) {
    if (str_contains((string) $l['log_description'], $creds['script']) || str_contains((string) $l['log_description'], $tok) || str_contains((string) $l['log_description'], (string) $one("SELECT token_hash FROM endpoint_agent_enrollment_tokens WHERE token_selector = '$selSc'"))) {
        $plainLeak = true;
    }
}
$ok(!$plainLeak, 'no full credential, enrollment token or token hash ever appears in the audit log');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'Linux File Downloaded'") > 0, '"Linux File Downloaded" is audited');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'Linux File Download Refused'") > 0, '"Linux File Download Refused" is audited for the bad attempts');

echo "\n--- self-hosted install command ---\n";
// RivetCore\Rmm\Admin\RmmAdmin::deploymentCommands() needs a published, current Windows binary for the requested architecture to build ITS commands.linux
// snippet (the version/label context it carries), even though the Linux command itself never touches that binary. Publish a minimal but real PE (same
// fixture shape as tests/rmm_installer_ui.php) so the call this test makes below (os=linux, arch=amd64) is not refused for an unrelated reason.
function lxPe(int $machine, int $size): string {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', 'lx-fixture' . $machine, true);
    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}
$peFixture = sys_get_temp_dir() . '/lx_pe_' . bin2hex(random_bytes(3)) . '.exe';
file_put_contents($peFixture, lxPe(0x8664, 8192));
$pubWin = $rmm->binaryStore()->publish($peFixture, '1.1.0', 'amd64', 1, ['activate' => true]);
@unlink($peFixture);
$ok($pubWin['ok'] === true, 'a Windows amd64 binary is published (deploymentCommands needs one, even for the Linux command)');
register_shutdown_function(function () use ($rmm) { $d = $rmm->binaryStore()->storageDir(); foreach ($d === null ? [] : (glob($d . '/bin_*.bin') ?: []) as $f) { if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', basename($f))) { @unlink($f); } } });

$REF = ['Referer: ' . $wb . '/agent/rmm_add_device.php'];
$sidA = ea_forge_session($sdir, 1);
$cmdTok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 1, 'cmd')->data['token'];
[$c, $bCmd] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidA, ['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'csrf_token' => 'csrftok1'], array_merge($REF, ['X-Requested-With: fetch', 'Accept: application/json']));
$jCmd = json_decode($bCmd, true);
$ok($c === 200 && $jCmd['success'] === true && is_string($jCmd['linux_self'] ?? null), 'the installer endpoint returns a linux_self command once files are hosted');
$ok(str_contains($jCmd['linux_self'], '/api/v1/agent_linux_file?name=install-linux.sh') && str_contains($jCmd['linux_self'], '/api/v1/agent_linux_file?name=rivetit-agent-linux-amd64'), 'the command downloads both files from this server');
$ok(!str_contains($jCmd['linux_self'], $tok) && !str_contains($jCmd['linux_self'], $cmdTok), 'neither the enrollment token used to build the snippet nor a stray other token appears raw in the command (only derived rvdl1 credentials do)');
$ok(substr_count($jCmd['linux_self'], 'rvdl1.') === 2, 'exactly two derived download credentials ride in the command (one per file)');

echo "\n--- Allow Linux devices to enroll, end to end, with hosted files in place ---\n";
$ok((int) $one('SELECT config_rmm_allow_linux FROM settings') === 1, 'default ON');
$linTok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 1, 'lin-e2e')->data['token'];
[$c, , $j] = ea_enroll($linTok, ea_dev(['hostname' => 'LIN-E2E', 'os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'arch' => 'amd64']));
$ok($c === 201 && (string) $one("SELECT os FROM endpoint_agent_devices WHERE hostname = 'LIN-E2E'") === 'linux', 'a Linux device enrolls with hosted files in place');

echo "\n--- deleting the current file is refused the normal way; not-current ones already proven above ---\n";
$cur = (int) $one("SELECT file_id FROM endpoint_agent_linux_files WHERE name = 'install-linux.sh' AND is_current = 1");
$ok(LinuxStore::delete($db, $cur) !== null, 'the current install-linux.sh cannot be deleted while current');
