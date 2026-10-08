<?php
/*
 * Golden replay: the HTTP transcripts RivetCore recorded from the ORIGINAL RivetIT endpoint agent code (rivet-core tests/Fixtures/rmm/golden, baseline
 * c26957c0b, 10 files covering the device API and the technician calls) are replayed against THIS install's bridges (api/v1/agent_*.php,
 * endpoint_devices.php) and must come out identical: status codes, headers, JSON (key order included), signatures (verified), stored side effects.
 *
 *   RIVET_CORE_DIR=/path/to/rivet-core RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=scratch_x RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *     RIVETMSP_REDIS_PORT=6371 php tests/endpoint_agent_golden.php
 *
 * RIVET_CORE_DIR is a checkout of rivet-core (the fixtures and the driver are not shipped in the Composer package). Scratch rules: a scratch database with 'scratch' in its name, config.php pointing at it, EA_ALLOW_INSECURE_HTTP defined. The
 * second server (the 426 transcripts) gets EA_TEST_NO_INSECURE=1, which a scratch config.php may honour:
 *     if (getenv('EA_TEST_NO_INSECURE') !== '1') { define('EA_ALLOW_INSECURE_HTTP', true); }
 * A throwaway Redis (RIVETMSP_REDIS_HOST/PORT, never the live one) is needed for the per-device 429 transcripts (the rate limiter fails open without it).
 */
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "set RIVETMSP_TEST_DB=1 and a scratch RIVETMSP_TEST_DB_NAME\n"); exit(2); }
$core = rtrim((string) getenv('RIVET_CORE_DIR'), '/');
if ($core === '' || !is_file("$core/scripts/rmm-golden/golden.php") || !is_dir("$core/tests/Fixtures/rmm/golden")) { fwrite(STDERR, "set RIVET_CORE_DIR to a rivet-core checkout\n"); exit(2); }
$root = dirname(__DIR__);
$cfgText = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]' . preg_quote((string) getenv('RIVETMSP_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText) || strpos($cfgText, 'EA_ALLOW_INSECURE_HTTP') === false) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database and define EA_ALLOW_INSECURE_HTTP\n"); exit(2);
}
// Four-digit ports on purpose: the stamped installer carries the server URL, so the recorded installer length depends on the port having four digits.
$free = static function (array $taken): int {
    for ($i = 0; $i < 200; $i++) {
        $p = random_int(8200, 9899);
        $s = in_array($p, $taken, true) ? false : @stream_socket_server("tcp://127.0.0.1:$p");
        if ($s) { fclose($s); return $p; }
    }
    fwrite(STDERR, "no free port\n"); exit(2);
};
$procs = [];
$start = static function (int $port, array $env) use ($root, &$procs): void {
    $log = sys_get_temp_dir() . "/ea_golden_$port.log";
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$log", '-S', "127.0.0.1:$port", "$root/tests/rmm_golden/router.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root, array_merge(getenv(), $env));
    $procs[] = $p;
    for ($i = 0; $i < 80; $i++) { if (@fsockopen('127.0.0.1', $port)) { return; } usleep(100000); }
    fwrite(STDERR, "server on $port did not start\n"); exit(2);
};
$main = $free([]);
$tls = $free([$main]);
$start($main, ['RIVETMSP_WEBHOOK_ALLOW_PRIVATE' => '1', 'RIVET_CORE_DIR' => $core]);
$start($tls, ['RIVETMSP_WEBHOOK_ALLOW_PRIVATE' => '1', 'EA_TEST_NO_INSECURE' => '1', 'RIVET_CORE_DIR' => $core]);
$mode = $argv[1] ?? 'replay';   // replay = record this install into a temp dir and compare with Core's transcripts, allowing the one intentional change; strict = the driver's own replay
$tmp = sys_get_temp_dir() . '/ea_golden_rec_' . bin2hex(random_bytes(4));
$cmd = [PHP_BINARY, "$core/scripts/rmm-golden/golden.php", $mode === 'strict' ? 'replay' : 'record', "--base=http://127.0.0.1:$main", "--tls-base=http://127.0.0.1:$tls", "--install=$root",
    "--adapter=$root/tests/rmm_golden/adapter.php", "--dir=" . ($mode === 'strict' ? "$core/tests/Fixtures/rmm/golden" : $tmp)];
$p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => ['file', '/dev/null', 'a']], $pipes, $root, array_merge(getenv(), ['RIVET_CORE_DIR' => $core]));
$code = proc_close($p);
foreach ($procs as $proc) { proc_terminate($proc); proc_close($proc); }
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/rmm_golden/adapter.php") . ' ' . escapeshellarg($root) . ' hook ' . escapeshellarg('{"hook":"cleanup"}') . ' 2>&1');   // leave no hosted binary behind
if ($mode === 'strict') { exit($code); }

// ---- compare with the transcripts recorded from the original RivetIT code. RivetMSP has no enrolled agents to stay compatible with, so a disabled module
// answers 503 module_disabled (Retry-After 3600) where the original answered 403 / 405 / 401 after a database lookup: the four "-disabled" exchanges of
// 01-disabled.json are the ONLY allowed difference; everything else must be identical, header for header and row for row.
$intentional = ['enroll-disabled', 'enroll-get-disabled', 'installer-disabled', 'checkin-garbage-bearer-disabled'];
$fails = 0;
$files = array_map('basename', glob("$core/tests/Fixtures/rmm/golden/*.json"));
sort($files);
foreach ($files as $f) {
    $want = json_decode((string) file_get_contents("$core/tests/Fixtures/rmm/golden/$f"), true);
    $got = is_file("$tmp/$f") ? json_decode((string) file_get_contents("$tmp/$f"), true) : null;
    if ($got === null) { echo "FAIL  $f was not recorded\n"; $fails++; continue; }
    if ($want === $got) { echo "PASS  $f identical\n"; continue; }
    if ($f !== '01-disabled.json' || count($want['steps']) !== count($got['steps']) || ($want['snapshots'] ?? null) !== ($got['snapshots'] ?? null)) { echo "FAIL  $f differs\n"; $fails++; continue; }
    $bad = [];
    foreach ($want['steps'] as $i => $w) {
        $g = $got['steps'][$i];
        if (in_array($w['id'] ?? '', $intentional, true)) {
            $h = $g['response']['headers'] ?? [];
            if (($g['response']['status'] ?? 0) !== 503 || ($g['response']['body']['code'] ?? '') !== 'module_disabled' || !in_array('retry-after: 3600', $h, true) || !in_array('cache-control: no-store', $h, true) || ($g['request'] ?? null) !== ($w['request'] ?? null)) { $bad[] = $w['id']; }
        } elseif ($w !== $g) { $bad[] = $w['id'] ?? "#$i"; }
    }
    if ($bad) { echo "FAIL  $f differs beyond the intentional change: " . implode(', ', $bad) . "\n"; $fails++; } else { echo "PASS  $f: identical except the 4 disabled exchanges, which answer 503 module_disabled + Retry-After 3600 + no-store (intentional)\n"; }
}
foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); }
@rmdir($tmp);
echo $fails === 0 ? 'replay ' . (count($files) - 1) . " transcript files compared: " . (count($files) - 2) . " identical, 1 with the intentional change\n" : "replay FAILED ($fails)\n";
exit($fails === 0 ? 0 : 1);
