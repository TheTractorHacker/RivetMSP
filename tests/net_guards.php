<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Outbound host guard used before the server connects to a user-supplied host (getSSL): literal-IP cases are
 * deterministic and need no network:  php tests/net_guards.php
 */
require __DIR__ . '/../includes/net_guards.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.9', '169.254.169.254', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '[::1]', '', ' '] as $h) {
    $ok(!hostResolvesOnlyToPublicIps($h), "blocks '$h'");
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $h) {
    $ok(hostResolvesOnlyToPublicIps($h), "allows public $h");
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
