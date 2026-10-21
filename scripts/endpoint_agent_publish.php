#!/usr/bin/env php
<?php

// Publishes an agent executable to the RivetMSP server (same validation as Administration > Endpoint agent > Agent binaries).
//   php scripts/endpoint_agent_publish.php dist/rivetit-agent-windows-amd64.exe --version 1.2.0 --arch amd64 [--activate]
//        [--release stable|pilot [--rollout 10] [--notes "text"]]
//   php scripts/endpoint_agent_publish.php dist/rivetit-agent-windows-amd64.msi --msi --version 1.0.0 --arch amd64 [--activate]
//   --msi       the file is an MSI TEMPLATE (RivetCore 1.0.0-rc.16, `make msi`): stored in the MSI template table, never an agent update (no --release). New MSI installers are a copy of the
//               current template with the per-client payload appended. Unsigned: SmartScreen or an application-control policy may warn. Upgrade, repair and uninstall are not yet tested.
//   --activate  make it the binary new per-client installers are stamped from for that architecture
//   --release   also offer it to already enrolled agents of that architecture as a self-update (staged by --rollout percent)
//   --os        windows (default; a PE .exe) or linux (the Linux agent program, for the self-update of enrolled Linux devices; RivetCore 1.0.0-rc.30). Every binary and
//               release belongs to one platform: a Linux device is never offered a Windows executable. --activate does not apply to linux.
// Run it as the web server user (sudo -u www-data php ...) or as root (the stored file is then handed to the directory owner).

chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once "../config.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";
require_once "../includes/rmm_bootstrap.php";

mysqli_report(MYSQLI_REPORT_OFF);
// Own argument parser: the executable may come before or after the options (PHP's getopt() stops at the first non-option).
$opts = [];
$file = null;
$args = array_slice($argv, 1);
$valueOpts = ['version', 'arch', 'release', 'rollout', 'notes', 'os'];
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if (strncmp($a, '--', 2) === 0) {
        $name = substr($a, 2);
        $val = null;
        if (strpos($name, '=') !== false) {
            [$name, $val] = explode('=', $name, 2);
        }
        if (in_array($name, $valueOpts, true)) {
            $opts[$name] = $val ?? ($args[++$i] ?? '');
        } elseif ($name === 'activate' || $name === 'help' || $name === 'msi') {
            $opts[$name] = true;
        } else {
            fwrite(STDERR, "Error: unknown option --$name\n");
            exit(1);
        }
    } elseif ($file === null) {
        $file = $a;
    } else {
        fwrite(STDERR, "Error: only one executable may be given.\n");
        exit(1);
    }
}
if (isset($opts['help']) || $file === null || !isset($opts['version'], $opts['arch'])) {
    echo "Usage: php endpoint_agent_publish.php <exe> --version X.Y.Z --arch amd64|arm64 [--os windows|linux] [--activate] [--release stable|pilot [--rollout N] [--notes TEXT]]\n"
        . "       php endpoint_agent_publish.php <msi> --msi --version X.Y.Z --arch amd64|arm64 [--activate]\n";
    exit(isset($opts['help']) ? 0 : 1);
}
if (isset($opts['msi']) && (isset($opts['release']) || isset($opts['rollout']) || isset($opts['notes']))) {
    fwrite(STDERR, "Error: an MSI template is never offered as an agent update; --release, --rollout and --notes do not apply to --msi.\n");
    exit(1);
}
$os = (string) ($opts['os'] ?? 'windows');
if (!in_array($os, ['windows', 'linux'], true) || (isset($opts['msi']) && $os !== 'windows') || ($os === 'linux' && isset($opts['activate']))) {
    fwrite(STDERR, "Error: --os must be windows or linux; an MSI template is Windows only, and --activate (the binary installers are stamped from) is Windows only.\n");
    exit(1);
}
if (Db_tableMissing(isset($opts['msi']) ? 'endpoint_agent_msi_templates' : 'endpoint_agent_binaries')) {
    fwrite(STDERR, "Error: the " . (isset($opts['msi']) ? 'MSI template table (DB 2.6.91)' : 'endpoint agent tables') . " is missing. Run: php scripts/update_cli.php --update_db\n");
    exit(1);
}
$session_user_id = 0;
$session_ip = 'cli';
$session_user_agent = 'endpoint_agent_publish.php';
if (isset($opts['msi'])) {
    // BinaryStore::publishMsi() (rivet/rivet-core): the same validation as the upload form (OLE compound file, size window, not already stamped, platform from the package).
    $res = rivetRmmModule()->binaryStore()->publishMsi($file, (string) $opts['version'], (string) $opts['arch'], 0, ['activate' => isset($opts['activate'])]);
    if (!$res['ok']) {
        fwrite(STDERR, 'Error: ' . $res['error'] . "\n");
        exit(1);
    }
    $t = $res['template'];
    logAction('Endpoint Agent', 'MSI Template Published', "CLI published MSI template {$t['version']} ({$t['arch']}, architecture from the {$t['arch_source']}, sha256 {$t['sha256']})" . (isset($opts['activate']) ? ' as current' : ''));
    printf("%s MSI template %s %s  %s  %s bytes  (platform read from: %s)%s\n", $res['created'] ? 'Published' : 'Already published', $t['version'], $t['arch'], $t['sha256'], $t['size_bytes'], $t['arch_source'], $t['is_current'] ? '  (current)' : '');
    exit(0);
}
// BinaryStore::publish() (rivet/rivet-core): the same validation as the upload form, no module switch involved.
$res = rivetRmmModule()->binaryStore()->publish($file, (string) $opts['version'], (string) $opts['arch'], 0, [
    'activate' => isset($opts['activate']),
    'release_ring' => $opts['release'] ?? null,
    'rollout_pct' => (int) ($opts['rollout'] ?? 10),
    'notes' => (string) ($opts['notes'] ?? ''),
    'os' => $os,
]);
if (!$res['ok']) {
    fwrite(STDERR, 'Error: ' . $res['error'] . "\n");
    exit(1);
}
$b = $res['binary'];
logAction('Endpoint Agent', 'Binary Published', "CLI published agent binary {$b['version']} ({$b['arch']}, $os, sha256 {$b['sha256']})" . (isset($opts['activate']) ? ' as current' : ''));
printf("%s %s %s %s  %s  %s bytes%s\n", $res['created'] ? 'Published' : 'Already published', $b['version'], $b['arch'], $os, $b['sha256'], $b['size_bytes'], $b['is_current'] ? '  (current)' : '');
exit(0);

function Db_tableMissing(string $table = 'endpoint_agent_binaries'): bool
{
    global $mysqli;
    $r = mysqli_query($mysqli, "SHOW TABLES LIKE '" . mysqli_real_escape_string($mysqli, $table) . "'");
    return !$r || mysqli_num_rows($r) === 0;
}
