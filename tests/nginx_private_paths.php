<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Static check (no DB, no network):  php tests/nginx_private_paths.php
 *  - the repo nginx templates deny the non-public trees (tests, vendor, src, includes) and root metadata files;
 *  - every PHP file under tests/ refuses to run outside the CLI.
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
foreach (['deploy/templates/itflow-locations.conf', 'docker/nginx.conf'] as $rel) {
    $s = file_get_contents(__DIR__ . '/../' . $rel);
    $ok(strpos($s, 'location ~ ^/(tests|vendor|src|includes|odoo_addons|mcp_server|setup/setup_functions\\.php) {') !== false, "$rel denies tests/vendor/src/includes");
    $ok(strpos($s, 'location ~* ^/[^/]+\\.(md|lock|sql|yml|yaml)$ {') !== false, "$rel denies *.md/lock/sql/yml at root");
    $ok(strpos($s, 'location = /composer.json') !== false, "$rel denies composer.json");
    // RivetCore 1.0.0-rc.11 stores file transfers from devices in backups/endpoint-transfer (includes/rmm_bootstrap.php, EA_TRANSFER_DIR): they are served by nobody but the technician download
    $ok(preg_match('/location \^~ \/backups\/ \{\s*deny all;/', $s) === 1, "$rel denies /backups/ (and with it backups/endpoint-transfer, backups/endpoint-agent, backups/rmm-state)");
}
$boot = file_get_contents(__DIR__ . '/../includes/rmm_bootstrap.php');
$ok(strpos($boot, "\$root . '/backups/endpoint-transfer'") !== false, 'the default transfer folder of the RMM module is inside the denied backups/ area');
$mig = file_get_contents(__DIR__ . '/../admin/database_updates.php');
$ok(strpos($mig, "dirname(__DIR__) . '/backups/endpoint-transfer'") !== false, 'the DB update creates the same folder (inside backups/)');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $n++;
    $head = implode("\n", array_slice(file($f->getPathname()), 0, 6));
    $ok(strpos($head, "PHP_SAPI !== 'cli'") !== false, 'CLI-only guard: ' . substr($f->getPathname(), strlen(__DIR__) + 1));
}
$ok($n > 0, "found $n test php files");
exit($fails ? 1 : 0);
