<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Fragment of tests/rmm_agent_feed.php (shares its variables): the Add device / Build installer page, the extended installer handler (agent/post/rmm_installer.php) and the Linux
 * scripts it hands out. State on entry: release 0.9.1 is published (both Windows executables, the MSI template, the Linux links); the module is on; service URL = the test server.
 */

use RivetCore\Rmm\Installer\InstallerStamp;
use RivetMsp\AgentFeed\LinuxInstall;

$XHR = ['X-Requested-With: fetch', 'Accept: application/json, application/octet-stream'];
$post = fn(string $sid, array $f, array $h = []) => web($wb, 'POST', '/agent/post/rmm_installer.php', $sid, $f + ['csrf_token' => 'csrftok1'], array_merge($XHR, $h));
$tokenCount = fn() => (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
$hdr = fn(string $headers, string $name) => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $headers, $m) === 1 ? trim($m[1]) : '';
$exeA = $r091['_files']['rivetit-agent-windows-amd64.exe'];

// ---------------------------------------------------------------- the page and who sees it
[$c, $b] = web($wb, 'GET', '/agent/rmm_add_device.php', $sidAdmin);
$ok($c === 200 && str_contains($b, 'Add device / Build installer') && str_contains($b, 'id="rmm-add-form"') && str_contains($b, '/js/rmm_add_device.js') && str_contains($b, 'data-add-format="exe"') && str_contains($b, 'data-add-format="msi"')
    && str_contains($b, 'data-add="commands-windows"') && str_contains($b, 'data-add="commands-linux"'), 'page (administrator): the form, a button for each format and the script');
$ok(preg_match('/data-add-format="exe"[^>]* disabled/', $b) === 0 && preg_match('/data-add-format="msi"[^>]* disabled/', $b) === 0, 'page: with a binary and an MSI template hosted, both Windows buttons are enabled');
$ok(str_contains($b, 'Release <strong data-add-linux-ver>0.9.1</strong>') && str_contains($b, 'id="add-linux-files"') && str_contains($b, 'rivetit-agent_0.9.1_amd64.deb') && str_contains($b, 'rivetit-agent-linux-arm64.tar.gz') && str_contains($b, 'data-add-copy-text='), 'page: the recorded Linux release, its files, SHA-256 values and copy-link buttons');
$ok(str_contains($b, hash('sha256', $r091['_files']['rivetit-agent-linux-amd64.tar.gz'])) && str_contains($b, 'checksum list') && str_contains($b, 'GitHub digest'), 'page: each file shows its SHA-256 and where that value came from');
[$c, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $sidAdmin);
$ok(str_contains($b, 'href="/agent/rmm_add_device.php"') && str_contains($b, 'data-nav-build-installer') && str_contains($b, 'rmm_fleet.php?add=1') && str_contains($b, 'id="rmmInstallerModal"'), 'menu: Build installer is there, the old Add device item and dialog are unchanged');
foreach (['technician' => $sidTech, 'viewer' => $sidViewer] as $who => $sid) {
    [$c, $b] = web($wb, 'GET', '/agent/rmm_add_device.php', $sid);
    $ok(!str_contains($b, 'id="rmm-add-form"') && !str_contains($b, 'rvte1.') && (str_contains($b, 'No access') || $c !== 200), "page ($who): no form (" . $c . ')');
}
[$c, , $hh] = web($wb, 'GET', '/agent/rmm_add_device.php', 'nosuchsession');
$ok($c === 302 && stripos($hdr($hh, 'Location'), 'login') !== false, 'page: not signed in goes to the login page');

