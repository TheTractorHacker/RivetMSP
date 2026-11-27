<?php
// MSP-DS-107: the certificate-fetch SSRF guard refuses CGNAT/benchmark/NAT64/6to4 addresses and getSSL() connects to the vetted IP. Run: php tests/msp_ds_107_net_guards.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/net_guards.php';
foreach (['127.0.0.1', '10.1.2.3', '192.168.1.1', '172.16.0.9', '169.254.169.254', '100.64.0.1', '100.127.255.254', '198.18.0.1', '198.19.255.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '64:ff9b::7f00:1', '64:ff9b::a00:1', '2002:7f00:1::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1'] as $ip) {
    $ok(publicIpsForHost($ip) === [] && !hostResolvesOnlyToPublicIps($ip), "refused: $ip");
}
foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
    $ok(publicIpsForHost($ip) === [$ip], "allowed: $ip");
}
$ok(publicIpsForHost('') === [] && publicIpsForHost('[::1]') === [], 'empty and bracketed loopback refused');
$f = file_get_contents($root . '/functions.php');
preg_match('/function getSSL\(.*?\n\}\n/s', $f, $m);
$ok(str_contains($m[0], 'publicIpsForHost($name)') && !str_contains($m[0], '"ssl://$name:$port"'), 'getSSL vets with publicIpsForHost and no longer connects by name');
$ok(str_contains($m[0], '$connect_ip = $vetted_ips[0];') && str_contains($m[0], '"peer_name" => $name'), 'it connects to the vetted IP with the name as peer_name/SNI');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
