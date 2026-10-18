<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fixture for tests/browser/rmm_agent_feed_smoke.mjs: writes one agent release (agent-v0.9.1 and an older agent-v0.9.0) into the directory of the LOCAL GitHub mock
 * (tests/support/mock_github.py) -- api.json, files/<tag>/<asset>, ctl.json. Touches neither the database nor the network.
 *
 *   php tests/browser/rmm_agent_feed_extra.php <mock dir>
 */
$dir = rtrim((string) ($argv[1] ?? ''), '/');
if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "usage: php rmm_agent_feed_extra.php <mock dir>\n"); exit(2); }
$pe = function (int $machine, int $size, string $fill): string {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
};
$msi = (string) file_get_contents(dirname(__DIR__) . '/fixtures/msi/template-x64.msi');
$out = [];
foreach (['0.9.1' => '2026-10-18T17:16:53Z', '0.9.0' => '2026-10-01T10:00:00Z'] as $ver => $at) {
    $tag = "agent-v$ver";
    @mkdir("$dir/files/$tag", 0700, true);
    $tarball = (string) @shell_exec('cd ' . escapeshellarg(sys_get_temp_dir()) . ' && true');
    $files = [
        'rivetit-agent-windows-amd64.exe' => $pe(0x8664, 16384, "a$ver"), 'rivetit-agent-windows-arm64.exe' => $pe(0xAA64, 16384, "b$ver"), 'rivetit-agent-windows-amd64.msi' => $msi,
        'install-linux.sh' => "#!/bin/sh\nexit 0\n", 'rivetit-agent-linux-amd64.tar.gz' => 'TAR-A-' . $ver . str_repeat('x', 400), 'rivetit-agent-linux-arm64.tar.gz' => 'TAR-B-' . $ver . str_repeat('y', 400),
        "rivetit-agent_{$ver}_amd64.deb" => 'DEB-A' . $ver . str_repeat('d', 300), "rivetit-agent-$ver-1.x86_64.rpm" => 'RPM-A' . $ver . str_repeat('r', 300),
    ];
    $sums = ''; $msiSums = '';
    foreach ($files as $n => $b) {
        if (str_ends_with($n, '.msi')) { $msiSums .= hash('sha256', $b) . "  $n\n"; } elseif (!str_ends_with($n, '.deb') && !str_ends_with($n, '.rpm')) { $sums .= hash('sha256', $b) . "  $n\n"; }
    }
    $files['SHA256SUMS'] = $sums; $files['SHA256SUMS.msi'] = $msiSums;
    $assets = [];
    foreach ($files as $n => $b) { file_put_contents("$dir/files/$tag/$n", $b); $assets[] = ['name' => $n, 'size' => strlen($b), 'digest' => 'sha256:' . hash('sha256', $b)]; }
    $out[] = ['tag_name' => $tag, 'name' => "Agent $ver", 'prerelease' => $ver === '0.9.1', 'draft' => false, 'published_at' => $at, 'body' => 'notes', 'assets' => $assets];
}
file_put_contents("$dir/api.json", json_encode($out));
file_put_contents("$dir/ctl.json", json_encode(['api' => 'ok', 'download' => 'cdn', 'etag' => '"smoke"']));
echo "wrote " . count($out) . " releases to $dir\n";