// ---------------------------------------------------------------- Windows: .exe and .msi from the published, verified files
$n = $tokenCount();
[$c, $body, $heads] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'download', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'exe']);
$ok($c === 200 && strncmp($body, $exeA, strlen($exeA)) === 0 && str_contains($body, 'RIVETIT-EMBED-v1') && $tokenCount() === $n + 1, 'exe: the stamped download starts with the verified GitHub bytes and carries the client\'s payload');
$stamp = InstallerStamp::read($body);
$ok($stamp !== null && ($stamp['data']['department'] ?? '') === 'Client A' && $stamp['exe_length'] === strlen($exeA), 'exe: the trailer decodes (client Client A) and stripping it gives the published file back');
[$c, $body, $heads] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'download', 'client_id' => 2, 'arch' => 'amd64', 'format' => 'msi']);
$stamp = InstallerStamp::read($body);
$ok($c === 200 && strncmp($body, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0 && $stamp !== null && substr($body, 0, $stamp['exe_length']) === $msiBytes && ($stamp['data']['department'] ?? '') === 'Client B'
    && preg_match('/filename="?[A-Za-z-]*client-b-x64\.msi/i', $hdr($heads, 'Content-Disposition')) === 1, 'msi: the template from the release plus Client B\'s payload; stripping the trailer gives the template back byte for byte');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'download', 'client_id' => 1, 'arch' => 'arm64', 'format' => 'msi']);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'MSI') && $tokenCount() === $n + 2, 'msi for ARM64 without a template: 422 in words, no token left behind');
[$c, $body] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'download', 'client_id' => 2, 'arch' => 'arm64', 'format' => 'exe']);
$ok($c === 200 && strncmp($body, $r091['_files']['rivetit-agent-windows-arm64.exe'], 4096) === 0, 'exe for ARM64: the published ARM64 bytes');

// ---------------------------------------------------------------- Windows commands (new: mode=commands for Windows)
$n = $tokenCount();
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi', 'multiple' => '1']);
$j = json_decode($b, true);
$tokRow = $rows('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1')[0];
$ok($c === 200 && ($j['success'] ?? false) === true && str_contains((string) $j['windows']['powershell'], $wb) && preg_match('/rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) $j['windows']['powershell']) === 1 && is_array($j['windows']['msi'])
    && str_contains((string) $j['windows']['msi']['msiexec'], 'msiexec') && $tokenCount() === $n + 1 && (int) $tokRow['max_uses'] === 25, 'Windows commands: the PowerShell snippet with a fresh token, and for msi the msiexec commands (25 uses with "multiple")');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'exe']);
$j = json_decode($b, true);
$ok($c === 200 && array_key_exists('msi', $j['windows']) && $j['windows']['msi'] === null, 'Windows commands (exe): no msi block');
[$c, $b] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'bogus', 'client_id' => 1]);
$ok($c === 422, 'an unknown mode: 422');
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'mode' => 'download', 'client_id' => 1]);
$ok($c === 422 && str_contains((string) (json_decode($b, true)['error'] ?? ''), 'Linux has no installer download'), 'Linux download is still refused');
$n = $tokenCount();
foreach (['technician' => $sidTech, 'viewer' => $sidViewer] as $who => $sid) {
    foreach ([['windows', 'commands'], ['linux', 'commands'], ['windows', 'download']] as [$os, $mode]) {
        [$c, $b] = $post($sid, ['os' => $os, 'mode' => $mode, 'client_id' => 1, 'arch' => 'amd64']);
        $ok($c === 403 && $tokenCount() === $n && !str_contains($b, 'rvte1.'), "$who: $os $mode is refused, no token");
    }
}
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_installer.php', $sidAdmin, ['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'csrf_token' => 'wrong'], $XHR);
$ok($c === 403 && $tokenCount() === $n, 'CSRF: a wrong token is refused, no token created');

// ---------------------------------------------------------------- Linux commands
$ca = sys_get_temp_dir() . '/rv_feed_ca_' . bin2hex(random_bytes(3));
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("$ca.key") . ' -out ' . escapeshellarg("$ca.crt") . ' -days 2 -subj /CN=feed-test 2>&1', $oo, $rc);
$caPem = $rc === 0 ? (string) file_get_contents("$ca.crt") : '';
@unlink("$ca.key"); @unlink("$ca.crt");
$ok($caPem !== '' && $rmm->admin()->saveSettings($admin, ['ca_pem' => $caPem])->ok, 'a CA certificate is set so the scripts carry it');
$q("UPDATE clients SET client_name = " . "'" . $esc("Evil'; touch /tmp/rv_pwned_$ghPort; echo '\nx") . "' WHERE client_id = 2");
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'mode' => 'commands', 'client_id' => 2, 'arch' => 'amd64']);
$j = json_decode($b, true);
$token = preg_match('/rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) ($j['linux_script'] ?? ''), $mt) === 1 ? $mt[0] : '';
$ok($c === 200 && ($j['success'] ?? false) && ($j['release'] ?? '') === '0.9.1' && is_string($j['linux_script']) && is_string($j['linux_deb']) && is_string($j['linux_rpm']) && $token !== '', 'Linux commands: the script, the .deb and the .rpm command, from the recorded release 0.9.1');
$ok(str_contains($j['linux'], './install-linux.sh') && !str_contains($j['linux'], 'curl'), 'Linux commands: the original tarball-next-to-it command is still returned as "linux"');
foreach (['linux_script', 'linux_deb', 'linux_rpm'] as $k) {
    $f = sys_get_temp_dir() . "/rv_feed_$k.sh";
    file_put_contents($f, $j[$k]);
    exec('bash -n ' . escapeshellarg($f) . ' 2>&1', $oo, $rc);
    $ok($rc === 0, "$k: bash -n accepts it (" . ($rc === 0 ? 'syntax ok' : implode(' ', $oo)) . ')');
    @unlink($f);
    $ok(!str_contains($j[$k], 'touch /tmp/rv_pwned') && !str_contains($j[$k], "Evil'") && str_contains($j[$k], 'Evil? touch'), "$k: a hostile client name is reduced to harmless comment text");
    $ok(str_contains($j[$k], hash('sha256', $r091['_files'][$k === 'linux_script' ? 'rivetit-agent-linux-amd64.tar.gz' : ($k === 'linux_deb' ? 'rivetit-agent_0.9.1_amd64.deb' : 'rivetit-agent-0.9.1-1.x86_64.rpm')])) && str_contains($j[$k], 'sha256sum -c -') && str_contains($j[$k], 'BEGIN CERTIFICATE'),
        "$k: the SHA-256 recorded at publish time is checked before anything runs; the CA is included");
}
$ok(substr_count($j['linux_script'], $token) === 1 && !preg_match('/--token\s/', $j['linux_script']) && str_contains($j['linux_script'], '--token-file') && str_contains($j['linux_deb'], '--token-file') && !str_contains($j['linux_deb'], '--token '), 'the token goes through a file, never a command-line argument');

// run the script for real against a real tar.gz served by the mock (a stub install-linux.sh records what it was called with)
$work = sys_get_temp_dir() . '/rv_feed_run_' . bin2hex(random_bytes(3));
mkdir("$work/tar", 0700, true); mkdir("$work/bin", 0700, true);
file_put_contents("$work/tar/rivetit-agent", "#!/bin/sh\necho agent\n"); chmod("$work/tar/rivetit-agent", 0755);
file_put_contents("$work/tar/install-linux.sh", "#!/bin/sh\n{ echo \"ARGS: \$*\"; } > \"$work/called.txt\"\nwhile [ \$# -gt 0 ]; do case \"\$1\" in --token-file) cat \"\$2\" > \"$work/seen_token.txt\";; --ca) cat \"\$2\" > \"$work/seen_ca.txt\";; esac; shift; done\n"); chmod("$work/tar/install-linux.sh", 0755);
exec('tar -C ' . escapeshellarg("$work/tar") . ' -czf ' . escapeshellarg("$work/agent.tar.gz") . ' rivetit-agent install-linux.sh 2>&1', $oo, $rc);
$tgz = (string) file_get_contents("$work/agent.tar.gz");
$rec = ['version' => '0.9.1', 'assets' => [['name' => 'rivetit-agent-linux-amd64.tar.gz', 'kind' => 'tarball', 'arch' => 'amd64', 'sha256' => hash('sha256', $tgz), 'url' => "http://127.0.0.1:$ghPort/files-test/agent.tar.gz"]]];
@mkdir("$ghDir/files/agent-v0.9.1", 0700, true);
file_put_contents("$ghDir/files/agent-v0.9.1/agent-test.tar.gz", $tgz);
$rec['assets'][0]['url'] = "http://127.0.0.1:$ghPort/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/agent-test.tar.gz";
$ctl(['api' => 'ok', 'download' => 'direct', 'etag' => '"v1"']);
$core = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet($wb, 'rvte1.AAAA.BBBB', 'amd64', 'Client A', $caPem, null);
$script = LinuxInstall::script($rec, $core, 'Client A');
$testScript = str_replace(" --proto '=https' --proto-redir '=https'", '', (string) $script);   // the mock is plain http on loopback; production keeps https only
$machine = trim((string) shell_exec('uname -m'));
if (in_array($machine, ['x86_64', 'amd64'], true)) {
    file_put_contents("$work/run.sh", $testScript);
    exec('bash ' . escapeshellarg("$work/run.sh") . ' 2>&1', $oo, $rc);
    $called = (string) @file_get_contents("$work/called.txt");
    $ok($rc === 0 && str_contains($called, '--server ' . $wb) && str_contains($called, '--token-file') && str_contains($called, '--ca') && trim((string) @file_get_contents("$work/seen_token.txt")) === 'rvte1.AAAA.BBBB' && str_contains((string) @file_get_contents("$work/seen_ca.txt"), 'BEGIN CERTIFICATE'),
        'the script runs: it downloads the tarball, checks the hash, unpacks it and calls install-linux.sh with the server, a token file and the CA (' . trim($called) . ')');
    // a tampered tarball: sha256sum fails and the installer is never called
    @unlink("$work/called.txt");
    file_put_contents("$ghDir/files/agent-v0.9.1/agent-test.tar.gz", $tgz . 'X');
    exec('bash ' . escapeshellarg("$work/run.sh") . ' 2>&1', $oo2, $rc2);
    $ok($rc2 !== 0 && !is_file("$work/called.txt") && str_contains(implode("\n", $oo2), 'FAILED'), 'a tarball that does not match the recorded SHA-256 stops the script before anything runs');
    $leftover = glob(sys_get_temp_dir() . '/tmp.*') ?: [];
} else {
    echo "SKIP  running the generated script needs an x86_64 host ($machine)\n";
}
// the .deb / .rpm scripts with stubs for the package manager and the packaged agent
foreach (['deb' => 'apt-get', 'rpm' => 'dnf'] as $kind => $pm) {
    $dl = $kind === 'deb' ? 'agent.deb' : 'agent.rpm';
    $file = "agent-test.$kind";
    file_put_contents("$ghDir/files/agent-v0.9.1/$file", "PKG-$kind");
    $rec2 = ['version' => '0.9.1', 'assets' => [['name' => $file, 'kind' => $kind, 'arch' => 'amd64', 'sha256' => hash('sha256', "PKG-$kind"), 'url' => "http://127.0.0.1:$ghPort/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/$file"]]];
    $s = LinuxInstall::package($rec2, $kind, $core, 'rvte1.CCCC.DDDD', $wb, $caPem, "Client\nB");
    $ok($s !== null && substr_count($s, "\n# ") <= 1 && !str_contains(explode("\n", $s)[0], "\n"), "$kind: a newline in the client name cannot break out of the comment line");
    $t = str_replace(" --proto '=https' --proto-redir '=https'", '', (string) $s);
    $t = str_replace('/opt/rivetit-agent/rivetit-agent', "$work/stub-agent", $t);
    file_put_contents("$work/stub-agent", "#!/bin/sh\n{ echo \"ARGS: \$*\"; } > \"$work/pkg_called_$kind.txt\"\nwhile [ \$# -gt 0 ]; do case \"\$1\" in --token-file) cat \"\$2\" > \"$work/pkg_token_$kind.txt\";; esac; shift; done\n"); chmod("$work/stub-agent", 0755);
    file_put_contents("$work/bin/$pm", "#!/bin/sh\necho \"$pm \$*\" > \"$work/pm_$kind.txt\"\n"); chmod("$work/bin/$pm", 0755);
    if ($kind === 'rpm') { file_put_contents("$work/bin/yum", "#!/bin/sh\nexit 1\n"); chmod("$work/bin/yum", 0755); }
    file_put_contents("$work/run_$kind.sh", $t);
    $o2 = [];
    exec('PATH=' . escapeshellarg("$work/bin") . ':$PATH bash ' . escapeshellarg("$work/run_$kind.sh") . ' 2>&1', $o2, $rc3);
    $isX86 = in_array($machine, ['x86_64', 'amd64'], true);
    if ($isX86) {
        $ok($rc3 === 0 && str_contains((string) @file_get_contents("$work/pm_$kind.txt"), 'install -y') && trim((string) @file_get_contents("$work/pkg_token_$kind.txt")) === 'rvte1.CCCC.DDDD' && str_contains((string) @file_get_contents("$work/pkg_called_$kind.txt"), "install --server $wb --token-file"),
            "$kind script runs with stubs: hash checked, package manager called, the packaged agent's install called with a token file (rc $rc3: " . implode(' ', array_slice($o2, 0, 2)) . ')');
        @unlink("$work/pkg_called_$kind.txt");
        file_put_contents("$ghDir/files/agent-v0.9.1/$file", "TAMPERED");
        exec('PATH=' . escapeshellarg("$work/bin") . ':$PATH bash ' . escapeshellarg("$work/run_$kind.sh") . ' 2>&1', $o3, $rc4);
        $ok($rc4 !== 0 && !is_file("$work/pkg_called_$kind.txt"), "$kind: a package that does not match its recorded SHA-256 is never installed");
    }
}
$ok(!is_file('/tmp/rv_pwned_' . $ghPort), 'nothing a hostile client name contained was executed');
foreach (glob("$work/{,bin/,tar/}*", GLOB_BRACE) ?: [] as $f) { @unlink($f); }
@rmdir("$work/bin"); @rmdir("$work/tar"); @rmdir($work);
$ok(LinuxInstall::package(['assets' => []], 'deb', $core, 't', 's', null, 'x') === null && LinuxInstall::script(['assets' => [['kind' => 'tarball', 'arch' => 'amd64', 'sha256' => 'zz', 'url' => 'u']]], $core, 'x') === null && LinuxInstall::package($rec2, 'zip', $core, 't', 's', null, 'x') === null,
    'a record without a usable SHA-256 (or an unknown package kind) produces no command');
$q("UPDATE clients SET client_name = 'Client B' WHERE client_id = 2");

// ---------------------------------------------------------------- no Linux release recorded: the old command is the fallback
$q("DELETE FROM endpoint_agent_feed_state WHERE state_key LIKE 'linux:%'");
[$c, $b] = $post($sidAdmin, ['os' => 'linux', 'mode' => 'commands', 'client_id' => 1]);
$j = json_decode($b, true);
$ok($c === 200 && str_contains($j['linux'], './install-linux.sh') && !isset($j['linux_script']) && !isset($j['release']), 'no Linux release recorded: only the original command, no release keys');
[$c, $b] = web($wb, 'GET', '/agent/rmm_add_device.php', $sidAdmin);
$ok(str_contains($b, 'data-add-notice="linux"') && str_contains($b, 'href="/admin/settings_endpoint_agent.php#agent-updates"') && !str_contains($b, 'id="add-linux-files"'), 'page: without a recorded release it says so and links to Agent updates');

// ---------------------------------------------------------------- no hosted Windows agent: the page says so
$q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates');
[$c, $b] = web($wb, 'GET', '/agent/rmm_add_device.php', $sidAdmin);
$ok(str_contains($b, 'data-add-notice="binary"') && preg_match('/data-add-format="exe"[^>]* disabled/', $b) === 1 && preg_match('/data-add-format="msi"[^>]* disabled/', $b) === 1 && str_contains($b, 'No MSI template is published'), 'page: no hosted agent and no template: both buttons are disabled with the reason');
// module off
$rmm->admin()->disable($admin);
[$c, $b] = web($wb, 'GET', '/agent/rmm_add_device.php', $sidAdmin);
$ok(str_contains($b, 'RMM module is turned off') && !str_contains($b, 'rmm-add-form'), 'module off: the page is the "turned off" notice');
$rmm->admin()->enable($admin);
$rmm->syncState();
